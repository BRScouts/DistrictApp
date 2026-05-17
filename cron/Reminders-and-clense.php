<?php

declare(strict_types=1);

/**
 * District Calendar data cleanse cron.
 *
 * Run from CLI:
 * php /home/brscouts/app.irvalscouts.org.uk/dc/cron/data-cleanse.php
 */


require_once __DIR__ . '/../app/bootstrap.php';

$pdo = db();

function dc_cron_log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function dc_cron_config(string $key, mixed $default = null): mixed
{
    return defined($key) ? constant($key) : $default;
}

function dc_cron_app_url(): string
{
    if (defined('APP_URL')) {
        return rtrim((string) APP_URL, '/');
    }

    if (function_exists('app_config')) {
        return rtrim((string) app_config('APP_URL', 'https://app.irvalscouts.org.uk'), '/');
    }

    return 'https://app.irvalscouts.org.uk';
}

function dc_cron_table_exists(string $table): bool
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

function dc_cron_column_exists(string $table, string $column): bool
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

function dc_cron_columns(string $table): array
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

function dc_cron_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function dc_cron_insert_compatible(string $table, array $data): int
{
    $columns = dc_cron_columns($table);

    if (!$columns) {
        throw new RuntimeException("Table {$table} does not exist or has no columns.");
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

    $quotedColumns = array_map('dc_cron_quote_identifier', array_keys($insert));
    $placeholders = array_map(static fn(string $column): string => ':' . $column, array_keys($insert));

    $stmt = db()->prepare("
        INSERT INTO " . dc_cron_quote_identifier($table) . "
        (" . implode(', ', $quotedColumns) . ")
        VALUES
        (" . implode(', ', $placeholders) . ")
    ");
    $stmt->execute($insert);

    return (int) db()->lastInsertId();
}

function dc_cron_audit_exists(string $action, int $eventId): bool
{
    if (!dc_cron_table_exists('audit_log')) {
        return false;
    }

    try {
        $stmt = db()->prepare("
            SELECT id
            FROM audit_log
            WHERE entity_id = :entity_id
              AND action = :action
              AND entity_type IN ('calendar_event', 'event')
            LIMIT 1
        ");
        $stmt->execute([
            'entity_id' => $eventId,
            'action' => $action,
        ]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function dc_cron_audit(string $action, int $eventId, ?int $groupId = null, array $details = []): void
{
    if (!dc_cron_table_exists('audit_log')) {
        return;
    }

    $data = [
        'actor_type' => 'system',
        'actor_person_id' => null,
        'admin_user_id' => null,
        'group_id' => $groupId,
        'entity_type' => 'calendar_event',
        'entity_id' => $eventId,
        'action' => $action,
        'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'ip_address' => null,
        'user_agent' => 'dc-data-cleanse-cron',
        'created_at' => date('Y-m-d H:i:s'),
    ];

    try {
        dc_cron_insert_compatible('audit_log', $data);
    } catch (Throwable $e) {
        dc_cron_log('Audit skipped: ' . $e->getMessage());
    }
}

function dc_cron_email_html(string $body): string
{
    $body = trim($body);
    $escaped = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<p>' . str_replace("\n", '<br>', $escaped) . '</p>';
}

function dc_cron_queue_email(
    string $toEmail,
    ?string $toName,
    string $subject,
    string $plainBody,
    string $notificationType,
    int $eventId
): void {
    $toEmail = strtolower(trim($toEmail));

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $htmlBody = dc_cron_email_html($plainBody);

    $data = [
        'to_email' => $toEmail,
        'to_name' => $toName,
        'subject' => $subject,
        'body' => $plainBody,
        'body_html' => $htmlBody,
        'status' => 'pending',
        'notification_type' => $notificationType,
        'related_entity_type' => 'calendar_event',
        'related_entity_id' => $eventId,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    dc_cron_insert_compatible('email_queue', $data);
}

function dc_cron_event_url(int $eventId): string
{
    return dc_cron_app_url() . '/dc/manage-event.php?id=' . $eventId;
}

function dc_cron_public_calendar_url(): string
{
    return dc_cron_app_url() . '/dc/';
}

function dc_cron_find_event_risk_mapping(): ?array
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
        if (!dc_cron_table_exists($table)) {
            continue;
        }

        if (dc_cron_column_exists($table, 'event_id') && dc_cron_column_exists($table, 'risk_assessment_id')) {
            return $mapping = [
                'table' => $table,
                'event_column' => 'event_id',
                'risk_column' => 'risk_assessment_id',
            ];
        }

        if (dc_cron_column_exists($table, 'calendar_event_id') && dc_cron_column_exists($table, 'risk_assessment_id')) {
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

            if (dc_cron_column_exists($table, 'event_id') && dc_cron_column_exists($table, 'risk_assessment_id')) {
                return $mapping = [
                    'table' => $table,
                    'event_column' => 'event_id',
                    'risk_column' => 'risk_assessment_id',
                ];
            }

            if (dc_cron_column_exists($table, 'calendar_event_id') && dc_cron_column_exists($table, 'risk_assessment_id')) {
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

function dc_cron_delete_event_risk_links(array $eventIds): int
{
    $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds))));

    if (!$eventIds) {
        return 0;
    }

    $mapping = dc_cron_find_event_risk_mapping();

    if (!$mapping) {
        return 0;
    }

    $table = dc_cron_quote_identifier((string) $mapping['table']);
    $eventColumn = dc_cron_quote_identifier((string) $mapping['event_column']);
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));

    $stmt = db()->prepare("
        DELETE FROM {$table}
        WHERE {$eventColumn} IN ({$placeholders})
    ");
    $stmt->execute($eventIds);

    return $stmt->rowCount();
}

function dc_cron_fetch_reviewer_recipients(): array
{
    $configured = '';

    if (function_exists('dc_setting')) {
        $configured = (string) dc_setting('event_notification_recipients', '');
    }

    $recipients = [];

    foreach (preg_split('/[;,\n]+/', $configured) ?: [] as $email) {
        $email = strtolower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $recipients[$email] = [
                'email' => $email,
                'name' => null,
            ];
        }
    }

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
                ];
            }
        }
    } catch (Throwable $e) {
        // Some installs may not hold district permissions at membership level.
    }

    return array_values($recipients);
}

