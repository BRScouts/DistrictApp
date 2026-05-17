<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function dc_setting(string $key, ?string $default = null): ?string
{
    static $cache = [];

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute(['setting_key' => $key]);
        $value = $stmt->fetchColumn();
        $cache[$key] = $value === false ? $default : (string) $value;
        return $cache[$key];
    } catch (Throwable $e) {
        return $default;
    }
}

function dc_split_emails(?string $value): array
{
    if (!$value) {
        return [];
    }

    $parts = preg_split('/[;,\n]+/', $value) ?: [];
    $emails = [];

    foreach ($parts as $part) {
        $email = trim($part);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[strtolower($email)] = $email;
        }
    }

    return array_values($emails);
}

function dc_log(string $action, ?string $entityType = null, ?int $entityId = null, array $details = [], ?int $groupId = null): void
{
    $ctx = dc_context(false);
    $actorType = $ctx['actor_type'] ?? 'system';
    $actorPersonId = $ctx['person_id'] ?? null;
    $groupId = $groupId ?? ($ctx['group_id'] ?? null);

    try {
        $stmt = db()->prepare("\n            INSERT INTO audit_log (actor_type, actor_person_id, group_id, entity_type, entity_id, action, details_json, ip_address, user_agent)\n            VALUES (:actor_type, :actor_person_id, :group_id, :entity_type, :entity_id, :action, :details_json, :ip_address, :user_agent)\n        ");
        $stmt->execute([
            'actor_type' => $actorType,
            'actor_person_id' => $actorPersonId,
            'group_id' => $groupId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'details_json' => $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Audit logging must not break a user-facing workflow.
    }
}

function dc_queue_email(string $toEmail, ?string $toName, string $subject, string $body, string $type, string $entityType, int $entityId): void
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare("\n            INSERT INTO email_queue (to_email, to_name, subject, body, status)\n            VALUES (:to_email, :to_name, :subject, :body, 'pending')\n        ");
        $stmt->execute([
            'to_email' => $toEmail,
            'to_name' => $toName,
            'subject' => $subject,
            'body' => $body,
        ]);

        $stmt = $pdo->prepare("\n            INSERT INTO notification_log (related_entity_type, related_entity_id, recipient_name, recipient_email, notification_type, subject, body_preview, sent_successfully)\n            VALUES (:related_entity_type, :related_entity_id, :recipient_name, :recipient_email, :notification_type, :subject, :body_preview, 0)\n        ");
        $stmt->execute([
            'related_entity_type' => $entityType,
            'related_entity_id' => $entityId,
            'recipient_name' => $toName,
            'recipient_email' => $toEmail,
            'notification_type' => $type,
            'subject' => $subject,
            'body_preview' => mb_substr(strip_tags($body), 0, 800),
        ]);
    } catch (Throwable $e) {
        // Do not fail the workflow because mail queue insert failed.
    }
}

