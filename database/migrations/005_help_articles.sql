-- Migration 005: Help articles
--
-- Adds the Help_Article_Store — the `help_articles` table that backs the
-- top-level Help Centre module (`/help-centre.php`). Each row is one help
-- article: a URL-safe `slug`, display `title`, short `summary`, search
-- `keywords`, a `topic` used to group articles on the index, a publication
-- `status`, a `sort_order` within the topic, and a `body` holding the
-- Rich_Body as a JSON array of ordered content blocks (paragraphs, headings,
-- step lists and action blocks).
--
-- Design notes:
--   * `CREATE TABLE IF NOT EXISTS` keeps the migration idempotent — re-running
--     it leaves an existing table untouched rather than failing. Seed content
--     (added in later statements) uses slug-guarded inserts for the same
--     reason.
--   * `slug` is UNIQUE so each article has one stable Article_Viewer URL and a
--     duplicate seed insert is rejected.
--   * The composite index on (status, topic, sort_order) supports the grouped
--     "published articles by topic" listing query the Help_Centre_Index runs.
--   * `body` is stored as JSON so the renderer can walk ordered, typed blocks.

CREATE TABLE IF NOT EXISTS help_articles (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(160)    NOT NULL,
    title       VARCHAR(255)    NOT NULL,
    summary     VARCHAR(500)    NOT NULL DEFAULT '',
    keywords    VARCHAR(500)    NOT NULL DEFAULT '',
    body        JSON            NOT NULL,
    topic       VARCHAR(120)    NOT NULL DEFAULT '',
    status      ENUM('draft','published') NOT NULL DEFAULT 'draft',
    sort_order  INT             NOT NULL DEFAULT 0,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_help_articles_slug (slug),
    KEY idx_help_articles_status_topic (status, topic, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
