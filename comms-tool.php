<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

if (user_needs_group_onboarding()) {
    redirect('/onboarding.php');
}

$pdo = db();
$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

$pageTitle = 'Comms Tool | ' . $appName;
$heroTitle = 'Comms Tool';
$heroText = 'Build a targeted recipient list and queue District emails for sending.';
$breadcrumb = '<a href="/index.php">Home</a> / Comms Tool';

function comms_table_exists(string $table): bool
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

function comms_column_exists(string $table, string $column): bool
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

function comms_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function comms_current_access_level(array $user): string
{
    $levels = [(string) ($user['highest_access_level'] ?? $user['role'] ?? 'member')];

    if (function_exists('user_group_memberships')) {
        try {
            $memberships = user_group_memberships((int) ($user['id'] ?? 0), false);

            foreach ($memberships as $membership) {
                if (($membership['status'] ?? 'active') !== 'active') {
                    continue;
                }

                $levels[] = (string) ($membership['access_level'] ?? 'member');
            }
        } catch (Throwable $e) {
        }
    }

    $rank = [
        'system_admin' => 5,
        'district_admin' => 4,
        'district_reviewer' => 3,
        'group_admin' => 2,
        'member' => 1,
    ];

    usort(
        $levels,
        static fn(string $a, string $b): int => ($rank[$b] ?? 0) <=> ($rank[$a] ?? 0)
    );

    return $levels[0] ?? 'member';
}

function comms_is_admin(array $user): bool
{
    return in_array(comms_current_access_level($user), ['district_admin', 'system_admin'], true);
}

function comms_decode_json_list(?string $json): array
{
    if (!$json) {
        return [];
    }

    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter(array_map('strval', $decoded)));
}

function comms_split_manual_recipients(string $value): array
{
    $parts = preg_split('/[,\n;]+/', $value) ?: [];
    $recipients = [];

    foreach ($parts as $part) {
        $part = trim($part);

        if ($part === '') {
            continue;
        }

        $name = null;
        $email = $part;

        if (preg_match('/^(.*?)<([^>]+)>$/', $part, $matches)) {
            $name = trim($matches[1], " \t\n\r\0\x0B\"'");
            $email = trim($matches[2]);
        }

        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $recipients[$email] = [
            'person_id' => null,
            'name' => $name ?: null,
            'email' => $email,
            'source' => 'manual',
            'groups' => '',
            'role_title' => '',
            'accreditations' => [],
        ];
    }

    return array_values($recipients);
}

function comms_extract_email_queue_columns(): array
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

