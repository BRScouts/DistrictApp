# PHP Coding Standards

Conventions every PHP file in this project follows. Match these when writing or editing code so the codebase stays consistent.

## File structure

- Start every PHP file with `<?php` then `declare(strict_types=1);` on the next line.
- Page/handler scripts (under `dc/`, `auth/`, `cron/`, the root `*.php`) pull shared setup via `require_once`, using `__DIR__`-relative paths:
  ```php
  require_once __DIR__ . '/../app/bootstrap.php';
  ```
  `bootstrap.php` wires up config, DB, auth, CSRF, security headers, and audit logging. Don't re-require those pieces individually from a page.
- Library files under `app/` define functions only — no side-effecting output at include time (the exception is `security-headers.php`, which sends headers on include by design).

## Functions

- Use `snake_case` for function names, with a prefix that signals the area:
  - `app_` — framework-level helpers in `bootstrap.php` (`app_config`, `app_url`, `e`, `redirect`)
  - `dc_` — district-coordinator app helpers (`dc_require_admin`, `dc_log`, `dc_get_event`, `dc_table_has_column`)
  - `dce_` — page-local helpers scoped to one screen (e.g. district-event form helpers)
  - `csrf_`, `audit_`, `csp_` — the matching subsystem
- Wrap globally-defined helpers in `if (!function_exists('name'))` when they live in files that could be included more than once (as `bootstrap.php` does).
- Add a short docblock above non-trivial functions describing intent, not mechanics. Document params/return when the signature isn't obvious.
- Use strict, explicit types in signatures: `?string`, `int`, `: void`, nullable params with defaults.

## Types and values

- Cast deliberately at boundaries: `(int) ($_GET['id'] ?? 0)`, `(string) ($_POST['title'] ?? '')`, `(float) $lat`.
- `trim()` text input before validating. Convert empty strings to `null` before storing nullable columns.
- Prefer `DateTimeImmutable` over `DateTime`. Parse with `createFromFormat` and fall back defensively, returning `null` on failure.

## Output

- Escape every dynamic value rendered into HTML with the `e()` helper (`htmlspecialchars` with `ENT_QUOTES`, UTF-8). Never echo user-derived data raw.
- Keep PHP-in-HTML templating in the alt syntax (`<?php if (...): ?> ... <?php endif; ?>`, `foreach (...):`) for the view portion of page scripts, as existing pages do.

## Error handling

- Catch `Throwable` (not just `Exception`) around DB and I/O work.
- For user-facing failures, append a readable message to an `$errors[]` array and re-render the form with an error summary. Don't let raw exceptions surface to users.
- For background/best-effort work (audit writes, optional cleanups), swallow the error and `error_log()` it rather than breaking the main flow.

## Redirects

- Always redirect via the `redirect()` helper, which blocks open redirects (only same-site paths starting with a single `/`). Never call `header('Location: ...')` directly in page code.

## Layout / views

- Page scripts set `$pageTitle`, `$heroTitle`, `$heroText`, `$active`, then `require __DIR__ . '/layout.php';` before emitting page body markup.
