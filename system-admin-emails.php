<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/system-admin-helpers.php';

$user = sa_require_system_admin();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

// ─── Helpers (defensive: the email_queue schema varies by environment) ───────

function sa_emails_table_exists(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'email_queue'
        ");
        $stmt->execute();
        return $exists = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $exists = false;
    }
}

function sa_emails_columns(): array
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

function sa_emails_has(string $column): bool
{
    return in_array($column, sa_emails_columns(), true);
}

function sa_emails_safe_count(string $sql, array $params = []): int
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

$tableExists = sa_emails_table_exists();
$hasStatus = $tableExists && sa_emails_has('status');
$hasSentAt = $tableExists && sa_emails_has('sent_at');
$hasCreatedAt = $tableExists && sa_emails_has('created_at');
$hasBodyHtml = $tableExists && sa_emails_has('body_html');
$hasBody = $tableExists && sa_emails_has('body');
$hasToName = $tableExists && sa_emails_has('to_name');
$hasAttempts = $tableExists && sa_emails_has('attempt_count');
$hasLastError = $tableExists && sa_emails_has('last_error');

// ─── Filters ─────────────────────────────────────────────────────────────────

$allowedStatuses = ['sent', 'pending', 'processing', 'failed'];
$statusFilter = (string) ($_GET['status'] ?? 'sent');
if (!in_array($statusFilter, array_merge(['all'], $allowedStatuses), true)) {
    $statusFilter = 'sent';
}

$search = trim((string) ($_GET['q'] ?? ''));

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// ─── Counts for the status tabs ──────────────────────────────────────────────

$counts = [
    'all' => 0,
    'sent' => 0,
    'pending' => 0,
    'processing' => 0,
    'failed' => 0,
];

if ($tableExists) {
    $counts['all'] = sa_emails_safe_count("SELECT COUNT(*) FROM email_queue");
    if ($hasStatus) {
        foreach ($allowedStatuses as $s) {
            $counts[$s] = sa_emails_safe_count(
                "SELECT COUNT(*) FROM email_queue WHERE status = :s",
                ['s' => $s]
            );
        }
    }
}

// ─── Build the query ─────────────────────────────────────────────────────────

$rows = [];
$total = 0;
$orderColumn = $hasSentAt ? 'sent_at' : ($hasCreatedAt ? 'created_at' : 'id');