function comms_insert_email_queue(array $data): void
{
    $columns = comms_extract_email_queue_columns();

    if (!$columns) {
        throw new RuntimeException('The email_queue table does not exist or has no recognised columns.');
    }

    $insert = [];

    foreach ($data as $column => $value) {
        if (in_array($column, $columns, true)) {
            $insert[$column] = $value;
        }
    }

    if (in_array('status', $columns, true) && !array_key_exists('status', $insert)) {
        $insert['status'] = 'pending';
    }

    if (in_array('created_at', $columns, true) && !array_key_exists('created_at', $insert)) {
        $insert['created_at'] = date('Y-m-d H:i:s');
    }

    if (!$insert) {
        throw new RuntimeException('No compatible email_queue columns were found.');
    }

    $quoted = array_map('comms_quote_identifier', array_keys($insert));
    $placeholders = array_map(static fn(string $column): string => ':' . $column, array_keys($insert));

    $stmt = db()->prepare("
        INSERT INTO email_queue (" . implode(', ', $quoted) . ")
        VALUES (" . implode(', ', $placeholders) . ")
    ");

    $stmt->execute($insert);
}

function comms_log(int $actorPersonId, string $action, array $details = []): void
{
    if (!comms_table_exists('audit_log')) {
        return;
    }

    try {
        $stmt = db()->prepare("
            INSERT INTO audit_log (
                actor_type,
                actor_person_id,
                action,
                entity_type,
                entity_id,
                details_json,
                created_at
            )
            VALUES (
                'person',
                :actor_person_id,
                :action,
                'comms_send',
                NULL,
                :details_json,
                NOW()
            )
        ");

        $stmt->execute([
            'actor_person_id' => $actorPersonId,
            'action' => $action,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
    }
}

function comms_plain_from_html(string $html): string
{
    $html = preg_replace('/<\s*br\s*\/?>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/p>/i', "\n\n", $html) ?? $html;
    $html = preg_replace('/<\/li>/i', "\n", $html) ?? $html;
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    return preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
}

function comms_preview_snippet(string $html, int $limit = 150): string
{
    $plain = preg_replace('/\s+/', ' ', comms_plain_from_html($html)) ?? '';

    if (function_exists('mb_strlen') && mb_strlen($plain) > $limit) {
        return mb_substr($plain, 0, $limit - 1) . '…';
    }

    if (strlen($plain) > $limit) {
        return substr($plain, 0, $limit - 1) . '…';
    }

    return $plain;
}

function comms_sanitise_html(string $html): string
{
    $html = trim($html);

    if ($html === '') {
        return '';
    }

    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|textarea|select|option|meta|link)[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#</?(script|style|iframe|object|embed|form|input|button|textarea|select|option|meta|link)[^>]*>#is', '', $html) ?? $html;
    $html = preg_replace('/\s+on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html) ?? $html;
    $html = preg_replace('/\s+style\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html) ?? $html;

    $allowedTags = '<p><br><strong><b><em><i><u><ul><ol><li><a><h2><h3><blockquote>';

    $html = strip_tags($html, $allowedTags);

    $html = preg_replace_callback('/<a\s+([^>]+)>/i', static function (array $matches): string {
        $attrs = $matches[1];

        if (!preg_match('/href\s*=\s*("|\')(.*?)\1/i', $attrs, $hrefMatch)) {
            return '<a>';
        }

        $href = trim(html_entity_decode($hrefMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (!preg_match('#^https?://#i', $href) && !preg_match('#^mailto:#i', $href)) {
            return '<a>';
        }

        return '<a href="' . e($href) . '">';
    }, $html) ?? $html;

    return trim($html);
}

function comms_normalise_html(string $html): string
{
    $html = comms_sanitise_html($html);

    if ($html === '') {
        return '';
    }

    if (!preg_match('/<(p|h2|h3|ul|ol|blockquote)\b/i', $html)) {
        $html = '<p>' . nl2br(e(trim(strip_tags($html)))) . '</p>';
    }

    return $html;
}

function comms_personalise_html(string $html, array $recipient): string
{
    return str_replace(
        ['{{name}}', '{{email}}'],
        [e($recipient['name'] ?: 'there'), e($recipient['email'])],
        $html
    );
}

function comms_fetch_active_role_options(): array
{
    try {
        $stmt = db()->query("
            SELECT
                dp.role_title,
                COUNT(DISTINCT p.id) AS people_count
            FROM people p
            JOIN directory_profiles dp
              ON dp.person_id = p.id
            WHERE p.status = 'active'
              AND dp.role_title IS NOT NULL
              AND dp.role_title <> ''
              AND p.primary_email IS NOT NULL
              AND p.primary_email <> ''
            GROUP BY dp.role_title
            ORDER BY dp.role_title ASC
        ");

        $roles = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $role = trim((string) $row['role_title']);

            if ($role === '') {
                continue;
            }

            $roles[$role] = (int) $row['people_count'];
        }

        return $roles;
    } catch (Throwable $e) {
        return [];
    }
}

function comms_fetch_accreditation_counts(array $knownAccreditations): array
{
    $counts = array_fill_keys($knownAccreditations, 0);

    try {
        $stmt = db()->query("
            SELECT dp.accreditations_json
            FROM people p
            JOIN directory_profiles dp
              ON dp.person_id = p.id
            WHERE p.status = 'active'
              AND p.primary_email IS NOT NULL
              AND p.primary_email <> ''
              AND dp.accreditations_json IS NOT NULL
              AND dp.accreditations_json <> ''
              AND dp.accreditations_json <> '[]'
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $items = array_unique(comms_decode_json_list((string) $json));

            foreach ($items as $item) {
                if (!array_key_exists($item, $counts)) {
                    $counts[$item] = 0;
                }

                $counts[$item]++;
            }
        }
    } catch (Throwable $e) {
    }

    return $counts;
}

function comms_filter_accreditation_options_by_count(array $accreditationOptions, array $counts): array
{
    $filtered = [];

    foreach ($accreditationOptions as $category => $items) {
        $kept = [];

        foreach ($items as $item) {
            if (($counts[$item] ?? 0) > 0) {
                $kept[] = $item;
            }
        }

        if ($kept) {
            $filtered[$category] = $kept;
        }
    }

    return $filtered;
}

function comms_fetch_recipients(array $filters, array $user): array
{
    $recipients = [];

    $audienceMode = (string) ($filters['audience_mode'] ?? 'filtered');
    $groupIds = array_values(array_filter(array_map('intval', (array) ($filters['group_ids'] ?? []))));
    $roleTitles = array_values(array_filter(array_map('strval', (array) ($filters['role_titles'] ?? []))));
    $accreditations = array_values(array_filter(array_map('strval', (array) ($filters['accreditations'] ?? []))));
    $keyword = trim((string) ($filters['keyword'] ?? ''));

    $hasTargetedFilters = $groupIds || $roleTitles || $accreditations || $keyword !== '';

    /*
     * Important safety rule:
     * - all_people means query all active people with email addresses.
     * - filtered means query people only when at least one filter is selected.
     * - manual recipients are always added separately below.
     *
     * This prevents a pasted manual list from accidentally sending to every active person.
     */
    $shouldQueryPeople = $audienceMode === 'all_people' || $hasTargetedFilters;

    if ($shouldQueryPeople) {
        $where = [
            "p.status = 'active'",
            "p.primary_email IS NOT NULL",
            "p.primary_email <> ''",
        ];

        $params = [];

        if ($audienceMode !== 'all_people') {
            if ($groupIds) {
                $where[] = "gm.group_id IN (" . implode(',', array_fill(0, count($groupIds), '?')) . ")";
                foreach ($groupIds as $id) {
                    $params[] = $id;
                }
            }

            if ($roleTitles) {
                $where[] = "dp.role_title IN (" . implode(',', array_fill(0, count($roleTitles), '?')) . ")";
                foreach ($roleTitles as $roleTitle) {
                    $params[] = $roleTitle;
                }
            }

            foreach ($accreditations as $accreditation) {
                $where[] = "dp.accreditations_json LIKE ?";
                $params[] = '%' . $accreditation . '%';
            }

            if ($keyword !== '') {
                $where[] = "(
                    p.full_name LIKE ?
                    OR p.primary_email LIKE ?
                    OR dp.role_title LIKE ?
                    OR dp.about_me LIKE ?
                    OR g.group_name LIKE ?
                    OR dp.accreditations_json LIKE ?
                )";

                $like = '%' . $keyword . '%';

                for ($i = 0; $i < 6; $i++) {
                    $params[] = $like;
                }
            }
        }

        $whereSql = implode("\n      AND ", $where);

        $sql = "
            SELECT
                p.id,
                p.full_name,
                p.primary_email,
                dp.role_title,
                dp.accreditations_json,
                GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.group_name SEPARATOR ', ') AS group_names
            FROM people p
            LEFT JOIN directory_profiles dp
              ON dp.person_id = p.id
            LEFT JOIN group_memberships gm
              ON gm.person_id = p.id
             AND gm.status = 'active'
            LEFT JOIN groups g
              ON g.id = gm.group_id
             AND g.is_active = 1
            WHERE {$whereSql}
            GROUP BY
                p.id,
                p.full_name,
                p.primary_email,
                dp.role_title,
                dp.accreditations_json
            ORDER BY p.full_name ASC
            LIMIT 5000
        ";

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $email = strtolower(trim((string) $row['primary_email']));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $recipients[$email] = [
                'person_id' => (int) $row['id'],
                'name' => (string) ($row['full_name'] ?? ''),
                'email' => $email,
                'source' => 'query',
                'groups' => (string) ($row['group_names'] ?? ''),
                'role_title' => (string) ($row['role_title'] ?? ''),
                'accreditations' => comms_decode_json_list($row['accreditations_json'] ?? null),
            ];
        }
    }

    $manual = comms_split_manual_recipients((string) ($filters['manual_recipients'] ?? ''));

    foreach ($manual as $manualRecipient) {
        $email = strtolower($manualRecipient['email']);
        $recipients[$email] = $manualRecipient;
    }

    if (!empty($filters['copy_self'])) {
        $selfEmail = strtolower(trim((string) ($user['email'] ?? '')));

        if (filter_var($selfEmail, FILTER_VALIDATE_EMAIL)) {
            $recipients[$selfEmail] = [
                'person_id' => (int) ($user['id'] ?? 0),
                'name' => (string) ($user['full_name'] ?? $user['preferred_name'] ?? 'Me'),
                'email' => $selfEmail,
                'source' => 'self_copy',
                'groups' => '',
                'role_title' => 'Self copy',
                'accreditations' => [],
            ];
        }
    }

    return array_values($recipients);
}

function comms_hidden_state_inputs(array $form, bool $includeMessage = true): void
{
    ?>
    <input type="hidden" name="audience_mode" value="<?= e((string) $form['audience_mode']) ?>">
    <input type="hidden" name="keyword" value="<?= e((string) $form['keyword']) ?>">
    <input type="hidden" name="manual_recipients" value="<?= e((string) $form['manual_recipients']) ?>">
    <input type="hidden" name="copy_self" value="<?= (int) $form['copy_self'] ?>">

    <?php foreach ((array) $form['group_ids'] as $groupId): ?>
        <input type="hidden" name="group_ids[]" value="<?= (int) $groupId ?>">
    <?php endforeach; ?>

    <?php foreach ((array) $form['role_titles'] as $roleTitle): ?>
        <input type="hidden" name="role_titles[]" value="<?= e((string) $roleTitle) ?>">
    <?php endforeach; ?>

    <?php foreach ((array) $form['accreditations'] as $accreditation): ?>
        <input type="hidden" name="accreditations[]" value="<?= e((string) $accreditation) ?>">
    <?php endforeach; ?>

    <?php if ($includeMessage): ?>
        <input type="hidden" name="subject" value="<?= e((string) $form['subject']) ?>">
        <input type="hidden" name="body_html" value="<?= e((string) $form['body_html']) ?>">
    <?php endif; ?>
    <?php
}

if (!comms_is_admin($user)) {
    http_response_code(403);
    include __DIR__ . '/header.php';
    echo '<main class="lt-main"><div class="alert alert-danger"><strong>Access denied:</strong> only District Admins can use the Comms Tool.</div></main>';
    include __DIR__ . '/footer.php';
    exit;
}

$stmt = $pdo->query("
    SELECT id, group_name
    FROM groups
    WHERE is_active = 1
    ORDER BY group_name ASC
");
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

$roleCounts = comms_fetch_active_role_options();
$roleOptions = array_keys($roleCounts);

$allAccreditationOptions = portal_accreditation_options();
$knownAccreditations = portal_flatten_options($allAccreditationOptions);
$accreditationCounts = comms_fetch_accreditation_counts($knownAccreditations);
$accreditationOptions = comms_filter_accreditation_options_by_count($allAccreditationOptions, $accreditationCounts);
$allowedAccreditations = array_keys(array_filter(
    $accreditationCounts,
    static fn(int $count): bool => $count > 0
));

$errors = [];
$success = null;

$step = (string) ($_POST['step'] ?? $_GET['step'] ?? 'recipients');
$action = (string) ($_POST['action'] ?? '');

$validSteps = ['recipients', 'write', 'preview'];

if (!in_array($step, $validSteps, true)) {
    $step = 'recipients';
}

$form = [
    'audience_mode' => (string) ($_POST['audience_mode'] ?? 'filtered'),
    'keyword' => trim((string) ($_POST['keyword'] ?? '')),
    'group_ids' => $_POST['group_ids'] ?? [],
    'role_titles' => $_POST['role_titles'] ?? [],
    'accreditations' => $_POST['accreditations'] ?? [],
    'manual_recipients' => (string) ($_POST['manual_recipients'] ?? ''),
    'copy_self' => isset($_POST['copy_self']) ? (int) ($_POST['copy_self'] === '1' || $_POST['copy_self'] === 'on') : 0,
    'subject' => trim((string) ($_POST['subject'] ?? '')),
    'body_html' => (string) ($_POST['body_html'] ?? ''),
];

if (!is_array($form['group_ids'])) {
    $form['group_ids'] = [];
}

if (!is_array($form['role_titles'])) {
    $form['role_titles'] = [];
}

if (!is_array($form['accreditations'])) {
    $form['accreditations'] = [];
}

$form['group_ids'] = array_values(array_unique(array_filter(
    array_map('intval', $form['group_ids']),
    static fn(int $id): bool => $id > 0
)));

$form['role_titles'] = array_values(array_intersect(
    array_map('strval', $form['role_titles']),
    $roleOptions
));

$form['accreditations'] = array_values(array_intersect(
    array_map('strval', $form['accreditations']),
    $allowedAccreditations
));

if (!in_array($form['audience_mode'], ['filtered', 'all_people'], true)) {
    $form['audience_mode'] = 'filtered';
}

$form['body_html'] = comms_normalise_html($form['body_html']);

$recipients = [];
$previewHtml = $form['body_html'];
$previewPlain = comms_plain_from_html($previewHtml);
$previewSnippet = comms_preview_snippet($previewHtml);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    if ($action === 'to_write') {
        $recipients = comms_fetch_recipients($form, $user);

        if (!$recipients) {
            $errors[] = 'No recipients matched this selection. Choose all active people, select at least one filter, or add manual recipients.';
            $step = 'recipients';
        } else {
            $step = 'write';
        }
    } elseif ($action === 'back_to_recipients') {
        $step = 'recipients';
    } elseif ($action === 'to_preview') {
        $recipients = comms_fetch_recipients($form, $user);

        if (!$recipients) {
            $errors[] = 'No recipients matched this selection.';
            $step = 'recipients';
        }

        if ($form['subject'] === '') {
            $errors[] = 'Enter an email subject.';
            $step = 'write';
        }

        if (trim(strip_tags($form['body_html'])) === '') {
            $errors[] = 'Enter the email message.';
            $step = 'write';
        }

        if (!$errors) {
            $previewHtml = $form['body_html'];
            $previewPlain = comms_plain_from_html($previewHtml);
            $previewSnippet = comms_preview_snippet($previewHtml);
            $step = 'preview';
        }
    } elseif ($action === 'back_to_write') {
        $step = 'write';
    } elseif ($action === 'send') {
        $recipients = comms_fetch_recipients($form, $user);

        if (!$recipients) {
            $errors[] = 'No recipients matched this selection.';
            $step = 'recipients';
        }

        if ($form['subject'] === '') {
            $errors[] = 'Enter an email subject.';
            $step = 'write';
        }

        if (trim(strip_tags($form['body_html'])) === '') {
            $errors[] = 'Enter the email message.';
            $step = 'write';
        }

        if (!$errors) {
            $pdo->beginTransaction();

            try {
                foreach ($recipients as $recipient) {
                    $personalisedHtml = comms_personalise_html($form['body_html'], $recipient);
                    $personalisedPlain = comms_plain_from_html($personalisedHtml);

                    $queueData = [
                        'to_email' => $recipient['email'],
                        'to_name' => $recipient['name'] ?: null,
                        'subject' => $form['subject'],
                        'body' => comms_column_exists('email_queue', 'body_html') ? $personalisedPlain : $personalisedHtml,
                        'body_html' => $personalisedHtml,
                        'body_markdown' => null,
                        'status' => 'pending',
                        'created_by_person_id' => (int) $user['id'],
                        'notification_type' => 'district_comms',
                        'related_entity_type' => 'comms_send',
                        'related_entity_id' => null,
                    ];

                    comms_insert_email_queue($queueData);
                }

                comms_log((int) $user['id'], 'comms_email_queued', [
                    'recipient_count' => count($recipients),
                    'subject' => $form['subject'],
                    'filters' => [
                        'audience_mode' => $form['audience_mode'],
                        'keyword' => $form['keyword'],
                        'group_ids' => $form['group_ids'],
                        'role_titles' => $form['role_titles'],
                        'accreditations' => $form['accreditations'],
                        'copy_self' => (bool) $form['copy_self'],
                        'manual_recipient_count' => count(comms_split_manual_recipients($form['manual_recipients'])),
                    ],
                ]);

                audit_log(AUDIT_COMMS_EMAIL_SENT, null, null, null, [
                    'recipient_count' => count($recipients),
                    'subject' => $form['subject'],
                    'audience_mode' => $form['audience_mode'],
                ]);

                $pdo->commit();

                $success = count($recipients) . ' email' . (count($recipients) === 1 ? '' : 's') . ' added to the email queue.';

                $step = 'recipients';
                $form = [
                    'audience_mode' => 'filtered',
                    'keyword' => '',
                    'group_ids' => [],
                    'role_titles' => [],
                    'accreditations' => [],
                    'manual_recipients' => '',
                    'copy_self' => 0,
                    'subject' => '',
                    'body_html' => '',
                ];
                $recipients = [];
                $previewHtml = '';
                $previewPlain = '';
                $previewSnippet = '';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'The emails could not be queued. ' . $e->getMessage();
                $step = 'preview';
            }
        }
    }
}

if (!$recipients && in_array($step, ['write', 'preview'], true)) {
    $recipients = comms_fetch_recipients($form, $user);
}

?>
<?php include __DIR__ . '/header.php'; ?>

<style>
    .comms-steps {
        display: grid;
        gap: .5rem;
        margin-bottom: 1rem;
    }

    @media (min-width: 760px) {
        .comms-steps {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    .comms-step {
        background: #ffffff;
        border: 1px solid #e6e6e6;
        border-left: 6px solid #d8d8d8;
        padding: .85rem 1rem;
        font-weight: 900;
    }

    .comms-step span {
        display: block;
        color: #555;
        font-size: .85rem;
        font-weight: 700;
        margin-top: .2rem;
    }

    .comms-step.active {
        border-left-color: #7413dc;
        background: #f7f5fb;
        color: #4d0b93;
    }

    .comms-layout {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 1100px) {
        .comms-layout {
            grid-template-columns: minmax(0, 1.35fr) minmax(360px, .65fr);
            align-items: start;
        }
    }

    .comms-panel {
        background: #ffffff;
        border: 1px solid #e6e6e6;
        border-radius: .75rem;
        padding: 1.25rem;
        box-shadow: 0 2px 12px rgba(0, 0, 0, .04);
        margin-bottom: 1rem;
    }

    .comms-panel h2 {
        margin: 0 0 .85rem;
        color: #4d0b93;
        font-size: 1.35rem;
        font-weight: 900;
    }

    .comms-filter-grid {
        display: grid;
        gap: .75rem;
    }

    @media (min-width: 760px) {
        .comms-filter-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    .comms-check-grid {
        display: grid;
        gap: .45rem;
        max-height: 230px;
        overflow-y: auto;
        border: 1px solid #e6e6e6;
        border-radius: .5rem;
        padding: .75rem;
        background: #fafafa;
    }

    .comms-check-grid label {
        margin-bottom: 0;
    }

    .comms-select-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
        margin-bottom: .5rem;
    }

    .comms-select-actions button {
        border: 1px solid #d8d8d8;
        background: #ffffff;
        color: #4d0b93;
        font-weight: 900;
        border-radius: .3rem;
        padding: .25rem .5rem;
        cursor: pointer;
    }

    .comms-select-actions button:hover,
    .comms-select-actions button:focus {
        background: #f7f5fb;
    }

    .comms-accreditations {
        max-height: 280px;
        overflow-y: auto;
    }

    .comms-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        align-items: center;
    }

    .comms-recipient-list {
        max-height: 420px;
        overflow-y: auto;
        border: 1px solid #e6e6e6;
        border-radius: .5rem;
    }

    .comms-recipient-row {
        display: grid;
        gap: .2rem;
        padding: .7rem .85rem;
        border-bottom: 1px solid #e6e6e6;
        background: #ffffff;
    }

    .comms-recipient-row:last-child {
        border-bottom: 0;
    }

    .comms-recipient-row strong {
        color: #1d1d1b;
        font-weight: 900;
    }

    .comms-recipient-meta {
        color: #555;
        font-size: .9rem;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .comms-badge {
        display: inline-flex;
        align-items: center;
        align-self: flex-start;
        border-radius: 999px;
        background: #f3f2f1;
        color: #333;
        padding: .15rem .5rem;
        font-size: .75rem;
        font-weight: 900;
        margin-right: .25rem;
    }

    .comms-badge-purple {
        background: #f7f5fb;
        color: #4d0b93;
    }

    .comms-sticky {
        position: sticky;
        top: 1rem;
    }

    @media (max-width: 1099.98px) {
        .comms-sticky {
            position: static;
        }
    }

    .comms-editor-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
        padding: .5rem;
        border: 1px solid #d8d8d8;
        border-bottom: 0;
        border-radius: .5rem .5rem 0 0;
        background: #f7f5fb;
    }

    .comms-editor-toolbar button,
    .comms-editor-toolbar select {
        border: 1px solid #d8d8d8;
        background: #ffffff;
        color: #1d1d1b;
        font-weight: 900;
        border-radius: .25rem;
        padding: .35rem .55rem;
        min-height: 36px;
    }

    .comms-editor-toolbar button {
        cursor: pointer;
    }

    .comms-editor-toolbar button:hover,
    .comms-editor-toolbar button:focus {
        outline: 3px solid #ffdd00;
        outline-offset: 1px;
    }

    .comms-editor {
        min-height: 320px;
        border: 1px solid #d8d8d8;
        border-radius: 0 0 .5rem .5rem;
        padding: 1rem;
        background: #ffffff;
        line-height: 1.5;
    }

    .comms-editor:focus {
        outline: 3px solid #ffdd00;
        outline-offset: 2px;
    }

    .comms-editor p {
        margin-top: 0;
    }

    .comms-editor-help {
        background: #f7f5fb;
        border-left: 5px solid #7413dc;
        padding: .85rem;
        font-weight: 700;
        margin-top: .75rem;
    }

    .comms-inbox-preview {
        border: 1px solid #d8d8d8;
        border-radius: .75rem;
        overflow: hidden;
        background: #ffffff;
    }

    .comms-inbox-row {
        display: grid;
        gap: .15rem;
        padding: 1rem;
        border-bottom: 1px solid #e6e6e6;
    }

    @media (min-width: 700px) {
        .comms-inbox-row {
            grid-template-columns: 180px minmax(0, 1fr);
            align-items: start;
        }
    }

    .comms-inbox-from {
        font-weight: 900;
        color: #1d1d1b;
    }

    .comms-inbox-subject {
        font-weight: 900;
        color: #1d1d1b;
    }

    .comms-inbox-snippet {
        color: #555;
        font-weight: 700;
    }

    .comms-email-preview {
        padding: 1rem;
        background: #ffffff;
    }

    .comms-email-preview h1,
    .comms-email-preview h2,
    .comms-email-preview h3 {
        color: #4d0b93;
        font-weight: 900;
    }

    .comms-email-preview p {
        line-height: 1.5;
    }

    .comms-summary-grid {
        display: grid;
        gap: .75rem;
    }

    @media (min-width: 760px) {
        .comms-summary-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    .comms-summary-card {
        background: #f7f5fb;
        border: 1px solid #e6e6e6;
        border-radius: .5rem;
        padding: .9rem;
    }

    .comms-summary-card strong {
        display: block;
        color: #4d0b93;
        font-size: 1.7rem;
        line-height: 1;
        font-weight: 900;
    }

    .comms-all-people-box {
        border: 2px solid #7413dc;
        border-radius: .5rem;
        padding: 1rem;
        background: #f7f5fb;
        margin-bottom: 1rem;
    }

    .comms-empty-filter {
        background: #fff8d6;
        border-left: 5px solid #ffdd00;
        padding: .85rem;
        font-weight: 700;
    }
</style>

<main class="lt-main">
    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <strong>There is a problem:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="comms-steps" aria-label="Comms Tool progress">
        <div class="comms-step <?= $step === 'recipients' ? 'active' : '' ?>">
            1. Choose recipients
            <span>Select all people or build a filtered list.</span>
        </div>
        <div class="comms-step <?= $step === 'write' ? 'active' : '' ?>">
            2. Write email
            <span>Use the rich-text editor.</span>
        </div>
        <div class="comms-step <?= $step === 'preview' ? 'active' : '' ?>">
            3. Preview and confirm
            <span>Check before queueing.</span>
        </div>
    </div>

    <?php if ($step === 'recipients'): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="recipients">

            <div class="comms-layout">
                <section>
                    <div class="comms-panel">
                        <h2>1. Choose recipients</h2>

                        <div class="comms-all-people-box">
                            <label class="lt-check mb-0">
                                <input
                                    type="radio"
                                    name="audience_mode"
                                    value="all_people"
                                    <?= $form['audience_mode'] === 'all_people' ? 'checked' : '' ?>
                                    data-audience-mode
                                >
                                <span><strong>Send to all active people</strong></span>
                            </label>
                            <p class="mb-0 mt-2">This includes every active person with an email address, plus any manual recipients you add below.</p>
                        </div>

                        <label class="lt-check mb-3">
                            <input
                                type="radio"
                                name="audience_mode"
                                value="filtered"
                                <?= $form['audience_mode'] !== 'all_people' ? 'checked' : '' ?>
                                data-audience-mode
                            >
                            <span><strong>Build a targeted list</strong></span>
                        </label>

                        <div id="targeted-filters">
                            <div class="form-group">
                                <label for="keyword">Search people</label>
                                <input
                                    type="search"
                                    id="keyword"
                                    name="keyword"
                                    class="form-control"
                                    value="<?= e($form['keyword']) ?>"
                                    placeholder="Name, email, Group, role or accreditation"
                                >
                            </div>

                            <div class="comms-filter-grid">
                                <div class="form-group">
                                    <label>Groups</label>

                                    <div class="comms-select-actions">
                                        <button type="button" data-check-all="group_ids[]">Select all Groups</button>
                                        <button type="button" data-clear-all="group_ids[]">Clear Groups</button>
                                    </div>

                                    <div class="comms-check-grid">
                                        <?php foreach ($groups as $group): ?>
                                            <?php $gid = (int) $group['id']; ?>
                                            <label class="lt-check">
                                                <input
                                                    type="checkbox"
                                                    name="group_ids[]"
                                                    value="<?= $gid ?>"
                                                    <?= in_array($gid, $form['group_ids'], true) ? 'checked' : '' ?>
                                                >
                                                <span><?= e((string) $group['group_name']) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Roles currently in use</label>

                                    <?php if ($roleOptions): ?>
                                        <div class="comms-select-actions">
                                            <button type="button" data-check-all="role_titles[]">Select all Roles</button>
                                            <button type="button" data-clear-all="role_titles[]">Clear Roles</button>
                                        </div>

                                        <div class="comms-check-grid">
                                            <?php foreach ($roleOptions as $roleOption): ?>
                                                <label class="lt-check">
                                                    <input
                                                        type="checkbox"
                                                        name="role_titles[]"
                                                        value="<?= e($roleOption) ?>"
                                                        <?= in_array($roleOption, $form['role_titles'], true) ? 'checked' : '' ?>
                                                    >
                                                    <span><?= e($roleOption) ?> (<?= (int) ($roleCounts[$roleOption] ?? 0) ?>)</span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="comms-empty-filter">
                                            No active roles are currently linked to active people.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <details class="mb-3" <?= $form['accreditations'] ? 'open' : '' ?>>
                                <summary class="font-weight-bold">Filter by accreditations currently in use</summary>

                                <?php if ($accreditationOptions): ?>
                                    <div class="comms-select-actions mt-3">
                                        <button type="button" data-check-all="accreditations[]">Select all Accreditations</button>
                                        <button type="button" data-clear-all="accreditations[]">Clear Accreditations</button>
                                    </div>

                                    <div class="comms-check-grid comms-accreditations mt-2">
                                        <?php foreach ($accreditationOptions as $category => $items): ?>
                                            <div>
                                                <strong><?= e((string) $category) ?></strong>

                                                <?php foreach ($items as $item): ?>
                                                    <label class="lt-check">
                                                        <input
                                                            type="checkbox"
                                                            name="accreditations[]"
                                                            value="<?= e($item) ?>"
                                                            <?= in_array($item, $form['accreditations'], true) ? 'checked' : '' ?>
                                                        >
                                                        <span><?= e($item) ?> (<?= (int) ($accreditationCounts[$item] ?? 0) ?>)</span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="comms-empty-filter mt-3">
                                        No accreditations are currently linked to active people.
                                    </div>
                                <?php endif; ?>
                            </details>
                        </div>

                        <div class="form-group">
                            <label for="manual_recipients">Additional recipients</label>
                            <textarea
                                id="manual_recipients"
                                name="manual_recipients"
                                class="form-control"
                                rows="3"
                                placeholder="One per line, or comma separated. Example: Jane Smith <jane@example.com>"
                            ><?= e($form['manual_recipients']) ?></textarea>
                        </div>

                        <label class="lt-check">
                            <input
                                type="checkbox"
                                name="copy_self"
                                value="1"
                                <?= $form['copy_self'] ? 'checked' : '' ?>
                            >
                            <span>Send me a copy</span>
                        </label>

                        <div class="comms-actions mt-3">
                            <button class="btn btn-primary lt-btn" type="submit" name="action" value="to_write">
                                Continue to write email
                            </button>
                            <a class="btn lt-btn lt-btn-secondary" href="/comms-tool.php">Reset</a>
                        </div>
                    </div>
                </section>

                <aside class="comms-sticky">
                    <div class="comms-panel">
                        <h2>Recipient rules</h2>
                        <p>
                            Choose <strong>all active people</strong> for a whole-District message, or build a targeted list using Group, role, accreditation and search filters.
                        </p>
                        <p>
                            Roles and accreditations only appear here when they are linked to active people.
                        </p>
                        <p class="mb-0">
                            Recipients are deduplicated by email address before queueing.
                        </p>
                    </div>
                </aside>
            </div>
        </form>
    <?php elseif ($step === 'write'): ?>
        <form method="post" id="comms-write-form">
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="write">
            <?php comms_hidden_state_inputs($form, false); ?>
            <input type="hidden" name="body_html" id="body_html" value="<?= e($form['body_html']) ?>">

            <div class="comms-layout">
                <section>
                    <div class="comms-panel">
                        <h2>2. Write email</h2>

                        <div class="form-group">
                            <label for="subject">Subject</label>
                            <input
                                type="text"
                                id="subject"
                                name="subject"
                                class="form-control"
                                value="<?= e($form['subject']) ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="editor">Message</label>

                            <div class="comms-editor-toolbar" aria-label="Editor toolbar">
                                <select id="formatBlock" aria-label="Text style">
                                    <option value="p">Paragraph</option>
                                    <option value="h2">Heading</option>
                                    <option value="h3">Subheading</option>
                                </select>

                                <button type="button" data-command="bold"><strong>B</strong></button>
                                <button type="button" data-command="italic"><em>I</em></button>
                                <button type="button" data-command="underline"><u>U</u></button>
                                <button type="button" data-command="insertUnorderedList">Bullets</button>
                                <button type="button" data-command="insertOrderedList">Numbers</button>
                                <button type="button" data-link>Link</button>
                                <button type="button" data-command="removeFormat">Clear format</button>
                            </div>

                            <div
                                id="editor"
                                class="comms-editor"
                                contenteditable="true"
                                role="textbox"
                                aria-multiline="true"
                            ><?= $form['body_html'] ?: '<p>Hello {{name}},</p><p></p>' ?></div>

                            <div class="comms-editor-help">
                                Use <code>{{name}}</code> to insert the recipient’s name and <code>{{email}}</code> to insert their email address.
                            </div>
                        </div>

                        <div class="comms-actions mt-3">
                            <button class="btn lt-btn lt-btn-secondary" type="submit" name="action" value="back_to_recipients">
                                Back
                            </button>

                            <button class="btn btn-primary lt-btn" type="submit" name="action" value="to_preview">
                                Preview email
                            </button>
                        </div>
                    </div>
                </section>

                <aside class="comms-sticky">
                    <div class="comms-panel">
                        <h2>Selected audience</h2>

                        <p class="font-weight-bold mb-2">
                            <?= count($recipients) ?> recipient<?= count($recipients) === 1 ? '' : 's' ?>
                        </p>

                        <div class="comms-recipient-list">
                            <?php foreach (array_slice($recipients, 0, 30) as $recipient): ?>
                                <div class="comms-recipient-row">
                                    <strong><?= e($recipient['name'] ?: $recipient['email']) ?></strong>
                                    <span class="comms-recipient-meta"><?= e($recipient['email']) ?></span>
                                    <?php if (!empty($recipient['groups'])): ?>
                                        <span class="comms-recipient-meta"><?= e($recipient['groups']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (count($recipients) > 30): ?>
                            <p class="mt-2 mb-0 font-weight-bold">Showing first 30 recipients.</p>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>
        </form>
    <?php elseif ($step === 'preview'): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="preview">
            <?php comms_hidden_state_inputs($form, true); ?>

            <div class="comms-layout">
                <section>
                    <div class="comms-panel">
                        <h2>3. Preview and confirm</h2>

                        <div class="comms-summary-grid mb-4">
                            <div class="comms-summary-card">
                                <strong><?= count($recipients) ?></strong>
                                <span>recipient<?= count($recipients) === 1 ? '' : 's' ?></span>
                            </div>

                            <div class="comms-summary-card">
                                <strong><?= e($form['audience_mode'] === 'all_people' ? 'All' : 'Filtered') ?></strong>
                                <span>audience type</span>
                            </div>

                            <div class="comms-summary-card">
                                <strong>Queue</strong>
                                <span>emails are sent by the cron job</span>
                            </div>
                        </div>

                        <h3 class="h5 font-weight-bold">Inbox preview</h3>

                        <div class="comms-inbox-preview mb-4">
                            <div class="comms-inbox-row">
                                <div class="comms-inbox-from">
                                    <?= e((string) app_config('SMTP_FROM_NAME', 'Irwell Valley Scout District')) ?>
                                </div>
                                <div>
                                    <div class="comms-inbox-subject"><?= e($form['subject']) ?></div>
                                    <div class="comms-inbox-snippet"><?= e($previewSnippet) ?></div>
                                </div>
                            </div>

                            <div class="comms-email-preview">
                                <?= $previewHtml ?>
                            </div>
                        </div>

                        <div class="alert alert-warning">
                            <strong>Check carefully before confirming.</strong>
                            This will add one pending email per recipient into the email queue.
                        </div>

                        <div class="comms-actions mt-3">
                            <button class="btn lt-btn lt-btn-secondary" type="submit" name="action" value="back_to_write">
                                Back to edit
                            </button>

                            <button
                                class="btn btn-primary lt-btn"
                                type="submit"
                                name="action"
                                value="send"
                                onclick="return confirm('Queue this email to <?= count($recipients) ?> recipient<?= count($recipients) === 1 ? '' : 's' ?>?');"
                            >
                                Confirm and queue emails
                            </button>
                        </div>
                    </div>
                </section>

                <aside class="comms-sticky">
                    <div class="comms-panel">
                        <h2>Recipients</h2>

                        <p class="font-weight-bold">
                            <?= count($recipients) ?> recipient<?= count($recipients) === 1 ? '' : 's' ?>
                        </p>

                        <div class="comms-recipient-list">
                            <?php foreach ($recipients as $recipient): ?>
                                <div class="comms-recipient-row">
                                    <strong><?= e($recipient['name'] ?: $recipient['email']) ?></strong>

                                    <span class="comms-recipient-meta"><?= e($recipient['email']) ?></span>

                                    <span>
                                        <?php if ($recipient['source'] === 'manual'): ?>
                                            <span class="comms-badge">Manual</span>
                                        <?php elseif ($recipient['source'] === 'self_copy'): ?>
                                            <span class="comms-badge">Self copy</span>
                                        <?php else: ?>
                                            <span class="comms-badge comms-badge-purple">Directory</span>
                                        <?php endif; ?>

                                        <?php if (!empty($recipient['role_title'])): ?>
                                            <span class="comms-badge"><?= e($recipient['role_title']) ?></span>
                                        <?php endif; ?>
                                    </span>

                                    <?php if (!empty($recipient['groups'])): ?>
                                        <span class="comms-recipient-meta"><?= e($recipient['groups']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    <?php endif; ?>
</main>

<script <?= csp_nonce_attr() ?>>
(function () {
    function syncEditor() {
        var editor = document.getElementById('editor');
        var hidden = document.getElementById('body_html');

        if (editor && hidden) {
            hidden.value = editor.innerHTML;
        }
    }

    document.querySelectorAll('[data-check-all]').forEach(function (button) {
        button.addEventListener('click', function () {
            var name = button.getAttribute('data-check-all');
            document.querySelectorAll('input[name="' + CSS.escape(name) + '"]').forEach(function (checkbox) {
                checkbox.checked = true;
            });
        });
    });

    document.querySelectorAll('[data-clear-all]').forEach(function (button) {
        button.addEventListener('click', function () {
            var name = button.getAttribute('data-clear-all');
            document.querySelectorAll('input[name="' + CSS.escape(name) + '"]').forEach(function (checkbox) {
                checkbox.checked = false;
            });
        });
    });

    function updateAudienceMode() {
        var selected = document.querySelector('[data-audience-mode]:checked');
        var filters = document.getElementById('targeted-filters');

        if (!selected || !filters) {
            return;
        }

        filters.style.display = selected.value === 'all_people' ? 'none' : '';
    }

    document.querySelectorAll('[data-audience-mode]').forEach(function (radio) {
        radio.addEventListener('change', updateAudienceMode);
    });

    updateAudienceMode();

    document.querySelectorAll('[data-command]').forEach(function (button) {
        button.addEventListener('click', function () {
            var command = button.getAttribute('data-command');
            document.execCommand(command, false, null);
            syncEditor();

            var editor = document.getElementById('editor');
            if (editor) editor.focus();
        });
    });

    var formatBlock = document.getElementById('formatBlock');

    if (formatBlock) {
        formatBlock.addEventListener('change', function () {
            document.execCommand('formatBlock', false, formatBlock.value);
            syncEditor();

            var editor = document.getElementById('editor');
            if (editor) editor.focus();
        });
    }

    document.querySelectorAll('[data-link]').forEach(function (button) {
        button.addEventListener('click', function () {
            var url = window.prompt('Enter the link URL');

            if (!url) {
                return;
            }

            if (!/^https?:\/\//i.test(url) && !/^mailto:/i.test(url)) {
                url = 'https://' + url;
            }

            document.execCommand('createLink', false, url);
            syncEditor();

            var editor = document.getElementById('editor');
            if (editor) editor.focus();
        });
    });

    var editor = document.getElementById('editor');

    if (editor) {
        editor.addEventListener('input', syncEditor);
        editor.addEventListener('blur', syncEditor);
    }

    var writeForm = document.getElementById('comms-write-form');

    if (writeForm) {
        writeForm.addEventListener('submit', syncEditor);
    }
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>