function dc_group_link_from_token(string $rawToken): ?array
{
    if ($rawToken === '') {
        return null;
    }

    $hash = hash('sha256', $rawToken);
    $stmt = db()->prepare("\n        SELECT gal.*, g.group_name, g.slug, g.notify_lead_on_event_created\n        FROM group_access_links gal\n        JOIN groups g ON g.id = gal.group_id\n        WHERE gal.token_hash = :token_hash\n          AND gal.status = 'active'\n          AND (gal.expires_at IS NULL OR gal.expires_at > NOW())\n          AND g.is_active = 1\n        LIMIT 1\n    ");
    $stmt->execute(['token_hash' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    db()->prepare('UPDATE group_access_links SET last_used_at = NOW() WHERE id = :id')
        ->execute(['id' => (int) $row['id']]);

    return $row;
}

function dc_context(bool $redirectIfMissing = true): array
{
    static $context = null;

    if (isset($_GET['token'])) {
        $link = dc_group_link_from_token((string) $_GET['token']);
        if ($link) {
            $_SESSION['dc_group_link'] = [
                'id' => (int) $link['id'],
                'group_id' => (int) $link['group_id'],
                'group_name' => (string) $link['group_name'],
                'slug' => (string) $link['slug'],
                'scope' => (string) $link['scope'],
            ];
            dc_log('group_link.used', 'group_access_link', (int) $link['id'], [], (int) $link['group_id']);
        } else {
            unset($_SESSION['dc_group_link']);
        }
        $context = null;
    }

    if ($context !== null) {
        return $context;
    }

    $user = current_user();
    if ($user) {
        $memberships = user_group_memberships((int) $user['id']);
        $groups = [];
        foreach ($memberships as $membership) {
            $groups[(int) $membership['group_id']] = [
                'id' => (int) $membership['group_id'],
                'group_name' => (string) $membership['group_name'],
                'slug' => (string) $membership['slug'],
                'access_level' => (string) $membership['access_level'],
                'membership_role' => (string) $membership['membership_role'],
            ];
        }

        $access = $user['highest_access_level'] ?? 'member';
        $isReviewer = in_array($access, ['district_reviewer', 'district_admin', 'system_admin'], true);

        if ($isReviewer) {
            $stmt = db()->query("SELECT id, group_name, slug FROM groups WHERE is_active = 1 ORDER BY group_name ASC");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $group) {
                $groups[(int) $group['id']] = [
                    'id' => (int) $group['id'],
                    'group_name' => (string) $group['group_name'],
                    'slug' => (string) $group['slug'],
                    'access_level' => $access,
                    'membership_role' => 'district_volunteer',
                ];
            }
        }

        $context = [
            'actor_type' => 'person',
            'person_id' => (int) $user['id'],
            'name' => (string) ($user['full_name'] ?? $user['email'] ?? 'Signed-in user'),
            'email' => (string) ($user['email'] ?? ''),
            'groups' => array_values($groups),
            'group_ids' => array_map('intval', array_keys($groups)),
            'is_reviewer' => $isReviewer,
            'is_signed_in' => true,
            'group_link' => null,
        ];
        return $context;
    }

    if (!empty($_SESSION['dc_group_link'])) {
        $link = $_SESSION['dc_group_link'];
        $context = [
            'actor_type' => 'group_link',
            'person_id' => null,
            'name' => 'Group link user',
            'email' => null,
            'groups' => [[
                'id' => (int) $link['group_id'],
                'group_name' => (string) $link['group_name'],
                'slug' => (string) $link['slug'],
                'access_level' => 'group_link',
                'membership_role' => 'group_link',
            ]],
            'group_ids' => [(int) $link['group_id']],
            'group_id' => (int) $link['group_id'],
            'is_reviewer' => false,
            'is_signed_in' => false,
            'group_link' => $link,
        ];
        return $context;
    }

    if ($redirectIfMissing) {
        redirect('/dc/login.php');
    }

    return [
        'actor_type' => 'system',
        'person_id' => null,
        'groups' => [],
        'group_ids' => [],
        'is_reviewer' => false,
        'is_signed_in' => false,
        'group_link' => null,
    ];
}

function dc_require_access(): array
{
    $ctx = dc_context(true);
    if (!$ctx['group_ids'] && !$ctx['is_reviewer']) {
        redirect('/dc/403.php');
    }
    return $ctx;
}

function dc_require_reviewer(): array
{
    $ctx = dc_require_access();
    if (!$ctx['is_reviewer']) {
        redirect('/dc/403.php');
    }
    return $ctx;
}

function dc_user_can_access_group(int $groupId): bool
{
    $ctx = dc_context(false);
    return $ctx['is_reviewer'] || in_array($groupId, $ctx['group_ids'], true);
}

function dc_accessible_groups(): array
{
    $ctx = dc_require_access();
    return $ctx['groups'];
}

function dc_selected_group_id(?int $requested = null): int
{
    $groups = dc_accessible_groups();
    if (!$groups) {
        redirect('/dc/403.php');
    }

    if ($requested && dc_user_can_access_group($requested)) {
        return $requested;
    }

    return (int) $groups[0]['id'];
}

function dc_group_options_html(?int $selectedId = null): string
{
    $groups = dc_accessible_groups();
    $html = '';
    foreach ($groups as $group) {
        $selected = ((int) $group['id'] === (int) $selectedId) ? ' selected' : '';
        $html .= '<option value="' . e($group['id']) . '"' . $selected . '>' . e($group['group_name']) . '</option>';
    }
    return $html;
}

function dc_fetch_sections(int $groupId): array
{
    $stmt = db()->prepare("\n        SELECT id, section_type, section_name\n        FROM group_sections\n        WHERE group_id = :group_id\n          AND is_active = 1\n        ORDER BY sort_order ASC, section_name ASC\n    ");
    $stmt->execute(['group_id' => $groupId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dc_fetch_group_people(int $groupId): array
{
    $stmt = db()->prepare("\n        SELECT DISTINCT\n            p.id, p.full_name, p.primary_email, p.phone,\n            gm.membership_role, gm.access_level\n        FROM group_memberships gm\n        JOIN people p ON p.id = gm.person_id\n        WHERE gm.group_id = :group_id\n          AND gm.status = 'active'\n          AND p.status = 'active'\n        ORDER BY FIELD(gm.membership_role, 'group_lead_volunteer', 'section_leader', 'assistant_section_leader', 'section_assistant', 'trustee', 'district_volunteer', 'administrator', 'other'), p.full_name\n    ");
    $stmt->execute(['group_id' => $groupId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dc_get_event(int $eventId): ?array
{
    $stmt = db()->prepare("\n        SELECT ce.*, g.group_name, g.slug\n        FROM calendar_events ce\n        JOIN groups g ON g.id = ce.group_id\n        WHERE ce.id = :id\n        LIMIT 1\n    ");
    $stmt->execute(['id' => $eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    return $event ?: null;
}

function dc_upload_base_dir(): string
{
    return __DIR__ . '/uploads/risk_assessments';
}

function dc_safe_upload_dir(): string
{
    $dir = dc_upload_base_dir() . '/' . date('Y') . '/' . date('m');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $htaccess = dc_upload_base_dir() . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Deny from all\n");
    }
    return $dir;
}

function dc_store_risk_assessment_upload(array $file, int $groupId, string $title, ?string $description, ?string $uploadedByName, ?string $uploadedByEmail, ?int $uploadedByPersonId, string $uploadedVia, string $visibility = 'district'): int
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The risk assessment file could not be uploaded.');
    }

    $maxBytes = 20 * 1024 * 1024;
    if ((int) $file['size'] > $maxBytes) {
        throw new RuntimeException('Risk assessment files must be 20MB or smaller.');
    }

    $original = (string) $file['name'];
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'doc', 'docx'];
    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException('Risk assessments must be PDF, DOC or DOCX files.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string) $file['tmp_name']) ?: 'application/octet-stream';
    $stored = 'risk_assessment_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $dir = dc_safe_upload_dir();
    $target = $dir . '/' . $stored;

    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        throw new RuntimeException('The risk assessment file could not be saved.');
    }

    $relative = 'uploads/risk_assessments/' . date('Y') . '/' . date('m') . '/' . $stored;
    $sha = hash_file('sha256', $target) ?: null;

    $stmt = db()->prepare("\n        INSERT INTO risk_assessments (\n            group_id, title, description, visibility, original_filename, stored_filename, file_path,\n            file_extension, mime_type, file_size_bytes, file_sha256, uploaded_by_person_id,\n            uploaded_by_name, uploaded_by_email, uploaded_via, status, admin_review_status\n        ) VALUES (\n            :group_id, :title, :description, :visibility, :original_filename, :stored_filename, :file_path,\n            :file_extension, :mime_type, :file_size_bytes, :file_sha256, :uploaded_by_person_id,\n            :uploaded_by_name, :uploaded_by_email, :uploaded_via, 'active', 'available'\n        )\n    ");
    $stmt->execute([
        'group_id' => $groupId,
        'title' => $title !== '' ? $title : pathinfo($original, PATHINFO_FILENAME),
        'description' => $description,
        'visibility' => in_array($visibility, ['group', 'district'], true) ? $visibility : 'district',
        'original_filename' => $original,
        'stored_filename' => $stored,
        'file_path' => $relative,
        'file_extension' => $extension,
        'mime_type' => $mime,
        'file_size_bytes' => (int) $file['size'],
        'file_sha256' => $sha,
        'uploaded_by_person_id' => $uploadedByPersonId,
        'uploaded_by_name' => $uploadedByName ?: 'Unknown leader',
        'uploaded_by_email' => $uploadedByEmail ?: 'unknown@example.invalid',
        'uploaded_via' => $uploadedVia,
    ]);

    $id = (int) db()->lastInsertId();
    dc_log('risk_assessment.uploaded', 'risk_assessment', $id, ['visibility' => $visibility], $groupId);
    return $id;
}

function dc_queue_event_notifications(int $eventId, string $eventAction): void
{
    $event = dc_get_event($eventId);
    if (!$event) {
        return;
    }

    $subject = match ($eventAction) {
        'approved' => 'Event approved: ' . $event['title'],
        'changes_requested' => 'Changes requested: ' . $event['title'],
        'rejected' => 'Event rejected: ' . $event['title'],
        default => 'Event submitted for review: ' . $event['title'],
    };

    $body = "Event: {$event['title']}\nGroup: {$event['group_name']}\nWhen: {$event['starts_at']} to {$event['ends_at']}\nStatus: {$event['status']}\n\nOpen the Leader Tool to review the full details.";

    $recipients = [];

    if ($eventAction === 'submitted') {
        foreach (dc_split_emails(dc_setting('event_notification_recipients', '')) as $email) {
            $recipients[$email] = ['email' => $email, 'name' => null];
        }

        $stmt = db()->prepare("\n            SELECT p.full_name, p.primary_email\n            FROM group_memberships gm\n            JOIN people p ON p.id = gm.person_id\n            JOIN groups g ON g.id = gm.group_id\n            WHERE gm.group_id = :group_id\n              AND gm.membership_role = 'group_lead_volunteer'\n              AND gm.status = 'active'\n              AND p.status = 'active'\n              AND p.primary_email IS NOT NULL\n              AND g.notify_lead_on_event_created = 1\n        ");
        $stmt->execute(['group_id' => (int) $event['group_id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $recipients[strtolower($row['primary_email'])] = ['email' => $row['primary_email'], 'name' => $row['full_name']];
        }
    }

    $recipients[strtolower((string) $event['leader_email'])] = ['email' => $event['leader_email'], 'name' => $event['leader_name']];

    foreach ($recipients as $recipient) {
        dc_queue_email($recipient['email'], $recipient['name'], $subject, $body, 'calendar_event_' . $eventAction, 'calendar_event', $eventId);
    }
}
