<?php
declare(strict_types=1);

// if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../auth.php';

$pdo = db();

function cron_log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function queue_draft_reminder(PDO $pdo, array $event, string $type): void
{
    $eventId = (int)$event['id'];

    $action = match ($type) {
        'eight_day' => 'draft_reminder_8_days_queued',
        'day_before' => 'draft_reminder_day_before_queued',
        default => throw new RuntimeException('Invalid reminder type.'),
    };

    $check = $pdo->prepare("
        SELECT id
        FROM audit_log
        WHERE entity_type = 'event'
          AND entity_id = :event_id
          AND action = :action
        LIMIT 1
    ");
    $check->execute([
        'event_id' => $eventId,
        'action' => $action,
    ]);

    if ($check->fetchColumn()) {
        return;
    }

    $eventLink = APP_URL . BASE_URL . '/add-event.php?event_id=' . $eventId;

    if ($type === 'day_before') {
        $subject = 'Draft event still not submitted: ' . $event['event_title'];
        $body = "Hello {$event['contact_name']},\n\n"
            . "Your Away From Hut event is still saved as a draft and has not been submitted for approval.\n\n"
            . "Event: {$event['event_title']}\n"
            . "Group: {$event['group_name']}\n"
            . "Start: " . date('d M Y H:i', strtotime((string)$event['starts_at'])) . "\n\n"
            . "If the event is still going ahead, please open the draft and submit it for approval as soon as possible:\n"
            . "{$eventLink}\n";
    } else {
        $subject = 'Reminder: submit your draft Away From Hut event';
        $body = "Hello {$event['contact_name']},\n\n"
            . "You have an Away From Hut event saved as a draft with less than 8 days to go.\n\n"
            . "Event: {$event['event_title']}\n"
            . "Group: {$event['group_name']}\n"
            . "Start: " . date('d M Y H:i', strtotime((string)$event['starts_at'])) . "\n\n"
            . "Please submit it for approval if the event is still going ahead:\n"
            . "{$eventLink}\n";
    }

    queue_email(
        (string)$event['contact_email'],
        $subject,
        nl2br(e($body))
    );

    $audit = $pdo->prepare("
        INSERT INTO audit_log (
            actor_type,
            admin_user_id,
            group_id,
            entity_type,
            entity_id,
            action,
            details,
            created_at
        ) VALUES (
            'system',
            NULL,
            :group_id,
            'event',
            :event_id,
            :action,
            :details,
            NOW()
        )
    ");

    $audit->execute([
        'group_id' => (int)$event['group_id'],
        'event_id' => $eventId,
        'action' => $action,
        'details' => json_encode([
            'recipient' => $event['contact_email'],
            'event_title' => $event['event_title'],
            'starts_at' => $event['starts_at'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

try {
    cron_log('Starting draft event cleanup.');

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | Delete draft events that have passed
    |--------------------------------------------------------------------------
    */
    $expired = $pdo->prepare("
        SELECT id
        FROM events
        WHERE status = 'draft'
          AND ends_at < NOW()
    ");
    $expired->execute();
    $expiredEventIds = array_map('intval', $expired->fetchAll(PDO::FETCH_COLUMN));

    if ($expiredEventIds) {
        $placeholders = implode(',', array_fill(0, count($expiredEventIds), '?'));

        $deleteLinks = $pdo->prepare("
            DELETE FROM event_risk_assessments
            WHERE event_id IN ($placeholders)
        ");
        $deleteLinks->execute($expiredEventIds);

        $deleteEvents = $pdo->prepare("
            DELETE FROM events
            WHERE id IN ($placeholders)
              AND status = 'draft'
              AND ends_at < NOW()
        ");
        $deleteEvents->execute($expiredEventIds);
    }

    cron_log('Deleted expired draft events: ' . count($expiredEventIds));

    /*
    |--------------------------------------------------------------------------
    | Day-before reminders
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT
            e.*,
            g.group_name
        FROM events e
        INNER JOIN groups g ON g.id = e.group_id
        WHERE e.status = 'draft'
          AND e.contact_email IS NOT NULL
          AND e.contact_email <> ''
          AND DATE(e.starts_at) = DATE(DATE_ADD(NOW(), INTERVAL 1 DAY))
    ");
    $stmt->execute();
    $dayBeforeEvents = $stmt->fetchAll();

    foreach ($dayBeforeEvents as $event) {
        queue_draft_reminder($pdo, $event, 'day_before');
    }

    cron_log('Queued day-before draft reminders: ' . count($dayBeforeEvents));

    /*
    |--------------------------------------------------------------------------
    | Less-than-8-days reminders
    | Excludes tomorrow because those get the stronger day-before reminder.
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT
            e.*,
            g.group_name
        FROM events e
        INNER JOIN groups g ON g.id = e.group_id
        WHERE e.status = 'draft'
          AND e.contact_email IS NOT NULL
          AND e.contact_email <> ''
          AND e.starts_at > DATE_ADD(NOW(), INTERVAL 1 DAY)
          AND e.starts_at < DATE_ADD(NOW(), INTERVAL 8 DAY)
    ");
    $stmt->execute();
    $eightDayEvents = $stmt->fetchAll();

    foreach ($eightDayEvents as $event) {
        queue_draft_reminder($pdo, $event, 'eight_day');
    }

    cron_log('Queued less-than-8-days draft reminders: ' . count($eightDayEvents));

    $pdo->commit();

    cron_log('Draft event cleanup completed.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    cron_log('ERROR: ' . $e->getMessage());
    exit(1);
}