<?php

/**
 * End-to-end oracle tests for Nginx access logs forwarded by Alloy to Loki.
 *
 * Covers all HTTP response classes (2xx, 3xx, 4xx, 5xx) via the
 * json_access log. Uses test/fixtures/nginx-log-test.php to trigger
 * configurable status codes.
 *
 * Each test generates a unique token, triggers a log event, polls
 * Loki's query_range API, and asserts the correct log_source,
 * service_name, and detected_level labels.
 */

declare(strict_types=1);

namespace LeonV\PnpvalgL\Tests;

use PHPUnit\Framework\TestCase;
use LeonV\PnpvalgL\Tests\Support\Http;
use LeonV\PnpvalgL\Tests\Support\Loki;

final class TestAlloyNginxAccess extends TestCase
{

    private const string NGINX_BASE = 'http://nginx';
    private const string NGINX_ACCESS_LOGQL = '{log_source="nginx-access"} |= "%s"';

    // ──────────────────────────────────────────────────────────────────
    // Nginx access log — 2xx
    // ──────────────────────────────────────────────────────────────────

    public function testNginxAccessLog2xxReachesLoki(): void
    {
        $token = (string) getenv('LOCAL_AUTH_KEY');
        $fromSec = Loki::now();

        Http::request(
            'GET',
            self::NGINX_BASE . '/test/fixtures/nginx-log-test.php',
            query: ['status' => '200'],
        );

        $this->assertNginxAccessLogReachesLoki($token, $fromSec, 'info', '2xx');
    }

    // ──────────────────────────────────────────────────────────────────
    // Nginx access log — 3xx
    // ──────────────────────────────────────────────────────────────────

    public function testNginxAccessLog3xxReachesLoki(): void
    {
        $token = (string) getenv('LOCAL_AUTH_KEY');
        $fromSec = Loki::now();

        // test/fixtures/nginx-log-test.php?status=301 sends a Location redirect.
        // The access log records the 301 before the client follows it.
        // cURL does not follow redirects by default.
        Http::request(
            'GET',
            self::NGINX_BASE . '/test/fixtures/nginx-log-test.php',
            query: ['status' => '301'],
        );

        $this->assertNginxAccessLogReachesLoki($token, $fromSec, 'info', '3xx');
    }

    // ──────────────────────────────────────────────────────────────────
    // Nginx access log — 4xx
    // ──────────────────────────────────────────────────────────────────

    public function testNginxAccessLog4xxReachesLoki(): void
    {
        $token = (string) getenv('LOCAL_AUTH_KEY');
        $fromSec = Loki::now();

        Http::request(
            'GET',
            self::NGINX_BASE . '/test/fixtures/nginx-log-test.php',
            query: ['status' => '404'],
        );

        $this->assertNginxAccessLogReachesLoki($token, $fromSec, 'warning', '4xx');
    }

    // ──────────────────────────────────────────────────────────────────
    // Nginx access log — 5xx
    // ──────────────────────────────────────────────────────────────────

    public function testNginxAccessLog5xxReachesLoki(): void
    {
        $token = (string) getenv('LOCAL_AUTH_KEY');
        $fromSec = Loki::now();

        Http::request(
            'GET',
            self::NGINX_BASE . '/test/fixtures/nginx-log-test.php',
            query: ['status' => '500'],
        );

        $this->assertNginxAccessLogReachesLoki($token, $fromSec, 'error', '5xx');
    }

    /**
     * Assert that an nginx access log entry reached Loki with correct labels.
     */
    private function assertNginxAccessLogReachesLoki(
        string $token,
        float $fromSec,
        string $expectedLevel,
        string $statusClass,
    ): void {
        $entry = Loki::query(
            sprintf(self::NGINX_ACCESS_LOGQL, $token),
            $fromSec,
        );

        $this->assertNotNull(
            $entry,
            "nginx-access {$statusClass} log entry not found in Loki within "
            . (Loki::POLL_TIMEOUT_MS / 1000) . 's',
        );

        $line = $entry['line'] ?? '';
        $this->assertStringContainsString($token, $line);
        $this->assertSame('nginx-access', $entry['labels']['log_source'] ?? '');
        $this->assertSame('nginx', $entry['labels']['service_name'] ?? '');
        $this->assertSame(
            $expectedLevel,
            $entry['labels']['detected_level'] ?? '',
            "{$statusClass} responses must have detected_level={$expectedLevel}",
        );
    }
}
