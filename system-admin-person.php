<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/system-admin-helpers.php';

$user = sa_require_system_admin();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
$pdo = db();

// ─── Helpers ────────────────────────────────────────────────────────────────

function sap_table_exists(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
        $stmt->execute(['t' => $table]);
        return $cache[$table] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) { return $cache[$table] = false; }
}

function sap_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
        $stmt->execute(['t' => $table, 'c' => $column]);
        return $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) { return $cache[$key] = false; }
}

function sap_table_columns(string $table): array
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    try {
        $stmt = db()->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
        $stmt->execute(['t' => $table]);
        return $cache[$table] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return $cache[$table] = []; }
}

// ─── Person Search (landing state) or Person View ────────────────────────────

$personId = (int) ($_GET['person_id'] ?? $_POST['person_id'] ?? 0);
$search = trim((string) ($_GET['search'] ?? ''));
$errors = [];
$success = null;

// ─── Handle POST actions ────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $personId > 0) {
    csrf_validate();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'update_details') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $primaryEmail = strtolower(trim((string) ($_POST['primary_email'] ?? '')));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $preferredName = trim((string) ($_POST['preferred_name'] ?? ''));

            if ($fullName === '') {
                throw new RuntimeException('Full name is required.');
            }
            if ($primaryEmail !== '' && !filter_var($primaryEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }

            $sets = ['full_name = :full_name', 'primary_email = :primary_email'];
            $params = ['full_name' => $fullName, 'primary_email' => $primaryEmail, 'id' => $personId];

            if (sap_column_exists('people', 'phone')) {
                $sets[] = 'phone = :phone';
                $params['phone'] = $phone;
            }
            if (sap_column_exists('people', 'preferred_name')) {
                $sets[] = 'preferred_name = :preferred_name';
                $params['preferred_name'] = $preferredName !== '' ? $preferredName : null;
            }
            if (sap_column_exists('people', 'updated_at')) {
                $sets[] = 'updated_at = NOW()';
            }

            $stmt = $pdo->prepare("UPDATE people SET " . implode(', ', $sets) . " WHERE id = :id");
            $stmt->execute($params);

            audit_log(AUDIT_USER_EDITED, 'person', $personId, $personId, [
                'fields_changed' => 'admin_edit',
                'admin_person_id' => (int) $user['id'],
            ]);

            $success = 'Person details updated.';
        } elseif ($action === 'set_status') {
            $newStatus = (string) ($_POST['new_status'] ?? '');
            if (!in_array($newStatus, ['active', 'inactive'], true)) {
                throw new RuntimeException('Invalid status.');
            }

            $stmt = $pdo->prepare("UPDATE people SET status = :status WHERE id = :id");
            $stmt->execute(['status' => $newStatus, 'id' => $personId]);

            // Also update all group memberships if deactivating
            if ($newStatus === 'inactive') {
                $stmt = $pdo->prepare("UPDATE group_memberships SET status = 'inactive' WHERE person_id = :id AND status = 'active'");
                $stmt->execute(['id' => $personId]);
            }

            audit_log(
                $newStatus === 'active' ? AUDIT_USER_REACTIVATED : AUDIT_USER_DEACTIVATED,
                'person', $personId, $personId,
                ['new_status' => $newStatus, 'admin_person_id' => (int) $user['id']]
            );

            $success = $newStatus === 'active' ? 'Person reactivated.' : 'Person deactivated (all memberships set to inactive).';
        } elseif ($action === 'update_access_level') {
            $groupId = (int) ($_POST['group_id'] ?? 0);
            $accessLevel = (string) ($_POST['access_level'] ?? 'member');
            $allowed = ['system_admin', 'district_admin', 'district_reviewer', 'group_reviewer', 'group_admin', 'member'];

            if (!in_array($accessLevel, $allowed, true)) {
                throw new RuntimeException('Invalid access level.');
            }
            if ($groupId < 1) {
                throw new RuntimeException('Invalid group.');
            }

            $stmt = $pdo->prepare("UPDATE group_memberships SET access_level = :access_level WHERE person_id = :person_id AND group_id = :group_id AND status = 'active'");
            $stmt->execute(['access_level' => $accessLevel, 'person_id' => $personId, 'group_id' => $groupId]);

            audit_log(AUDIT_USER_ROLE_CHANGED, 'person', $personId, $personId, [
                'group_id' => $groupId,
                'new_access_level' => $accessLevel,
                'admin_person_id' => (int) $user['id'],
            ], $groupId);

            $success = 'Access level updated.';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// ─── Fetch person data ──────────────────────────────────────────────────────

$person = null;
$memberships = [];
$m365Account = null;
$m365Requests = [];
$recentLogins = [];
$recentActivity = [];
$searchResults = [];

if ($personId > 0) {
    // Fetch person record
    try {
        $stmt = $pdo->prepare("SELECT * FROM people WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $personId]);
        $person = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $person = null;
    }

    if ($person) {
        // Group memberships
        try {
            $stmt = $pdo->prepare("
                SELECT gm.*, g.group_name
                FROM group_memberships gm
                JOIN groups g ON g.id = gm.group_id
                WHERE gm.person_id = :person_id
                ORDER BY gm.status ASC, g.group_name ASC
            ");
            $stmt->execute(['person_id' => $personId]);
            $memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $memberships = []; }

        // M365 linked account
        if (sap_table_exists('user_accounts')) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM user_accounts WHERE person_id = :id AND provider = 'microsoft' LIMIT 1");
                $stmt->execute(['id' => $personId]);
                $m365Account = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) { $m365Account = null; }
        }

        // M365 provisioning requests
        if (sap_table_exists('m365_account_requests')) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM m365_account_requests WHERE person_id = :id ORDER BY created_at DESC LIMIT 5");
                $stmt->execute(['id' => $personId]);
                $m365Requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $m365Requests = []; }
        }

        // Recent logins (last 30 days)
        try {
            $stmt = $pdo->prepare("
                SELECT created_at, ip_address, user_agent
                FROM audit_log
                WHERE actor_person_id = :id
                  AND action = 'auth.login_success'
                ORDER BY created_at DESC
                LIMIT 20
            ");
            $stmt->execute(['id' => $personId]);
            $recentLogins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $recentLogins = []; }

        // Recent activity (last 50 actions)
        try {
            $stmt = $pdo->prepare("
                SELECT al.*, p.full_name AS target_name
                FROM audit_log al
                LEFT JOIN people p ON p.id = al.target_person_id
                WHERE al.actor_person_id = :id
                   OR al.target_person_id = :id2
                ORDER BY al.created_at DESC, al.id DESC
                LIMIT 50
            ");
            $stmt->execute(['id' => $personId, 'id2' => $personId]);
            $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $recentActivity = []; }
    }
} elseif ($search !== '') {
    // Search for people
    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.full_name, p.primary_email, p.status,
                   GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.group_name SEPARATOR ', ') AS groups_list
            FROM people p
            LEFT JOIN group_memberships gm ON gm.person_id = p.id AND gm.status = 'active'
            LEFT JOIN groups g ON g.id = gm.group_id AND g.is_active = 1
            WHERE (p.full_name LIKE :s1 OR p.primary_email LIKE :s2 OR p.id = :exact_id)
            GROUP BY p.id, p.full_name, p.primary_email, p.status
            ORDER BY p.status ASC, p.full_name ASC
            LIMIT 30
        ");
        $stmt->execute([
            's1' => '%' . $search . '%',
            's2' => '%' . $search . '%',
            'exact_id' => is_numeric($search) ? (int) $search : 0,
        ]);
        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $searchResults = []; }
}

