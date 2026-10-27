# Domain Glossary

This is a calendar and communications portal for a UK Scouts **District**. Use these terms accurately in code, comments, UI copy, and commit messages so the language stays consistent with how the organisation actually talks.

## Organisation

- **District** — the top-level organisation this app serves. Contains many groups.
- **Group** (Scout Group) — a local scout group within the district. Most data (events, people, calendars) is scoped to a group via `group_id`.
- **GLV (Group Lead Volunteer)** — the lead volunteer for a group. A common notification audience ("email all GLVs").
- **Leader** — an active adult volunteer in a group. Another notification audience ("email all leaders").
- **Person / people** — individuals stored in the `people` table (leaders, volunteers). Referenced by `person_id` throughout.

## App areas

- **DC** — the district-coordinator app under `dc/`. Admin-facing screens for managing the district (guarded by `dc_require_admin()`).
- **District Event** — an event created by a district/system admin that every group sees on the District Calendar by default, styled with a distinct hatched header. Flagged by `calendar_events.is_district_event` and attached to the hidden "District Events" group (see migration 004).
- **Calendar event** — a normal group event in `calendar_events`. Has a lifecycle: draft → submitted → approved/rejected/changes requested → (cancelled/deleted).
- **Risk assessment** — a PDF document that can be uploaded and linked to an event.

## Integrations & systems

- **M365 / Microsoft 365** — accounts provisioned and profiles synced via the `cron/` jobs and `app/microsoft.php` (OAuth via `league/oauth2-client`).
- **SSO** — single sign-on via Microsoft; events created through the admin UI are recorded with `submitted_via = 'sso'`.
- **Audit log** — the formal record of security/admin actions (`app/audit.php`). Distinct from **dc_log**, the app's own activity feed.
- **Email queue** — outbound email is queued (`comms.email_queued`) and sent by `cron/send-email-queue.php` rather than sent inline.

## Writing conventions

- Capitalise "District Event" when referring to the specific feature; lowercase "group event" for ordinary events.
- Prefer the organisation's words ("leader", "GLV", "group") over generic ones ("user", "admin", "team") in user-facing copy.
