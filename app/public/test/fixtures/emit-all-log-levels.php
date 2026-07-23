<?php

// emit-all-log-levels.php — generates all 11 log levels with a shared
// token, then returns JSON declaring exactly what was sent. No level
// shows as "unknown" in Loki — the test fails if any does.
//
// Usage: GET /emit-all-log-levels.php?token=<shared-key>&auth=<env-key>

require __DIR__ . '/FixtureHelpers.php';

$token = FixtureHelpers::requireAuth();

$pid = getmypid();
$entries = [];

$logSuffix   = "(token=$token pid=$pid)";
$tokenPrefix = "(token=$token";

register_shutdown_function(function () use (&$entries, $token): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header_remove();
    http_response_code(200);
    header('Content-Type: application/json');

    echo json_encode(
        ['token' => $token, 'entries' => $entries],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    );
});

/**
 * Send a syslog message and record the entry for the JSON contract.
 *
 * @param array  &$entries      Accumulator for the shutdown payload.
 * @param int    $syslogLevel   syslog() priority constant.
 * @param int    $level         Numeric severity for the JSON report.
 * @param string $messagePrefix Human-readable log prefix.
 */
function sendSyslog(
    array &$entries,
    int $syslogLevel,
    int $level,
    string $messagePrefix,
): void {
    global $logSuffix, $tokenPrefix;

    syslog($syslogLevel, "$messagePrefix $logSuffix");

    $entries[] = [
        'source'  => 'syslog',
        'level'   => $level,
        'pattern' => "$messagePrefix $tokenPrefix",
    ];
}

/**
 * Trigger a PHP user-level error and record the entry.
 *
 * Recording happens before trigger_error() so the entry is captured
 * even when E_USER_ERROR terminates the script. PHP's default error
 * handler (error_log=syslog in zz-error-logging.ini) writes to syslog
 * — no custom handler needed.
 *
 * @param array  &$entries      Accumulator for the shutdown payload.
 * @param string $messagePrefix Human-readable log prefix.
 * @param int    $errorLevel    E_USER_* constant.
 */
function sendTriggerError(
    array &$entries,
    string $messagePrefix,
    int $errorLevel,
): void {
    global $tokenPrefix, $logSuffix;

    $entries[] = [
        'source'  => 'trigger_error',
        'level'   => $errorLevel,
        'pattern' => "$messagePrefix $tokenPrefix",
    ];

    trigger_error("$messagePrefix $logSuffix", $errorLevel);
}

// ── syslog() — 7 levels ────────────────────────────────────────────

sendSyslog($entries, LOG_ALERT,   LOG_ALERT,   'TEST ALERT:   Disk at 98% on /dev/sda1');
sendSyslog($entries, LOG_CRIT,    LOG_CRIT,    'TEST CRIT:    API key validation circuit open');
sendSyslog($entries, LOG_ERR,     LOG_ERR,     'TEST ERR:     Payment gateway returned 503');
sendSyslog($entries, LOG_WARNING, LOG_WARNING, 'TEST WARNING: Cache hit ratio dropped below 50%');
sendSyslog($entries, LOG_NOTICE,  LOG_NOTICE,  'TEST NOTICE:  Daily report generation starting');
sendSyslog($entries, LOG_INFO,    LOG_INFO,    'TEST INFO:    Request GET /emit-all-log-levels.php');
sendSyslog($entries, LOG_DEBUG,   LOG_DEBUG,   'TEST DEBUG:   SQL query took 12.3ms: SELECT * FROM orders');

// ── trigger_error() — 4 levels ─────────────────────────────────────

sendTriggerError($entries, 'TEST User notice',     E_USER_NOTICE);
sendTriggerError($entries, 'TEST User warning',    E_USER_WARNING);
sendTriggerError($entries, 'TEST User deprecated', E_USER_DEPRECATED);
sendTriggerError($entries, 'TEST User fatal',      E_USER_ERROR);