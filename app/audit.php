<?php

declare(strict_types=1);

/**
 * Unified Audit Logging System
 *
 * Provides a single entry point for logging all security-relevant and
 * administrative actions across the application. Uses structured event
 * codes with a lookup table for categorisation and severity.
 *
 * Usage:
 *   audit_log('auth.login_success');
 *   audit_log('user.edited', 'person', $personId, $personId, ['field' => 'email']);
 *   audit_log('event.created', 'calendar_event', $eventId, null, ['title' => $title], $groupId);
 */

// ─── Event Code Constants ────────────────────────────────────────────────────

// Authentication
const AUDIT_AUTH_LOGIN_SUCCESS       = 'auth.login_success';
const AUDIT_AUTH_LOGIN_FAILED        = 'auth.login_failed';
const AUDIT_AUTH_LOGOUT              = 'auth.logout';
const AUDIT_AUTH_SESSION_EXPIRED     = 'auth.session_expired';

// User / Person management
const AUDIT_USER_CREATED             = 'user.created';
const AUDIT_USER_EDITED              = 'user.edited';
const AUDIT_USER_ROLE_CHANGED        = 'user.role_changed';
const AUDIT_USER_DEACTIVATED         = 'user.deactivated';
const AUDIT_USER_REACTIVATED         = 'user.reactivated';
const AUDIT_USER_PRIMARY_SET         = 'user.primary_set';
const AUDIT_USER_LINKED_TO_GROUP     = 'user.linked_to_group';
const AUDIT_USER_INSTRUCTIONS_SENT   = 'user.instructions_sent';

// Group management
const AUDIT_GROUP_CREATED            = 'group.created';
const AUDIT_GROUP_UPDATED            = 'group.details_updated';
const AUDIT_GROUP_STATUS_CHANGED     = 'group.status_changed';
const AUDIT_GROUP_MEMBER_ADDED       = 'group.member_added';
const AUDIT_GROUP_MEMBER_REMOVED     = 'group.member_removed';
const AUDIT_GROUP_EDITOR_ASSIGNED    = 'group.editor_assigned';
const AUDIT_GROUP_EDITOR_REMOVED     = 'group.editor_removed';
const AUDIT_GROUP_LINK_CREATED       = 'group.link_created';
const AUDIT_GROUP_LINK_ROTATED       = 'group.link_rotated';
const AUDIT_GROUP_LINK_DISABLED      = 'group.link_disabled';

// Calendar events
const AUDIT_EVENT_CREATED            = 'event.created';
const AUDIT_EVENT_DRAFT_CREATED      = 'event.draft_created';
const AUDIT_EVENT_UPDATED            = 'event.updated';
const AUDIT_EVENT_SUBMITTED          = 'event.submitted';
const AUDIT_EVENT_APPROVED           = 'event.approved';
const AUDIT_EVENT_REJECTED           = 'event.rejected';
const AUDIT_EVENT_CHANGES_REQUESTED  = 'event.changes_requested';
const AUDIT_EVENT_CANCELLED          = 'event.cancelled';
const AUDIT_EVENT_DELETED            = 'event.deleted';

// Risk assessments
const AUDIT_RISK_UPLOADED            = 'risk.uploaded';
const AUDIT_RISK_LINKED              = 'risk.linked_to_event';

// Communications
const AUDIT_COMMS_EMAIL_QUEUED       = 'comms.email_queued';
const AUDIT_COMMS_EMAIL_SENT         = 'comms.email_sent';

// System / Admin
const AUDIT_ADMIN_SETTINGS_CHANGED   = 'admin.settings_changed';
const AUDIT_ADMIN_PERMISSION_CHANGED = 'admin.permission_changed';
const AUDIT_ADMIN_M365_ACCOUNT_REQ   = 'admin.m365_account_requested';

// ─── Severity Levels ─────────────────────────────────────────────────────────

const AUDIT_SEVERITY_INFO     = 'info';
const AUDIT_SEVERITY_WARNING  = 'warning';
const AUDIT_SEVERITY_CRITICAL = 'critical';

// ─── Event Type Registry (in-memory, seeded to DB via migration) ─────────────

