<?php

declare(strict_types=1);

/**
 * District Calendar data cleanse and reminder cron.
 *
 * Path:
 * /cron/data-clense.php
 *
 * Run manually:
 * php /home/brscouts/app.irvalscouts.org.uk/cron/data-clense.php
 *
 * Suggested cron:
 * 10 6 * * * /usr/bin/php /home/brscouts/app.irvalscouts.org.uk/cron/data-clense.php >> /home/brscouts/app.irvalscouts.org.uk/storage/logs/dc-data-clense.log 2>&1
 */



require_once __DIR__ . '/../app/bootstrap.php';

$pdo = db();

/**
 * -----------------------------------------------------------------------------
 * Basic helpers
 * -----------------------------------------------------------------------------
 */

function dc_clense_log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function dc_clense_config(string $key, mixed $default = null): mixed
{
    return defined($key) ? constant($key) : $default;
}

function dc_clense_app_url(): string
{
    if (defined('APP_URL')) {
        return rtrim((string) APP_URL, '/');
    }

    if (function_exists('app_config')) {
        return rtrim((string) app_config('APP_URL', 'https://app.irvalscouts.org.uk'), '/');
    }

    return 'https://app.irvalscouts.org.uk';
}

function dc_clense_event_url(int $eventId): string
{
    return dc_clense_app_url() . '/dc/manage-event.php?id=' . $eventId;
}

