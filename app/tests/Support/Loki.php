<?php

declare(strict_types=1);

namespace LeonV\Pnpvalg\Tests\Support;

/**
 * Static Loki query_range polling helpers for Alloy end-to-end tests.
 *
 * Provides poll-with-deadline fetching of log entries from Loki,
 * token generation, and the common constants used across all
 * service-specific test classes.
 */
final class Loki
{
    public const string LOKI_BASE = 'http://loki:3100';
    public const int POLL_TIMEOUT_MS = 5_000;
    public const int POLL_INTERVAL_MS = 250;

    /**
     * Return the current time as a float with microsecond precision.
     */
    public static function now(): float
    {
        return (float) microtime(true);
    }

    /**
     * Poll Loki for the first log entry matching the given LogQL query.
     *
     * @param float $fromSec Timestamp (microtime) captured before the log event.
     *
     * @return array{timestamp: string, line: string, labels: array}|null
     */
    public static function query(string $logql, float $fromSec): ?array
    {
        $deadline = self::now() + (self::POLL_TIMEOUT_MS / 1000);
        $t0 = self::now();

        while (self::now() < $deadline) {
            $entry = self::fetchEntry($logql, $fromSec);

            if ($entry !== null) {
                $elapsed = (self::now() - $t0) * 1000;
                fwrite(
                    STDERR,
                    sprintf("[Loki] matched in %.0f ms  |  %s\n", $elapsed, $logql),
                );

                return $entry;
            }

            usleep(self::POLL_INTERVAL_MS * 1000);
        }

        $elapsed = (self::now() - $t0) * 1000;
        fwrite(
            STDERR,
            sprintf("[Loki] timed out after %.0f ms  |  %s\n", $elapsed, $logql),
        );

        return null;
    }

    /**
     * Poll Loki for all log entries matching the given LogQL query.
     *
     * @param float $fromSec Timestamp (microtime) captured before the log event.
     *
     * @return list<array{timestamp: string, line: string, labels: array}>
     */
    public static function queryAll(string $logql, float $fromSec): array
    {
        $deadline = self::now() + (self::POLL_TIMEOUT_MS / 1000);
        $t0 = self::now();

        while (self::now() < $deadline) {
            $entries = self::fetchEntries($logql, $fromSec, 50);

            if ($entries !== []) {
                $elapsed = (self::now() - $t0) * 1000;
                fwrite(
                    STDERR,
                    sprintf("[Loki] queryAll matched %d in %.0f ms  |  %s\n", count($entries), $elapsed, $logql),
                );

                return $entries;
            }

            usleep(self::POLL_INTERVAL_MS * 1000);
        }

        $elapsed = (self::now() - $t0) * 1000;
        fwrite(
            STDERR,
            sprintf("[Loki] queryAll timed out after %.0f ms  |  %s\n", $elapsed, $logql),
        );

        return [];
    }

    /**
     * Single attempt to fetch the first matching log entry from Loki.
     *
     * @return array{timestamp: string, line: string, labels: array}|null
     */
    private static function fetchEntry(string $logql, float $fromSec): ?array
    {
        $data = self::queryRange($logql, $fromSec, 1);

        if ($data === null) {
            return null;
        }

        $results = $data['data']['result'] ?? [];

        $stream = array_find(
            $results,
            fn(array $s): bool => ($s['values'] ?? []) !== [],
        );

        if ($stream === null) {
            return null;
        }

        $values = $stream['values'];

        return [
            'timestamp' => $values[0][0],
            'line'      => $values[0][1],
            'labels'    => $stream['stream'] ?? [],
        ];
    }

    /**
     * Single attempt to fetch all matching log entries from Loki.
     *
     * @return list<array{timestamp: string, line: string, labels: array}>
     */
    private static function fetchEntries(string $logql, float $fromSec, int $limit): array
    {
        $data = self::queryRange($logql, $fromSec, $limit);

        if ($data === null) {
            return [];
        }

        $entries = [];
        $results = $data['data']['result'] ?? [];

        foreach ($results as $stream) {
            $labels = $stream['stream'] ?? [];

            foreach ($stream['values'] ?? [] as $value) {
                $entries[] = [
                    'timestamp' => $value[0],
                    'line'      => $value[1],
                    'labels'    => $labels,
                ];
            }
        }

        return $entries;
    }

    /**
     * Execute a Loki query_range HTTP request.
     *
     * @param float $fromSec Timestamp (microtime) marking the start of the query window.
     *
     * @return array|null The decoded JSON response body, or null on failure.
     */
    private static function queryRange(string $logql, float $fromSec, int $limit): ?array
    {
        $nowNs = (string) ((int) (self::now() * 1_000_000_000));
        $startNs = (string) ((int) ($fromSec * 1_000_000_000));

        $url = sprintf(
            '%s/loki/api/v1/query_range?query=%s&start=%s&end=%s&limit=%d&direction=backward',
            self::LOKI_BASE,
            urlencode($logql),
            $startNs,
            $nowNs,
            $limit,
        );

        $ch = curl_init($url);

        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!is_string($response)) {
            return null;
        }

        $data = json_decode($response, true);

        if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
            return null;
        }

        return $data;
    }

    public static function generateToken(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(8));
    }
}