/**
 * Master event type definitions.
 * Used for DB seeding and for runtime label/severity lookups.
 *
 * Format: 'event.code' => ['category', 'Human Label', 'severity']
 */
function audit_event_types(): array
{
    static $types = null;

    if ($types !== null) {
        return $types;
    }

    $types = [
        // Auth
        AUDIT_AUTH_LOGIN_SUCCESS       => ['auth', 'Successful login', AUDIT_SEVERITY_INFO],
        AUDIT_AUTH_LOGIN_FAILED        => ['auth', 'Failed login attempt', AUDIT_SEVERITY_WARNING],
        AUDIT_AUTH_LOGOUT              => ['auth', 'User logged out', AUDIT_SEVERITY_INFO],
        AUDIT_AUTH_SESSION_EXPIRED     => ['auth', 'Session expired', AUDIT_SEVERITY_INFO],

        // User
        AUDIT_USER_CREATED             => ['user', 'Person record created', AUDIT_SEVERITY_INFO],
        AUDIT_USER_EDITED              => ['user', 'Person details edited', AUDIT_SEVERITY_INFO],
        AUDIT_USER_ROLE_CHANGED        => ['user', 'User role/access changed', AUDIT_SEVERITY_WARNING],
        AUDIT_USER_DEACTIVATED         => ['user', 'Person deactivated', AUDIT_SEVERITY_WARNING],
        AUDIT_USER_REACTIVATED         => ['user', 'Person reactivated', AUDIT_SEVERITY_INFO],
        AUDIT_USER_PRIMARY_SET         => ['user', 'Primary membership set', AUDIT_SEVERITY_INFO],
        AUDIT_USER_LINKED_TO_GROUP     => ['user', 'Person linked to group', AUDIT_SEVERITY_INFO],
        AUDIT_USER_INSTRUCTIONS_SENT   => ['user', 'Access instructions sent', AUDIT_SEVERITY_INFO],

        // Group
        AUDIT_GROUP_CREATED            => ['group', 'Group created', AUDIT_SEVERITY_INFO],
        AUDIT_GROUP_UPDATED            => ['group', 'Group details updated', AUDIT_SEVERITY_INFO],
        AUDIT_GROUP_STATUS_CHANGED     => ['group', 'Group status changed', AUDIT_SEVERITY_WARNING],
        AUDIT_GROUP_MEMBER_ADDED       => ['group', 'Member added to group', AUDIT_SEVERITY_INFO],
        AUDIT_GROUP_MEMBER_REMOVED     => ['group', 'Member removed from group', AUDIT_SEVERITY_WARNING],
        AUDIT_GROUP_EDITOR_ASSIGNED    => ['group', 'Group editor assigned', AUDIT_SEVERITY_INFO],
        AUDIT_GROUP_EDITOR_REMOVED     => ['group', 'Group editor removed', AUDIT_SEVERITY_WARNING],
        AUDIT_GROUP_LINK_CREATED       => ['group', 'Calendar access link created', AUDIT_SEVERITY_INFO],
        AUDIT_GROUP_LINK_ROTATED       => ['group', 'Calendar access link rotated', AUDIT_SEVERITY_WARNING],
        AUDIT_GROUP_LINK_DISABLED      => ['group', 'Calendar access link disabled', AUDIT_SEVERITY_WARNING],

        // Events
        AUDIT_EVENT_CREATED            => ['event', 'Calendar event created', AUDIT_SEVERITY_INFO],
        AUDIT_EVENT_DRAFT_CREATED      => ['event', 'Event draft saved', AUDIT_SEVERITY_INFO],
        AUDIT_EVENT_UPDATED            => ['event', 'Calendar event updated', AUDIT_SEVERITY_INFO],
        AUDIT_EVENT_SUBMITTED          => ['event', 'Event submitted for review', AUDIT_SEVERITY_INFO],
        AUDIT_EVENT_APPROVED           => ['event', 'Event approved', AUDIT_SEVERITY_INFO],
        AUDIT_EVENT_REJECTED           => ['event', 'Event rejected', AUDIT_SEVERITY_WARNING],
        AUDIT_EVENT_CHANGES_REQUESTED  => ['event', 'Event changes requested', AUDIT_SEVERITY_WARNING],
        AUDIT_EVENT_CANCELLED          => ['event', 'Event cancelled', AUDIT_SEVERITY_WARNING],
        AUDIT_EVENT_DELETED            => ['event', 'Event deleted', AUDIT_SEVERITY_WARNING],

        // Risk
        AUDIT_RISK_UPLOADED            => ['risk', 'Risk assessment uploaded', AUDIT_SEVERITY_INFO],
        AUDIT_RISK_LINKED              => ['risk', 'Risk assessment linked to event', AUDIT_SEVERITY_INFO],

        // Comms
        AUDIT_COMMS_EMAIL_QUEUED       => ['comms', 'Email queued for sending', AUDIT_SEVERITY_INFO],
        AUDIT_COMMS_EMAIL_SENT         => ['comms', 'Bulk communication sent', AUDIT_SEVERITY_INFO],

        // Admin
        AUDIT_ADMIN_SETTINGS_CHANGED   => ['admin', 'System settings changed', AUDIT_SEVERITY_CRITICAL],
        AUDIT_ADMIN_PERMISSION_CHANGED => ['admin', 'Permission level changed', AUDIT_SEVERITY_CRITICAL],
        AUDIT_ADMIN_M365_ACCOUNT_REQ   => ['admin', 'M365 account requested', AUDIT_SEVERITY_INFO],
    ];

    return $types;
}

