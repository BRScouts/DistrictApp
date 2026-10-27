# Audit & Logging Conventions

The project has two logging paths. Use the right one, and never let logging break a user workflow.

## audit_log() — the system of record

- Defined in `app/audit.php`. Use it for every security-relevant or administrative action: auth, user/person changes, group changes, event lifecycle, risk assessments, comms, admin settings, cron runs, GDPR.
- Always log through an `AUDIT_*` constant, never a bare string:
  ```php
  audit_log(
      AUDIT_EVENT_CREATED,     // event code constant
      'calendar_event',        // entity type
      $eventId,                // entity id
      $targetPersonId,         // person acted upon (null if n/a)
      ['title' => $title],     // details (stored as JSON)
      $groupId                 // group context (null if n/a)
  );
  ```
- Guard the call with `if (defined('AUDIT_...'))` when the surrounding code may run against an older build that predates the constant, matching the existing pages.
- Adding a new audited action means **both**: add the `AUDIT_*` constant and register it in `audit_event_types()` with `['category', 'Human Label', severity]`. Severity is `AUDIT_SEVERITY_INFO | WARNING | CRITICAL` — use `CRITICAL` for permission/settings changes, `WARNING` for destructive or sensitive actions, `INFO` otherwise.
- `audit_log()` resolves the actor from the session automatically; only pass `actorPersonId` to override (e.g. cron/system context).

## Event code naming

- Codes are `entity.action`, lowercase, dot-separated: `event.created`, `group.member_removed`, `cron.reminders_cleanse_run`. Keep the entity consistent with existing families (`auth`, `user`, `group`, `event`, `risk`, `comms`, `admin`, `cron`).

## dc_log() — app activity log

- Use `dc_log('entity.action', 'entity_type', $id, [...details], $groupId)` for district-app activity. Pages typically call **both** `dc_log` and `audit_log` for the same action (see `dc/add-district-event.php`): `dc_log` for the app's own activity feed, `audit_log` for the formal audit trail.

## Never break the app

- Logging is best-effort. `audit_log()` already swallows `Throwable` and falls back to `error_log()`. Don't wrap user-facing logic in a way that fails if a log write fails.
- Use `error_log()` for diagnostic/debug output only — never for the audit trail, and never log secrets or full PII payloads.