if ($tableExists) {
    $where = [];
    $params = [];

    if ($statusFilter !== 'all' && $hasStatus) {
        $where[] = 'status = :status';
        $params['status'] = $statusFilter;
    }

    if ($search !== '') {
        $searchParts = ['to_email LIKE :search', 'subject LIKE :search'];
        if ($hasToName) {
            $searchParts[] = 'to_name LIKE :search';
        }
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
        $params['search'] = '%' . $search . '%';
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $total = sa_emails_safe_count("SELECT COUNT(*) FROM email_queue {$whereClause}", $params);

    try {
        $stmt = db()->prepare("
            SELECT *
            FROM email_queue
            {$whereClause}
            ORDER BY COALESCE({$orderColumn}, id) DESC, id DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }
}

$totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

function sa_email_status_badge(string $status): string
{
    $map = [
        'sent' => 'em-badge-ok',
        'pending' => 'em-badge-pending',
        'processing' => 'em-badge-warn',
        'failed' => 'em-badge-error',
    ];
    $class = $map[$status] ?? 'em-badge-stale';
    return '<span class="em-badge ' . $class . '">' . e($status !== '' ? $status : 'unknown') . '</span>';
}

function sa_email_preview(string $html, int $length = 140): string
{
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    if (mb_strlen($text) > $length) {
        $text = mb_substr($text, 0, $length) . '…';
    }
    return $text;
}

$saNavCurrent = 'emails';

$pageTitle = 'System Admin — Email Queue | ' . $appName;
$heroTitle = 'System Admin';
$heroText = 'Recently sent emails and the delivery queue.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/system-admin.php">System Admin</a> / Email Queue';

include __DIR__ . '/header.php';
?>

<style>
    .sa-service-bar { background: #1d1d1b; border-bottom: 4px solid #ffdd00; }
    .sa-service-bar-inner { max-width: 1180px; margin: 0 auto; padding: 0 1rem; display: flex; align-items: stretch; gap: 0; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .sa-nav-link { display: inline-flex; align-items: center; padding: .85rem 1.1rem; color: rgba(255,255,255,.88); font-weight: 900; font-size: .92rem; text-decoration: none; white-space: nowrap; border-bottom: 4px solid transparent; margin-bottom: -4px; transition: background .1s, border-color .1s; }
    .sa-nav-link:hover { color: #fff; background: rgba(255,255,255,.08); text-decoration: none; }
    .sa-nav-link:focus { outline: 3px solid #ffdd00; outline-offset: -3px; color: #fff; }
    .sa-nav-link[aria-current="page"] { color: #fff; border-bottom-color: #ffdd00; background: rgba(255,255,255,.06); }

    .em-toolbar { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; justify-content: space-between; margin-bottom: 1.25rem; }
    .em-tabs { display: flex; flex-wrap: wrap; gap: .35rem; }
    .em-tab { display: inline-flex; align-items: center; gap: .4rem; padding: .45rem .8rem; border: 2px solid #e5e5e5; background: #fff; color: #1d1d1b; font-weight: 900; font-size: .85rem; text-decoration: none; }
    .em-tab:hover { border-color: #4d0b93; color: #4d0b93; text-decoration: none; }
    .em-tab[aria-current="page"] { background: #4d0b93; border-color: #4d0b93; color: #fff; }
    .em-tab-count { background: rgba(0,0,0,.08); padding: 0 .4rem; font-size: .72rem; border-radius: 2px; }
    .em-tab[aria-current="page"] .em-tab-count { background: rgba(255,255,255,.22); }

    .em-search { display: flex; gap: .5rem; }
    .em-search input[type="search"] { padding: .5rem .7rem; border: 2px solid #d8d8d8; font-size: .9rem; min-width: 220px; }
    .em-search button { padding: .5rem 1rem; border: 0; background: #4d0b93; color: #fff; font-weight: 900; cursor: pointer; }
    .em-search button:hover { background: #3a0870; }

    .em-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    .em-table th, .em-table td { border-bottom: 1px solid #e5e5e5; padding: .6rem .6rem; text-align: left; vertical-align: top; }
    .em-table th { background: #f7f5fb; font-weight: 900; color: #4d0b93; white-space: nowrap; }
    .em-table tr:hover td { background: #faf8fd; }
    .em-recipient { font-weight: 700; color: #1d1d1b; overflow-wrap: anywhere; }
    .em-recipient-name { color: #666; font-weight: 400; font-size: .78rem; }
    .em-subject { overflow-wrap: anywhere; }
    .em-preview { color: #777; font-size: .78rem; margin-top: .2rem; }
    .em-muted { color: #666; font-size: .8rem; white-space: nowrap; }
    .em-error { color: #842029; font-size: .78rem; overflow-wrap: anywhere; }

    .em-badge { display: inline-block; padding: .12rem .45rem; font-size: .7rem; font-weight: 900; text-transform: uppercase; }
    .em-badge-ok { background: #d1e7dd; color: #0f5132; }
    .em-badge-warn { background: #fff3cd; color: #664d03; }
    .em-badge-error { background: #f8d7da; color: #842029; }
    .em-badge-pending { background: #e7f1ff; color: #004085; }
    .em-badge-stale { background: #f5f5f5; color: #666; }

    .em-view-btn { padding: .3rem .6rem; border: 2px solid #d8d8d8; background: #fff; color: #4d0b93; font-weight: 900; font-size: .75rem; cursor: pointer; }
    .em-view-btn:hover { border-color: #4d0b93; }

    .em-empty { padding: 2rem; text-align: center; color: #666; background: #faf8fd; border: 2px dashed #e0d9ee; }

    .em-pagination { display: flex; gap: .5rem; align-items: center; justify-content: center; margin-top: 1.5rem; }
    .em-pagination a, .em-pagination span { padding: .4rem .8rem; border: 2px solid #e5e5e5; font-weight: 900; font-size: .82rem; color: #4d0b93; text-decoration: none; }
    .em-pagination a:hover { border-color: #4d0b93; }
    .em-pagination .em-page-current { background: #4d0b93; color: #fff; border-color: #4d0b93; }

    .em-detail-body { background: #f7f5fb; border: 1px solid #e0d9ee; padding: .75rem; margin: .5rem 0 0; display: none; }
    .em-detail-body.is-open { display: block; }
    .em-detail-body iframe { width: 100%; min-height: 320px; border: 1px solid #ddd; background: #fff; }
    .em-detail-plain { white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; color: #333; }
</style>

<nav class="sa-service-bar" aria-label="System Admin navigation">
    <div class="sa-service-bar-inner">
        <a class="sa-nav-link" href="/system-admin-dashboard.php" <?= $saNavCurrent === 'dashboard' ? 'aria-current="page"' : '' ?>>Dashboard</a>
        <a class="sa-nav-link" href="/system-admin.php" <?= $saNavCurrent === 'audit-log' ? 'aria-current="page"' : '' ?>>Audit Log</a>
        <a class="sa-nav-link" href="/system-admin-emails.php" <?= $saNavCurrent === 'emails' ? 'aria-current="page"' : '' ?>>Email Queue</a>
        <a class="sa-nav-link" href="/system-admin-cron.php" <?= $saNavCurrent === 'cron' ? 'aria-current="page"' : '' ?>>Cron Jobs</a>
        <a class="sa-nav-link" href="/system-admin-gdpr.php" <?= $saNavCurrent === 'gdpr' ? 'aria-current="page"' : '' ?>>GDPR</a>
        <a class="sa-nav-link" href="/system-admin-permissions.php" <?= $saNavCurrent === 'permissions' ? 'aria-current="page"' : '' ?>>Permissions</a>
        <a class="sa-nav-link" href="/system-admin-person.php" <?= $saNavCurrent === 'person' ? 'aria-current="page"' : '' ?>>Person Lookup</a>
        <a class="sa-nav-link" href="/system-admin-kb.php" <?= $saNavCurrent === 'kb' ? 'aria-current="page"' : '' ?>>KB</a>
    </div>
</nav>

<main class="lt-main">

<?php if (!$tableExists): ?>
    <div class="em-empty">
        The <code>email_queue</code> table was not found in this database, so there are no emails to display.
    </div>
<?php else: ?>

    <div class="em-toolbar">
        <div class="em-tabs">
            <?php
                $tabs = [
                    'sent' => 'Sent',
                    'pending' => 'Pending',
                    'processing' => 'Processing',
                    'failed' => 'Failed',
                    'all' => 'All',
                ];
                foreach ($tabs as $key => $label):
                    $qs = http_build_query(array_filter([
                        'status' => $key,
                        'q' => $search !== '' ? $search : null,
                    ]));
            ?>
                <a class="em-tab" href="/system-admin-emails.php?<?= e($qs) ?>" <?= $statusFilter === $key ? 'aria-current="page"' : '' ?>>
                    <?= e($label) ?>
                    <span class="em-tab-count"><?= number_format($counts[$key] ?? 0) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <form class="em-search" method="get" action="/system-admin-emails.php">
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search recipient or subject" aria-label="Search emails">
            <button type="submit">Search</button>
        </form>
    </div>

    <?php if (!$rows): ?>
        <div class="em-empty">
            No emails match this view<?= $search !== '' ? ' for &ldquo;' . e($search) . '&rdquo;' : '' ?>.
        </div>
    <?php else: ?>
        <table class="em-table">
            <thead>
                <tr>
                    <th>Recipient</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th><?= $hasSentAt ? 'Sent' : 'Created' ?></th>
                    <?php if ($hasAttempts): ?><th>Attempts</th><?php endif; ?>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
                $id = (int) ($row['id'] ?? 0);
                $status = (string) ($row['status'] ?? '');
                $bodyHtml = $hasBodyHtml ? (string) ($row['body_html'] ?? '') : '';
                $bodyPlain = $hasBody ? (string) ($row['body'] ?? '') : '';
                $previewSource = $bodyHtml !== '' ? $bodyHtml : $bodyPlain;
                $sentAt = $hasSentAt ? ($row['sent_at'] ?? null) : null;
                $createdAt = $hasCreatedAt ? ($row['created_at'] ?? null) : null;
                $displayTime = $sentAt ?: $createdAt;
                $lastError = $hasLastError ? trim((string) ($row['last_error'] ?? '')) : '';
            ?>
                <tr>
                    <td>
                        <div class="em-recipient"><?= e((string) ($row['to_email'] ?? '')) ?></div>
                        <?php if ($hasToName && !empty($row['to_name'])): ?>
                            <div class="em-recipient-name"><?= e((string) $row['to_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="em-subject"><?= e((string) ($row['subject'] ?? '(no subject)')) ?></div>
                        <?php if ($previewSource !== ''): ?>
                            <div class="em-preview"><?= e(sa_email_preview($previewSource)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= sa_email_status_badge($status) ?></td>
                    <td class="em-muted">
                        <?= $displayTime ? e(date('d M Y H:i', strtotime((string) $displayTime))) : '—' ?>
                    </td>
                    <?php if ($hasAttempts): ?>
                        <td class="em-muted"><?= (int) ($row['attempt_count'] ?? 0) ?></td>
                    <?php endif; ?>
                    <td>
                        <?php if ($previewSource !== '' || $lastError !== ''): ?>
                            <button type="button" class="em-view-btn" data-email-toggle="email-body-<?= $id ?>" aria-expanded="false">View</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($previewSource !== '' || $lastError !== ''): ?>
                <tr>
                    <td colspan="<?= $hasAttempts ? 6 : 5 ?>" style="padding: 0;">
                        <div class="em-detail-body" id="email-body-<?= $id ?>">
                            <?php if ($lastError !== ''): ?>
                                <p class="em-error"><strong>Last error:</strong> <?= e($lastError) ?></p>
                            <?php endif; ?>
                            <?php if ($bodyHtml !== ''): ?>
                                <iframe sandbox="" srcdoc="<?= e($bodyHtml) ?>" title="Email body preview"></iframe>
                            <?php elseif ($bodyPlain !== ''): ?>
                                <div class="em-detail-plain"><?= e($bodyPlain) ?></div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
            <nav class="em-pagination" aria-label="Pagination">
                <?php
                    $baseParams = array_filter([
                        'status' => $statusFilter,
                        'q' => $search !== '' ? $search : null,
                    ]);
                    $pageUrl = static function (int $p) use ($baseParams): string {
                        return '/system-admin-emails.php?' . http_build_query($baseParams + ['page' => $p]);
                    };
                ?>
                <?php if ($page > 1): ?>
                    <a href="<?= e($pageUrl($page - 1)) ?>">&larr; Prev</a>
                <?php endif; ?>
                <span class="em-page-current">Page <?= number_format($page) ?> of <?= number_format($totalPages) ?></span>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= e($pageUrl($page + 1)) ?>">Next &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

<?php endif; ?>

</main>

<script <?= csp_nonce_attr() ?>>
(function () {
    document.querySelectorAll('[data-email-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-email-toggle'));
            if (!target) return;
            var open = target.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.textContent = open ? 'Hide' : 'View';
        });
    });
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>
