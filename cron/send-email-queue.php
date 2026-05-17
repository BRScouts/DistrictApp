<?php

declare(strict_types=1);

/**
 * Send pending emails from email_queue using PHPMailer.
 *
 * Intended to run from CLI cron only:
 * php /home/brscouts/app.irvalscouts.org.uk/cron/send-email-queue.php
 */



require_once __DIR__ . '/../app/bootstrap.php';

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoload file not found. Run: composer require phpmailer/phpmailer\n");
    exit(1);
}

require_once $autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function mailq_config(string $key, mixed $default = null): mixed
{
    return defined($key) ? constant($key) : $default;
}

function mailq_table_has_column(string $table, string $column): bool
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

function mailq_email_queue_columns(): array
{
    static $columns = null;

    if ($columns !== null) {
        return $columns;
    }

    try {
        $stmt = db()->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'email_queue'
        ");
        $stmt->execute();

        return $columns = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return $columns = [];
    }
}

function mailq_column_exists(string $column): bool
{
    return in_array($column, mailq_email_queue_columns(), true);
}

function mailq_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function mailq_update_email(int $id, array $values): void
{
    $columns = mailq_email_queue_columns();
    $set = [];
    $params = ['id' => $id];

    foreach ($values as $column => $value) {
        if (!in_array($column, $columns, true)) {
            continue;
        }

        $set[] = mailq_quote_identifier($column) . ' = :' . $column;
        $params[$column] = $value;
    }

    if (!$set) {
        return;
    }

    $stmt = db()->prepare("
        UPDATE email_queue
        SET " . implode(', ', $set) . "
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute($params);
}

function mailq_plain_text_from_html(string $html): string
{
    $html = preg_replace('/<\s*br\s*\/?>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/p>/i', "\n\n", $html) ?? $html;
    $html = preg_replace('/<\/li>/i', "\n", $html) ?? $html;
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    return preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
}

function mailq_escape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mailq_wrap_template(string $subject, string $bodyHtml): string
{
    $logoUrl = (string) mailq_config('MAIL_LOGO_URL', '');
    $footerText = (string) mailq_config('MAIL_FOOTER_TEXT', 'Irwell Valley Scout District');
    $appUrl = (string) mailq_config('MAIL_APP_URL', '');

    $logoHtml = '';

    if ($logoUrl !== '') {
        $logoHtml = '
            <tr>
                <td style="padding: 24px 24px 8px 24px;">
                    <img src="' . mailq_escape($logoUrl) . '" alt="Irwell Valley Scout District" style="max-width: 220px; height: auto; display: block;">
                </td>
            </tr>';
    }

    $dashboardLink = '';

    if ($appUrl !== '') {
        $dashboardLink = '
            <p style="margin: 20px 0 0 0;">
                <a href="' . mailq_escape($appUrl) . '" style="color: #4d0b93; font-weight: 800;">Open District Dashboard</a>
            </p>';
    }

    return '<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>' . mailq_escape($subject) . '</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 0; background: #f3f2f1; font-family: Arial, Helvetica, sans-serif; color: #1d1d1b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: #f3f2f1; margin: 0; padding: 0;">
        <tr>
            <td align="center" style="padding: 24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 680px; background: #ffffff; border: 1px solid #dddddd;">
                    ' . $logoHtml . '
                    <tr>
                        <td style="padding: 8px 24px 0 24px;">
                            <div style="height: 6px; background: #7413dc; line-height: 6px;">&nbsp;</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px;">
                            <h1 style="margin: 0 0 18px 0; color: #4d0b93; font-size: 26px; line-height: 1.15; font-weight: 900;">
                                ' . mailq_escape($subject) . '
                            </h1>

                            <div style="font-size: 16px; line-height: 1.55;">
                                ' . $bodyHtml . '
                            </div>

                            ' . $dashboardLink . '
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 18px 24px; background: #f7f5fb; border-top: 1px solid #e6e6e6; font-size: 13px; line-height: 1.45; color: #555555;">
                            <strong>' . mailq_escape($footerText) . '</strong><br>
                            This message was sent from the District Dashboard.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
}

function mailq_build_mailer(): PHPMailer
{
    $host = (string) mailq_config('SMTP_HOST', '');
    $username = (string) mailq_config('SMTP_USERNAME', '');
    $password = (string) mailq_config('SMTP_PASSWORD', '');
    $fromEmail = (string) mailq_config('SMTP_FROM_EMAIL', $username);
    $fromName = (string) mailq_config('SMTP_FROM_NAME', 'Irwell Valley Scout District');

    if ($host === '' || $username === '' || $fromEmail === '') {
        throw new RuntimeException('SMTP settings are incomplete. Check SMTP_HOST, SMTP_USERNAME and SMTP_FROM_EMAIL in config.php.');
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = (int) mailq_config('SMTP_PORT', 587);
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->CharSet = 'UTF-8';

    $encryption = strtolower((string) mailq_config('SMTP_ENCRYPTION', 'tls'));

    if ($encryption === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = false;
    }

    $mail->setFrom($fromEmail, $fromName);

    $replyToEmail = (string) mailq_config('SMTP_REPLY_TO_EMAIL', '');
    $replyToName = (string) mailq_config('SMTP_REPLY_TO_NAME', '');

    if ($replyToEmail !== '') {
        $mail->addReplyTo($replyToEmail, $replyToName ?: $fromName);
    }

    return $mail;
}

function mailq_fetch_pending(int $limit): array
{
    $columns = mailq_email_queue_columns();

    if (!in_array('id', $columns, true)) {
        throw new RuntimeException('email_queue.id column is required.');
    }

    $statusCondition = in_array('status', $columns, true)
        ? "status = 'pending'"
        : "1 = 1";

    $attemptCondition = '';

    if (in_array('attempt_count', $columns, true)) {
        $maxAttempts = (int) mailq_config('EMAIL_QUEUE_MAX_ATTEMPTS', 5);
        $attemptCondition = "AND COALESCE(attempt_count, 0) < {$maxAttempts}";
    }

    $orderColumn = in_array('created_at', $columns, true) ? 'created_at' : 'id';

    $stmt = db()->prepare("
        SELECT *
        FROM email_queue
        WHERE {$statusCondition}
          {$attemptCondition}
        ORDER BY {$orderColumn} ASC
        LIMIT :limit
    ");
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function mailq_mark_processing(int $id): bool
{
    if (!mailq_column_exists('status')) {
        return true;
    }

    $values = [
        'status' => 'processing',
    ];

    if (mailq_column_exists('processing_started_at')) {
        $values['processing_started_at'] = date('Y-m-d H:i:s');
    }

    mailq_update_email($id, $values);

    return true;
}

function mailq_mark_sent(int $id): void
{
    $values = [];

    if (mailq_column_exists('status')) {
        $values['status'] = 'sent';
    }

    if (mailq_column_exists('sent_at')) {
        $values['sent_at'] = date('Y-m-d H:i:s');
    }

    if (mailq_column_exists('last_error')) {
        $values['last_error'] = null;
    }

    mailq_update_email($id, $values);
}

function mailq_mark_failed(int $id, string $error): void
{
    $values = [];

    if (mailq_column_exists('attempt_count')) {
        try {
            db()->prepare('UPDATE email_queue SET attempt_count = COALESCE(attempt_count, 0) + 1 WHERE id = :id LIMIT 1')
                ->execute(['id' => $id]);
        } catch (Throwable $e) {
        }
    }

    if (mailq_column_exists('last_error')) {
        $values['last_error'] = mb_substr($error, 0, 1000);
    }

    if (mailq_column_exists('last_attempt_at')) {
        $values['last_attempt_at'] = date('Y-m-d H:i:s');
    }

    if (mailq_column_exists('status')) {
        $values['status'] = 'pending';

        if (mailq_column_exists('attempt_count')) {
            try {
                $stmt = db()->prepare('SELECT COALESCE(attempt_count, 0) FROM email_queue WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $id]);
                $attemptCount = (int) $stmt->fetchColumn();

                if ($attemptCount >= (int) mailq_config('EMAIL_QUEUE_MAX_ATTEMPTS', 5)) {
                    $values['status'] = 'failed';
                }
            } catch (Throwable $e) {
            }
        } else {
            $values['status'] = 'failed';
        }
    }

    mailq_update_email($id, $values);
}

function mailq_send_row(array $row): void
{
    $id = (int) ($row['id'] ?? 0);
    $toEmail = trim((string) ($row['to_email'] ?? ''));
    $toName = trim((string) ($row['to_name'] ?? ''));
    $subject = trim((string) ($row['subject'] ?? ''));
    $body = (string) ($row['body'] ?? '');
    $bodyHtml = (string) ($row['body_html'] ?? '');

    if ($id <= 0) {
        throw new RuntimeException('Invalid queue row id.');
    }

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid recipient email address.');
    }

    if ($subject === '') {
        throw new RuntimeException('Missing email subject.');
    }

    if ($bodyHtml === '') {
        $bodyHtml = $body;
    }

    if (trim($bodyHtml) === '') {
        throw new RuntimeException('Missing email body.');
    }

    $html = mailq_wrap_template($subject, $bodyHtml);
    $plain = mailq_plain_text_from_html($bodyHtml);

    $mail = mailq_build_mailer();
    $mail->addAddress($toEmail, $toName);
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body = $html;
    $mail->AltBody = $plain !== '' ? $plain : strip_tags($subject);

    $mail->send();
}

$batchSize = (int) mailq_config('EMAIL_QUEUE_BATCH_SIZE', 25);
$batchSize = max(1, min($batchSize, 100));

$sent = 0;
$failed = 0;

try {
    $rows = mailq_fetch_pending($batchSize);

    foreach ($rows as $row) {
        $id = (int) $row['id'];

        try {
            mailq_mark_processing($id);
            mailq_send_row($row);
            mailq_mark_sent($id);
            $sent++;

            echo "[sent] #{$id} " . ($row['to_email'] ?? '') . PHP_EOL;
        } catch (Throwable $e) {
            mailq_mark_failed($id, $e->getMessage());
            $failed++;

            echo "[failed] #{$id} " . ($row['to_email'] ?? '') . ' - ' . $e->getMessage() . PHP_EOL;
        }
    }

    echo "Done. Sent: {$sent}. Failed: {$failed}." . PHP_EOL;
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Email queue fatal error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}