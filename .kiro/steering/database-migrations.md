# Database & Migrations

How schema changes and data access work in this project.

## Migrations

- All schema changes live as numbered SQL files in `database/migrations/`, e.g. `004_district_events.sql`. The next migration takes the next number in sequence (currently `006_`).
- Name the file `NNN_short_description.sql` with a zero-padded three-digit prefix.
- Start every migration with a comment block: what it does, and "Design notes" explaining any non-obvious choices (why a column, why a seed row, trade-offs considered).
- Make migrations **idempotent and re-runnable** where practical:
  - Seed rows with `INSERT ... SELECT ... WHERE NOT EXISTS (...)` rather than a bare `INSERT`.
  - For settings, do insert-if-missing followed by an `UPDATE`, so you don't depend on a UNIQUE key existing.
- Prefer **additive** changes (new nullable columns, new indexes). The application reads new columns defensively, so older code keeps working mid-deploy.
- Add an index when a new column will be filtered or sorted on in a query (as `004` did with `idx_calendar_events_district`).

## Defensive schema access

- Before using a column that a migration adds, guard it with `dc_table_has_column('table', 'column')` and degrade gracefully if it's absent — build the SQL and params conditionally. This lets the app run before its migration has been applied and surfaces a clear "run migration NNN" message instead of a fatal error.
- When a required piece of schema/seed data is missing, add a readable message to `$errors[]` telling the user which migration to run.

## Queries and transactions

- Get the PDO handle via `db()`. Always use prepared statements with bound parameters (see the security steering).
- Wrap any multi-statement write in a transaction:
  ```php
  $pdo = db();
  $pdo->beginTransaction();
  try {
      // ... statements ...
      $pdo->commit();
  } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
          $pdo->rollBack();
      }
      // handle / record error
  }
  ```
- Use `NOW()` for server-side timestamps and `lastInsertId()` after inserts.
- Scope updates/deletes with the ownership/flag conditions that protect the row (e.g. `AND is_district_event = 1`), and add `LIMIT 1` to single-row mutations.