// ─── Page Setup ─────────────────────────────────────────────────────────────

$saNavCurrent = 'person';

$pageTitle = 'System Admin — Person' . ($person ? ' — ' . e($person['full_name']) : '') . ' | ' . $appName;
$heroTitle = 'System Admin';
$heroText = $person ? 'Viewing: ' . e($person['full_name']) : 'Search for a person to view and manage their record.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/system-admin-dashboard.php">System Admin</a> / Person Lookup';

include __DIR__ . '/header.php';
?>

<style>
    .sa-service-bar { background: #1d1d1b; border-bottom: 4px solid #ffdd00; }
    .sa-service-bar-inner { max-width: 1180px; margin: 0 auto; padding: 0 1rem; display: flex; align-items: stretch; gap: 0; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .sa-nav-link { display: inline-flex; align-items: center; padding: .85rem 1.1rem; color: rgba(255,255,255,.88); font-weight: 900; font-size: .92rem; text-decoration: none; white-space: nowrap; border-bottom: 4px solid transparent; margin-bottom: -4px; transition: background .1s, border-color .1s; }
    .sa-nav-link:hover { color: #fff; background: rgba(255,255,255,.08); text-decoration: none; }
    .sa-nav-link:focus { outline: 3px solid #ffdd00; outline-offset: -3px; color: #fff; }
    .sa-nav-link[aria-current="page"] { color: #fff; border-bottom-color: #ffdd00; background: rgba(255,255,255,.06); }

    .sap-search { background: #f7f5fb; border: 2px solid #e5e5e5; padding: 1.25rem; margin-bottom: 1.5rem; }
    .sap-search-row { display: flex; gap: .75rem; align-items: end; flex-wrap: wrap; }
    .sap-search-row .form-group { flex: 1; min-width: 220px; margin-bottom: 0; }

    .sap-results { margin-bottom: 1.5rem; }
    .sap-result-item { display: flex; align-items: center; justify-content: space-between; padding: .7rem 1rem; border: 1px solid #e5e5e5; background: #fff; margin-bottom: .4rem; }
    .sap-result-item:hover { border-color: #4d0b93; background: #faf8fd; }
    .sap-result-name { font-weight: 900; color: #4d0b93; text-decoration: none; }
    .sap-result-name:hover { text-decoration: underline; }
    .sap-result-meta { font-size: .82rem; color: #666; }
    .sap-badge { display: inline-block; padding: .12rem .4rem; font-size: .7rem; font-weight: 900; text-transform: uppercase; }
    .sap-badge-active { background: #d1e7dd; color: #0f5132; }
    .sap-badge-inactive { background: #f8d7da; color: #842029; }

    .sap-person-header { display: flex; align-items: center; gap: 1.25rem; margin-bottom: 1.5rem; padding: 1.25rem; background: #fff; border: 2px solid #e5e5e5; }
    .sap-person-avatar { width: 64px; height: 64px; border-radius: 50%; background: #4d0b93; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 900; flex-shrink: 0; }
    .sap-person-info h2 { margin: 0 0 .25rem; font-size: 1.3rem; color: #1d1d1b; }
    .sap-person-info p { margin: 0; font-size: .88rem; color: #555; }

    .sap-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
    @media (max-width: 768px) { .sap-grid { grid-template-columns: 1fr; } }

    .sap-panel { background: #fff; border: 2px solid #e5e5e5; padding: 1.25rem; margin-bottom: 1.5rem; }
    .sap-panel h3 { margin: 0 0 .75rem; font-size: 1rem; font-weight: 900; color: #4d0b93; padding-bottom: .4rem; border-bottom: 2px solid #f0ecf5; }

    .sap-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    .sap-table th, .sap-table td { border-bottom: 1px solid #e5e5e5; padding: .5rem .6rem; text-align: left; vertical-align: top; }
    .sap-table th { background: #f7f5fb; font-weight: 900; color: #4d0b93; white-space: nowrap; }
    .sap-table tr:hover td { background: #faf8fd; }

    .sap-detail-grid { display: grid; grid-template-columns: 140px 1fr; gap: .4rem .75rem; font-size: .88rem; }
    .sap-detail-key { font-weight: 900; color: #4d0b93; font-size: .78rem; text-transform: uppercase; }
    .sap-detail-value { color: #1d1d1b; word-break: break-word; }

    .sap-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
    .sap-muted { color: #666; font-size: .82rem; }
    .sap-link { color: #4d0b93; font-weight: 700; text-decoration: none; }
    .sap-link:hover { text-decoration: underline; }
</style>

<nav class="sa-service-bar" aria-label="System Admin navigation">
    <div class="sa-service-bar-inner">
        <a class="sa-nav-link" href="/system-admin-dashboard.php" <?= $saNavCurrent === 'dashboard' ? 'aria-current="page"' : '' ?>>Dashboard</a>
        <a class="sa-nav-link" href="/system-admin.php" <?= $saNavCurrent === 'audit-log' ? 'aria-current="page"' : '' ?>>Audit Log</a>
        <a class="sa-nav-link" href="/system-admin-cron.php" <?= $saNavCurrent === 'cron' ? 'aria-current="page"' : '' ?>>Cron Jobs</a>
        <a class="sa-nav-link" href="/system-admin-gdpr.php" <?= $saNavCurrent === 'gdpr' ? 'aria-current="page"' : '' ?>>GDPR</a>
        <a class="sa-nav-link" href="/system-admin-permissions.php" <?= $saNavCurrent === 'permissions' ? 'aria-current="page"' : '' ?>>Permissions</a>
        <a class="sa-nav-link" href="/system-admin-person.php" <?= $saNavCurrent === 'person' ? 'aria-current="page"' : '' ?>>Person Lookup</a>
        <a class="sa-nav-link" href="/system-admin-kb.php" <?= $saNavCurrent === 'kb' ? 'aria-current="page"' : '' ?>>KB</a>
    </div>
</nav>

<main class="lt-main">

    <?php if ($errors): ?>
        <div class="alert alert-danger" style="margin-bottom: 1rem;">
            <?php foreach ($errors as $err): ?>
                <p style="margin: 0;"><strong>Error:</strong> <?= e($err) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom: 1rem;">
            <p style="margin: 0;"><?= e($success) ?></p>
        </div>
    <?php endif; ?>

    <!-- ─── Search Bar (always visible) ──────────────────────────────── -->
    <div class="sap-search">
        <form method="get">
            <div class="sap-search-row">
                <div class="form-group">
                    <label for="sap-search">Search people</label>
                    <input class="form-control" type="search" id="sap-search" name="search" value="<?= e($search) ?>" placeholder="Name, email, or person ID...">
                </div>
                <button class="btn btn-primary lt-btn" type="submit">Search</button>
                <?php if ($person): ?>
                    <a class="btn btn-secondary lt-btn" href="/system-admin-person.php">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ─── Search Results ───────────────────────────────────────────── -->
    <?php if (!$person && $search !== '' && $searchResults): ?>
        <div class="sap-results">
            <p class="sap-muted"><?= count($searchResults) ?> result<?= count($searchResults) !== 1 ? 's' : '' ?> for "<?= e($search) ?>"</p>
            <?php foreach ($searchResults as $sr): ?>
                <div class="sap-result-item">
                    <div>
                        <a class="sap-result-name" href="/system-admin-person.php?person_id=<?= (int) $sr['id'] ?>"><?= e($sr['full_name']) ?></a>
                        <br><span class="sap-result-meta"><?= e($sr['primary_email'] ?? '') ?> &middot; <?= e($sr['groups_list'] ?? 'No group') ?></span>
                    </div>
                    <span class="sap-badge <?= $sr['status'] === 'active' ? 'sap-badge-active' : 'sap-badge-inactive' ?>"><?= e($sr['status']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif (!$person && $search !== ''): ?>
        <p class="sap-muted">No people found matching "<?= e($search) ?>".</p>
    <?php endif; ?>

    <!-- ─── Person View ──────────────────────────────────────────────── -->
    <?php if ($person): ?>

        <?php
            $initials = '';
            $nameParts = explode(' ', (string) $person['full_name']);
            if (count($nameParts) >= 2) {
                $initials = strtoupper(mb_substr($nameParts[0], 0, 1) . mb_substr(end($nameParts), 0, 1));
            } else {
                $initials = strtoupper(mb_substr((string) $person['full_name'], 0, 2));
            }
        ?>

        <div class="sap-person-header">
            <div class="sap-person-avatar"><?= e($initials) ?></div>
            <div class="sap-person-info">
                <h2><?= e($person['full_name']) ?></h2>
                <p>
                    <?= e($person['primary_email'] ?? '') ?>
                    &middot; Person #<?= (int) $person['id'] ?>
                    &middot; <span class="sap-badge <?= $person['status'] === 'active' ? 'sap-badge-active' : 'sap-badge-inactive' ?>"><?= e($person['status']) ?></span>
                </p>
            </div>
        </div>

        <div class="sap-grid">
            <!-- ─── Left Column ──────────────────────────────────────── -->
            <div>
                <!-- Edit Details -->
                <div class="sap-panel">
                    <h3>Person Details</h3>
                    <form method="post">
                        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="person_id" value="<?= (int) $person['id'] ?>">
                        <input type="hidden" name="action" value="update_details">

                        <div class="form-group">
                            <label for="sap-full-name">Full name</label>
                            <input class="form-control" type="text" id="sap-full-name" name="full_name" value="<?= e($person['full_name'] ?? '') ?>" required>
                        </div>

                        <?php if (sap_column_exists('people', 'preferred_name')): ?>
                        <div class="form-group">
                            <label for="sap-preferred-name">Preferred name</label>
                            <input class="form-control" type="text" id="sap-preferred-name" name="preferred_name" value="<?= e($person['preferred_name'] ?? '') ?>">
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="sap-email">Email</label>
                            <input class="form-control" type="email" id="sap-email" name="primary_email" value="<?= e($person['primary_email'] ?? '') ?>">
                        </div>

                        <?php if (sap_column_exists('people', 'phone')): ?>
                        <div class="form-group">
                            <label for="sap-phone">Phone</label>
                            <input class="form-control" type="tel" id="sap-phone" name="phone" value="<?= e($person['phone'] ?? '') ?>">
                        </div>
                        <?php endif; ?>

                        <button class="btn btn-primary lt-btn" type="submit">Save Changes</button>
                    </form>
                </div>

                <!-- Status Actions -->
                <div class="sap-panel">
                    <h3>Account Status</h3>
                    <div class="sap-detail-grid" style="margin-bottom: 1rem;">
                        <span class="sap-detail-key">Status</span>
                        <span class="sap-detail-value"><span class="sap-badge <?= $person['status'] === 'active' ? 'sap-badge-active' : 'sap-badge-inactive' ?>"><?= e($person['status']) ?></span></span>
                        <?php if (sap_column_exists('people', 'updated_at') && !empty($person['updated_at'])): ?>
                            <span class="sap-detail-key">Last updated</span>
                            <span class="sap-detail-value sap-muted"><?= e(date('d M Y H:i', strtotime($person['updated_at']))) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="sap-actions">
                        <?php if ($person['status'] === 'active'): ?>
                            <form method="post" onsubmit="return confirm('Deactivate this person? All their group memberships will be set to inactive.');">
                                <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="person_id" value="<?= (int) $person['id'] ?>">
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="new_status" value="inactive">
                                <button class="btn btn-danger lt-btn" type="submit">Deactivate Person</button>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="person_id" value="<?= (int) $person['id'] ?>">
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="new_status" value="active">
                                <button class="btn btn-primary lt-btn" type="submit">Reactivate Person</button>
                            </form>
                        <?php endif; ?>
                        <a class="btn btn-secondary lt-btn" href="/system-admin.php?target_person_id=<?= (int) $person['id'] ?>">View in Audit Log</a>
                    </div>
                </div>

                <!-- Group Memberships -->
                <div class="sap-panel">
                    <h3>Group Memberships</h3>
                    <?php if ($memberships): ?>
                        <table class="sap-table">
                            <thead>
                                <tr><th>Group</th><th>Role</th><th>Access</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($memberships as $gm): ?>
                                <tr>
                                    <td><?= e($gm['group_name']) ?><?= !empty($gm['is_primary']) ? ' <strong title="Primary">&starf;</strong>' : '' ?></td>
                                    <td class="sap-muted"><?= e(ucwords(str_replace('_', ' ', (string) ($gm['membership_role'] ?? 'member')))) ?></td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="person_id" value="<?= (int) $person['id'] ?>">
                                            <input type="hidden" name="action" value="update_access_level">
                                            <input type="hidden" name="group_id" value="<?= (int) $gm['group_id'] ?>">
                                            <select name="access_level" onchange="this.form.submit()" style="font-size:.78rem; padding:.15rem .3rem;">
                                                <?php foreach (['member','group_admin','group_reviewer','district_reviewer','district_admin','system_admin'] as $lvl): ?>
                                                    <option value="<?= $lvl ?>" <?= ($gm['access_level'] ?? 'member') === $lvl ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $lvl)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td><span class="sap-badge <?= ($gm['status'] ?? '') === 'active' ? 'sap-badge-active' : 'sap-badge-inactive' ?>"><?= e($gm['status'] ?? '') ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="sap-muted">No group memberships found.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ─── Right Column ─────────────────────────────────────── -->
            <div>
                <!-- M365 Account Status -->
                <div class="sap-panel">
                    <h3>Microsoft 365 Account</h3>
                    <?php if ($m365Account): ?>
                        <div class="sap-detail-grid">
                            <span class="sap-detail-key">Provider</span>
                            <span class="sap-detail-value">Microsoft (linked)</span>
                            <?php if (!empty($m365Account['email'])): ?>
                                <span class="sap-detail-key">UPN / Email</span>
                                <span class="sap-detail-value"><?= e($m365Account['email']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($m365Account['provider_subject'])): ?>
                                <span class="sap-detail-key">Object ID</span>
                                <span class="sap-detail-value sap-muted" style="font-family:monospace;font-size:.78rem;"><?= e($m365Account['provider_subject']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($m365Account['last_login_at'])): ?>
                                <span class="sap-detail-key">Last sign-in</span>
                                <span class="sap-detail-value"><?= e(date('d M Y H:i', strtotime($m365Account['last_login_at']))) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p class="sap-muted">No Microsoft account linked.</p>
                    <?php endif; ?>

                    <?php
                        // Also show district_email / m365_user_principal_name from people table if exists
                        $districtEmail = $person['district_email'] ?? $person['m365_user_principal_name'] ?? $person['microsoft_user_principal_name'] ?? null;
                        if ($districtEmail && (!$m365Account || empty($m365Account['email']) || strtolower($m365Account['email']) !== strtolower($districtEmail))):
                    ?>
                        <div class="sap-detail-grid" style="margin-top: .75rem;">
                            <span class="sap-detail-key">District email</span>
                            <span class="sap-detail-value"><?= e($districtEmail) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($m365Requests): ?>
                        <h4 style="font-size:.85rem; font-weight:900; margin:1rem 0 .4rem; color:#1d1d1b;">Provisioning Requests</h4>
                        <table class="sap-table">
                            <thead>
                                <tr><th>UPN</th><th>Status</th><th>Date</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($m365Requests as $req): ?>
                                <tr>
                                    <td class="sap-muted"><?= e($req['requested_upn'] ?? '') ?></td>
                                    <td>
                                        <?php
                                            $ps = (string) ($req['provision_status'] ?? 'pending');
                                            $psClass = match($ps) {
                                                'provisioned', 'already_exists' => 'sap-badge-active',
                                                'failed' => 'sap-badge-inactive',
                                                default => 'sap-badge-active',
                                            };
                                        ?>
                                        <span class="sap-badge <?= $psClass ?>"><?= e($ps) ?></span>
                                    </td>
                                    <td class="sap-muted"><?= e(date('d M Y', strtotime($req['created_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Recent Sign-ins -->
                <div class="sap-panel">
                    <h3>Recent Sign-ins</h3>
                    <?php if ($recentLogins): ?>
                        <table class="sap-table">
                            <thead>
                                <tr><th>Date & Time</th><th>IP Address</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentLogins as $login): ?>
                                <tr>
                                    <td><?= e(date('d M Y H:i', strtotime($login['created_at']))) ?></td>
                                    <td class="sap-muted"><?= e($login['ip_address'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="sap-muted">No sign-in records found.</p>
                    <?php endif; ?>
                </div>

                <!-- Recent Activity -->
                <div class="sap-panel">
                    <h3>Recent Activity</h3>
                    <?php if ($recentActivity): ?>
                        <table class="sap-table">
                            <thead>
                                <tr><th>Time</th><th>Event</th><th>Role</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach (array_slice($recentActivity, 0, 25) as $act):
                                $eventCode = (string) ($act['action'] ?? '');
                                $isActor = ((int) ($act['actor_person_id'] ?? 0)) === $personId;
                            ?>
                                <tr>
                                    <td class="sap-muted" style="white-space:nowrap;"><?= e(date('d M H:i', strtotime($act['created_at']))) ?></td>
                                    <td>
                                        <strong><?= e(audit_event_label($eventCode)) ?></strong>
                                        <?php if (!$isActor && !empty($act['actor_person_id'])): ?>
                                            <br><span class="sap-muted">by person #<?= (int) $act['actor_person_id'] ?></span>
                                        <?php endif; ?>
                                        <?php if ($isActor && !empty($act['target_name'])): ?>
                                            <br><span class="sap-muted">&rarr; <?= e($act['target_name']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="sap-muted"><?= $isActor ? 'Actor' : 'Target' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (count($recentActivity) > 25): ?>
                            <p class="sap-muted" style="margin-top:.5rem;">Showing 25 of <?= count($recentActivity) ?> recent events. <a class="sap-link" href="/system-admin.php?target_person_id=<?= (int) $person['id'] ?>">View all in Audit Log</a></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="sap-muted">No recent activity recorded.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php elseif (!$search): ?>
        <!-- ─── Empty state ──────────────────────────────────────────── -->
        <div style="text-align:center; padding:3rem 1rem; background:#f7f5fb; border:2px solid #e5e5e5;">
            <h2 style="color:#4d0b93; margin:0 0 .5rem;">Person Lookup</h2>
            <p style="color:#555; margin:0;">Search by name, email address, or person ID to view and manage a volunteer's record.</p>
        </div>
    <?php endif; ?>

</main>

<?php include __DIR__ . '/footer.php'; ?>
