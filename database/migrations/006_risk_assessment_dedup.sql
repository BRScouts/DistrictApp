-- Migration 006: Risk assessment deduplication
--
-- Supports detecting and reusing byte-identical risk assessment uploads. The
-- application already computes a SHA-256 of each uploaded file and stores it in
-- risk_assessments.file_sha256; this migration makes the dedup lookup fast and
-- repairs any older schema that is missing the column.
--
-- Design notes:
--   * dc_store_risk_assessment_upload() now looks up an existing active risk
--     assessment for the SAME group with a matching file_sha256 before writing
--     a new file, and reuses that row if found. The lookup filters on
--     (group_id, file_sha256, status), so a composite index on
--     (group_id, file_sha256) keeps it off a full table scan.
--   * Dedup is scoped to the group, not global: risk assessments are group
--     data, so the same template held by two different groups is legitimately
--     two rows and must not collide. That is why the index leads with group_id
--     rather than being a UNIQUE key on file_sha256 alone.
--   * Both changes are additive and guarded so the migration is re-runnable:
--     we add file_sha256 only if absent, and add the index only if absent.
--     The app reads file_sha256 defensively via dc_table_has_column(), so it
--     keeps working before this migration is applied.

-- 1. Ensure the file_sha256 column exists -----------------------------------
--
-- Older installs created risk_assessments with the column already present; this
-- block repairs any schema where it is missing without erroring if it exists.

SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'risk_assessments'
      AND COLUMN_NAME = 'file_sha256'
);

SET @add_col_sql := IF(
    @col_exists = 0,
    'ALTER TABLE risk_assessments ADD COLUMN file_sha256 CHAR(64) NULL DEFAULT NULL AFTER file_size_bytes',
    'SELECT 1'
);

PREPARE add_col_stmt FROM @add_col_sql;
EXECUTE add_col_stmt;
DEALLOCATE PREPARE add_col_stmt;

-- 2. Add the composite index for the dedup lookup ---------------------------
--
-- Speeds up "does this group already hold a file with this hash?".

SET @idx_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'risk_assessments'
      AND INDEX_NAME = 'idx_risk_assessments_group_sha'
);

SET @add_idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE risk_assessments ADD INDEX idx_risk_assessments_group_sha (group_id, file_sha256)',
    'SELECT 1'
);

PREPARE add_idx_stmt FROM @add_idx_sql;
EXECUTE add_idx_stmt;
DEALLOCATE PREPARE add_idx_stmt;
