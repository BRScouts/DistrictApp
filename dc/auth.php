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

function dc_access_rank(string $accessLevel): int
{
    return match ($accessLevel) {
        'system_admin' => 100,
        'district_admin' => 90,
        'district_reviewer' => 80,
        'group_admin' => 60,
        'member' => 10,
        'group_link' => 1,
        default => 0,
    };
}

function dc_highest_access_level(array $accessLevels): string
{
    $highest = 'member';
    $highestRank = dc_access_rank($highest);

    foreach ($accessLevels as $level) {
        $level = (string) $level;
        $rank = dc_access_rank($level);

        if ($rank > $highestRank) {
            $highest = $level;
            $highestRank = $rank;
        }
    }

    return $highest;
}

function dc_context_has_reviewer_access(array $ctx): bool
{
    $accessLevels = array_filter(array_map('strval', (array) ($ctx['access_levels'] ?? [])));

    if (!empty($ctx['access_level'])) {
        $accessLevels[] = (string) $ctx['access_level'];
    }

    if (!empty($ctx['highest_access_level'])) {
        $accessLevels[] = (string) $ctx['highest_access_level'];
    }

    $accessLevels = array_values(array_unique($accessLevels));

    return (bool) ($ctx['is_reviewer'] ?? false)
        || in_array('district_reviewer', $accessLevels, true)
        || in_array('district_admin', $accessLevels, true)
        || in_array('system_admin', $accessLevels, true);
}

function dc_context_has_admin_access(array $ctx): bool
{
    $accessLevels = array_filter(array_map('strval', (array) ($ctx['access_levels'] ?? [])));

    if (!empty($ctx['access_level'])) {
        $accessLevels[] = (string) $ctx['access_level'];
    }

    if (!empty($ctx['highest_access_level'])) {
        $accessLevels[] = (string) $ctx['highest_access_level'];
    }

    $accessLevels = array_values(array_unique($accessLevels));

    return (bool) ($ctx['is_admin'] ?? false)
        || in_array('district_admin', $accessLevels, true)
        || in_array('system_admin', $accessLevels, true);
}

