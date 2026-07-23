<?php

declare(strict_types=1);

namespace App\Infra;

require_once __DIR__ . '/Message.php';

use App\Infra\Message;

// ───────────────────────────────────────────────────────────────────────────
// LokiLogger.php — infrastructure-side structured error & exception logging.
//
// Loaded via auto_prepend_file (/usr/local/lib/php/LokiLogger.php).
// Registers error, exception, and shutdown handlers that capture full backtraces and push structured JSON directly to Loki's HTTP API.
// ───────────────────────────────────────────────────────────────────────────

abstract class LokiLogger
{
    private bool $registered = false;

    /** Maps PHP error constants to Loki-recognisable level labels. */
    private const ERROR_LABELS = [
        E_ERROR             => 'error',
        E_WARNING           => 'warn',
        E_PARSE             => 'error',
        E_NOTICE            => 'info',
        E_CORE_ERROR        => 'error',
        E_CORE_WARNING      => 'warn',
        E_COMPILE_ERROR     => 'error',
        E_COMPILE_WARNING   => 'warn',
        E_USER_ERROR        => 'error',
        E_USER_WARNING      => 'warn',
        E_USER_NOTICE       => 'info',
        E_STRICT            => 'info',
        E_RECOVERABLE_ERROR => 'error',
        E_DEPRECATED        => 'info',
        E_USER_DEPRECATED   => 'info',
    ];

    /** Maps syslog(3) level constants to labels for the JSON payload. */
    private const PRIORITY_LABELS = [
        LOG_EMERG   => 'EMERGENCY',
        LOG_ALERT   => 'info',
        LOG_CRIT    => 'CRITICAL',
        LOG_ERR     => 'ERROR',
        LOG_WARNING => 'WARNING',
        LOG_NOTICE  => 'info',
        LOG_INFO    => 'INFO',
        LOG_DEBUG   => 'DEBUG',
    ];

    final public function __construct()
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;
        $this->register();
    }

    /**
     * Return a human-readable label for a syslog(3) priority constant.
     */
    public static function priorityLabel(int $priority): string
    {
        $level = $priority & 0x07;

        return self::PRIORITY_LABELS[$level] ?? 'UNKNOWN';
    }

    /**
     * uopz mock for the built-in syslog(). Every deliberate syslog()
     * call is intercepted, wrapped in a Message, and pushed directly
     * to Loki's HTTP API — no syslog/rsyslog involved.
     */
    public static function syslog(int $priority, string $message): bool
    {
        $raw = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $trace = array_slice($raw, 1);

        LokiLogger::push(new Message(
            type: 'syslog',
            level: LokiLogger::priorityLabel($priority),
            message: $message,
            file: $trace[0]['file'] ?? 'unknown',
            line: $trace[0]['line'] ?? 0,
            trace: $trace,
        ));

        return true;
    }

    /**
     * Return the log_source label for a Message type.
     */
    private static function logSourceFor(Message $message): string
    {
        return match ($message->type) {
            'syslog'    => 'syslog',
            'error'     => 'error',
            'fatal'     => 'fatal',
            'exception' => 'exception',
            default     => 'unknown',
        };
    }

    /**
     * Push a Message to Loki via HTTP POST (cURL). Single log line,
     * single Loki entry.
     */
    public static function push(Message $message): void
    {
        $tsNs = (string) (int) (microtime(true) * 1_000_000_000);

        $body = json_encode([
            'streams' => [[
                'stream' => [
                    'log_source'   => self::logSourceFor($message),
                    'service_name' => 'php',
                ],
                'values' => [[
                    $tsNs,
                    json_encode($message->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                ]],
            ]],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init('http://loki:3100/loki/api/v1/push');

        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => 100,
            CURLOPT_NOSIGNAL       => true,
        ]);

        curl_exec($ch);
    }

    private function register(): void
    {
        set_error_handler($this->handleError(...));
        set_exception_handler($this->handleException(...));
        register_shutdown_function($this->handleShutdown(...));
    }

    // ── Error handler (non-fatal) ──────────────────────────────────────

    private function handleError(
        int $errno,
        string $errstr,
        string $errfile,
        int $errline,
    ): bool {
        if (!(error_reporting() & $errno)) {
            return true;
        }

        $raw = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        LokiLogger::push(new Message(
            type: 'error',
            level: self::ERROR_LABELS[$errno] ?? 'Unknown error',
            message: $errstr,
            file: $errfile,
            line: $errline,
            trace: array_slice($raw, 1),
        ));

        return true;
    }

    // ── Exception handler ─────────────────────────────────────────────

    private function handleException(\Throwable $e): void
    {
        $previous = $e->getPrevious();

        LokiLogger::push(new Message(
            type: 'exception',
            level: $e::class,
            message: $e->getMessage(),
            file: $e->getFile(),
            line: $e->getLine(),
            trace: $e->getTrace(),
            code: $e->getCode(),
            previous: $previous !== null
                ? ['class' => $previous::class, 'message' => $previous->getMessage()]
                : null,
        ));
    }

    // ── Shutdown handler (fatal errors) ───────────────────────────────

    private function handleShutdown(): void
    {
        $last = error_get_last();

        if ($last === null) {
            return;
        }

        $fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

        if (!($last['type'] & $fatalMask)) {
            return;
        }

        LokiLogger::push(new Message(
            type: 'fatal',
            level: self::ERROR_LABELS[$last['type']] ?? 'Fatal error',
            message: $last['message'],
            file: $last['file'],
            line: $last['line'],
        ));
    }
}

// ── Bootstrap: intercept syslog, instantiate logger ───────────────────────

(function (): void {
    \uopz_set_return('syslog', LokiLogger::syslog(...), true);

    new class extends LokiLogger {};
})();
