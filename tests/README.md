# Tests

PHPUnit-based tests for the District App.

## Running

Install dev dependencies once, then run the suite:

```bash
composer install
composer test
# or directly:
vendor/bin/phpunit --testdox
```

## Database backend

The migration example tests (`tests/Migration/HelpArticlesMigrationTest.php`)
apply the **real** migration file `database/migrations/005_help_articles.sql`
and assert its behaviour.

They support two backends:

- **SQLite in-memory (default).** No server or credentials required — the tests
  run anywhere PHP is built with `pdo_sqlite`. The MySQL-dialect DDL is
  translated to an equivalent SQLite schema (same columns, the `slug` UNIQUE
  constraint, a `status` CHECK mirroring the ENUM), and the
  `... FROM DUAL WHERE NOT EXISTS (...)` idempotent-insert idiom is rewritten to
  SQLite's portable `... WHERE NOT EXISTS (...)` form. The slug guards and every
  seeded value are preserved, so the idempotence and seed-content behaviour
  under test is identical to production.

- **MySQL / MariaDB (opt-in, highest fidelity).** Point the tests at a disposable
  MySQL/MariaDB **test** database to run the migration SQL unchanged:

  ```bash
  HELP_TEST_MYSQL_DSN="mysql:host=127.0.0.1;dbname=district_test;charset=utf8mb4" \
  HELP_TEST_MYSQL_USER="test" \
  HELP_TEST_MYSQL_PASS="test" \
  vendor/bin/phpunit --testdox
  ```

  Prerequisites for the MySQL run:
  - A reachable MySQL/MariaDB server with the `JSON` type (MySQL 5.7+/MariaDB 10.2+).
  - An **empty, disposable** schema named in the DSN — the tests create the
    `help_articles` table and seed into it. Do not point this at a real database.

## What `HelpArticlesMigrationTest` covers

| Test | Requirement |
| --- | --- |
| Applying the migration twice keeps the seed count stable (no duplicates, no error) | 1.13 |
| No slug is seeded more than once | 1.13 |
| A duplicate-slug insert is rejected by the unique constraint | 1.3, 1.13 |
| All 14 expected articles exist, published, under their expected topics | 5.9 |
| Each article Rich_Body links to its expected action target(s) (external targets flagged) | 5.4, 5.5, 5.9 |
