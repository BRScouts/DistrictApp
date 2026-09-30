-- Migration 003: Ensure email_queue has HTML body columns
--
-- Background:
--   Emails are queued by many callers (technical-support.php, comms-tool.php,
--   the cron notification scripts, group-manager-helpers.php, etc.). Each caller
--   stores the plain-text version in `body` and the rich HTML version in
--   `body_html` — BUT only if the `body_html` column exists. When the column is
--   missing they fall back to writing the raw HTML into the plain `body` column.
--
--   The queue sender (cron/send-email-queue.php) then saw an empty `body_html`,
--   assumed `body` was plain text, and ran it through the plain-text->HTML
--   converter which HTML-escapes everything. The result: recipients saw raw
--   markup (e.g. <div>...</div>) as literal visible text.
--
--   The sender has been hardened to detect HTML in the fallback body, but the
--   correct long-term fix is to make sure the dedicated columns exist so callers
--   store content in the right place.
--
-- This migration is additive and idempotent — it only adds columns that are
-- missing, so it is safe to run on any environment.

DELIMITER //

DROP PROCEDURE IF EXISTS migrate_003_email_queue_columns //

CREATE PROCEDURE migrate_003_email_queue_columns()
BEGIN
    -- body_html: the rich HTML version of the message.
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'email_queue'
          AND COLUMN_NAME = 'body_html'
    ) THEN
        ALTER TABLE email_queue ADD COLUMN body_html MEDIUMTEXT NULL AFTER body;
    END IF;

    -- body_text: an explicit plain-text alternative (some callers set this).
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'email_queue'
          AND COLUMN_NAME = 'body_text'
    ) THEN
        ALTER TABLE email_queue ADD COLUMN body_text MEDIUMTEXT NULL AFTER body_html;
    END IF;

    -- body_markdown: reserved for markdown source, written as NULL by callers.
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'email_queue'
          AND COLUMN_NAME = 'body_markdown'
    ) THEN
        ALTER TABLE email_queue ADD COLUMN body_markdown MEDIUMTEXT NULL AFTER body_text;
    END IF;
END //

DELIMITER ;

CALL migrate_003_email_queue_columns();
DROP PROCEDURE IF EXISTS migrate_003_email_queue_columns;

-- Optional backfill: for any pending rows where body_html is empty but body
-- clearly contains HTML markup, move that HTML into body_html so the sender
-- treats it correctly. Adjust/skip if you prefer to let the hardened sender
-- handle it at send time.
--
-- UPDATE email_queue
-- SET body_html = body
-- WHERE status = 'pending'
--   AND (body_html IS NULL OR body_html = '')
--   AND body REGEXP '<[[:space:]]*/?[[:space:]]*(div|p|br|h[1-6]|ul|ol|li|table|a|strong|hr)';