function dc_clense_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function dc_clense_table_exists(string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute(['table_name' => $table]);

        return $cache[$table] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function dc_clense_column_exists(string $table, string $column): bool
{
    static $cache = [];

    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function dc_clense_columns(string $table): array
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute(['table_name' => $table]);

        return $cache[$table] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

function dc_clense_insert_compatible(string $table, array $data): int
{
    $columns = dc_clense_columns($table);

    if (!$columns) {
        throw new RuntimeException("Table {$table} does not exist or has no readable columns.");
    }

    $insert = [];

    foreach ($data as $column => $value) {
        if (in_array($column, $columns, true)) {
            $insert[$column] = $value;
        }
    }

    if (!$insert) {
        throw new RuntimeException("No compatible columns found for {$table}.");
    }

    $quotedColumns = array_map('dc_clense_quote_identifier', array_keys($insert));
    $placeholders = array_map(
        static fn(string $column): string => ':' . $column,
        array_keys($insert)
    );

    $stmt = db()->prepare("
        INSERT INTO " . dc_clense_quote_identifier($table) . "
        (" . implode(', ', $quotedColumns) . ")
        VALUES
        (" . implode(', ', $placeholders) . ")
    ");

    $stmt->execute($insert);

    return (int) db()->lastInsertId();
}

function dc_clense_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dc_clense_html_from_text(string $text): string
{
    $text = trim($text);

    if ($text === '') {
        return '';
    }

    $paragraphs = preg_split("/\n\s*\n/", $text) ?: [];
    $html = '';

    foreach ($paragraphs as $paragraph) {
        $lines = array_map('dc_clense_escape', explode("\n", trim($paragraph)));
        $html .= '<p>' . implode('<br>', $lines) . '</p>' . "\n";
    }

    return $html;
}

function dc_clense_format_date(?string $date): string
{
    if (!$date || !strtotime($date)) {
        return 'Unknown';
    }

    return date('j M Y H:i', strtotime($date));
}

/**
 * -----------------------------------------------------------------------------
 * Settings
 * -----------------------------------------------------------------------------
 */

function dc_clense_get_setting(string $key, ?string $default = null): ?string
{
    if (!dc_clense_table_exists('app_settings')) {
        return $default;
    }

    try {
        $stmt = db()->prepare("
            SELECT setting_value
            FROM app_settings
            WHERE setting_key = :setting_key
            LIMIT 1
        ");
        $stmt->execute(['setting_key' => $key]);

        $value = $stmt->fetchColumn();

        return $value === false ? $default : (string) $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function dc_clense_split_emails(?string $value): array
{
    if (!$value) {
        return [];
    }

    $parts = preg_split('/[;,\n]+/', $value) ?: [];
    $emails = [];

    foreach ($parts as $part) {
        $email = strtolower(trim($part));

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[$email] = $email;
        }
    }

    return array_values($emails);
}

/**
 * -----------------------------------------------------------------------------
 * Audit and duplicate prevention
 * -----------------------------------------------------------------------------
 */

function dc_clense_audit_exists(string $action, int $eventId): bool
{
    if (!dc_clense_table_exists('audit_log')) {
        return false;
    }

    try {
        $stmt = db()->prepare("
            SELECT id
            FROM audit_log
            WHERE action = :action
              AND entity_id = :entity_id
              AND entity_type IN ('calendar_event', 'event')
            LIMIT 1
        ");
        $stmt->execute([
            'action' => $action,
            'entity_id' => $eventId,
        ]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function dc_clense_audit(string $action, int $eventId, ?int $groupId = null, array $details = []): void
{
    if (!dc_clense_table_exists('audit_log')) {
        return;
    }

    $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $data = [
        'actor_type' => 'system',
        'actor_person_id' => null,
        'admin_user_id' => null,
        'group_id' => $groupId,
        'entity_type' => 'calendar_event',
        'entity_id' => $eventId,
        'action' => $action,
        'details_json' => $detailsJson,
        'details' => $detailsJson,
        'ip_address' => null,
        'user_agent' => 'dc-data-clense-cron',
        'created_at' => date('Y-m-d H:i:s'),
    ];

    try {
        dc_clense_insert_compatible('audit_log', $data);
    } catch (Throwable $e) {
        dc_clense_log('Audit skipped: ' . $e->getMessage());
    }
}

function dc_clense_queue_duplicate_exists(string $notificationType, int $eventId, string $toEmail): bool
{
    if (!dc_clense_table_exists('email_queue')) {
        return false;
    }

    $columns = dc_clense_columns('email_queue');

    if (!in_array('to_email', $columns, true)) {
        return false;
    }

    $where = ['LOWER(to_email) = :to_email'];
    $params = ['to_email' => strtolower($toEmail)];

    if (in_array('notification_type', $columns, true)) {
        $where[] = 'notification_type = :notification_type';
        $params['notification_type'] = $notificationType;
    }

    if (in_array('related_entity_type', $columns, true)) {
        $where[] = "related_entity_type = 'calendar_event'";
    }

    if (in_array('related_entity_id', $columns, true)) {
        $where[] = 'related_entity_id = :event_id';
        $params['event_id'] = $eventId;
    } elseif (in_array('body', $columns, true)) {
        $where[] = 'body LIKE :event_marker';
        $params['event_marker'] = '%Event ID: ' . $eventId . '%';
    }

    if (in_array('status', $columns, true)) {
        $where[] = "status IN ('pending', 'processing', 'sent')";
    }

    try {
        $stmt = db()->prepare("
            SELECT id
            FROM email_queue
            WHERE " . implode(' AND ', $where) . "
            LIMIT 1
        ");
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * -----------------------------------------------------------------------------
 * Email queue
 * -----------------------------------------------------------------------------
 */

function dc_clense_queue_email(
    string $toEmail,
    ?string $toName,
    string $subject,
    string $plainBody,
    string $notificationType,
    int $eventId
): bool {
    $toEmail = strtolower(trim($toEmail));

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        dc_clense_log("Skipped invalid email for event #{$eventId}: {$toEmail}");
        return false;
    }

    if (!dc_clense_table_exists('email_queue')) {
        throw new RuntimeException('email_queue table does not exist.');
    }

    if (dc_clense_queue_duplicate_exists($notificationType, $eventId, $toEmail)) {
        dc_clense_log("Skipped duplicate queued email: {$notificationType} event #{$eventId} to {$toEmail}");
        return false;
    }

    $plainBody = trim($plainBody) . "\n\nEvent ID: {$eventId}";
    $htmlBody = dc_clense_html_from_text($plainBody);

    $data = [
        'to_email' => $toEmail,
        'to_name' => $toName,
        'subject' => $subject,
        'body' => $plainBody,
        'body_html' => $htmlBody,
        'body_markdown' => null,
        'status' => 'pending',
        'attempt_count' => 0,
        'notification_type' => $notificationType,
        'related_entity_type' => 'calendar_event',
        'related_entity_id' => $eventId,
        'created_by_person_id' => null,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    $queueId = dc_clense_insert_compatible('email_queue', $data);

    dc_clense_log("Queued email #{$queueId}: {$notificationType} event #{$eventId} to {$toEmail}");

    return true;
}

/**
 * -----------------------------------------------------------------------------
 * Risk assessment mapping cleanup
 * -----------------------------------------------------------------------------
 */

function dc_clense_find_event_risk_mapping(): ?array
{
    static $mapping = null;
    static $checked = false;

    if ($checked) {
        return $mapping;
    }

    $checked = true;

    $preferredTables = [
        'event_risk_assessments',
        'calendar_event_risk_assessments',
        'calendar_event_risk_assessment_links',
        'event_risk_assessment_links',
        'risk_assessment_event_links',
        'event_risk_links',
    ];

    foreach ($preferredTables as $table) {
        if (!dc_clense_table_exists($table)) {
            continue;
        }

        if (dc_clense_column_exists($table, 'event_id') && dc_clense_column_exists($table, 'risk_assessment_id')) {
            return $mapping = [
                'table' => $table,
                'event_column' => 'event_id',
                'risk_column' => 'risk_assessment_id',
            ];
        }

        if (dc_clense_column_exists($table, 'calendar_event_id') && dc_clense_column_exists($table, 'risk_assessment_id')) {
            return $mapping = [
                'table' => $table,
                'event_column' => 'calendar_event_id',
                'risk_column' => 'risk_assessment_id',
            ];
        }
    }

    try {
        $stmt = db()->query("
            SELECT DISTINCT TABLE_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND COLUMN_NAME IN ('event_id', 'calendar_event_id', 'risk_assessment_id')
            ORDER BY TABLE_NAME ASC
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $table = (string) $table;

            if (!preg_match('/risk|assessment|event/i', $table)) {
                continue;
            }

            if (dc_clense_column_exists($table, 'event_id') && dc_clense_column_exists($table, 'risk_assessment_id')) {
                return $mapping = [
                    'table' => $table,
                    'event_column' => 'event_id',
                    'risk_column' => 'risk_assessment_id',
                ];
            }

            if (dc_clense_column_exists($table, 'calendar_event_id') && dc_clense_column_exists($table, 'risk_assessment_id')) {
                return $mapping = [
                    'table' => $table,
                    'event_column' => 'calendar_event_id',
                    'risk_column' => 'risk_assessment_id',
                ];
            }
        }
    } catch (Throwable $e) {
        return $mapping = null;
    }

    return $mapping = null;
}

function dc_clense_delete_event_risk_links(array $eventIds): int
{
    $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds))));

    if (!$eventIds) {
        return 0;
    }

    $mapping = dc_clense_find_event_risk_mapping();

    if (!$mapping) {
        return 0;
    }

    $table = dc_clense_quote_identifier((string) $mapping['table']);
    $eventColumn = dc_clense_quote_identifier((string) $mapping['event_column']);
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));

    $stmt = db()->prepare("
        DELETE FROM {$table}
        WHERE {$eventColumn} IN ({$placeholders})
    ");
    $stmt->execute($eventIds);

    return $stmt->rowCount();
}

/**
 * -----------------------------------------------------------------------------
 * Recipients
 * -----------------------------------------------------------------------------
 */

function dc_clense_reviewer_recipients(): array
{
    $recipients = [];

    $configured = dc_clense_get_setting('event_notification_recipients', '');

    foreach (dc_clense_split_emails($configured) as $email) {
        $recipients[$email] = [
            'email' => $email,
            'name' => null,
            'source' => 'app_settings',
        ];
    }

    /*
     * Pull district admins/reviewers from group memberships where available.
     */
    try {
        $stmt = db()->query("
            SELECT DISTINCT
                p.full_name,
                p.primary_email
            FROM group_memberships gm
            JOIN people p
              ON p.id = gm.person_id
            WHERE gm.status = 'active'
              AND p.status = 'active'
              AND p.primary_email IS NOT NULL
              AND p.primary_email <> ''
              AND gm.access_level IN ('district_reviewer', 'district_admin', 'system_admin')
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $email = strtolower(trim((string) $row['primary_email']));

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[$email] = [
                    'email' => $email,
                    'name' => (string) ($row['full_name'] ?? ''),
                    'source' => 'group_memberships',
                ];
            }
        }
    } catch (Throwable $e) {
        dc_clense_log('Reviewer lookup from group_memberships skipped: ' . $e->getMessage());
    }

    /*
     * Pull district admins/reviewers from people.highest_access_level where available.
     */
    if (dc_clense_column_exists('people', 'highest_access_level')) {
        try {
            $stmt = db()->query("
                SELECT DISTINCT
                    full_name,
                    primary_email
                FROM people
                WHERE status = 'active'
                  AND primary_email IS NOT NULL
                  AND primary_email <> ''
                  AND highest_access_level IN ('district_reviewer', 'district_admin', 'system_admin')
            ");

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $email = strtolower(trim((string) $row['primary_email']));

                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $recipients[$email] = [
                        'email' => $email,
                        'name' => (string) ($row['full_name'] ?? ''),
                        'source' => 'people.highest_access_level',
                    ];
                }
            }
        } catch (Throwable $e) {
            dc_clense_log('Reviewer lookup from people.highest_access_level skipped: ' . $e->getMessage());
        }
    }

    return array_values($recipients);
}

/**
 * -----------------------------------------------------------------------------
 * Reminder queueing
 * -----------------------------------------------------------------------------
 */

function dc_clense_queue_draft_reminder(array $event, string $type): bool
{
    $eventId = (int) $event['id'];
    $groupId = (int) $event['group_id'];

    $action = match ($type) {
        'day_before' => 'calendar_draft_reminder_day_before_queued',
        'eight_day' => 'calendar_draft_reminder_8_days_queued',
        default => throw new RuntimeException('Unknown draft reminder type.'),
    };

    if (dc_clense_audit_exists($action, $eventId)) {
        dc_clense_log("Skipped already-audited draft reminder {$type} for event #{$eventId}");
        return false;
    }

    $recipient = strtolower(trim((string) ($event['leader_email'] ?? '')));

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        dc_clense_log("Skipped draft reminder for event #{$eventId}; no valid leader_email.");
        return false;
    }

    $eventTitle = (string) ($event['title'] ?? 'Untitled event');
    $groupName = (string) ($event['group_name'] ?? 'Unknown Group');
    $leaderName = trim((string) ($event['leader_name'] ?? ''));
    $helloName = $leaderName !== '' ? $leaderName : 'there';
    $start = dc_clense_format_date((string) ($event['starts_at'] ?? ''));
    $eventUrl = dc_clense_event_url($eventId);

    if ($type === 'day_before') {
        $subject = 'Draft event still not submitted: ' . $eventTitle;
        $body = "Hello {$helloName},\n\n"
            . "Your District Calendar event is still saved as a draft and has not been submitted for review.\n\n"
            . "Event: {$eventTitle}\n"
            . "Group: {$groupName}\n"
            . "Start: {$start}\n\n"
            . "If the event is still going ahead, please open the draft and submit it as soon as possible:\n"
            . "{$eventUrl}";
    } else {
        $subject = 'Reminder: submit your draft District Calendar event';
        $body = "Hello {$helloName},\n\n"
            . "You have a District Calendar event saved as a draft with less than 8 days to go.\n\n"
            . "Event: {$eventTitle}\n"
            . "Group: {$groupName}\n"
            . "Start: {$start}\n\n"
            . "Please submit it for review if the event is still going ahead:\n"
            . "{$eventUrl}";
    }

    $queued = dc_clense_queue_email(
        $recipient,
        $leaderName !== '' ? $leaderName : null,
        $subject,
        $body,
        'calendar_draft_reminder_' . $type,
        $eventId
    );

    if ($queued) {
        dc_clense_audit($action, $eventId, $groupId, [
            'recipient' => $recipient,
            'type' => $type,
            'title' => $eventTitle,
            'starts_at' => $event['starts_at'] ?? null,
        ]);
    }

    return $queued;
}

function dc_clense_queue_review_reminder(array $event): int
{
    $eventId = (int) $event['id'];
    $groupId = (int) $event['group_id'];
    $action = 'calendar_review_reminder_queued';

    if (dc_clense_audit_exists($action, $eventId)) {
        dc_clense_log("Skipped already-audited review reminder for event #{$eventId}");
        return 0;
    }

    $recipients = dc_clense_reviewer_recipients();

    if (!$recipients) {
        dc_clense_log("No reviewer recipients found for event #{$eventId}");
        return 0;
    }

    $eventTitle = (string) ($event['title'] ?? 'Untitled event');
    $groupName = (string) ($event['group_name'] ?? 'Unknown Group');
    $status = (string) ($event['status'] ?? 'unknown');
    $start = dc_clense_format_date((string) ($event['starts_at'] ?? ''));
    $eventUrl = dc_clense_event_url($eventId);

    $subject = 'Event waiting for review: ' . $eventTitle;

    $body = "A District Calendar event is waiting for review and is coming up soon.\n\n"
        . "Event: {$eventTitle}\n"
        . "Group: {$groupName}\n"
        . "Start: {$start}\n"
        . "Status: {$status}\n\n"
        . "Review the event here:\n"
        . "{$eventUrl}";

    $queuedCount = 0;

    foreach ($recipients as $recipient) {
        $queued = dc_clense_queue_email(
            (string) $recipient['email'],
            $recipient['name'] ?: null,
            $subject,
            $body,
            'calendar_review_reminder',
            $eventId
        );

        if ($queued) {
            $queuedCount++;
        }
    }

    if ($queuedCount > 0) {
        dc_clense_audit($action, $eventId, $groupId, [
            'recipient_count' => $queuedCount,
            'title' => $eventTitle,
            'starts_at' => $event['starts_at'] ?? null,
            'status' => $status,
        ]);
    }

    return $queuedCount;
}

/**
 * -----------------------------------------------------------------------------
 * Main runner
 * -----------------------------------------------------------------------------
 */

try {
    dc_clense_log('Starting District Calendar data clense.');

    if (!dc_clense_table_exists('calendar_events')) {
        throw new RuntimeException('calendar_events table not found.');
    }

    if (!dc_clense_table_exists('email_queue')) {
        throw new RuntimeException('email_queue table not found.');
    }

    $deleteAfterDays = (int) dc_clense_config('DC_EVENT_DELETE_AFTER_DAYS', 365);
    $deleteAfterDays = max(365, $deleteAfterDays);

    $draftReminderDays = (int) dc_clense_config('DC_DRAFT_REMINDER_DAYS_BEFORE_START', 8);
    $draftReminderDays = max(2, min($draftReminderDays, 30));

    $reviewReminderDays = (int) dc_clense_config('DC_REVIEW_REMINDER_DAYS_BEFORE_START', 7);
    $reviewReminderDays = max(1, min($reviewReminderDays, 30));

    dc_clense_log("Delete threshold: events ended more than {$deleteAfterDays} days ago.");
    dc_clense_log("Draft reminder threshold: less than {$draftReminderDays} days before start.");
    dc_clense_log("Review reminder threshold: less than {$reviewReminderDays} days before start.");

    /*
     * -------------------------------------------------------------------------
     * 1. Queue day-before draft reminders
     * -------------------------------------------------------------------------
     */
    $stmt = $pdo->prepare("
        SELECT
            ce.*,
            g.group_name
        FROM calendar_events ce
        JOIN groups g
          ON g.id = ce.group_id
        WHERE ce.status = 'draft'
          AND ce.leader_email IS NOT NULL
          AND ce.leader_email <> ''
          AND ce.starts_at >= DATE_ADD(CURDATE(), INTERVAL 1 DAY)
          AND ce.starts_at < DATE_ADD(CURDATE(), INTERVAL 2 DAY)
        ORDER BY ce.starts_at ASC
    ");
    $stmt->execute();
    $dayBeforeDrafts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    dc_clense_log('Day-before draft candidates: ' . count($dayBeforeDrafts));

    $dayBeforeQueued = 0;

    foreach ($dayBeforeDrafts as $event) {
        if (dc_clense_queue_draft_reminder($event, 'day_before')) {
            $dayBeforeQueued++;
        }
    }

    dc_clense_log('Day-before draft reminders queued: ' . $dayBeforeQueued);

    /*
     * -------------------------------------------------------------------------
     * 2. Queue less-than-8-days draft reminders
     * Excludes tomorrow because those get the stronger day-before reminder.
     * -------------------------------------------------------------------------
     */
    $stmt = $pdo->prepare("
        SELECT
            ce.*,
            g.group_name
        FROM calendar_events ce
        JOIN groups g
          ON g.id = ce.group_id
        WHERE ce.status = 'draft'
          AND ce.leader_email IS NOT NULL
          AND ce.leader_email <> ''
          AND ce.starts_at >= DATE_ADD(CURDATE(), INTERVAL 2 DAY)
          AND ce.starts_at < DATE_ADD(NOW(), INTERVAL {$draftReminderDays} DAY)
        ORDER BY ce.starts_at ASC
    ");
    $stmt->execute();
    $eightDayDrafts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    dc_clense_log('Less-than-' . $draftReminderDays . '-days draft candidates: ' . count($eightDayDrafts));

    $eightDayQueued = 0;

    foreach ($eightDayDrafts as $event) {
        if (dc_clense_queue_draft_reminder($event, 'eight_day')) {
            $eightDayQueued++;
        }
    }

    dc_clense_log('Less-than-' . $draftReminderDays . '-days draft reminders queued: ' . $eightDayQueued);

    /*
     * -------------------------------------------------------------------------
     * 3. Queue reviewer reminders
     * -------------------------------------------------------------------------
     */
    $stmt = $pdo->prepare("
        SELECT
            ce.*,
            g.group_name
        FROM calendar_events ce
        JOIN groups g
          ON g.id = ce.group_id
        WHERE ce.status IN ('submitted', 'under_review', 'changes_requested')
          AND ce.starts_at >= NOW()
          AND ce.starts_at < DATE_ADD(NOW(), INTERVAL {$reviewReminderDays} DAY)
        ORDER BY ce.starts_at ASC
    ");
    $stmt->execute();
    $reviewEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    dc_clense_log('Reviewer reminder candidates: ' . count($reviewEvents));

    $reviewEventsQueued = 0;
    $reviewEmailsQueued = 0;

    foreach ($reviewEvents as $event) {
        $queued = dc_clense_queue_review_reminder($event);

        if ($queued > 0) {
            $reviewEventsQueued++;
            $reviewEmailsQueued += $queued;
        }
    }

    dc_clense_log('Reviewer reminder events queued: ' . $reviewEventsQueued);
    dc_clense_log('Reviewer reminder emails queued: ' . $reviewEmailsQueued);

    /*
     * -------------------------------------------------------------------------
     * 4. Delete old draft/cancelled/rejected events only after one year minimum
     * -------------------------------------------------------------------------
     */
    $stmt = $pdo->prepare("
        SELECT id
        FROM calendar_events
        WHERE status IN ('draft', 'cancelled', 'rejected')
          AND ends_at < DATE_SUB(NOW(), INTERVAL {$deleteAfterDays} DAY)
    ");
    $stmt->execute();

    $oldEventIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    dc_clense_log('Old draft/cancelled/rejected candidates for deletion: ' . count($oldEventIds));

    $deletedRiskLinks = 0;
    $deletedEvents = 0;

    if ($oldEventIds) {
        $deletedRiskLinks = dc_clense_delete_event_risk_links($oldEventIds);

        $placeholders = implode(',', array_fill(0, count($oldEventIds), '?'));

        $deleteStmt = $pdo->prepare("
            DELETE FROM calendar_events
            WHERE id IN ({$placeholders})
              AND status IN ('draft', 'cancelled', 'rejected')
              AND ends_at < DATE_SUB(NOW(), INTERVAL {$deleteAfterDays} DAY)
        ");
        $deleteStmt->execute($oldEventIds);

        $deletedEvents = $deleteStmt->rowCount();
    }

    dc_clense_log('Deleted old risk assessment links: ' . $deletedRiskLinks);
    dc_clense_log('Deleted old draft/cancelled/rejected events: ' . $deletedEvents);

    dc_clense_log('District Calendar data clense completed.');
    exit(0);
} catch (Throwable $e) {
    dc_clense_log('ERROR: ' . $e->getMessage());
    exit(1);
}