<?php

declare(strict_types=1);

/**
 * Cron: Leavers notification.
 *
 * Detects people who have been made inactive (status = 'inactive') and have
 * a Microsoft 365 account. Sends an email to support@irvalscouts.org.uk
 * informing them to disable the account.
 *
 * To avoid duplicate notifications, the script tracks which people have
 * already been reported via the `leaver_notified_at` column on the people
 * table (added automatically if missing), or via the audit_log table.
 *
 * Run manually:
 *   php /home/brscouts/app.irvalscouts.org.uk/cron/leavers_notification.php
 *
 * Suggested cron (once daily):
 *   30 6 * * * /usr/local/bin/php /home/brscouts/app.irvalscouts.org.uk/cron/leavers_notification.php >> /home/brscouts/app.irvalscouts.org.uk/storage/logs/leavers-notification.log 2>&1
 */

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = db();

// ─── Helpers ────────────────────────────────────────────────────────────────

function leavers_log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function leavers_config(string $key, ?string $fallbackKey = null, string $default = ''): string
{
    if (defined($key)) {
        $value = (string) constant($key);
        if ($value !== '') {
            return $value;
        }
    }

    if (function_exists('app_config')) {
        $value = (string) app_config($key, '');
        if ($value !== '') {
            return $value;
        }
    }

    if ($fallbackKey !== null && defined($fallbackKey)) {
        $value = (string) constant($fallbackKey);
        if ($value !== '') {
            return $value;
        }
    }

    if ($fallbackKey !== null && function_exists('app_config')) {
        $value = (string) app_config($fallbackKey, '');
        if ($value !== '') {
            return $value;
        }
    }

    return $default;
}

function leavers_table_exists(string $table): bool
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

function leavers_column_exists(string $table, string $column): bool
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

function leavers_table_columns(string $table): array
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

function leavers_escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─── Duplicate prevention ───────────────────────────────────────────────────

/**
 * Ensure the people table has a leaver_notified_at column for tracking.
 * If it can't be added, we fall back to checking the audit_log.
 */
function leavers_ensure_tracking_column(): bool
{
    if (leavers_column_exists('people', 'leaver_notified_at')) {
        return true;
    }

    try {
        db()->exec("ALTER TABLE people ADD COLUMN leaver_notified_at DATETIME NULL DEFAULT NULL");
        leavers_log('Added leaver_notified_at column to people table.');
        return true;
    } catch (Throwable $e) {
        leavers_log('WARNING: Could not add leaver_notified_at column (' . $e->getMessage() . '). Will use audit_log for dedup.');
        return false;
    }
}

/**
 * Check audit_log to see if we already sent a leaver notification for this person.
 */