function dc_cron_queue_draft_reminder(array $event, string $type): bool
{
    $eventId = (int) $event['id'];

    $action = match ($type) {
        'eight_day' => 'calendar_draft_reminder_8_days_queued',
        'day_before' => 'calendar_draft_reminder_day_before_queued',
        default => throw new RuntimeException('Invalid reminder type.'),
    };

    if (dc_cron_audit_exists($action, $eventId)) {
        return false;
    }

    $recipient = strtolower(trim((string) ($event['leader_email'] ?? '')));

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $leaderName = trim((string) ($event['leader_name'] ?? ''));
    $helloName = $leaderName !== '' ? $leaderName : 'there';
    $eventTitle = (string) ($event['title'] ?? 'Untitled event');
    $groupName = (string) ($event['group_name'] ?? 'Unknown Group');
    $start = date('j M Y H:i', strtotime((string) $event['starts_at']));
    $eventUrl = dc_cron_event_url($eventId);

    if ($type === 'day_before') {
        $subject = 'Draft event still not submitted: ' . $eventTitle;
        $body = "Hello {$helloName},\n\n"
            . "Your District Calendar event is still saved as a draft and has not been submitted for review.\n\n"
            . "Event: {$eventTitle}\n"
            . "Group: {$groupName}\n"
            . "Start: {$start}\n\n"
            . "If the event is still going ahead, please open the draft and submit it as soon as possible:\n"
            . "{$eventUrl}\n";
    } else {
        $subject = 'Reminder: submit your draft District Calendar event';
        $body = "Hello {$helloName},\n\n"
            . "You have a District Calendar event saved as a draft with less than 8 days to g until the scheduled start.\n\n"
            . "Event: {$eventTitle}\n"
            . "Group: {$groupName}\n"
            . "Start: {$start}\n\n"
            . "Please submit it for review if the event is still going ahead:\n"
            . "{$eventUrl}\n";
    }

    dc_cron_queue_email(
        $recipient,
        $leaderName !== '' ? $leaderName : null,
        $subject,
        $body,
        'calendar_draft_reminder',
        $eventId
    );

    dc_cron_audit($action, $eventId, (int) $event['group_id'], [
        'recipient' => $recipient,
        'event_title' => $eventTitle,
        'starts_at' => $event['starts_at'],
    ]);

    return true;
}

