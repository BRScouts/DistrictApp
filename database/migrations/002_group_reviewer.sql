-- Migration 002: Group-level event reviewer (can_review_events column)
--
-- Adds a boolean flag to group_memberships allowing any member to be
-- designated as a reviewer for their group's calendar events.
--
-- This is purely additive — it does NOT change anyone's access_level.
-- A GLV keeps group_admin, a member keeps member, etc.
--
-- District-level reviewers (district_reviewer, district_admin) still
-- review all events across all groups as before.

ALTER TABLE group_memberships
ADD COLUMN can_review_events TINYINT(1) NOT NULL DEFAULT 0
AFTER access_level;

-- To grant review permission:
--   UPDATE group_memberships
--   SET can_review_events = 1
--   WHERE person_id = :person_id
--     AND group_id = :group_id
--     AND status = 'active';
--
-- To revoke:
--   UPDATE group_memberships
--   SET can_review_events = 0
--   WHERE person_id = :person_id
--     AND group_id = :group_id;
--
-- Behaviour:
--   - People with can_review_events = 1 can access /dc/reviewer/ pages
--     but ONLY see events for groups where they hold this flag.
--   - They cannot review their own submitted events (self-review prevention).
--   - They receive email notifications when events are submitted for their group.
--   - District-level reviewers are unaffected and retain full cross-district access.
--   - system_admin users are NOT emailed on event submission.
--
-- The permission is granted via Group Manager > Person page by District Admins.