function leavers_already_notified_via_audit(int $personId): bool
{
    if (!leavers_table_exists('audit_log')) {
        return false;
    }

    try {
        $stmt = db()->prepare("
            SELECT id
            FROM audit_log
            WHERE action = 'leaver_notification_sent'
              AND entity_type = 'person'
              AND entity_id = :person_id
            LIMIT 1
        ");
        $stmt->execute(['person_id' => $personId]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Record in audit_log that we sent a leaver notification.
 */
function leavers_audit(int $personId, array $details = []): void
{
    if (!leavers_table_exists('audit_log')) {
        return;
    }

    $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $data = [
        'actor_type' => 'system',
        'actor_person_id' => null,
        'action' => 'leaver_notification_sent',
        'entity_type' => 'person',
        'entity_id' => $personId,
        'details_json' => $detailsJson,
        'details' => $detailsJson,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    $columns = leavers_table_columns('audit_log');
    $insert = [];

    foreach ($data as $col => $val) {
        if (in_array($col, $columns, true)) {
            $insert[$col] = $val;
        }
    }

    if (!$insert) {
        return;
    }

    try {
        $fieldSql = implode(', ', array_map(fn(string $c) => '`' . str_replace('`', '``', $c) . '`', array_keys($insert)));
        $placeholderSql = implode(', ', array_map(fn(string $c) => ':' . $c, array_keys($insert)));

        $stmt = db()->prepare("INSERT INTO audit_log ({$fieldSql}) VALUES ({$placeholderSql})");
        $stmt->execute($insert);
    } catch (Throwable $e) {
        // Audit should not block the notification.
    }
}

// ─── Email helpers ──────────────────────────────────────────────────────────

function leavers_queue_email(string $toEmail, string $toName, string $subject, string $plainBody, string $htmlBody, int $personId): bool
{
    if (!leavers_table_exists('email_queue')) {
        return false;
    }

    $columns = leavers_table_columns('email_queue');

    $data = [
        'to_email' => strtolower(trim($toEmail)),
        'to_name' => $toName,
        'subject' => $subject,
        'body' => $plainBody,
        'body_text' => $plainBody,
        'body_html' => $htmlBody,
        'notification_type' => 'leaver_disable_account',
        'related_entity_type' => 'person',
        'related_entity_id' => $personId,
        'status' => 'pending',
        'attempt_count' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    $insert = [];
    foreach ($data as $col => $val) {
        if (in_array($col, $columns, true)) {
            $insert[$col] = $val;
        }
    }

    if (!$insert || !isset($insert['to_email'])) {
        return false;
    }

    $fieldSql = implode(', ', array_map(fn(string $c) => '`' . str_replace('`', '``', $c) . '`', array_keys($insert)));
    $placeholderSql = implode(', ', array_map(fn(string $c) => ':' . $c, array_keys($insert)));

    $stmt = db()->prepare("INSERT INTO email_queue ({$fieldSql}) VALUES ({$placeholderSql})");
    return $stmt->execute($insert);
}

function leavers_email_shell(string $title, string $previewText, string $bodyHtml): string
{
    return '<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . leavers_escape_html($title) . '</title>
</head>
<body style="margin:0;padding:0;background:#f3f2f1;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . leavers_escape_html($previewText) . '</div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f2f1;padding:24px 0;">
        <tr>
            <td align="center" style="padding:0 12px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:660px;background:#ffffff;border:2px solid #d8d8d8;">
                    <tr>
                        <td style="background:#4d0b93;color:#ffffff;padding:22px 24px;font-family:Arial,Helvetica,sans-serif;">
                            <div style="font-size:13px;line-height:18px;font-weight:bold;letter-spacing:.03em;text-transform:uppercase;color:#d9c6ff;">Irwell Valley Scout District</div>
                            <h1 style="margin:6px 0 0;font-size:25px;line-height:31px;font-weight:900;">' . leavers_escape_html($title) . '</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;font-family:Arial,Helvetica,sans-serif;color:#1d1d1b;font-size:16px;line-height:24px;">
                            ' . $bodyHtml . '
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f7f5fb;border-top:1px solid #e6e6e6;padding:16px 24px;font-family:Arial,Helvetica,sans-serif;color:#555555;font-size:13px;line-height:19px;">
                            This is an automated message from the Irwell Valley Leader Tool.<br>
                            Irwell Valley Scout District
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
}

function leavers_detail_table(array $rows): string
{
    $html = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;margin:18px 0;">';

    foreach ($rows as $label => $value) {
        $html .= '<tr>';
        $html .= '<td style="border:1px solid #d8d8d8;padding:10px;font-weight:bold;background:#f7f5fb;width:38%;">' . leavers_escape_html((string) $label) . '</td>';
        $html .= '<td style="border:1px solid #d8d8d8;padding:10px;">' . leavers_escape_html((string) $value) . '</td>';
        $html .= '</tr>';
    }

    return $html . '</table>';
}

// ─── Fetch inactive leavers ─────────────────────────────────────────────────

function leavers_fetch_new_leavers(bool $hasTrackingColumn): array
{
    $pdo = db();

    // Determine which column stores the M365 UPN / email on the people table.
    $upnColumn = null;
    foreach (['m365_user_principal_name', 'microsoft_user_principal_name', 'district_email'] as $candidate) {
        if (leavers_column_exists('people', $candidate)) {
            $upnColumn = $candidate;
            break;
        }
    }

    // Also check for the M365 user ID column.
    $m365IdColumn = null;
    foreach (['m365_user_id', 'microsoft_user_id'] as $candidate) {
        if (leavers_column_exists('people', $candidate)) {
            $m365IdColumn = $candidate;
            break;
        }
    }

    // We need at least one identifier to know they have an M365 account.
    if ($upnColumn === null && $m365IdColumn === null) {
        leavers_log('ERROR: No M365 identifier column found on people table (need m365_user_principal_name, microsoft_user_principal_name, district_email, m365_user_id, or microsoft_user_id).');
        return [];
    }

    // Build the condition for "has M365 account".
    $m365Conditions = [];
    if ($upnColumn !== null) {
        $m365Conditions[] = "(p.`{$upnColumn}` IS NOT NULL AND p.`{$upnColumn}` <> '')";
    }
    if ($m365IdColumn !== null) {
        $m365Conditions[] = "(p.`{$m365IdColumn}` IS NOT NULL AND p.`{$m365IdColumn}` <> '')";
    }
    $m365Where = '(' . implode(' OR ', $m365Conditions) . ')';

    // Build the "not yet notified" filter.
    if ($hasTrackingColumn) {
        $notifiedFilter = "AND p.leaver_notified_at IS NULL";
    } else {
        // Fallback: exclude anyone already in audit_log with this action.
        $notifiedFilter = "AND p.id NOT IN (
            SELECT entity_id FROM audit_log
            WHERE action = 'leaver_notification_sent' AND entity_type = 'person'
        )";
    }

    $upnSelect = $upnColumn !== null
        ? "p.`{$upnColumn}` AS m365_upn"
        : "NULL AS m365_upn";

    $m365IdSelect = $m365IdColumn !== null
        ? "p.`{$m365IdColumn}` AS m365_user_id"
        : "NULL AS m365_user_id";

    $sql = "
        SELECT
            p.id AS person_id,
            p.full_name,
            p.primary_email,
            {$upnSelect},
            {$m365IdSelect}
        FROM people p
        WHERE p.status = 'inactive'
          AND {$m365Where}
          {$notifiedFilter}
        ORDER BY p.full_name ASC
        LIMIT 50
    ";

    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ─── Main execution ─────────────────────────────────────────────────────────

leavers_log('=== Leavers Notification cron started ===');

$hasTrackingColumn = leavers_ensure_tracking_column();
$leavers = leavers_fetch_new_leavers($hasTrackingColumn);
$total = count($leavers);

leavers_log("Found {$total} inactive people with M365 accounts to report.");

if ($total === 0) {
    leavers_log('Nothing to do.');
    exit(0);
}

$supportEmail = leavers_config('M365_PROVISIONING_SUPPORT_EMAIL', null, 'support@irvalscouts.org.uk');
$notified = 0;
$errors = 0;

foreach ($leavers as $leaver) {
    $personId = (int) $leaver['person_id'];
    $fullName = (string) $leaver['full_name'];
    $personalEmail = (string) ($leaver['primary_email'] ?? '');
    $m365Upn = (string) ($leaver['m365_upn'] ?? '');
    $m365UserId = (string) ($leaver['m365_user_id'] ?? '');

    $identifier = $m365Upn !== '' ? $m365Upn : $m365UserId;

    $subject = 'Leaver Account Disable Request — ' . $fullName;

    $plainBody = "Hello,\n\n"
        . "The following volunteer has been marked as inactive in the Leader Tool and their Microsoft 365 account should now be disabled.\n\n"
        . "Name: {$fullName}\n"
        . "Personal email: {$personalEmail}\n"
        . "Microsoft 365 account: {$identifier}\n"
        . "Person ID: {$personId}\n\n"
        . "Please disable their account in Microsoft 365 at your earliest convenience.\n\n"
        . "This is an automated message from the Irwell Valley Leader Tool.\n"
        . "Irwell Valley Scout District";

    $htmlBody = '
        <p style="margin-top:0;">Hello,</p>
        <p>The following volunteer has been marked as <strong>inactive</strong> in the Leader Tool and their Microsoft 365 account should now be disabled.</p>
        ' . leavers_detail_table([
            'Name' => $fullName,
            'Personal email' => $personalEmail,
            'Microsoft 365 account' => $identifier,
            'Person ID' => (string) $personId,
        ]) . '
        <p>Please disable their account in Microsoft 365 at your earliest convenience.</p>
    ';

    $fullHtml = leavers_email_shell($subject, "Leaver: {$fullName} — please disable their M365 account.", $htmlBody);

    $queued = leavers_queue_email($supportEmail, 'Irwell Valley Support', $subject, $plainBody, $fullHtml, $personId);

    if (!$queued) {
        leavers_log("  ERROR [{$fullName}]: Could not queue email.");
        $errors++;
        continue;
    }

    // Mark as notified.
    if ($hasTrackingColumn) {
        try {
            $stmt = db()->prepare("UPDATE people SET leaver_notified_at = NOW() WHERE id = :id");
            $stmt->execute(['id' => $personId]);
        } catch (Throwable $e) {
            // Non-fatal: audit_log will also track it.
        }
    }

    leavers_audit($personId, [
        'full_name' => $fullName,
        'm365_account' => $identifier,
        'notified_email' => $supportEmail,
    ]);

    leavers_log("  NOTIFIED [{$fullName}]: email queued to {$supportEmail} (M365: {$identifier}).");
    $notified++;
}

leavers_log("=== Leavers Notification complete: {$notified} notified, {$errors} errors ===");
