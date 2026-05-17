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

function comms_markdown_inline(string $text): string
{
    $text = e($text);

    $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/__(.*?)__/s', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/(?<!\*)\*(?!\s)(.*?)(?<!\s)\*(?!\*)/s', '<em>$1</em>', $text) ?? $text;
    $text = preg_replace('/(?<!_)_(?!\s)(.*?)(?<!\s)_(?!_)/s', '<em>$1</em>', $text) ?? $text;
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
    $text = preg_replace(
        '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/',
        '<a href="$2">$1</a>',
        $text
    ) ?? $text;

    return $text;
}

function comms_markdown_to_html(string $markdown): string
{
    $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));
    $lines = explode("\n", $markdown);

    $html = '';
    $paragraph = [];
    $inList = false;

    $flushParagraph = static function () use (&$html, &$paragraph): void {
        if (!$paragraph) {
            return;
        }

        $text = implode(' ', array_map('trim', $paragraph));
        $html .= '<p>' . comms_markdown_inline($text) . '</p>' . "\n";
        $paragraph = [];
    };

    $closeList = static function () use (&$html, &$inList): void {
        if ($inList) {
            $html .= '</ul>' . "\n";
            $inList = false;
        }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            $flushParagraph();
            $closeList();
            continue;
        }

        if (preg_match('/^###\s+(.+)$/', $trimmed, $matches)) {
            $flushParagraph();
            $closeList();
            $html .= '<h3>' . comms_markdown_inline($matches[1]) . '</h3>' . "\n";
            continue;
        }

        if (preg_match('/^##\s+(.+)$/', $trimmed, $matches)) {
            $flushParagraph();
            $closeList();
            $html .= '<h2>' . comms_markdown_inline($matches[1]) . '</h2>' . "\n";
            continue;
        }

        if (preg_match('/^#\s+(.+)$/', $trimmed, $matches)) {
            $flushParagraph();
            $closeList();
            $html .= '<h1>' . comms_markdown_inline($matches[1]) . '</h1>' . "\n";
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $matches)) {
            $flushParagraph();

            if (!$inList) {
                $html .= '<ul>' . "\n";
                $inList = true;
            }

            $html .= '<li>' . comms_markdown_inline($matches[1]) . '</li>' . "\n";
            continue;
        }

        $paragraph[] = $trimmed;
    }

    $flushParagraph();
    $closeList();

    return $html;
}

function comms_plain_preview(string $markdown): string
{
    $plain = preg_replace('/\[(.*?)\]\((.*?)\)/', '$1 ($2)', $markdown) ?? $markdown;
    $plain = str_replace(['**', '__', '*', '_', '`', '#'], '', $plain);

    return trim($plain);
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

function comms_fetch_recipients(array $filters, array $user): array
{
    $where = [
        "p.status = 'active'",
        "p.primary_email IS NOT NULL",
        "p.primary_email <> ''",
    ];

    $params = [];

    $groupIds = array_values(array_filter(array_map('intval', (array) ($filters['group_ids'] ?? []))));
    $roleTitles = array_values(array_filter(array_map('strval', (array) ($filters['role_titles'] ?? []))));
    $accreditations = array_values(array_filter(array_map('strval', (array) ($filters['accreditations'] ?? []))));
    $keyword = trim((string) ($filters['keyword'] ?? ''));
    $microsoftFilter = (string) ($filters['microsoft_filter'] ?? 'all');

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

    foreach ($accreditations as $index => $accreditation) {
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

    if ($microsoftFilter === 'linked') {
        $where[] = "ua.id IS NOT NULL";
    } elseif ($microsoftFilter === 'not_linked') {
        $where[] = "ua.id IS NULL";
    }

    $whereSql = implode("\n      AND ", $where);

    $sql = "
        SELECT
            p.id,
            p.full_name,
            p.primary_email,
            dp.role_title,
            dp.accreditations_json,
            GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.group_name SEPARATOR ', ') AS group_names,
            MAX(CASE WHEN ua.provider = 'microsoft' THEN 1 ELSE 0 END) AS has_microsoft_account
        FROM people p
        LEFT JOIN directory_profiles dp
          ON dp.person_id = p.id
        LEFT JOIN group_memberships gm
          ON gm.person_id = p.id
         AND gm.status = 'active'
        LEFT JOIN groups g
          ON g.id = gm.group_id
         AND g.is_active = 1
        LEFT JOIN user_accounts ua
          ON ua.person_id = p.id
         AND ua.provider = 'microsoft'
        WHERE {$whereSql}
        GROUP BY
            p.id,
            p.full_name,
            p.primary_email,
            dp.role_title,
            dp.accreditations_json
        ORDER BY p.full_name ASC
        LIMIT 2000
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $recipients = [];

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
            'has_microsoft_account' => (int) ($row['has_microsoft_account'] ?? 0) === 1,
        ];
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
                'has_microsoft_account' => true,
            ];
        }
    }

    return array_values($recipients);
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

