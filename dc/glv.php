<?php

declare(strict_types=1);

require_once __DIR__ . 'auth.php';

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
    require __DIR__ . '/../403.php';
    exit;
}

$groups = dc_accessible_groups();

$requestedGroupId = isset($_GET['group_id'])
    ? (int) $_GET['group_id']
    : (isset($_POST['group_id']) ? (int) $_POST['group_id'] : null);

$selectedGroupId = dc_selected_group_id($requestedGroupId);
$showGroupPicker = count($groups) > 1;

$errors = [];
$success = '';
$newGroupLink = null;

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

function dc_glv_create_group_link(int $groupId, ?int $personId): string
{
    $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $tokenHash = hash('sha256', $rawToken);

    $columns = [
        'group_id' => $groupId,
        'token_hash' => $tokenHash,
        'status' => 'active',
    ];

    if (dc_glv_column_exists('group_access_links', 'scope')) {
        $columns['scope'] = 'group';
    }

    if ($personId && dc_glv_column_exists('group_access_links', 'created_by_person_id')) {
        $columns['created_by_person_id'] = $personId;
    }

    if (dc_glv_column_exists('group_access_links', 'created_at')) {
        $columns['created_at'] = date('Y-m-d H:i:s');
    }

    $columnNames = array_keys($columns);
    $placeholders = array_map(static fn (string $column): string => ':' . $column, $columnNames);

    $stmt = db()->prepare("
        INSERT INTO group_access_links (
            " . implode(', ', $columnNames) . "
        ) VALUES (
            " . implode(', ', $placeholders) . "
        )
    ");

    $stmt->execute($columns);

    dc_log(
        'group_access_link.created',
        'group_access_link',
        (int) db()->lastInsertId(),
        ['group_id' => $groupId],
        $groupId
    );

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'app.irvalscouts.org.uk';

    return $scheme . '://' . $host . '/dc/login.php?token=' . urlencode($rawToken);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_group_link') {
        try {
            $newGroupLink = dc_glv_create_group_link(
                $selectedGroupId,
                isset($ctx['person_id']) ? (int) $ctx['person_id'] : null
            );

            $success = 'New Group link created. Copy it now — it cannot be shown again later because only the secure hash is stored.';
        } catch (Throwable $e) {
            $errors[] = 'The Group link could not be created. ' . $e->getMessage();
        }
    }
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
    require __DIR__ . '/../404.php';
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

$stmt = db()->prepare("
    SELECT
        id,
        scope,
        status,
        expires_at,
        last_used_at,
        created_at
    FROM group_access_links
    WHERE group_id = :group_id
    ORDER BY
        CASE WHEN status = 'active' THEN 0 ELSE 1 END,
        created_at DESC,
        id DESC
    LIMIT 20
");

try {
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
$heroText = 'See leader activity, manage Group access and share the Group event submission link.';
$active = 'glv';

require __DIR__ . '/../layout.php';
?>

<style>
    .dc-glv-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 992px) {
        .dc-glv-grid {
            grid-template-columns: minmax(0, 1.3fr) 360px;
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
        border: 2px solid #000;
        background: #fff;
        padding: 1rem;
    }

    .dc-stat-card strong {
        display: block;
        font-size: 2rem;
        line-height: 1;
        font-weight: 900;
    }

    .dc-stat-card span {
        display: block;
        margin-top: 0.35rem;
        font-weight: 800;
    }

    .dc-leader-table-wrap {
        overflow-x: auto;
        border: 1px solid #d8d8d8;
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
        padding: 0.75rem;
        border-bottom: 1px solid #d8d8d8;
        text-align: left;
        vertical-align: top;
    }

    .dc-leader-table th {
        background: #f5f5f5;
        font-weight: 900;
    }

    .dc-leader-name {
        font-weight: 900;
        font-size: 1.05rem;
    }

    .dc-muted {
        color: #4a4a4a;
        font-size: 0.92rem;
    }

    .dc-link-box {
        border: 2px solid #006ddf;
        background: #e8f1ff;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .dc-link-output {
        width: 100%;
        font-family: monospace;
        font-size: 0.95rem;
        padding: 0.75rem;
        border: 2px solid #000;
        background: #fff;
    }

    .dc-group-link-list {
        display: grid;
        gap: 0.5rem;
    }

    .dc-group-link-item {
        border: 1px solid #d8d8d8;
        background: #fff;
        padding: 0.75rem;
    }

    .dc-mobile-leader-list {
        display: grid;
        gap: 0.75rem;
    }

    .dc-mobile-leader-card {
        border: 1px solid #d8d8d8;
        background: #fff;
        padding: 0.75rem;
    }

    @media (min-width: 768px) {
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

<?php if ($success !== ''): ?>
    <div class="dc-success"><?= e($success) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="dc-error-summary" role="alert">
        <h2>Check this page</h2>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($showGroupPicker): ?>
    <form method="get" class="lt-panel-grey dc-filter-form" action="/dc/glv/">
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
            <h2 class="lt-section-title">Group link</h2>

            <p>
                Share this link with leaders who need to submit events for this Group but do not use District Microsoft 365 sign-in.
            </p>

            <?php if ($newGroupLink): ?>
                <div class="dc-link-box">
                    <label for="new_group_link"><strong>New Group link</strong></label>
                    <input
                        id="new_group_link"
                        class="dc-link-output"
                        value="<?= e($newGroupLink) ?>"
                        readonly
                        onclick="this.select();"
                    >
                    <p class="form-text mb-0">
                        Copy this now. It will not be shown again.
                    </p>
                </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                <input type="hidden" name="action" value="create_group_link">

                <button
                    class="btn btn-primary lt-btn"
                    type="submit"
                    onclick="return confirm('Create a new Group link? Existing links will remain active unless disabled elsewhere.');"
                >
                    Create new Group link
                </button>
            </form>

            <hr>

            <h3 class="h5 font-weight-bold">Existing links</h3>

            <?php if (!$groupLinks): ?>
                <p class="mb-0">
                    No Group links found for this Group yet.
                </p>
            <?php else: ?>
                <div class="dc-group-link-list">
                    <?php foreach ($groupLinks as $link): ?>
                        <article class="dc-group-link-item">
                            <strong>
                                <?= e(ucwords(str_replace('_', ' ', (string) ($link['status'] ?? 'unknown')))) ?>
                            </strong>

                            <?php if (!empty($link['scope'])): ?>
                                <div class="dc-muted">
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

<?php require __DIR__ . '/../layout-footer.php'; ?>