// ─── Core Logging Function ───────────────────────────────────────────────────

/**
 * Write an audit log entry.
 *
 * @param string      $eventCode      One of the AUDIT_* constants (e.g. 'auth.login_success')
 * @param string|null $entityType     The type of entity affected (e.g. 'person', 'calendar_event', 'group')
 * @param int|null    $entityId       The ID of the affected entity
 * @param int|null    $targetPersonId The person this action was done TO (for user-centric queries)
 * @param array       $details        Arbitrary key-value details stored as JSON
 * @param int|null    $groupId        The group context for this action (if applicable)
 * @param int|null    $actorPersonId  Override the actor (defaults to current session user)
 */
function audit_log(
    string $eventCode,
    ?string $entityType = null,
    ?int $entityId = null,
    ?int $targetPersonId = null,
    array $details = [],
    ?int $groupId = null,
    ?int $actorPersonId = null
): void {
    // Never let audit logging break the application
    try {
        // Resolve actor from session if not explicitly provided
        if ($actorPersonId === null) {
            $sessionUser = $_SESSION['portal_user'] ?? null;
            $actorPersonId = $sessionUser ? (int) ($sessionUser['id'] ?? 0) : null;

            if ($actorPersonId === 0) {
                $actorPersonId = null;
            }
        }

        $actorType = $actorPersonId ? 'person' : 'system';

        // Resolve event_type_id from the lookup table (cached)
        $eventTypeId = audit_resolve_event_type_id($eventCode);

        // Get severity for the details
        $types = audit_event_types();
        $severity = $types[$eventCode][2] ?? AUDIT_SEVERITY_INFO;

        $stmt = db()->prepare("
            INSERT INTO audit_log (
                event_type_id,
                actor_type,
                actor_person_id,
                target_person_id,
                group_id,
                entity_type,
                entity_id,
                action,
                details_json,
                ip_address,
                user_agent,
                created_at
            ) VALUES (
                :event_type_id,
                :actor_type,
                :actor_person_id,
                :target_person_id,
                :group_id,
                :entity_type,
                :entity_id,
                :action,
                :details_json,
                :ip_address,
                :user_agent,
                NOW()
            )
        ");

        $stmt->execute([
            'event_type_id'    => $eventTypeId,
            'actor_type'       => $actorType,
            'actor_person_id'  => $actorPersonId,
            'target_person_id' => $targetPersonId,
            'group_id'         => $groupId,
            'entity_type'      => $entityType,
            'entity_id'        => $entityId,
            'action'           => $eventCode,
            'details_json'     => $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'ip_address'       => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'       => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : null,
        ]);
    } catch (Throwable $e) {
        // Audit logging must never break user-facing workflows.
        // Optionally log to error_log for debugging.
        error_log('audit_log() failed: ' . $e->getMessage());
    }
}

// ─── Helper: Resolve event_type_id from DB ───────────────────────────────────

/**
 * Look up the event_type_id for a given event code.
 * Caches results in a static variable for the request lifecycle.
 * Returns null if the audit_event_types table doesn't exist or the code isn't found.
 */
function audit_resolve_event_type_id(string $eventCode): ?int
{
    static $cache = [];
    static $tableChecked = null;

    // Check if table exists (once per request)
    if ($tableChecked === null) {
        try {
            $stmt = db()->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'audit_event_types'
            ");
            $stmt->execute();
            $tableChecked = ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $tableChecked = false;
        }
    }

    if (!$tableChecked) {
        return null;
    }

    if (array_key_exists($eventCode, $cache)) {
        return $cache[$eventCode];
    }

    try {
        $stmt = db()->prepare("
            SELECT id FROM audit_event_types WHERE code = :code LIMIT 1
        ");
        $stmt->execute(['code' => $eventCode]);
        $id = $stmt->fetchColumn();

        $cache[$eventCode] = $id !== false ? (int) $id : null;
    } catch (Throwable $e) {
        $cache[$eventCode] = null;
    }

    return $cache[$eventCode];
}

// ─── Helper: Get human label for an event code ───────────────────────────────

function audit_event_label(string $eventCode): string
{
    $types = audit_event_types();
    return $types[$eventCode][1] ?? ucwords(str_replace(['.', '_'], ' ', $eventCode));
}

function audit_event_category(string $eventCode): string
{
    $types = audit_event_types();
    return $types[$eventCode][0] ?? 'other';
}

function audit_event_severity(string $eventCode): string
{
    $types = audit_event_types();
    return $types[$eventCode][2] ?? AUDIT_SEVERITY_INFO;
}

// ─── Helper: Fetch recent audit entries for an entity ────────────────────────

/**
 * Fetch audit log entries for a specific entity.
 */
function audit_fetch_for_entity(string $entityType, int $entityId, int $limit = 50): array
{
    try {
        $stmt = db()->prepare("
            SELECT
                al.*,
                p.full_name AS actor_name,
                tp.full_name AS target_name,
                g.group_name
            FROM audit_log al
            LEFT JOIN people p ON p.id = al.actor_person_id
            LEFT JOIN people tp ON tp.id = al.target_person_id
            LEFT JOIN groups g ON g.id = al.group_id
            WHERE al.entity_type = :entity_type
              AND al.entity_id = :entity_id
            ORDER BY al.created_at DESC, al.id DESC
            LIMIT :lim
        ");
        $stmt->bindValue('entity_type', $entityType);
        $stmt->bindValue('entity_id', $entityId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Fetch audit log entries for a specific person (as target).
 */
function audit_fetch_for_person(int $personId, int $limit = 50): array
{
    try {
        $stmt = db()->prepare("
            SELECT
                al.*,
                p.full_name AS actor_name,
                tp.full_name AS target_name,
                g.group_name
            FROM audit_log al
            LEFT JOIN people p ON p.id = al.actor_person_id
            LEFT JOIN people tp ON tp.id = al.target_person_id
            LEFT JOIN groups g ON g.id = al.group_id
            WHERE al.target_person_id = :person_id
               OR al.actor_person_id = :person_id2
            ORDER BY al.created_at DESC, al.id DESC
            LIMIT :lim
        ");
        $stmt->bindValue('person_id', $personId, PDO::PARAM_INT);
        $stmt->bindValue('person_id2', $personId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Fetch audit log entries by actor.
 */
function audit_fetch_by_actor(int $actorPersonId, int $limit = 50): array
{
    try {
        $stmt = db()->prepare("
            SELECT
                al.*,
                p.full_name AS actor_name,
                tp.full_name AS target_name,
                g.group_name
            FROM audit_log al
            LEFT JOIN people p ON p.id = al.actor_person_id
            LEFT JOIN people tp ON tp.id = al.target_person_id
            LEFT JOIN groups g ON g.id = al.group_id
            WHERE al.actor_person_id = :actor_id
            ORDER BY al.created_at DESC, al.id DESC
            LIMIT :lim
        ");
        $stmt->bindValue('actor_id', $actorPersonId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}
