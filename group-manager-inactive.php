<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/group-manager-helpers.php';

require_login();

if (function_exists('user_needs_group_onboarding') && user_needs_group_onboarding()) {
    redirect('/onboarding.php');
}

$pdo = db();
$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

$memberships = gm_current_memberships($user);
$isDistrictAdmin = gm_actor_is_district_admin($user, $memberships);
$manageableGroups = gm_manageable_groups((int) $user['id'], $isDistrictAdmin);

if (!$manageableGroups) {
    http_response_code(403);

    $pageTitle = 'Inactive people | ' . $appName;
    $heroTitle = 'Inactive people';
    $heroText = 'This area is for Group Lead Volunteers and District administrators.';
    $breadcrumb = '<a href="/index.php">Home</a> / <a href="/group-manager.php">Group Manager</a> / Inactive people';

    include __DIR__ . '/header.php';
    echo '<main class="lt-main"><div class="alert alert-danger"><strong>Access denied:</strong> You do not currently manage any Groups.</div></main>';
    include __DIR__ . '/footer.php';
    exit;
}

$selectedGroupId = gm_selected_group_id($manageableGroups);
$selectedGroup = gm_fetch_group($selectedGroupId);

if (!$selectedGroup || !gm_group_is_manageable($selectedGroupId, $manageableGroups)) {
    http_response_code(404);

    $pageTitle = 'Inactive people | ' . $appName;
    $heroTitle = 'Inactive people';
    $heroText = 'Group not found or you do not have permission to manage it.';
    $breadcrumb = '<a href="/index.php">Home</a> / <a href="/group-manager.php">Group Manager</a> / Inactive people';

    include __DIR__ . '/header.php';
    echo '<main class="lt-main"><div class="alert alert-danger"><strong>Group not found:</strong> You cannot manage this Group.</div></main>';
    include __DIR__ . '/footer.php';
    exit;
}

$errors = [];
$success = null;
$actorPersonId = (int) $user['id'];
$roleOptions = gm_membership_role_options();

function gmi_membership_role_label(?string $role): string
{
    return match ((string) $role) {
        'group_lead_volunteer' => 'Group Lead Volunteer',
        'section_leader' => 'Section Leader',
        'assistant_section_leader' => 'Assistant Section Leader',
        'section_assistant' => 'Section Assistant',
        'trustee' => 'Trustee',
        'district_volunteer' => 'District Volunteer',
        'administrator' => 'Administrator',
        'member' => 'Member',
        'other' => 'Other',
        default => ucwords(str_replace('_', ' ', (string) ($role ?: 'Member'))),
    };
}

function gmi_access_level_label(?string $accessLevel): string
{
    return match ((string) $accessLevel) {
        'system_admin' => 'System Admin',
        'district_admin' => 'District Admin',
        'district_reviewer' => 'District Reviewer',
        'group_admin' => 'Group Admin',
        'member' => 'Member',
        default => ucwords(str_replace('_', ' ', (string) ($accessLevel ?: 'member'))),
    };
}

function gmi_role_title_from_membership_role(string $membershipRole): string
{
    if (function_exists('gm_role_title_from_membership_role')) {
        return gm_role_title_from_membership_role($membershipRole);
    }

    return gmi_membership_role_label($membershipRole);
}

function gmi_access_level_from_membership_role(string $membershipRole, ?string $existingAccessLevel = null): string
{
    if (function_exists('portal_access_level_from_membership_role')) {
        return portal_access_level_from_membership_role($membershipRole);
    }

    if ($membershipRole === 'group_lead_volunteer') {
        return 'group_admin';
    }

    if ($existingAccessLevel === 'district_admin' || $existingAccessLevel === 'system_admin') {
        return $existingAccessLevel;
    }

    return 'member';
}

