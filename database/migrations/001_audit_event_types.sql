    -- ═══════════════════════════════════════════════════════════════════════════════
    -- Audit Logging System: Event Types & Schema Enhancements
    -- Run this migration to set up the structured audit logging system.
    -- ═══════════════════════════════════════════════════════════════════════════════

    -- ─── 1. Create the audit_event_types lookup table ────────────────────────────

    CREATE TABLE IF NOT EXISTS audit_event_types (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code        VARCHAR(80) NOT NULL,
        category    VARCHAR(40) NOT NULL,
        label       VARCHAR(120) NOT NULL,
        severity    ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_code (code),
        KEY idx_category (category),
        KEY idx_severity (severity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- ─── 2. Alter audit_log table to add new columns ─────────────────────────────

    DELIMITER //

    DROP PROCEDURE IF EXISTS _audit_migration_add_columns//

    CREATE PROCEDURE _audit_migration_add_columns()
    BEGIN
        IF NOT EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'event_type_id'
        ) THEN
            ALTER TABLE audit_log ADD COLUMN event_type_id INT UNSIGNED NULL AFTER id;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'target_person_id'
        ) THEN
            ALTER TABLE audit_log ADD COLUMN target_person_id INT UNSIGNED NULL AFTER actor_person_id;
        END IF;

        SET @idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND INDEX_NAME = 'idx_audit_event_type');
        IF @idx = 0 THEN ALTER TABLE audit_log ADD INDEX idx_audit_event_type (event_type_id); END IF;

        SET @idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND INDEX_NAME = 'idx_audit_target_person');
        IF @idx = 0 THEN ALTER TABLE audit_log ADD INDEX idx_audit_target_person (target_person_id); END IF;

        SET @idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND INDEX_NAME = 'idx_audit_created');
        IF @idx = 0 THEN ALTER TABLE audit_log ADD INDEX idx_audit_created (created_at); END IF;

        SET @idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND INDEX_NAME = 'idx_audit_actor');
        IF @idx = 0 THEN ALTER TABLE audit_log ADD INDEX idx_audit_actor (actor_person_id); END IF;

        SET @idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND INDEX_NAME = 'idx_audit_action');
        IF @idx = 0 THEN ALTER TABLE audit_log ADD INDEX idx_audit_action (action); END IF;
    END//

    DELIMITER ;

    CALL _audit_migration_add_columns();
    DROP PROCEDURE IF EXISTS _audit_migration_add_columns;

    -- ─── 3. Seed the event types ─────────────────────────────────────────────────

    INSERT IGNORE INTO audit_event_types (code, category, label, severity) VALUES
    -- Authentication
    ('auth.login_success',       'auth',   'Successful login',             'info'),
    ('auth.login_failed',        'auth',   'Failed login attempt',         'warning'),
    ('auth.logout',              'auth',   'User logged out',              'info'),
    ('auth.session_expired',     'auth',   'Session expired',              'info'),

    -- User / Person
    ('user.created',             'user',   'Person record created',        'info'),
    ('user.edited',              'user',   'Person details edited',        'info'),
    ('user.role_changed',        'user',   'User role/access changed',     'warning'),
    ('user.deactivated',         'user',   'Person deactivated',           'warning'),
    ('user.reactivated',         'user',   'Person reactivated',           'info'),
    ('user.primary_set',         'user',   'Primary membership set',       'info'),
    ('user.linked_to_group',     'user',   'Person linked to group',       'info'),
    ('user.instructions_sent',   'user',   'Access instructions sent',     'info'),

    -- Group
    ('group.created',            'group',  'Group created',                'info'),
    ('group.details_updated',    'group',  'Group details updated',        'info'),
    ('group.status_changed',     'group',  'Group status changed',         'warning'),
    ('group.member_added',       'group',  'Member added to group',        'info'),
    ('group.member_removed',     'group',  'Member removed from group',    'warning'),
    ('group.editor_assigned',    'group',  'Group editor assigned',        'info'),
    ('group.editor_removed',     'group',  'Group editor removed',         'warning'),
    ('group.link_created',       'group',  'Calendar access link created', 'info'),
    ('group.link_rotated',       'group',  'Calendar access link rotated', 'warning'),
    ('group.link_disabled',      'group',  'Calendar access link disabled','warning'),

    -- Calendar Events
    ('event.created',            'event',  'Calendar event created',       'info'),
    ('event.draft_created',      'event',  'Event draft saved',            'info'),
    ('event.updated',            'event',  'Calendar event updated',       'info'),
    ('event.submitted',          'event',  'Event submitted for review',   'info'),
    ('event.approved',           'event',  'Event approved',               'info'),
    ('event.rejected',           'event',  'Event rejected',               'warning'),
    ('event.changes_requested',  'event',  'Event changes requested',      'warning'),
    ('event.cancelled',          'event',  'Event cancelled',              'warning'),
    ('event.deleted',            'event',  'Event deleted',                'warning'),

    -- Risk Assessments
    ('risk.uploaded',            'risk',   'Risk assessment uploaded',     'info'),
    ('risk.linked_to_event',     'risk',   'Risk assessment linked',       'info'),

    -- Communications
    ('comms.email_queued',       'comms',  'Email queued for sending',     'info'),
    ('comms.email_sent',         'comms',  'Bulk communication sent',      'info'),

    -- Admin / System
    ('admin.settings_changed',   'admin',  'System settings changed',      'critical'),
    ('admin.permission_changed', 'admin',  'Permission level changed',     'critical'),
    ('admin.m365_account_requested', 'admin', 'M365 account requested',   'info'),

    -- Cron Jobs
    ('cron.leavers_run',         'cron',   'Leavers notification cron completed',  'info'),
    ('cron.provision_m365_run',  'cron',   'M365 provisioning cron completed',     'info'),
    ('cron.reminders_cleanse_run','cron',  'Reminders and cleanse cron completed', 'info'),
    ('cron.sync_m365_profiles_run','cron', 'M365 profile sync cron completed',    'info');