function dc_log(string $action, ?string $entityType = null, ?int $entityId = null, array $details = [], ?int $groupId = null): void
{
    $ctx = dc_context(false);
    $actorType = $ctx['actor_type'] ?? 'system';
    $actorPersonId = $ctx['person_id'] ?? null;
    $groupId = $groupId ?? ($ctx['group_id'] ?? null);

    try {
        $stmt = db()->prepare("
            INSERT INTO audit_log (
                actor_type,
                actor_person_id,
                group_id,
                entity_type,
                entity_id,
                action,
                details_json,
                ip_address,
                user_agent
            ) VALUES (
                :actor_type,
                :actor_person_id,
                :group_id,
                :entity_type,
                :entity_id,
                :action,
                :details_json,
                :ip_address,
                :user_agent
            )
        ");

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

        $stmt = $pdo->prepare("
            INSERT INTO email_queue (
                to_email,
                to_name,
                subject,
                body,
                status
            ) VALUES (
                :to_email,
                :to_name,
                :subject,
                :body,
                'pending'
            )
        ");

        $stmt->execute([
            'to_email' => $toEmail,
            'to_name' => $toName,
            'subject' => $subject,
            'body' => $body,
        ]);

        $stmt = $pdo->prepare("
            INSERT INTO notification_log (
                related_entity_type,
                related_entity_id,
                recipient_name,
                recipient_email,
                notification_type,
                subject,
                body_preview,
                sent_successfully
            ) VALUES (
                :related_entity_type,
                :related_entity_id,
                :recipient_name,
                :recipient_email,
                :notification_type,
                :subject,
                :body_preview,
                0
            )
        ");

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

    $stmt = db()->prepare("
        SELECT
            gal.*,
            g.group_name,
            g.slug,
            g.notify_lead_on_event_created
        FROM group_access_links gal
        JOIN groups g
          ON g.id = gal.group_id
        WHERE gal.token_hash = :token_hash
          AND gal.status = 'active'
          AND (gal.expires_at IS NULL OR gal.expires_at > NOW())
          AND g.is_active = 1
        LIMIT 1
    ");

    $stmt->execute(['token_hash' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    db()->prepare('UPDATE group_access_links SET last_used_at = NOW() WHERE id = :id')
        ->execute(['id' => (int) $row['id']]);

    return $row;
}

function dc_fetch_person_memberships(int $personId): array
{
    $stmt = db()->prepare("
        SELECT
            gm.group_id,
            gm.membership_role,
            gm.access_level,
            g.group_name,
            g.slug
        FROM group_memberships gm
        JOIN groups g
          ON g.id = gm.group_id
        WHERE gm.person_id = :person_id
          AND gm.status = 'active'
          AND g.is_active = 1
        ORDER BY g.group_name ASC
    ");

    $stmt->execute(['person_id' => $personId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dc_fetch_all_active_groups_for_access(string $accessLevel, string $membershipRole = 'district_volunteer'): array
{
    $stmt = db()->query("
        SELECT
            id,
            group_name,
            slug
        FROM groups
        WHERE is_active = 1
        ORDER BY group_name ASC
    ");

    $groups = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $group) {
        $groups[(int) $group['id']] = [
            'id' => (int) $group['id'],
            'group_id' => (int) $group['id'],
            'group_name' => (string) $group['group_name'],
            'slug' => (string) $group['slug'],
            'access_level' => $accessLevel,
            'membership_role' => $membershipRole,
        ];
    }

    return $groups;
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
        $personId = (int) $user['id'];
        $memberships = dc_fetch_person_memberships($personId);

        $groups = [];
        $groupIds = [];
        $accessLevels = [];
        $membershipRoles = [];

        foreach ($memberships as $membership) {
            $groupId = (int) $membership['group_id'];

            $groups[$groupId] = [
                'id' => $groupId,
                'group_id' => $groupId,
                'group_name' => (string) $membership['group_name'],
                'slug' => (string) $membership['slug'],
                'access_level' => (string) $membership['access_level'],
                'membership_role' => (string) $membership['membership_role'],
            ];

            $groupIds[] = $groupId;

            if (!empty($membership['access_level'])) {
                $accessLevels[] = (string) $membership['access_level'];
            }

            if (!empty($membership['membership_role'])) {
                $membershipRoles[] = (string) $membership['membership_role'];
            }
        }

        if (!empty($user['highest_access_level'])) {
            $accessLevels[] = (string) $user['highest_access_level'];
        }

        $accessLevels = array_values(array_unique(array_filter($accessLevels)));
        $membershipRoles = array_values(array_unique(array_filter($membershipRoles)));

        $highestAccessLevel = dc_highest_access_level($accessLevels);

        $isReviewer = in_array('district_reviewer', $accessLevels, true)
            || in_array('district_admin', $accessLevels, true)
            || in_array('system_admin', $accessLevels, true)
            || in_array($highestAccessLevel, ['district_reviewer', 'district_admin', 'system_admin'], true);

        $isAdmin = in_array('district_admin', $accessLevels, true)
            || in_array('system_admin', $accessLevels, true)
            || in_array($highestAccessLevel, ['district_admin', 'system_admin'], true);

        $isGlv = in_array('group_lead_volunteer', $membershipRoles, true)
            || in_array('group_admin', $accessLevels, true)
            || $isAdmin;

        if ($isReviewer) {
            $reviewerGroups = dc_fetch_all_active_groups_for_access($highestAccessLevel, 'district_volunteer');

            foreach ($reviewerGroups as $groupId => $group) {
                if (!isset($groups[$groupId])) {
                    $groups[$groupId] = $group;
                    $groupIds[] = $groupId;
                }
            }
        }

        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));

        $context = [
            'actor_type' => 'person',
            'person_id' => $personId,
            'name' => (string) ($user['full_name'] ?? $user['email'] ?? 'Signed-in user'),
            'email' => (string) ($user['email'] ?? ''),
            'groups' => array_values($groups),
            'group_ids' => $groupIds,
            'access_levels' => $accessLevels,
            'membership_roles' => $membershipRoles,
            'access_level' => $highestAccessLevel,
            'highest_access_level' => $highestAccessLevel,
            'membership_role' => $membershipRoles[0] ?? '',
            'is_reviewer' => $isReviewer,
            'is_admin' => $isAdmin,
            'is_glv' => $isGlv,
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
                'group_id' => (int) $link['group_id'],
                'group_name' => (string) $link['group_name'],
                'slug' => (string) $link['slug'],
                'access_level' => 'group_link',
                'membership_role' => 'group_link',
            ]],
            'group_ids' => [(int) $link['group_id']],
            'group_id' => (int) $link['group_id'],
            'access_levels' => ['group_link'],
            'membership_roles' => ['group_link'],
            'access_level' => 'group_link',
            'highest_access_level' => 'group_link',
            'membership_role' => 'group_link',
            'is_reviewer' => false,
            'is_admin' => false,
            'is_glv' => false,
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
        'name' => 'Guest',
        'email' => null,
        'groups' => [],
        'group_ids' => [],
        'access_levels' => [],
        'membership_roles' => [],
        'access_level' => '',
        'highest_access_level' => '',
        'membership_role' => '',
        'is_reviewer' => false,
        'is_admin' => false,
        'is_glv' => false,
        'is_signed_in' => false,
        'group_link' => null,
    ];
}

function dc_require_access(): array
{
    $ctx = dc_context(true);

    if (!$ctx['group_ids'] && !dc_context_has_reviewer_access($ctx)) {
        redirect('/dc/403.php');
    }

    return $ctx;
}

function dc_require_reviewer(): array
{
    $ctx = dc_require_access();

    if (!dc_context_has_reviewer_access($ctx)) {
        redirect('/dc/403.php');
    }

    return $ctx;
}

function dc_require_admin(): array
{
    $ctx = dc_require_access();

    if (!dc_context_has_admin_access($ctx)) {
        redirect('/dc/403.php');
    }

    return $ctx;
}

function dc_user_can_access_group(int $groupId): bool
{
    $ctx = dc_context(false);

    return dc_context_has_reviewer_access($ctx)
        || in_array($groupId, array_map('intval', (array) $ctx['group_ids']), true);
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
        $groupId = (int) ($group['id'] ?? $group['group_id'] ?? 0);
        $groupName = (string) ($group['group_name'] ?? $group['name'] ?? 'Unknown Group');

        $selected = ($groupId === (int) $selectedId) ? ' selected' : '';

        $html .= '<option value="' . e((string) $groupId) . '"' . $selected . '>' . e($groupName) . '</option>';
    }

    return $html;
}

function dc_fetch_sections(int $groupId): array
{
    $stmt = db()->prepare("
        SELECT
            id,
            section_type,
            section_name
        FROM group_sections
        WHERE group_id = :group_id
          AND is_active = 1
        ORDER BY sort_order ASC, section_name ASC
    ");

    $stmt->execute(['group_id' => $groupId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dc_fetch_group_people(int $groupId): array
{
    $stmt = db()->prepare("
        SELECT DISTINCT
            p.id,
            p.full_name,
            p.primary_email,
            p.phone,
            gm.membership_role,
            gm.access_level
        FROM group_memberships gm
        JOIN people p
          ON p.id = gm.person_id
        WHERE gm.group_id = :group_id
          AND gm.status = 'active'
          AND p.status = 'active'
        ORDER BY FIELD(
            gm.membership_role,
            'group_lead_volunteer',
            'section_leader',
            'assistant_section_leader',
            'section_assistant',
            'trustee',
            'district_volunteer',
            'administrator',
            'other'
        ), p.full_name
    ");

    $stmt->execute(['group_id' => $groupId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dc_get_event(int $eventId): ?array
{
    $stmt = db()->prepare("
        SELECT
            ce.*,
            g.group_name,
            g.slug
        FROM calendar_events ce
        JOIN groups g
          ON g.id = ce.group_id
        WHERE ce.id = :id
        LIMIT 1
    ");

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

function dc_store_risk_assessment_upload(
    array $file,
    int $groupId,
    string $title,
    ?string $description,
    ?string $uploadedByName,
    ?string $uploadedByEmail,
    ?int $uploadedByPersonId,
    string $uploadedVia,
    string $visibility = 'district'
): int {
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

    $stmt = db()->prepare("
        INSERT INTO risk_assessments (
            group_id,
            title,
            description,
            visibility,
            original_filename,
            stored_filename,
            file_path,
            file_extension,
            mime_type,
            file_size_bytes,
            file_sha256,
            uploaded_by_person_id,
            uploaded_by_name,
            uploaded_by_email,
            uploaded_via,
            status,
            admin_review_status
        ) VALUES (
            :group_id,
            :title,
            :description,
            :visibility,
            :original_filename,
            :stored_filename,
            :file_path,
            :file_extension,
            :mime_type,
            :file_size_bytes,
            :file_sha256,
            :uploaded_by_person_id,
            :uploaded_by_name,
            :uploaded_by_email,
            :uploaded_via,
            'active',
            'available'
        )
    ");

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
        'cancelled' => 'Event cancelled: ' . $event['title'],
        default => 'Event submitted for review: ' . $event['title'],
    };

    $body = "Event: {$event['title']}\n"
        . "Group: {$event['group_name']}\n"
        . "When: {$event['starts_at']} to {$event['ends_at']}\n"
        . "Status: {$event['status']}\n\n"
        . "Open the Leader Tool to review the full details.";

    $recipients = [];

    if ($eventAction === 'submitted') {
        foreach (dc_split_emails(dc_setting('event_notification_recipients', '')) as $email) {
            $recipients[strtolower($email)] = [
                'email' => $email,
                'name' => null,
            ];
        }

        $stmt = db()->prepare("
            SELECT
                p.full_name,
                p.primary_email
            FROM group_memberships gm
            JOIN people p
              ON p.id = gm.person_id
            JOIN groups g
              ON g.id = gm.group_id
            WHERE gm.group_id = :group_id
              AND gm.membership_role = 'group_lead_volunteer'
              AND gm.status = 'active'
              AND p.status = 'active'
              AND p.primary_email IS NOT NULL
              AND g.notify_lead_on_event_created = 1
        ");

        $stmt->execute(['group_id' => (int) $event['group_id']]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $recipients[strtolower((string) $row['primary_email'])] = [
                'email' => (string) $row['primary_email'],
                'name' => (string) $row['full_name'],
            ];
        }
    }

    if (!empty($event['leader_email'])) {
        $recipients[strtolower((string) $event['leader_email'])] = [
            'email' => (string) $event['leader_email'],
            'name' => (string) ($event['leader_name'] ?? null),
        ];
    }

    foreach ($recipients as $recipient) {
        dc_queue_email(
            $recipient['email'],
            $recipient['name'],
            $subject,
            $body,
            'calendar_event_' . $eventAction,
            'calendar_event',
            $eventId
        );
    }
}