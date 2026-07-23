<?php

/**
 * End-to-end oracle tests for Postgres logs forwarded through Alloy.
 *
 * Postgres writes jsonlog-format entries to /var/log/postgres/*.log.
 * Alloy tails these, parses the JSON, extracts error_severity as
 * pg_severity and detected_level labels, then forwards to Loki.
 *
 * Each test generates a unique token, triggers a specific Postgres
 * log event (connection, disconnection, DDL, slow query, error),
 * polls Loki's query_range API, and asserts the correct severity
 * labels arrived.
 */

declare(strict_types=1);

namespace LeonV\Pnpvalg\Tests;

use PHPUnit\Framework\TestCase;
use LeonV\Pnpvalg\Tests\Support\Loki;

final class TestAlloyPostgres extends TestCase
{

    private const string PG_LOGQL = '{log_source="postgres"} |= "%s"';
    private const string PG_LEVEL_TOKEN_LOGQL = '{log_source="postgres"} |= "pg-level-token: %s"';
    private const string PG_LEVEL_TEST_CONN = 'pg-level-test';

    // ──────────────────────────────────────────────────────────────────
    // Postgres connection helper
    // ──────────────────────────────────────────────────────────────────

    /**
     * Open a PDO connection to Postgres with the given application_name.
     *
     * All connection parameters are read from environment variables
     * with no fallbacks — missing values will cause a connection error.
     */
    private function getPdoConnection(string $applicationName): \PDO
    {
        $host = (string) getenv('DB_HOST');
        $port = (string) getenv('DB_PORT');
        $db   = (string) getenv('DB_DATABASE');
        $user = (string) getenv('DB_USERNAME');
        $pass = (string) getenv('DB_PASSWORD');

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;options=--application_name=%s',
            $host,
            $port,
            $db,
            $applicationName,
        );

        return new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_TIMEOUT => 3,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Connection log
    // ──────────────────────────────────────────────────────────────────

