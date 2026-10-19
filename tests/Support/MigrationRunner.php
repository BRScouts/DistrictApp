<?php

declare(strict_types=1);

namespace DistrictApp\Tests\Support;

use PDO;
use RuntimeException;

/**
 * Applies a real migration .sql file against a PDO connection for testing.
 *
 * The help-centre migration (005_help_articles.sql) is authored in MySQL /
 * MariaDB dialect — the same database the application runs on. These example
 * tests apply that exact file so the test exercises the real seed content and
 * the real slug-guarded, idempotent INSERT statements rather than a hand-copied
 * fixture.
 *
 * Two backends are supported:
 *
 *   - MySQL / MariaDB (opt-in): when the HELP_TEST_MYSQL_* environment is set,
 *     the raw migration SQL is executed unchanged against a real test database.
 *     This is the highest-fidelity run and matches production exactly.
 *
 *   - SQLite in-memory (default): so the tests run anywhere PHP + pdo_sqlite is
 *     available with no server or credentials. The MySQL-specific DDL is
 *     translated to equivalent SQLite DDL, and the `... FROM DUAL WHERE NOT
 *     EXISTS (...)` idempotent-insert idiom is rewritten to the SQLite-portable
 *     `... WHERE NOT EXISTS (...)` form. The slug UNIQUE constraint, the
 *     NOT-EXISTS guards and every seeded value are preserved, so the
 *     idempotence and seed-content behaviour under test is identical.
 */
final class MigrationRunner
{
    public const DRIVER_SQLITE = 'sqlite';
    public const DRIVER_MYSQL = 'mysql';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $driver
    ) {
    }

    public static function migrationPath(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations/005_help_articles.sql';
    }

    /**
     * Build a connection for the configured backend.
     *
     * Set HELP_TEST_MYSQL_DSN (and optionally HELP_TEST_MYSQL_USER /
     * HELP_TEST_MYSQL_PASS) to run against a real MySQL/MariaDB test database;
     * otherwise an isolated in-memory SQLite database is used.
     */
    public static function connect(): self
    {
        $dsn = getenv('HELP_TEST_MYSQL_DSN');

        if (is_string($dsn) && $dsn !== '') {
            $user = getenv('HELP_TEST_MYSQL_USER') ?: null;
            $pass = getenv('HELP_TEST_MYSQL_PASS') ?: null;

            $pdo = new PDO($dsn, $user === false ? null : $user, $pass === false ? null : $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            return new self($pdo, self::DRIVER_MYSQL);
        }

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Enforce UNIQUE / NOT NULL etc. the same way the app's server does.
        $pdo->exec('PRAGMA foreign_keys = ON');

        return new self($pdo, self::DRIVER_SQLITE);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * Apply the real migration file once. Running it again is expected to be a
     * no-op on the seed rows (idempotence is exactly what the tests assert).
     */
    public function apply(): void
    {
        $sql = file_get_contents(self::migrationPath());

        if ($sql === false) {
            throw new RuntimeException('Could not read migration: ' . self::migrationPath());
        }

        foreach ($this->statements($sql) as $statement) {
            $translated = $this->driver === self::DRIVER_SQLITE
                ? $this->translateForSqlite($statement)
                : $statement;

            $this->pdo->exec($translated);
        }
    }

    /**
     * Strip line comments and split the migration into individual statements.
     *
     * The migration terminates every statement with a semicolon and the JSON
     * bodies contain no semicolons, so splitting on ';' after removing '--'
     * comment lines is safe for this file.
     *
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            // Drop whole-line SQL comments (the migration only uses line comments).
            if (preg_match('/^\s*--/', $line)) {
                continue;
            }
            $kept[] = $line;
        }

        $clean = implode("\n", $kept);

        $statements = [];
        foreach (explode(';', $clean) as $chunk) {
            $trimmed = trim($chunk);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
        }

        return $statements;
    }

    /**
     * Rewrite a single MySQL statement into an equivalent SQLite statement,
     * preserving the UNIQUE slug constraint, column semantics and the
     * NOT-EXISTS idempotence guards.
     */
    private function translateForSqlite(string $statement): string
    {
        $upper = ltrim($statement);

        if (stripos($upper, 'CREATE TABLE') === 0) {
            return $this->translateCreateTableForSqlite($statement);
        }

        if (stripos($upper, 'INSERT') === 0) {
            // `SELECT ... FROM DUAL WHERE NOT EXISTS (...)` -> SQLite drops DUAL.
            return preg_replace('/\bFROM\s+DUAL\b/i', '', $statement) ?? $statement;
        }

        return $statement;
    }

    private function translateCreateTableForSqlite(string $statement): string
    {
        // A faithful SQLite equivalent of the help_articles schema: same column
        // names, the slug UNIQUE constraint (what idempotence relies on), the
        // status CHECK mirroring the ENUM, and timestamp defaults.
        return <<<SQL
            CREATE TABLE IF NOT EXISTS help_articles (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                slug        TEXT    NOT NULL,
                title       TEXT    NOT NULL,
                summary     TEXT    NOT NULL DEFAULT '',
                keywords    TEXT    NOT NULL DEFAULT '',
                body        TEXT    NOT NULL,
                topic       TEXT    NOT NULL DEFAULT '',
                status      TEXT    NOT NULL DEFAULT 'draft'
                                    CHECK (status IN ('draft','published')),
                sort_order  INTEGER NOT NULL DEFAULT 0,
                created_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (slug)
            )
            SQL;
    }
}
