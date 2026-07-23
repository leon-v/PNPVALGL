<?php
declare(strict_types=1);

namespace LeonV\Pnpvalg\Tests;

use PHPUnit\Framework\TestCase;
use LeonV\Pnpvalg\Tests\Support\Http;
use LeonV\Pnpvalg\Tests\Support\Loki;

/**
 * End-to-end tests that validate PHP log events reach Loki via Alloy.
 *
 * Each test generates a unique token, emits a log event via syslog(),
 * polls Loki's query_range API for the token, and asserts the entry
 * arrived with the expected fields and nanosecond timestamp precision.
 *
 * Note: trigger_error() cannot be tested inside PHPUnit because
 * PHPUnit's error handler intercepts E_USER_* errors before they reach
 * PHP's default handler (which is what writes to syslog when
 * error_log=syslog). The tests use syslog() directly, which is the
 * same underlying code path.
 */
final class TestAlloyPhp extends TestCase
{

    private const string SYSLOG_LOGQL = '{log_source="syslog"} |= "%s"';

    /**
     * Fetch a URL via HTTP GET, returning the response body or false.
     */
    private function fetchUrl(string $url): string|false
    {
        $response = Http::request('GET', $url);

        return $response?->body ?? false;
    }

    public function testErrorLogReachesLoki(): void
    {
        $token = Loki::generateToken('e2e-errorlog');
        $fromSec = Loki::now();

        // syslog(LOG_NOTICE) uses the same php_syslog() code path as
        // trigger_error() with error_log=syslog. PHPUnit's error handler
        // prevents trigger_error() from reaching that path, so we call
        // syslog() directly.
        syslog(LOG_NOTICE, "E2E error_log: $token");

        $this->assertSyslogReachesLoki($token, $fromSec, 'error_log()');
    }

    public function testSyslogReachesLoki(): void
    {
        $token = Loki::generateToken('e2e-syslog');
        $fromSec = Loki::now();

        syslog(LOG_WARNING, "E2E syslog: $token");

        $this->assertSyslogReachesLoki($token, $fromSec, 'syslog()');
    }

    /**
     * Assert that a syslog entry reached Loki with correct labels.
     */
    private function assertSyslogReachesLoki(string $token, float $fromSec, string $label): void
    {
        $entry = Loki::query(sprintf(self::SYSLOG_LOGQL, $token), $fromSec);

        if ($entry === null) {
            $this->fail("{$label} log not found in Loki within " . (Loki::POLL_TIMEOUT_MS / 1000) . 's');
        }

        $this->assertStringContainsString(
            $token,
            $entry['line'],
            'Log line should contain the unique token',
        );

        $this->assertSame(
            'syslog',
            $entry['labels']['log_source'] ?? '',
            'log_source should be syslog',
        );

        $this->assertSame(
            'php',
            $entry['labels']['service_name'] ?? '',
            'service_name should be php',
        );

        // Verify the timestamp is a valid nanosecond epoch (19 digits).
        // TODO: This actually needs to validate any milliseconds or
        // smaller digits are not 0. We only require millisecond accuracy.
        $tsNs = (int) $entry['timestamp'];
        $this->assertGreaterThan(
            1_700_000_000_000_000_000,
            $tsNs,
            'Timestamp must be a plausible nanosecond epoch value',
        );
    }

    /**
     * All 11 log entries from emit-all-log-levels.php reach Loki with the
     * expected detected_level.
     *
     * Fetches http://nginx/test/fixtures/emit-all-log-levels.php?token=…, then queries
     * Loki for each expected log line — 7 syslog() levels plus 4
     * trigger_error() levels — and asserts the Loki-detected level
     * matches what the endpoint's JSON contract declares.
     */
    public function testAllLogLevelsReachLoki(): void
    {
        $token = (string) getenv('LOCAL_AUTH_KEY');

        $fromSec = Loki::now();
        $response = $this->fetchUrl('http://nginx/test/fixtures/emit-all-log-levels.php');

        if ($response === false) {
            $this->fail('Failed to fetch test/fixtures/emit-all-log-levels.php');
        }

        $contract = json_decode($response, true);
        $this->assertIsArray($contract, 'Response must be valid JSON');
        $this->assertArrayHasKey('entries', $contract, 'JSON must contain entries key');

        $expectedEntries = $contract['entries'];
        $this->assertCount(11, $expectedEntries, 'Contract must declare exactly 11 log entries');

        $lokiEntries = Loki::queryAll(
            sprintf('{log_source=~"syslog|error|fatal"} |= "%s"', $token),
            $fromSec,
        );
        $this->assertNotEmpty($lokiEntries, "No log entries found in Loki for token: $token");

        // PHP constant → expected Loki detected_level.
        $levelMap = [
            LOG_ALERT         => 'info',
            LOG_CRIT          => 'critical',
            LOG_ERR           => 'error',
            LOG_WARNING       => 'warn',
            LOG_NOTICE        => 'info',
            LOG_INFO          => 'info',
            LOG_DEBUG         => 'debug',
            E_USER_NOTICE     => 'info',
            E_USER_WARNING    => 'warn',
            E_USER_DEPRECATED => 'info',
            E_USER_ERROR      => 'error',
        ];

        // Index Loki lines by detected_level.
        $byLevel = [];
        foreach ($lokiEntries as $le) {
            $level = $le['labels']['detected_level'] ?? 'unknown';
            $byLevel[$level][] = $le['line'];
        }

        foreach ($expectedEntries as $expected) {
            $expectedPattern = $expected['pattern'];
            $expectedLevel = $levelMap[$expected['level']] ?? 'unknown';

            $candidates = $byLevel[$expectedLevel] ?? [];
            $found = array_any(
                $candidates,
                fn(string $line): bool => str_contains($line, $expectedPattern),
            );

            $this->assertTrue(
                $found,
                sprintf(
                    'Log entry not found in Loki.'
                    . '  Pattern: %s'
                    . '  PHP level: %s'
                    . '  Expected Loki level: %s'
                    . '  Source: %s'
                    . '  Hint: hit http://localhost:8080/test/fixtures/emit-all-log-levels.php?token=%s then re-run.',
                    $expectedPattern,
                    $expected['level'],
                    $expectedLevel,
                    $expected['source'],
                    $token,
                ),
            );
        }
    }

}