function gmi_find_inactive_membership(int $personId, int $groupId): ?array
{
    $stmt = db()->prepare("
        SELECT
            gm.*,
            p.full_name,
            p.primary_email,
            p.phone,
            p.status AS person_status
        FROM group_memberships gm
        JOIN people p
          ON p.id = gm.person_id
        WHERE gm.person_id = :person_id
          AND gm.group_id = :group_id
        LIMIT 1
    ");
    $stmt->execute([
        'person_id' => $personId,
        'group_id' => $groupId,
    ]);

    $membership = $stmt->fetch(PDO::FETCH_ASSOC);

    return $membership ?: null;
}

function gmi_ensure_directory_profile(int $personId, string $membershipRole): void
{
    $roleTitle = gmi_role_title_from_membership_role($membershipRole);

    $stmt = db()->prepare("
        INSERT INTO directory_profiles (
            person_id,
            role_title,
            visible_in_directory,
            share_phone,
            profile_updated_at
        )
        VALUES (
            :person_id,
            :role_title,
            1,
            0,
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            role_title = CASE
                WHEN role_title IS NULL OR role_title = '' THEN VALUES(role_title)
                ELSE role_title
            END,
            visible_in_directory = 1,
            profile_updated_at = NOW()
    ");
    $stmt->execute([
        'person_id' => $personId,
        'role_title' => $roleTitle,
    ]);
}

function gmi_reactivate_person(
    int $personId,
    int $groupId,
    string $membershipRole,
    int $actorPersonId
): void {
    $membership = gmi_find_inactive_membership($personId, $groupId);

    if (!$membership) {
        throw new RuntimeException('This person is not linked to this Group.');
    }

    $existingAccessLevel = (string) ($membership['access_level'] ?? 'member');
    $accessLevel = gmi_access_level_from_membership_role($membershipRole, $existingAccessLevel);

    db()->beginTransaction();

    try {
        $stmt = db()->prepare("
            UPDATE people
            SET status = 'active'
            WHERE id = :person_id
        ");
        $stmt->execute([
            'person_id' => $personId,
        ]);

        $stmt = db()->prepare("
            UPDATE group_memberships
            SET
                membership_role = :membership_role,
                access_level = :access_level,
                status = 'active',
                approved_at = COALESCE(approved_at, NOW())
            WHERE person_id = :person_id
              AND group_id = :group_id
        ");
        $stmt->execute([
            'membership_role' => $membershipRole,
            'access_level' => $accessLevel,
            'person_id' => $personId,
            'group_id' => $groupId,
        ]);

        gmi_ensure_directory_profile($personId, $membershipRole);

        if (function_exists('gm_log_action')) {
            gm_log_action($actorPersonId, 'group_person_reactivated', 'person', $personId, [
                'group_id' => $groupId,
                'membership_role' => $membershipRole,
                'access_level' => $accessLevel,
                'previous_person_status' => $membership['person_status'] ?? null,
                'previous_membership_status' => $membership['status'] ?? null,
            ]);
        }

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function gmi_fetch_inactive_people(int $groupId, string $search = ''): array
{
    $where = [
        'gm.group_id = :group_id',
        "(gm.status <> 'active' OR p.status <> 'active')",
    ];

    $params = [
        'group_id' => $groupId,
    ];

    if ($search !== '') {
        $where[] = "(
            p.full_name LIKE :search
            OR p.primary_email LIKE :search
            OR p.phone LIKE :search
            OR dp.role_title LIKE :search
            OR gm.membership_role LIKE :search
        )";
        $params['search'] = '%' . $search . '%';
    }

    $stmt = db()->prepare("
        SELECT
            p.id AS person_id,
            p.full_name,
            p.primary_email,
            p.phone,
            p.status AS person_status,

            gm.id AS membership_id,
            gm.membership_role,
            gm.access_level,
            gm.status AS membership_status,
            gm.is_primary,
            gm.approved_at,

            dp.role_title,
            dp.visible_in_directory,
            dp.share_phone,
            dp.profile_updated_at,

            ua.last_login_at AS microsoft_last_login_at

        FROM group_memberships gm

        JOIN people p
          ON p.id = gm.person_id

        LEFT JOIN directory_profiles dp
          ON dp.person_id = p.id

        LEFT JOIN user_accounts ua
          ON ua.person_id = p.id
         AND ua.provider = 'microsoft'

        WHERE " . implode(' AND ', $where) . "

        ORDER BY
            p.full_name ASC,
            p.primary_email ASC
    ");
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $postedGroupId = (int) ($_POST['group_id'] ?? 0);
    $personId = (int) ($_POST['person_id'] ?? 0);
    $membershipRole = (string) ($_POST['membership_role'] ?? 'section_leader');

    try {
        if ($postedGroupId !== $selectedGroupId || !gm_group_is_manageable($postedGroupId, $manageableGroups)) {
            throw new RuntimeException('You do not have permission to manage that Group.');
        }

        if ($personId < 1) {
            throw new RuntimeException('Choose a valid person.');
        }

        if ($action !== 'reactivate_person') {
            throw new RuntimeException('Choose a valid action.');
        }

        if (!array_key_exists($membershipRole, $roleOptions)) {
            throw new RuntimeException('Choose a valid role.');
        }

        gmi_reactivate_person($personId, $selectedGroupId, $membershipRole, $actorPersonId);

        $success = 'Person reactivated and added back to this Group.';
    } catch (Throwable $e) {
        $errors[] = $e->getMessage() ?: 'The request could not be completed.';
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$inactivePeople = gmi_fetch_inactive_people($selectedGroupId, $search);

$pageTitle = 'Inactive people | ' . $appName;
$heroTitle = 'Inactive people';
$heroText = 'Review and reactivate people previously removed from ' . (string) $selectedGroup['group_name'] . '.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/group-manager.php?group_id=' . (int) $selectedGroupId . '">Group Manager</a> / Inactive people';

include __DIR__ . '/header.php';
?>

<style>
    .gmi-subnav {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        margin-bottom: 1rem;
    }

    .gmi-panel {
        background: #ffffff;
        border: 2px solid #e6e6e6;
        padding: 1.25rem;
        margin-bottom: 1rem;
        box-shadow: none;
        border-radius: 0;
    }

    .gmi-panel h2,
    .gmi-panel h3 {
        color: #4d0b93;
        font-weight: 900;
    }

    .gmi-search-row {
        display: grid;
        gap: .75rem;
    }

    @media (min-width: 768px) {
        .gmi-search-row {
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: end;
        }
    }

    .gmi-table-wrap {
        overflow-x: auto;
    }

    .gmi-table {
        width: 100%;
        border-collapse: collapse;
        background: #ffffff;
    }

    .gmi-table th,
    .gmi-table td {
        border-bottom: 1px solid #e6e6e6;
        padding: .85rem;
        vertical-align: top;
    }

    .gmi-table th {
        background: #f7f5fb;
        color: #4d0b93;
        font-weight: 900;
        white-space: nowrap;
    }

    .gmi-person-name {
        font-weight: 900;
        color: #1d1d1b;
    }

    .gmi-muted {
        color: #555;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .gmi-badge-row {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
        margin-top: .45rem;
    }

    .gmi-badge {
        display: inline-flex;
        align-items: center;
        border: 2px solid #d8d8d8;
        background: #ffffff;
        color: #333;
        padding: .2rem .45rem;
        font-size: .78rem;
        font-weight: 900;
        border-radius: 0;
    }

    .gmi-badge-warning {
        border-color: #ffdd00;
        background: #fff8d6;
    }

    .gmi-badge-danger {
        border-color: #d4351c;
        background: #fbeaea;
        color: #942514;
    }

    .gmi-action-form {
        display: grid;
        gap: .5rem;
        min-width: 260px;
    }

    .gmi-empty {
        background: #f7f5fb;
        border: 2px solid #e6e6e6;
        padding: 1.25rem;
        border-radius: 0;
    }
</style>

<main class="lt-main">
    <div class="gmi-subnav">
        <a class="btn btn-secondary lt-btn" href="/group-manager.php?group_id=<?= (int) $selectedGroupId ?>">Back to Group Manager</a>
        <a class="btn btn-secondary lt-btn" href="/group-manager-add-person.php?group_id=<?= (int) $selectedGroupId ?>">Add person</a>
        <a class="btn btn-secondary lt-btn" href="/group-manager-access.php?group_id=<?= (int) $selectedGroupId ?>">Calendar access</a>
    </div>

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

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <section class="gmi-panel">
        <h2>Inactive people in <?= e($selectedGroup['group_name']) ?></h2>
        <p class="mb-0">
            Use this page to bring someone back into the Group after they have previously been made inactive.
            Reactivating a person also makes them active in the Directory again.
        </p>
    </section>

    <section class="gmi-panel">
        <form method="get">
            <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">

            <div class="gmi-search-row">
                <div class="form-group mb-md-0">
                    <label for="q">Search inactive people</label>
                    <input
                        class="form-control"
                        type="search"
                        id="q"
                        name="q"
                        value="<?= e($search) ?>"
                        placeholder="Search by name, email, phone or role"
                    >
                </div>

                <div>
                    <button class="btn btn-primary lt-btn" type="submit">Search</button>
                    <?php if ($search !== ''): ?>
                        <a class="btn btn-secondary lt-btn" href="/group-manager-inactive.php?group_id=<?= (int) $selectedGroupId ?>">Clear</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </section>

    <section class="gmi-panel">
        <h2>People to review</h2>

        <?php if (!$inactivePeople): ?>
            <div class="gmi-empty">
                <strong>No inactive people found.</strong>
                <p class="mb-0 mt-2">
                    There are no inactive people linked to this Group<?= $search !== '' ? ' matching your search' : '' ?>.
                </p>
            </div>
        <?php else: ?>
            <div class="gmi-table-wrap">
                <table class="gmi-table">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th>Current status</th>
                            <th>Previous role</th>
                            <th>Directory</th>
                            <th>Reactivate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inactivePeople as $person): ?>
                            <?php
                                $personId = (int) $person['person_id'];
                                $personStatus = (string) ($person['person_status'] ?? '');
                                $membershipStatus = (string) ($person['membership_status'] ?? '');
                                $membershipRole = (string) ($person['membership_role'] ?? 'section_leader');
                                $accessLevel = (string) ($person['access_level'] ?? 'member');
                                $visibleInDirectory = (int) ($person['visible_in_directory'] ?? 1);
                                $sharePhone = (int) ($person['share_phone'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <div class="gmi-person-name"><?= e($person['full_name'] ?: 'Unnamed person') ?></div>

                                    <?php if (!empty($person['primary_email'])): ?>
                                        <div class="gmi-muted"><?= e((string) $person['primary_email']) ?></div>
                                    <?php endif; ?>

                                    <?php if (!empty($person['phone'])): ?>
                                        <div class="gmi-muted"><?= e((string) $person['phone']) ?></div>
                                    <?php endif; ?>

                                    <div class="mt-2">
                                        <a href="/group-manager-person.php?group_id=<?= (int) $selectedGroupId ?>&person_id=<?= (int) $personId ?>">
                                            View person record
                                        </a>
                                    </div>
                                </td>

                                <td>
                                    <div class="gmi-badge-row">
                                        <span class="gmi-badge <?= $personStatus !== 'active' ? 'gmi-badge-danger' : '' ?>">
                                            Person: <?= e(ucfirst($personStatus ?: 'unknown')) ?>
                                        </span>

                                        <span class="gmi-badge <?= $membershipStatus !== 'active' ? 'gmi-badge-warning' : '' ?>">
                                            Group link: <?= e(ucfirst($membershipStatus ?: 'unknown')) ?>
                                        </span>
                                    </div>

                                    <?php if (!empty($person['microsoft_last_login_at'])): ?>
                                        <div class="gmi-muted mt-2">
                                            Last Microsoft sign-in: <?= e((string) $person['microsoft_last_login_at']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <strong><?= e(gmi_membership_role_label($membershipRole)) ?></strong>
                                    <div class="gmi-muted"><?= e(gmi_access_level_label($accessLevel)) ?></div>

                                    <?php if (!empty($person['role_title'])): ?>
                                        <div class="gmi-muted mt-2">
                                            Directory role: <?= e((string) $person['role_title']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="gmi-badge-row">
                                        <span class="gmi-badge <?= $visibleInDirectory === 1 ? '' : 'gmi-badge-danger' ?>">
                                            <?= $visibleInDirectory === 1 ? 'Visible' : 'Hidden' ?>
                                        </span>

                                        <span class="gmi-badge">
                                            Phone <?= $sharePhone === 1 ? 'shown' : 'hidden' ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <form method="post" class="gmi-action-form">
                                        <input type="hidden" name="action" value="reactivate_person">
                                        <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">

                                        <div class="form-group mb-0">
                                            <label for="membership_role_<?= (int) $personId ?>">Role</label>
                                            <select
                                                class="form-control"
                                                id="membership_role_<?= (int) $personId ?>"
                                                name="membership_role"
                                                required
                                            >
                                                <?php foreach ($roleOptions as $value => $label): ?>
                                                    <option value="<?= e((string) $value) ?>" <?= (string) $value === $membershipRole ? 'selected' : '' ?>>
                                                        <?= e((string) $label) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <button class="btn btn-primary lt-btn" type="submit">
                                            Reactivate person
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>