-- Migration 004: District events
--
-- Adds support for "District Events" — events added by district/system admins
-- that every group sees on the District Calendar by default. They are styled
-- distinctly (an Outlook-style "out of office" hatched header) and carry
-- optional booking / information links.
--
-- Design notes:
--   * calendar_events.group_id is NOT NULL and the calendar query INNER JOINs
--     `groups`, so every event must belong to a group row. Rather than relax
--     that join everywhere, we seed a dedicated hidden "District Events" group
--     and attach district events to it. Its id is stored in app_settings under
--     the key `district_events_group_id` so the app can resolve it at runtime.
--   * `is_district_event` is the flag the calendar uses to (a) show the event
--     to every group regardless of membership and (b) apply the distinct tile
--     styling.
--   * All new columns are additive and read defensively via
--     dc_table_has_column(), so older code keeps working.

-- 1. Flag + extra fields on calendar_events ---------------------------------

ALTER TABLE calendar_events
    ADD COLUMN is_district_event TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

ALTER TABLE calendar_events
    ADD COLUMN booking_url VARCHAR(1024) NULL DEFAULT NULL AFTER is_district_event;

ALTER TABLE calendar_events
    ADD COLUMN info_url VARCHAR(1024) NULL DEFAULT NULL AFTER booking_url;

-- Helps the calendar quickly pull "all district events in range".
ALTER TABLE calendar_events
    ADD INDEX idx_calendar_events_district (is_district_event, starts_at);

-- 2. Seed the dedicated District Events group -------------------------------
--
-- We use a stable slug so the seed is idempotent: the INSERT ... SELECT only
-- inserts when the slug is not already present.
--
-- The `groups` table requires only group_name and slug; every other column is
-- nullable or defaulted (group_kind -> scout_group, is_active -> 1,
-- created_at/updated_at auto). We set is_active = 0 so the hidden District
-- Events container never shows up as a selectable group anywhere.

INSERT INTO groups (group_name, slug, is_active)
SELECT
    'District Events',
    'district-events',
    0
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM groups WHERE slug = 'district-events'
);

-- 3. Record the District Events group id in app_settings --------------------
--
-- Done as an insert-if-missing followed by an update so it does not depend on
-- a UNIQUE key existing on app_settings.setting_key.

INSERT INTO app_settings (setting_key, setting_value)
SELECT
    'district_events_group_id',
    CAST((SELECT id FROM groups WHERE slug = 'district-events' LIMIT 1) AS CHAR)
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM app_settings WHERE setting_key = 'district_events_group_id'
);

UPDATE app_settings
SET setting_value = CAST((SELECT id FROM groups WHERE slug = 'district-events' LIMIT 1) AS CHAR)
WHERE setting_key = 'district_events_group_id';
