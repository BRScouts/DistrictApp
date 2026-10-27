# Security Practices

This app holds personal data for a Scouts district (leaders, volunteers, young people's events). Security is not optional. Apply every rule below by default; never relax one for convenience without calling it out.

## SQL

- Use PDO prepared statements for every query. Pass values as bound parameters — named (`:id`) or positional. Never interpolate user input into SQL strings.
- When a query's shape varies (optional columns), build the statement from a whitelist of known-safe column fragments and still bind all values, as `dc/add-district-event.php` does. Never concatenate raw input into the SQL text.
- Bind typed values with `bindValue(..., PDO::PARAM_INT)` where the driver needs the hint (e.g. `LIMIT`).

## CSRF

- Every state-changing request is a POST and must call `csrf_validate()` at the top of its handler, before doing any work.
- Every `<form method="post">` must include `<?= csrf_field() ?>`.
- `csrf_validate()` uses `hash_equals`, returns 403 on mismatch, and rotates the token after success. Don't bypass or reimplement it.
- Never perform a mutation on a GET request.

## Content Security Policy / inline scripts

- Security headers (including a nonce-based CSP) are sent from `app/security-headers.php` via bootstrap. Don't send conflicting headers elsewhere.
- Every inline `<script>` must carry the per-request nonce: `<script <?= csp_nonce_attr() ?>>`. Scripts without the nonce will be blocked by CSP.
- External scripts/styles must come from origins already allow-listed in the CSP (currently `cdn.jsdelivr.net`, Google Fonts). Adding a new external origin means updating the CSP in `security-headers.php` — do it deliberately, not by loosening the policy to `*`.

## Redirects

- Only redirect through the `redirect()` helper. It rejects absolute URLs and protocol-relative (`//`) paths to prevent open-redirect abuse.

## Secrets

- Secrets live in environment config (`.env` via phpdotenv) and `config.php`, read through `app_config()`. Never hard-code credentials, client secrets, or tokens in application code.
- Never commit `.env`, `config.php` with real values, or any credential/token file. Flag them before staging.
- Don't echo secret values into pages, logs, or error messages.

## Access control

- Gate admin screens with the relevant guard (e.g. `dc_require_admin()`) at the top of the script, before any output or processing.
- Scope mutations to the records the actor is allowed to touch (e.g. district event updates include `AND is_district_event = 1` in the WHERE clause). Don't trust an ID alone.

## Input handling

- Treat everything in `$_GET`, `$_POST`, `$_SERVER`, and uploaded files as untrusted. Validate and normalise before use.
- Validate URLs with `filter_var(..., FILTER_VALIDATE_URL)` and force a scheme; validate numerics with `is_numeric` before casting.
- Cap stored free-text lengths and store structured data (e.g. audit details) as JSON via `json_encode`, not concatenated strings.