$roleOptions = portal_role_options();
$accreditationOptions = portal_accreditation_options();
$allowedAccreditations = portal_flatten_options($accreditationOptions);

$errors = [];
$success = null;
$mode = (string) ($_POST['action'] ?? 'compose');

$form = [
    'keyword' => trim((string) ($_POST['keyword'] ?? '')),
    'group_ids' => $_POST['group_ids'] ?? [],
    'role_titles' => $_POST['role_titles'] ?? [],
    'accreditations' => $_POST['accreditations'] ?? [],
    'microsoft_filter' => (string) ($_POST['microsoft_filter'] ?? 'all'),
    'manual_recipients' => (string) ($_POST['manual_recipients'] ?? ''),
    'copy_self' => isset($_POST['copy_self']) ? 1 : 0,
    'subject' => trim((string) ($_POST['subject'] ?? '')),
    'body_markdown' => (string) ($_POST['body_markdown'] ?? ''),
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

if (!in_array($form['microsoft_filter'], ['all', 'linked', 'not_linked'], true)) {
    $form['microsoft_filter'] = 'all';
}

$recipients = [];
$previewHtml = '';
$previewPlain = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipients = comms_fetch_recipients($form, $user);

    if ($form['subject'] === '') {
        $errors[] = 'Enter an email subject.';
    }

    if (trim($form['body_markdown']) === '') {
        $errors[] = 'Enter the email message.';
    }

    if (!$recipients) {
        $errors[] = 'No recipients matched this query. Adjust the filters or add manual recipients.';
    }

    if (!$errors) {
        $previewHtml = comms_markdown_to_html($form['body_markdown']);
        $previewPlain = comms_plain_preview($form['body_markdown']);

        if ($mode === 'send') {
            $pdo->beginTransaction();

            try {
                foreach ($recipients as $recipient) {
                    $personalisedHtml = str_replace(
                        ['{{name}}', '{{email}}'],
                        [e($recipient['name'] ?: 'there'), e($recipient['email'])],
                        $previewHtml
                    );

                    $personalisedPlain = str_replace(
                        ['{{name}}', '{{email}}'],
                        [$recipient['name'] ?: 'there', $recipient['email']],
                        $previewPlain
                    );

                    $queueData = [
                        'to_email' => $recipient['email'],
                        'to_name' => $recipient['name'] ?: null,
                        'subject' => $form['subject'],
                        'body' => comms_column_exists('email_queue', 'body_html') ? $personalisedPlain : $personalisedHtml,
                        'body_html' => $personalisedHtml,
                        'body_markdown' => $form['body_markdown'],
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
                        'keyword' => $form['keyword'],
                        'group_ids' => $form['group_ids'],
                        'role_titles' => $form['role_titles'],
                        'accreditations' => $form['accreditations'],
                        'microsoft_filter' => $form['microsoft_filter'],
                        'copy_self' => (bool) $form['copy_self'],
                        'manual_recipient_count' => count(comms_split_manual_recipients($form['manual_recipients'])),
                    ],
                ]);

                $pdo->commit();

                $success = count($recipients) . ' email' . (count($recipients) === 1 ? '' : 's') . ' added to the email queue.';
                $mode = 'compose';
                $recipients = [];
                $previewHtml = '';
                $previewPlain = '';

                $form['subject'] = '';
                $form['body_markdown'] = '';
                $form['manual_recipients'] = '';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'The emails could not be queued. ' . $e->getMessage();
            }
        } else {
            $mode = 'preview';
        }
    }
}

?>
<?php include __DIR__ . '/header.php'; ?>

