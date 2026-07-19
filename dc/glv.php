<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$ctx = dc_require_access();

$accessLevels = array_filter(array_map('strval', (array) ($ctx['access_levels'] ?? [])));
$membershipRoles = array_filter(array_map('strval', (array) ($ctx['membership_roles'] ?? [])));

if (!empty($ctx['access_level'])) {
    $accessLevels[] = (string) $ctx['access_level'];
}

if (!empty($ctx['highest_access_level'])) {
    $accessLevels[] = (string) $ctx['highest_access_level'];
}

if (!empty($ctx['membership_role'])) {
    $membershipRoles[] = (string) $ctx['membership_role'];
}

$accessLevels = array_values(array_unique($accessLevels));
$membershipRoles = array_values(array_unique($membershipRoles));

$isAdmin = (bool) ($ctx['is_admin'] ?? false)
    || in_array('district_admin', $accessLevels, true)
    || in_array('system_admin', $accessLevels, true);

$isGlv = (bool) ($ctx['is_glv'] ?? false)
    || in_array('group_lead_volunteer', $membershipRoles, true)
    || in_array('group_admin', $accessLevels, true)
    || $isAdmin;

if (!$isGlv) {
    http_response_code(403);
    require __DIR__ . '/403.php';
    exit;
}

$groups = dc_accessible_groups();

$requestedGroupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : null;
$selectedGroupId = dc_selected_group_id($requestedGroupId);
$showGroupPicker = count($groups) > 1;



function dc_glv_column_exists(string $table, string $column): bool
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

        $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function dc_glv_absolute_url(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'app.irvalscouts.org.uk';

    return $scheme . '://' . $host . $path;
}

function dc_glv_share_url_from_row(array $row): ?string
{
    $urlColumns = [
        'link_url',
        'access_url',
        'public_url',
    ];

    foreach ($urlColumns as $column) {
        if (!empty($row[$column])) {
            return (string) $row[$column];
        }
    }

    $tokenColumns = [
        'token_plain',
        'token',
        'raw_token',
        'plain_token',
        'access_token',
        'public_token',
    ];

    foreach ($tokenColumns as $column) {
        if (!empty($row[$column])) {
            return dc_glv_absolute_url('/dc/login.php?token=' . urlencode((string) $row[$column]));
        }
    }

    return null;
}