    /**
     * Connection log reaches Loki with pg_severity=LOG and detected_level=info.
     *
     * log_connections=on writes a LOG-level entry for every new session.
     * The application_name token appears in the JSON log line so the
     * line filter matches it.
     */
    public function testPostgresConnectionLogReachesLoki(): void
    {
        $token = Loki::generateToken('e2e-pg-conn');
        $fromSec = Loki::now();

        try {
            $pdo = $this->getPdoConnection($token);
            $pdo->exec('SELECT 1');
            unset($pdo);
        } catch (\PDOException $e) {
            $this->fail("Postgres connection failed: {$e->getMessage()}");
        }

        $logql = sprintf(self::PG_LOGQL, $token);
        $entry = Loki::query($logql, $fromSec);

        if ($entry === null) {
            $this->fail(
                'Postgres connection log entry not found in Loki within '
                . (Loki::POLL_TIMEOUT_MS / 1000) . 's',
            );
        }

        $this->assertStringContainsString(
            $token,
            $entry['line'],
            'Log line should contain the unique application_name token',
        );

        $this->assertSame(
            'postgres',
            $entry['labels']['log_source'] ?? '',
            'log_source should be postgres',
        );

        $this->assertSame(
            'postgres',
            $entry['labels']['service_name'] ?? '',
            'service_name should be postgres',
        );

        $this->assertSame(
            'LOG',
            $entry['labels']['pg_severity'] ?? '',
            'pg_severity should be LOG for connection events',
        );

        $this->assertSame(
            'info',
            $entry['labels']['detected_level'] ?? '',
            'detected_level should be info for LOG-severity events',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Disconnection log
    // ──────────────────────────────────────────────────────────────────

    /**
     * Disconnection log reaches Loki with pg_severity=LOG.
     *
     * log_disconnections=on writes a LOG-level entry when a session
     * ends. The application_name appears as a top-level key in the
     * JSON log line, so the line filter matches.
     */
    public function testPostgresDisconnectionLogReachesLoki(): void
    {
        $token = Loki::generateToken('e2e-pg-disc');
        $fromSec = Loki::now();

        try {
            $pdo = $this->getPdoConnection($token);
            $pdo->exec('SELECT 1');
            unset($pdo);
            // Force PDO destructor to run so the backend session closes.
        } catch (\PDOException $e) {
            $this->fail("Postgres connection failed: {$e->getMessage()}");
        }

        $logql = sprintf(
            '{log_source="postgres"} |= "disconnection" |= "%s"',
            $token,
        );
        $entry = Loki::query($logql, $fromSec);

        if ($entry === null) {
            $this->fail(
                'Postgres disconnection log entry not found in Loki within '
                . (Loki::POLL_TIMEOUT_MS / 1000) . 's',
            );
        }

        $this->assertStringContainsString(
            'disconnection',
            $entry['line'],
            'Log line should mention disconnection',
        );

        $this->assertSame(
            'postgres',
            $entry['labels']['log_source'] ?? '',
        );

        $this->assertSame(
            'LOG',
            $entry['labels']['pg_severity'] ?? '',
            'pg_severity should be LOG for disconnection events',
        );

        $this->assertSame(
            'info',
            $entry['labels']['detected_level'] ?? '',
            'detected_level should be info for LOG-severity events',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // DDL statement log
    // ──────────────────────────────────────────────────────────────────

    /**
     * DDL statement log reaches Loki with pg_severity=LOG.
     *
     * log_statement='ddl' writes a LOG-level entry containing the full
     * DDL statement text. The table name appears in the logged
     * statement so it can be matched by the line filter.
     */
    public function testPostgresDdlLogReachesLoki(): void
    {
        $token = Loki::generateToken('e2e-pg-ddl');
        $tableName = 'e2e_ddl_' . str_replace('-', '_', $token);
        $fromSec = Loki::now();

        try {
            $pdo = $this->getPdoConnection("ddl-{$token}");
            $pdo->exec("CREATE TABLE IF NOT EXISTS {$tableName} (id INTEGER)");
            $pdo->exec("DROP TABLE IF EXISTS {$tableName}");
            unset($pdo);
        } catch (\PDOException $e) {
            $this->fail("DDL statement failed: {$e->getMessage()}");
        }

        $logql = sprintf(
            '{log_source="postgres"} |= "statement:" |= "%s"',
            $tableName,
        );
        $entry = Loki::query($logql, $fromSec);

        if ($entry === null) {
            $this->fail(
                'DDL statement log entry not found in Loki within '
                . (Loki::POLL_TIMEOUT_MS / 1000) . 's',
            );
        }

        $this->assertStringContainsString(
            $tableName,
            $entry['line'],
            'Log line should contain the table name from the DDL statement',
        );

        $this->assertSame(
            'LOG',
            $entry['labels']['pg_severity'] ?? '',
            'pg_severity should be LOG for DDL statements',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Slow query log
    // ──────────────────────────────────────────────────────────────────

    /**
     * Slow query log reaches Loki with pg_severity=LOG.
     *
     * log_min_duration_statement=1000 logs any statement that takes
     * ≥1 s. pg_sleep(2) guarantees the threshold is exceeded.
     * The token is embedded in a column alias so it appears in the
     * logged statement.
     */
    public function testPostgresSlowQueryLogReachesLoki(): void
    {
        $token = Loki::generateToken('e2e-pg-slow');
        $alias = 'e2e_slow_' . str_replace('-', '_', $token);
        $fromSec = Loki::now();

        try {
            $pdo = $this->getPdoConnection("slow-{$token}");
            $pdo->exec("SELECT pg_sleep(2) AS \"{$alias}\"");
            unset($pdo);
        } catch (\PDOException $e) {
            $this->fail("Slow query failed: {$e->getMessage()}");
        }

        $logql = sprintf(
            '{log_source="postgres"} |= "duration:" |= "%s"',
            $alias,
        );
        $entry = Loki::query($logql, $fromSec);

        if ($entry === null) {
            $this->fail(
                'Slow query log entry not found in Loki within '
                . (Loki::POLL_TIMEOUT_MS / 1000) . 's',
            );
        }

        $this->assertStringContainsString(
            'duration:',
            $entry['line'],
            'Log line should contain a duration measurement',
        );

        $this->assertStringContainsString(
            $alias,
            $entry['line'],
            'Log line should contain the unique column alias',
        );

        $this->assertSame(
            'LOG',
            $entry['labels']['pg_severity'] ?? '',
            'pg_severity should be LOG for slow query statements',
        );

        $this->assertSame(
            'info',
            $entry['labels']['detected_level'] ?? '',
            'detected_level should be info for LOG-severity events',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // SQL error log
    // ──────────────────────────────────────────────────────────────────

    /**
     * SQL error log reaches Loki with pg_severity=ERROR and detected_level=error.
     *
     * A relation-not-found error produces an ERROR-level entry.
     * The token is used as a deliberately non-existent table name so
     * it appears in the error message.
     */
    public function testPostgresErrorLogReachesLoki(): void
    {
        $token = Loki::generateToken('e2e-pg-err');
        $badTable = 'nonexistent_' . str_replace('-', '_', $token);
        $fromSec = Loki::now();

        try {
            $pdo = $this->getPdoConnection("err-{$token}");
            // Suppress the PDO exception so we can query Loki for the
            // Postgres-side error log.
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
            $pdo->exec("SELECT 1 FROM {$badTable}");
            unset($pdo);
        } catch (\PDOException $e) {
            // ERRORMODE_SILENT prevents this path, but guard anyway.
        }

        $logql = sprintf(self::PG_LOGQL, $badTable);
        $entry = Loki::query($logql, $fromSec);

        if ($entry === null) {
            $this->fail(
                'Postgres error log entry not found in Loki within '
                . (Loki::POLL_TIMEOUT_MS / 1000) . 's',
            );
        }

        $this->assertStringContainsString(
            $badTable,
            $entry['line'],
            'Log line should contain the non-existent table name',
        );

        $this->assertSame(
            'ERROR',
            $entry['labels']['pg_severity'] ?? '',
            'pg_severity should be ERROR for SQL errors',
        );

        $this->assertSame(
            'error',
            $entry['labels']['detected_level'] ?? '',
            'detected_level should be error for ERROR-severity events',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // RAISE severity levels
    // ──────────────────────────────────────────────────────────────────

    /**
     * RAISE WARNING reaches Loki — log_min_messages defaults to WARNING.
     */
    public function testPostgresWarningLogReachesLoki(): void
    {
        $this->assertPostgresRaiseLevelReachesLoki('e2e-pg-warn', 'WARNING', 'warn');
    }

    /**
     * RAISE NOTICE reaches Loki — requires SET log_min_messages below default.
     */
    public function testPostgresNoticeLogReachesLoki(): void
    {
        $this->assertPostgresRaiseLevelReachesLoki('e2e-pg-notice', 'NOTICE', 'info', 'NOTICE');
    }

    /**
     * RAISE INFO reaches Loki.
     */
    public function testPostgresInfoLogReachesLoki(): void
    {
        $this->assertPostgresRaiseLevelReachesLoki('e2e-pg-info', 'INFO', 'info', 'INFO');
    }

    /**
     * RAISE DEBUG reaches Loki.
     */
    public function testPostgresDebugLogReachesLoki(): void
    {
        $this->assertPostgresRaiseLevelReachesLoki('e2e-pg-debug', 'DEBUG', 'debug', 'DEBUG');
    }

    /**
     * Assert that a RAISE severity-level log entry reached Loki.
     *
     * @param string      $tokenPrefix   e.g. 'e2e-pg-warn'
     * @param string      $pgSeverity    Postgres severity: WARNING, NOTICE, INFO, DEBUG
     * @param string      $detectedLevel Expected Loki detected_level: warn, info, debug
     * @param string|null $logMinMessages SET log_min_messages override (null = use default)
     */
    private function assertPostgresRaiseLevelReachesLoki(
        string $tokenPrefix,
        string $pgSeverity,
        string $detectedLevel,
        ?string $logMinMessages = null,
    ): void {
        $token = Loki::generateToken($tokenPrefix);
        $fromSec = Loki::now();

        try {
            $pdo = $this->getPdoConnection(self::PG_LEVEL_TEST_CONN);

            if ($logMinMessages !== null) {
                $pdo->exec("SET log_min_messages = '{$logMinMessages}'");
            }

            $pdo->exec(
                "DO \$\$ BEGIN RAISE {$pgSeverity} 'pg-level-token: {$token}'; END \$\$",
            );
            unset($pdo);
        } catch (\PDOException $e) {
            $this->fail("RAISE {$pgSeverity} failed: {$e->getMessage()}");
        }

        $logql = sprintf(self::PG_LEVEL_TOKEN_LOGQL, $token);
        $entry = Loki::query($logql, $fromSec);

        if ($entry === null) {
            $this->fail(
                "{$pgSeverity} log entry not found in Loki within "
                . (Loki::POLL_TIMEOUT_MS / 1000) . 's',
            );
        }

        $this->assertSame(
            $pgSeverity,
            $entry['labels']['pg_severity'] ?? '',
            "pg_severity should be {$pgSeverity}",
        );

        $this->assertSame(
            $detectedLevel,
            $entry['labels']['detected_level'] ?? '',
            "detected_level should be {$detectedLevel} for {$pgSeverity}-severity events",
        );
    }
}