<style>
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
        max-height: 220px;
        overflow-y: auto;
        border: 1px solid #e6e6e6;
        border-radius: .5rem;
        padding: .75rem;
        background: #fafafa;
    }

    .comms-check-grid label {
        margin-bottom: 0;
    }

    .comms-accreditations {
        max-height: 260px;
        overflow-y: auto;
    }

    .comms-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        align-items: center;
    }

    .comms-markdown-help {
        background: #f7f5fb;
        border-left: 5px solid #7413dc;
        padding: .85rem;
        font-weight: 700;
        margin-top: .75rem;
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

    .comms-preview {
        border: 1px solid #e6e6e6;
        border-radius: .5rem;
        background: #ffffff;
        padding: 1rem;
    }

    .comms-preview h1,
    .comms-preview h2,
    .comms-preview h3 {
        color: #4d0b93;
        font-weight: 900;
    }

    .comms-preview p {
        line-height: 1.5;
    }

    .comms-preview code {
        background: #f3f2f1;
        padding: .1rem .25rem;
        border-radius: .25rem;
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

    <form method="post">
        <div class="comms-layout">
            <section>
                <div class="comms-panel">
                    <h2>1. Choose recipients</h2>

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
                            <label>Roles</label>
                            <div class="comms-check-grid">
                                <?php foreach ($roleOptions as $roleOption): ?>
                                    <label class="lt-check">
                                        <input
                                            type="checkbox"
                                            name="role_titles[]"
                                            value="<?= e($roleOption) ?>"
                                            <?= in_array($roleOption, $form['role_titles'], true) ? 'checked' : '' ?>
                                        >
                                        <span><?= e($roleOption) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="microsoft_filter">Microsoft 365 status</label>
                        <select id="microsoft_filter" name="microsoft_filter" class="form-control">
                            <option value="all" <?= $form['microsoft_filter'] === 'all' ? 'selected' : '' ?>>All active people</option>
                            <option value="linked" <?= $form['microsoft_filter'] === 'linked' ? 'selected' : '' ?>>Only people linked to Microsoft 365</option>
                            <option value="not_linked" <?= $form['microsoft_filter'] === 'not_linked' ? 'selected' : '' ?>>Only people not linked to Microsoft 365</option>
                        </select>
                    </div>

                    <details class="mb-3" <?= $form['accreditations'] ? 'open' : '' ?>>
                        <summary class="font-weight-bold">Filter by accreditations</summary>

                        <div class="comms-check-grid comms-accreditations mt-3">
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
                                            <span><?= e($item) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>

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
                </div>

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
                        <label for="body_markdown">Message</label>
                        <textarea
                            id="body_markdown"
                            name="body_markdown"
                            class="form-control"
                            rows="14"
                            required
                            placeholder="Write your email using Markdown..."
                        ><?= e($form['body_markdown']) ?></textarea>
                    </div>

                    <div class="comms-markdown-help">
                        Markdown examples:
                        <code>**bold**</code>,
                        <code>*italic*</code>,
                        <code>- bullet</code>,
                        <code>## heading</code>,
                        <code>[link text](https://example.com)</code>.
                        You can also use <code>{{name}}</code> and <code>{{email}}</code>.
                    </div>

                    <div class="comms-actions mt-3">
                        <button class="btn btn-primary lt-btn" type="submit" name="action" value="preview">
                            Preview email
                        </button>

                        <a class="btn lt-btn lt-btn-secondary" href="/comms-tool.php">
                            Reset
                        </a>
                    </div>
                </div>

                <?php if ($mode === 'preview' && !$errors): ?>
                    <div class="comms-panel">
                        <h2>3. Preview and queue</h2>

                        <p class="font-weight-bold">
                            This will queue <?= count($recipients) ?> email<?= count($recipients) === 1 ? '' : 's' ?>.
                        </p>

                        <div class="mb-3">
                            <strong>Subject:</strong>
                            <?= e($form['subject']) ?>
                        </div>

                        <div class="comms-preview">
                            <?= $previewHtml ?>
                        </div>

                        <div class="comms-actions mt-3">
                            <button
                                class="btn btn-primary lt-btn"
                                type="submit"
                                name="action"
                                value="send"
                                onclick="return confirm('Queue this email to <?= count($recipients) ?> recipient<?= count($recipients) === 1 ? '' : 's' ?>?');"
                            >
                                Queue emails
                            </button>

                            <button class="btn lt-btn lt-btn-secondary" type="submit" name="action" value="preview">
                                Refresh preview
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <aside class="comms-sticky">
                <div class="comms-panel">
                    <h2>Recipients</h2>

                    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
                        <p class="mb-0">
                            Build your filters and click <strong>Preview email</strong> to see the matching recipients.
                        </p>
                    <?php elseif (!$recipients): ?>
                        <p class="mb-0">No recipients matched.</p>
                    <?php else: ?>
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
                    <?php endif; ?>
                </div>

                <div class="comms-panel">
                    <h2>Sending notes</h2>
                    <p>
                        Emails are added to the queue as pending. Your cron job will pick them up and send them using your HTML email template.
                    </p>
                    <p class="mb-0">
                        Recipients are deduplicated by email address before queueing.
                    </p>
                </div>
            </aside>
        </div>
    </form>
</main>

<?php include __DIR__ . '/footer.php'; ?>