$stmt = db()->prepare("
    SELECT
        g.id,
        g.group_name,
        g.slug,
        g.notify_lead_on_event_created
    FROM groups g
    WHERE g.id = :group_id
    LIMIT 1
");

$stmt->execute(['group_id' => $selectedGroupId]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$group) {
    require __DIR__ . '/404.php';
    exit;
}

$stmt = db()->prepare("
    SELECT
        p.id AS person_id,
        p.full_name,
        p.primary_email,
        p.phone,
        gm.membership_role,
        gm.access_level,
        gm.status,
        COUNT(DISTINCT ce.id) AS total_events,
        SUM(CASE WHEN ce.status = 'draft' THEN 1 ELSE 0 END) AS draft_events,
        SUM(CASE WHEN ce.status IN ('submitted', 'under_review') THEN 1 ELSE 0 END) AS in_review_events,
        SUM(CASE WHEN ce.status = 'approved' THEN 1 ELSE 0 END) AS approved_events,
        SUM(CASE WHEN ce.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_events,
        MAX(ce.starts_at) AS latest_event_at
    FROM group_memberships gm
    JOIN people p
      ON p.id = gm.person_id
    LEFT JOIN calendar_events ce
      ON ce.group_id = gm.group_id
     AND (
            ce.submitted_by_person_id = p.id
            OR LOWER(ce.leader_email) = LOWER(p.primary_email)
        )
    WHERE gm.group_id = :group_id
      AND gm.status = 'active'
      AND p.status = 'active'
    GROUP BY
        p.id,
        p.full_name,
        p.primary_email,
        p.phone,
        gm.membership_role,
        gm.access_level,
        gm.status
    ORDER BY
        total_events DESC,
        latest_event_at DESC,
        p.full_name ASC
");

$stmt->execute(['group_id' => $selectedGroupId]);
$leaders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$linkColumns = [
    'id',
    'group_id',
    'token_hash',
    'status',
];

$optionalColumns = [
    'scope',
    'expires_at',
    'last_used_at',
    'created_at',
    'label',
    'token_plain',
    'link_url',
    'access_url',
    'public_url',
    'token',
    'raw_token',
    'plain_token',
    'access_token',
    'public_token',
];

foreach ($optionalColumns as $column) {
    if (dc_glv_column_exists('group_access_links', $column)) {
        $linkColumns[] = $column;
    }
}

$groupLinks = [];

try {
    $hasExpiresAt = dc_glv_column_exists('group_access_links', 'expires_at');
    $hasCreatedAt = dc_glv_column_exists('group_access_links', 'created_at');

    $stmt = db()->prepare("
        SELECT
            " . implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $linkColumns)) . "
        FROM group_access_links
        WHERE group_id = :group_id
          AND status = 'active'
          AND (
                " . ($hasExpiresAt ? "expires_at IS NULL OR expires_at > NOW()" : "1 = 1") . "
              )
        ORDER BY
            " . ($hasCreatedAt ? "created_at DESC," : "") . "
            id DESC
        LIMIT 20
    ");

    $stmt->execute(['group_id' => $selectedGroupId]);
    $groupLinks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $groupLinks = [];
}

$totalEvents = array_sum(array_map(static fn (array $leader): int => (int) $leader['total_events'], $leaders));
$totalLeaders = count($leaders);
$leadersWithEvents = count(array_filter($leaders, static fn (array $leader): bool => (int) $leader['total_events'] > 0));

$groupManagerUrl = '/group-manager.php?group_id=' . (int) $selectedGroupId;

$pageTitle = 'Group Lead Volunteer';
$heroTitle = 'Group Lead Volunteer dashboard';
$heroText = 'See leader activity, share the Group calendar link and manage leaders in the main app.';
$active = 'glv';

require __DIR__ . '/layout.php';
?>

<style>
    .dc-glv-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 992px) {
        .dc-glv-grid {
            grid-template-columns: minmax(0, 1.3fr) 380px;
            align-items: start;
        }
    }

    .dc-glv-sidebar {
        position: static;
    }

    @media (min-width: 992px) {
        .dc-glv-sidebar {
            position: sticky;
            top: 1rem;
        }
    }

    .dc-stat-grid {
        display: grid;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    @media (min-width: 768px) {
        .dc-stat-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    .dc-stat-card {
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
        background: #fff;
        padding: 1rem;
    }

    .dc-stat-card strong {
        display: block;
        font-size: 1.75rem;
        line-height: 1;
        font-weight: 800;
        color: var(--dc-scouts-purple-dark, #4d0b93);
    }

    .dc-stat-card span {
        display: block;
        margin-top: 0.3rem;
        font-weight: 600;
        font-size: 0.88rem;
        color: var(--dc-muted, #64748b);
    }

    .dc-leader-table-wrap {
        overflow-x: auto;
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
        background: #fff;
    }

    .dc-leader-table {
        width: 100%;
        min-width: 860px;
        border-collapse: collapse;
        margin: 0;
    }

    .dc-leader-table th,
    .dc-leader-table td {
        padding: 0.7rem;
        border-bottom: 1px solid var(--dc-border, #e2e8f0);
        text-align: left;
        vertical-align: top;
    }

    .dc-leader-table th {
        background: var(--dc-canvas, #f8fafc);
        font-weight: 700;
        font-size: 0.82rem;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        color: var(--dc-muted, #64748b);
    }

    .dc-leader-name {
        font-weight: 700;
        font-size: 0.95rem;
    }

    .dc-muted {
        color: var(--dc-muted, #64748b);
        font-size: 0.85rem;
    }

    .dc-link-box {
        border: 1px solid rgba(0, 109, 223, 0.3);
        border-radius: 0;
        background: #eff6ff;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .dc-link-output {
        width: 100%;
        font-family: monospace;
        font-size: 0.88rem;
        padding: 0.6rem;
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
        background: #fff;
    }

    .dc-group-link-list {
        display: grid;
        gap: 0.75rem;
    }

    .dc-group-link-item {
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
        background: #fff;
        padding: 0.75rem;
    }

    .dc-mobile-leader-list {
        display: grid;
        gap: 0.75rem;
    }

    .dc-mobile-leader-card {
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
        background: #fff;
        padding: 0.75rem;
    }

    .dc-warning-panel {
        border-left: 3px solid #d4a300;
        border-radius: 0;
        background: #fefce8;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .dc-copy-row {
        display: grid;
        gap: 0.5rem;
    }

    @media (min-width: 768px) {
        .dc-copy-row {
            grid-template-columns: 1fr auto;
            align-items: center;
        }

        .dc-mobile-leader-list {
            display: none;
        }
    }

    @media (max-width: 767.98px) {
        .dc-leader-table-wrap {
            display: none;
        }
    }
</style>

<?php if ($showGroupPicker): ?>
    <form method="get" class="lt-panel-grey dc-filter-form" action="/dc/glv.php">
        <label for="group_id">Choose Group</label>
        <select id="group_id" name="group_id" class="form-control" onchange="this.form.submit()">
            <?= dc_group_options_html($selectedGroupId) ?>
        </select>
    </form>
<?php endif; ?>

<div class="dc-glv-grid">
    <div>
        <section class="lt-panel">
            <div class="dc-action-bar">
                <div>
                    <h2 class="lt-section-title mb-1"><?= e((string) $group['group_name']) ?></h2>
                    <p class="mb-0">
                        Leader activity for this Group.
                    </p>
                </div>

                <a class="btn btn-primary lt-btn" href="<?= e($groupManagerUrl) ?>">
                    Manage leaders
                </a>
            </div>

            <div class="dc-stat-grid">
                <div class="dc-stat-card">
                    <strong><?= (int) $totalLeaders ?></strong>
                    <span>Active leaders</span>
                </div>

                <div class="dc-stat-card">
                    <strong><?= (int) $leadersWithEvents ?></strong>
                    <span>Leaders with events</span>
                </div>

                <div class="dc-stat-card">
                    <strong><?= (int) $totalEvents ?></strong>
                    <span>Total linked events</span>
                </div>
            </div>

            <?php if (!$leaders): ?>
                <p class="mb-0">
                    No active leaders are currently linked to this Group.
                    Use the main app Group Manager to add leaders.
                </p>
            <?php else: ?>
                <div class="dc-leader-table-wrap">
                    <table class="dc-leader-table">
                        <thead>
                            <tr>
                                <th>Leader</th>
                                <th>Role</th>
                                <th>Total</th>
                                <th>In review</th>
                                <th>Approved</th>
                                <th>Drafts</th>
                                <th>Cancelled</th>
                                <th>Latest event</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leaders as $leader): ?>
                                <tr>
                                    <td>
                                        <div class="dc-leader-name"><?= e((string) $leader['full_name']) ?></div>

                                        <?php if (!empty($leader['primary_email'])): ?>
                                            <div class="dc-muted"><?= e((string) $leader['primary_email']) ?></div>
                                        <?php endif; ?>

                                        <?php if (!empty($leader['phone'])): ?>
                                            <div class="dc-muted"><?= e((string) $leader['phone']) ?></div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= e(ucwords(str_replace('_', ' ', (string) $leader['membership_role']))) ?>
                                        <div class="dc-muted">
                                            <?= e(ucwords(str_replace('_', ' ', (string) $leader['access_level']))) ?>
                                        </div>
                                    </td>

                                    <td><?= (int) $leader['total_events'] ?></td>
                                    <td><?= (int) $leader['in_review_events'] ?></td>
                                    <td><?= (int) $leader['approved_events'] ?></td>
                                    <td><?= (int) $leader['draft_events'] ?></td>
                                    <td><?= (int) $leader['cancelled_events'] ?></td>

                                    <td>
                                        <?php if (!empty($leader['latest_event_at'])): ?>
                                            <?= e(date('j M Y', strtotime((string) $leader['latest_event_at']))) ?>
                                        <?php else: ?>
                                            <span class="dc-muted">No events yet</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="dc-mobile-leader-list">
                    <?php foreach ($leaders as $leader): ?>
                        <article class="dc-mobile-leader-card">
                            <h3 class="mb-1"><?= e((string) $leader['full_name']) ?></h3>

                            <?php if (!empty($leader['primary_email'])): ?>
                                <p class="dc-muted mb-1"><?= e((string) $leader['primary_email']) ?></p>
                            <?php endif; ?>

                            <p class="mb-1">
                                <?= e(ucwords(str_replace('_', ' ', (string) $leader['membership_role']))) ?>
                            </p>

                            <p class="mb-0">
                                <strong><?= (int) $leader['total_events'] ?></strong> events ·
                                <strong><?= (int) $leader['approved_events'] ?></strong> approved ·
                                <strong><?= (int) $leader['in_review_events'] ?></strong> in review
                            </p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="dc-glv-sidebar">
        <section class="lt-panel-grey">
            <h2 class="lt-section-title">Group calendar link</h2>

            <p>
                Share this link with leaders who need to access the shared calendar and submit events for this Group without using District Microsoft 365 sign-in.
            </p>

            <?php if (!$groupLinks): ?>
                <div class="dc-warning-panel">
                    <strong>No active Group link found.</strong>
                    <p class="mb-0">
                        Ask a District admin to create or provide the Group access link for this Group.
                    </p>
                </div>
            <?php else: ?>
                <div class="dc-group-link-list">
                    <?php foreach ($groupLinks as $link): ?>
                        <?php
                            $shareUrl = dc_glv_share_url_from_row($link);
                            $label = trim((string) ($link['label'] ?? ''));

                            if ($label === '') {
                                $label = 'Group calendar link';
                            }
                        ?>

                        <article class="dc-group-link-item">
                            <strong><?= e($label) ?></strong>

                            <?php if ($shareUrl): ?>
                                <div class="dc-copy-row mt-2">
                                    <input
                                        class="dc-link-output"
                                        value="<?= e($shareUrl) ?>"
                                        readonly
                                        onclick="this.select();"
                                        aria-label="<?= e($label) ?>"
                                    >

                                    <button class="btn btn-primary lt-btn dc-copy-link" type="button">
                                        Copy
                                    </button>
                                </div>

                                <p class="form-text mb-0">
                                    This link gives access to this Group’s District Calendar and event submission form.
                                </p>
                            <?php else: ?>
                                <div class="dc-warning-panel mt-2 mb-0">
                                    <strong>This link exists, but the visible token is missing.</strong>
                                    <p class="mb-0">
                                        Add <code>token_plain</code> for this link or ask a District admin to rotate it once so the shareable URL can be displayed here.
                                    </p>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($link['scope'])): ?>
                                <div class="dc-muted mt-2">
                                    Scope: <?= e((string) $link['scope']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($link['created_at'])): ?>
                                <div class="dc-muted">
                                    Created: <?= e(date('j M Y H:i', strtotime((string) $link['created_at']))) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($link['last_used_at'])): ?>
                                <div class="dc-muted">
                                    Last used: <?= e(date('j M Y H:i', strtotime((string) $link['last_used_at']))) ?>
                                </div>
                            <?php else: ?>
                                <div class="dc-muted">
                                    Last used: never
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($link['expires_at'])): ?>
                                <div class="dc-muted">
                                    Expires: <?= e(date('j M Y H:i', strtotime((string) $link['expires_at']))) ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="lt-panel">
            <h2 class="lt-section-title">Leader management</h2>

            <p>
                Add, update or remove leaders in the main app.
            </p>

            <a class="btn btn-primary lt-btn" href="<?= e($groupManagerUrl) ?>">
                Open Group Manager
            </a>
        </section>
    </aside>
</div>

<script <?= csp_nonce_attr() ?>>
(function () {
    document.querySelectorAll('.dc-copy-link').forEach(function (button) {
        button.addEventListener('click', async function () {
            const input = button.parentElement ? button.parentElement.querySelector('input') : null;

            if (!input) {
                return;
            }

            input.select();

            try {
                await navigator.clipboard.writeText(input.value);
                button.textContent = 'Copied';
                setTimeout(function () {
                    button.textContent = 'Copy';
                }, 1800);
            } catch (error) {
                document.execCommand('copy');
                button.textContent = 'Copied';
                setTimeout(function () {
                    button.textContent = 'Copy';
                }, 1800);
            }
        });
    });
})();
</script>

<?php require __DIR__ . '/layout-footer.php'; ?>