function dc_cron_queue_reviewer_reminder(array $event): int
{
    $eventId = (int) $event['id'];
    $action = 'calendar_review_reminder_queued';

    if (dc_cron_audit_exists($action, $eventId)) {
        return 0;
    }

    $recipients = dc_cron_fetch_reviewer_recipients();

    if (!$recipients) {
        return 0;
    }

    $eventTitle = (string) ($event['title'] ?? 'Untitled event');
    $groupName = (string) ($event['group_name'] ?? 'Unknown Group');
    $start = date('j M Y H:i', strtotime((string) $event['starts_at']));
    $eventUrl = dc_cron_event_url($eventId);

    $subject = 'Event waiting for review: ' . $eventTitle;
    $body = "A District Calendar event is waiting for review and is coming up soon.\n\n"
        . "Event: {$eventTitle}\n"
        . "Group: {$groupName}\n"
        . "Start: {$start}\n"
        . "Status: {$event['status']}\n\n"
        . "Review the event here:\n"
        . "{$eventUrl}\n";

    $queued = 0;

    foreach ($recipients as $recipient) {
        dc_cron_queue_email(
            $recipient['email'],
            $recipient['name'] ?: null,
            $subject,
            $body,
            'calendar_review_reminder',
            $eventId
        );

        $queued++;
    }

    dc_cron_audit($action, $eventId, (int) $event['group_id'], [
        'recipient_count' => $queued,
        'event_title' => $eventTitle,
        'starts_at' => $event['starts_at'],
    ]);

    return $queued;
}

try {
    dc_cron_log('Starting District Calendar data cleanse.');

    if (!dc_cron_table_exists('calendar_events')) {
        throw new RuntimeException('calendar_events table not found.');
    }

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | 1. Delete expired draft events
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT id
        FROM calendar_events
        WHERE status = 'draft'
          AND ends_at < NOW()
    ");
    $stmt->execute();

    $expiredDraftIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $deletedRiskLinks = 0;

    if ($expiredDraftIds) {
        $deletedRiskLinks = dc_cron_delete_event_risk_links($expiredDraftIds);

        $placeholders = implode(',', array_fill(0, count($expiredDraftIds), '?'));

        $deleteEvents = $pdo->prepare("
            DELETE FROM calendar_events
            WHERE id IN ({$placeholders})
              AND status = 'draft'
              AND ends_at < NOW()
        ");
        $deleteEvents->execute($expiredDraftIds);
    }

    dc_cron_log('Deleted expired draft events: ' . count($expiredDraftIds));
    dc_cron_log('Deleted expired draft risk links: ' . $deletedRiskLinks);

    /*
    |--------------------------------------------------------------------------
    | 2. Day-before draft reminders
    |--------------------------------------------------------------------------
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
          AND DATE(ce.starts_at) = DATE(DATE_ADD(NOW(), INTERVAL 1 DAY))
    ");
    $stmt->execute();

    $dayBeforeDrafts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $dayBeforeQueued = 0;

    foreach ($dayBeforeDrafts as $event) {
        if (dc_cron_queue_draft_reminder($event, 'day_before')) {
            $dayBeforeQueued++;
        }
    }

    dc_cron_log('Queued day-before draft reminders: ' . $dayBeforeQueued);

    /*
    |--------------------------------------------------------------------------
    | 3. Less-than-8-days draft reminders
    | Excludes tomorrow because those get the stronger day-before reminder.
    |--------------------------------------------------------------------------
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
          AND ce.starts_at > DATE_ADD(NOW(), INTERVAL 1 DAY)
          AND ce.starts_at < DATE_ADD(NOW(), INTERVAL 8 DAY)
    ");
    $stmt->execute();

    $eightDayDrafts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $eightDayQueued = 0;

    foreach ($eightDayDrafts as $event) {
        if (dc_cron_queue_draft_reminder($event, 'eight_day')) {
            $eightDayQueued++;
        }
    }

    dc_cron_log('Queued less-than-8-days draft reminders: ' . $eightDayQueued);

    /*
    |--------------------------------------------------------------------------
    | 4. Reviewer reminders for events still waiting review
    |--------------------------------------------------------------------------
    */
    $reviewReminderDays = (int) dc_cron_config('DC_REVIEW_REMINDER_DAYS_BEFORE_START', 7);
    $reviewReminderDays = max(1, min($reviewReminderDays, 30));

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
    ");
    $stmt->execute();

    $reviewEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $reviewEmailsQueued = 0;
    $reviewEventsQueued = 0;

    foreach ($reviewEvents as $event) {
        $queued = dc_cron_queue_reviewer_reminder($event);

        if ($queued > 0) {
            $reviewEventsQueued++;
            $reviewEmailsQueued += $queued;
        }
    }

    dc_cron_log('Review reminder events queued: ' . $reviewEventsQueued);
    dc_cron_log('Review reminder emails queued: ' . $reviewEmailsQueued);

     
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    dc_cron_log('ERROR: ' . $e->getMessage());
    exit(1);
}