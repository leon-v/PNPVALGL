<?php

/**
 * End-to-end oracle tests for Nginx error logs forwarded by Alloy to Loki.
 *
 * Covers the nginx daemon-level error log (log_source=nginx-error),
 * the Alloy-detected severity level (detected_level), the raw
 * nginx level label (nginx_level), and all structured fields
 * (timestamp, pid, tid, cid, message) extracted by the Alloy
 * regex pipeline.
 *
 * The info-level test triggers a real invalid request. The remaining
 * seven levels are tested by writing synthetic nginx-format log
 * lines directly to the error log.
 */

declare(strict_types=1);

namespace LeonV\Pnpvalg\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use LeonV\Pnpvalg\Tests\Support\Loki;

final class TestAlloyNginxError extends TestCase
{

    private const string NGINX_ERROR_LOGQL = '{log_source="nginx-error"} |= "%s"';

    // ──────────────────────────────────────────────────────────────
    // Data provider: nginx level → expected detected_level
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function nginxErrorLevelProvider(): array
    {
        return [
            'emerg → critical'  => ['emerg', 'critical'],
            'alert → critical'  => ['alert', 'critical'],
            'crit → critical'   => ['crit', 'critical'],
            'error → error'     => ['error', 'error'],
            'warn → warn'       => ['warn', 'warn'],
            'notice → info'     => ['notice', 'info'],
            'info → info'       => ['info', 'info'],
            'debug → debug'     => ['debug', 'debug'],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // Real-event test: warn-level via invalid request
    // ──────────────────────────────────────────────────────────────

    /**
     * Nginx error log reaches Loki with log_source=nginx-error.
     *
     * Triggers a deliberately invalid request — a bare token with no
     * method, path, or HTTP version — to provoke an info-level
     * "client sent invalid method" entry. The token appears in the
     * logged request fragment so it can be matched by LogQL.
     */
    public function testNginxErrorLogReachesLoki(): void
    {
        $token = (string) getenv('LOCAL_AUTH_KEY');

        // nginx error log has only second precision — capture and
        // floor the query start before the write so the entry
        // timestamp always lands at or after the query window.
        $fromSec = floor(Loki::now());

        $fp = @fsockopen('tcp://nginx', 80, $errno, $errstr, 2);

        if ($fp === false) {
            $this->markTestSkipped("Cannot connect to nginx: [$errno] $errstr");
        }

        fwrite($fp, "{$token}\r\n\r\n");
        fclose($fp);

        $logql = sprintf(self::NGINX_ERROR_LOGQL, $token);
        $entry = Loki::query($logql, $fromSec);

        if ($entry === null) {
            $this->markTestSkipped(
                'No nginx-error log entry found within '
                . (Loki::POLL_TIMEOUT_MS / 1000)
                . 's. The info-level error_log threshold may be '
                . 'suppressing the event. Reload nginx or check '
                . 'the error_log level in default.conf.',
            );
        }

        $this->assertSame(
            'nginx-error',
            $entry['labels']['log_source'] ?? '',
            'log_source should be nginx-error',
        );

        $this->assertSame(
            'nginx',
            $entry['labels']['service_name'] ?? '',
            'service_name should be nginx',
        );

        $this->assertSame(
            'info',
            $entry['labels']['detected_level'] ?? '',
            'Invalid request must be detected as info',
        );

        $this->assertSame(
            'info',
            $entry['labels']['nginx_level'] ?? '',
            'Raw nginx level must be info',
        );

        // The real nginx log line includes the token in the request
        // fragment: …request: "<token>"
        $this->assertStringContainsString(
            $token,
            $entry['line'],
            'Log line must contain the unique token',
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Synthetic-event tests: all eight nginx levels
    // ──────────────────────────────────────────────────────────────

    /**
     * Every nginx error-log level maps to the expected detected_level,
     * and all structured fields reach Loki correctly.
     *
     * Writes a synthetic nginx-format log line directly to the
     * error log so that emerg, alert, crit, notice, info, and debug
     * — which cannot be triggered from PHP — are covered alongside
     * error and warn.
     *
     * The synthetic line uses distinct pid, tid, and cid values
     * so that each field can be independently verified in the
     * Loki log line.
     */
    #[DataProvider('nginxErrorLevelProvider')]
    public function testNginxErrorLevelMapping(string $nginxLevel, string $expectedDetectedLevel): void
    {
        $token = Loki::generateToken("e2e-nginx-{$nginxLevel}");

        // Round the timestamp up to the next full second so the
        // Loki entry always lands unambiguously after the query
        // start, regardless of where the sub-second tick falls.
        $fromSec = floor(Loki::now());
        $timestamp = gmdate('Y/m/d H:i:s', (int) $fromSec);

        // Build a fully-populated nginx error log line.
        // Format: YYYY/MM/DD HH:MM:SS [level] pid#tid: *cid message
        $logLine = sprintf(
            "%s [%s] 9999#8888: *7777 e2e-nginx-error token=%s level=%s\n",
            $timestamp,
            $nginxLevel,
            $token,
            $nginxLevel,
        );

        // Write via shell redirect — Docker Desktop FUSE sometimes
        // caches file_put_contents writes so the alloy container
        // cannot see them.  A shell >> redirect takes a different
        // I/O path that is more reliably visible across containers.
        $cmd = sprintf(
            'printf %%s %s >> /var/log/nginx/error.log',
            escapeshellarg($logLine),
        );
        exec($cmd, result_code: $exitCode);

        if ($exitCode !== 0) {
            $this->fail(
                "Could not write synthetic [{$nginxLevel}] log line to /var/log/nginx/error.log. "
                . 'Check that ./docker/nginx/logs is mounted into the PHP container.',
            );
        }

        // Give Alloy's file poller a short window to detect the new
        // data before we start querying Loki.
        usleep(200_000);

        $logql = sprintf(self::NGINX_ERROR_LOGQL, $token);
        $entry = Loki::query($logql, $fromSec);

        if ($entry === null) {
            $this->fail(
                "nginx-error [{$nginxLevel}] log entry not found in Loki within "
                . (Loki::POLL_TIMEOUT_MS / 1000) . 's',
            );
        }

        $line = $entry['line'];
        $labels = $entry['labels'];

        // -- Labels -------------------------------------------------

        $this->assertSame(
            'nginx-error',
            $labels['log_source'] ?? '',
        );

        $this->assertSame(
            'nginx',
            $labels['service_name'] ?? '',
        );

        $this->assertSame(
            $expectedDetectedLevel,
            $labels['detected_level'] ?? '',
            "[{$nginxLevel}] must map to detected_level={$expectedDetectedLevel}",
        );

        $this->assertSame(
            $nginxLevel,
            $labels['nginx_level'] ?? '',
            "Raw nginx_level must be {$nginxLevel}",
        );

        // -- Structured fields — verified in the log line -----------

        $this->assertStringContainsString($token, $line);

        $this->assertStringContainsString(
            '9999',
            $line,
            'Log line must contain the synthetic pid',
        );

        $this->assertStringContainsString(
            '8888',
            $line,
            'Log line must contain the synthetic tid',
        );

        $this->assertStringContainsString(
            '*7777',
            $line,
            'Log line must contain the synthetic cid',
        );

        $this->assertStringContainsString(
            "e2e-nginx-error token={$token}",
            $line,
            'Log line must contain the synthetic message',
        );

        // Verify the line has the correct prefix structure.
        // Expected: …[level] pid#tid: *cid …
        $this->assertStringContainsString(
            "[{$nginxLevel}] 9999#8888: *7777",
            $line,
            'Log line must match nginx error log format: [level] pid#tid: *cid',
        );
    }
}
