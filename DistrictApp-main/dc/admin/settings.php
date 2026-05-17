<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_admin();

$pdo = db();
$flash = '';
$error = '';

$activeTab = trim((string)($_GET['tab'] ?? $_POST['active_tab'] ?? 'groups'));
$allowedTabs = ['groups', 'users'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'groups';
}

function settings_redirect(string $tab): void
{
    redirect(BASE_URL . '/admin/settings.php?tab=' . urlencode($tab) . '&saved=1');
}

function current_admin_id(): ?int
{
    $admin = auth_admin();

    if ($admin) {
        $id = (int)($admin['admin_user_id'] ?? $admin['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    $portalUser = auth_portal_user();

    if ($portalUser) {
        $id = (int)($portalUser['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    return null;
}

function full_group_link(array $group): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return $scheme . '://' . $host . BASE_URL . '/index.php?token=' . urlencode((string)$group['access_token']);
}

/*
|--------------------------------------------------------------------------
| Handle actions
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = trim((string)($_POST['form_type'] ?? ''));

    if ($formType === 'add_group' || $formType === 'update_group') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $groupName = trim((string)($_POST['group_name'] ?? ''));
        $districtName = trim((string)($_POST['district_name'] ?? ''));
        $leadName = trim((string)($_POST['lead_volunteer_name'] ?? ''));
        $leadEmail = trim((string)($_POST['lead_volunteer_email'] ?? ''));
        $notifyLead = isset($_POST['notify_lead_on_event_created']) ? 1 : 0;

        if ($groupName === '') {
            $error = 'Group name is required.';
            $activeTab = 'groups';
        } elseif ($leadEmail !== '' && !filter_var($leadEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Lead volunteer email is invalid.';
            $activeTab = 'groups';
        } else {
            if ($formType === 'add_group') {
                $token = bin2hex(random_bytes(32));

                $stmt = $pdo->prepare("
                    INSERT INTO groups (
                        group_name,
                        district_name,
                        access_token,
                        lead_volunteer_name,
                        lead_volunteer_email,
                        notify_lead_on_event_created,
                        is_active,
                        created_by_admin_id,
                        created_at,
                        updated_at
                    ) VALUES (
                        :group_name,
                        :district_name,
                        :access_token,
                        :lead_name,
                        :lead_email,
                        :notify_lead,
                        1,
                        :created_by,
                        NOW(),
                        NOW()
                    )
                ");
                $stmt->execute([
                    'group_name' => $groupName,
                    'district_name' => $districtName !== '' ? $districtName : null,
                    'access_token' => $token,
                    'lead_name' => $leadName !== '' ? $leadName : null,
                    'lead_email' => $leadEmail !== '' ? $leadEmail : null,
                    'notify_lead' => $notifyLead,
                    'created_by' => current_admin_id(),
                ]);

                settings_redirect('groups');
            }

            if ($formType === 'update_group') {
                if ($groupId <= 0) {
                    $error = 'Invalid group selected.';
                    $activeTab = 'groups';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE groups
                        SET group_name = :group_name,
                            district_name = :district_name,
                            lead_volunteer_name = :lead_name,
                            lead_volunteer_email = :lead_email,
                            notify_lead_on_event_created = :notify_lead,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'group_name' => $groupName,
                        'district_name' => $districtName !== '' ? $districtName : null,
                        'lead_name' => $leadName !== '' ? $leadName : null,
                        'lead_email' => $leadEmail !== '' ? $leadEmail : null,
                        'notify_lead' => $notifyLead,
                        'id' => $groupId,
                    ]);

                    settings_redirect('groups');
                }
            }
        }
    }

    if ($formType === 'set_group_status') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

        if ($groupId <= 0) {
            $error = 'Invalid group selected.';
            $activeTab = 'groups';
        } else {
            $stmt = $pdo->prepare("
                UPDATE groups
                SET is_active = :is_active,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'is_active' => $isActive,
                'id' => $groupId,
            ]);

            settings_redirect('groups');
        }
    }

    if ($formType === 'regenerate_group_token') {
        $groupId = (int)($_POST['group_id'] ?? 0);

        if ($groupId <= 0) {
            $error = 'Invalid group selected.';
            $activeTab = 'groups';
        } else {
            $stmt = $pdo->prepare("
                UPDATE groups
                SET access_token = :access_token,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'access_token' => bin2hex(random_bytes(32)),
                'id' => $groupId,
            ]);

            settings_redirect('groups');
        }
    }

    if ($formType === 'add_user' || $formType === 'update_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $role = trim((string)($_POST['role'] ?? 'reviewer'));
        $password = (string)($_POST['password'] ?? '');

        if ($fullName === '' || $email === '') {
            $error = 'Full name and email are required.';
            $activeTab = 'users';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email address is invalid.';
            $activeTab = 'users';
        } elseif (!in_array($role, ['admin', 'reviewer'], true)) {
            $error = 'Invalid role selected.';
            $activeTab = 'users';
        } elseif ($formType === 'add_user' && $password === '') {
            $error = 'Temporary password is required.';
            $activeTab = 'users';
        } elseif ($password !== '' && strlen($password) < 12) {
            $error = 'Password must be at least 12 characters.';
            $activeTab = 'users';
        } else {
            $checkSql = "
                SELECT id
                FROM admin_users
                WHERE email = :email
            ";

            if ($formType === 'update_user') {
                $checkSql .= " AND id <> :id";
            }

            $checkSql .= " LIMIT 1";

            $check = $pdo->prepare($checkSql);
            $params = ['email' => $email];

            if ($formType === 'update_user') {
                $params['id'] = $userId;
            }

            $check->execute($params);

            if ($check->fetch()) {
                $error = 'A user with that email already exists.';
                $activeTab = 'users';
            } elseif ($formType === 'add_user') {
                $stmt = $pdo->prepare("
                    INSERT INTO admin_users (
                        full_name,
                        email,
                        password_hash,
                        role,
                        is_active,
                        created_at,
                        updated_at
                    ) VALUES (
                        :full_name,
                        :email,
                        :password_hash,
                        :role,
                        1,
                        NOW(),
                        NOW()
                    )
                ");
                $stmt->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role,
                ]);

                settings_redirect('users');
            } else {
                if ($userId <= 0) {
                    $error = 'Invalid user selected.';
                    $activeTab = 'users';
                } else {
                    if ($password !== '') {
                        $stmt = $pdo->prepare("
                            UPDATE admin_users
                            SET full_name = :full_name,
                                email = :email,
                                role = :role,
                                password_hash = :password_hash,
                                updated_at = NOW()
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            'full_name' => $fullName,
                            'email' => $email,
                            'role' => $role,
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'id' => $userId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE admin_users
                            SET full_name = :full_name,
                                email = :email,
                                role = :role,
                                updated_at = NOW()
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            'full_name' => $fullName,
                            'email' => $email,
                            'role' => $role,
                            'id' => $userId,
                        ]);
                    }

                    settings_redirect('users');
                }
            }
        }
    }

    if ($formType === 'set_user_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

        if ($userId <= 0) {
            $error = 'Invalid user selected.';
            $activeTab = 'users';
        } elseif ($userId === current_admin_id() && $isActive === 0) {
            $error = 'You cannot disable your own account.';
            $activeTab = 'users';
        } else {
            $stmt = $pdo->prepare("
                UPDATE admin_users
                SET is_active = :is_active,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'is_active' => $isActive,
                'id' => $userId,
            ]);

            settings_redirect('users');
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $flash = 'Settings saved successfully.';
}

/*
|--------------------------------------------------------------------------
| Load data
|--------------------------------------------------------------------------
*/
$groups = $pdo->query("
    SELECT
        id,
        group_name,
        district_name,
        lead_volunteer_name,
        lead_volunteer_email,
        notify_lead_on_event_created,
        access_token,
        is_active,
        updated_at
    FROM groups
    ORDER BY is_active DESC, group_name ASC
")->fetchAll();

$users = $pdo->query("
    SELECT
        id,
        full_name,
        email,
        role,
        is_active,
        last_login_at,
        updated_at
    FROM admin_users
    ORDER BY is_active DESC, full_name ASC
")->fetchAll();

render_page_start('Settings');
render_header('settings');
?>

<div class="container-fluid">
    <div class="mb-4">
        <h1 class="mb-1">Settingqs</h1>
        <p class="text-muted mb-0">Manage groups, portal access links, reviewer users and admin users.</p>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-success"><?= e($flash) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'groups' ? 'active' : '' ?>"
               href="#groups-tab"
               data-toggle="tab"
               role="tab">
                Groups
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'users' ? 'active' : '' ?>"
               href="#users-tab"
               data-toggle="tab"
               role="tab">
                Reviewer / Admin Users
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade <?= $activeTab === 'groups' ? 'show active' : '' ?>" id="groups-tab" role="tabpanel">
            <div class="row">
                <div class="col-xl-4 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h2 class="h4 mb-3">Add Group</h2>

                            <form method="post">
                                <input type="hidden" name="form_type" value="add_group">
                                <input type="hidden" name="active_tab" value="groups">

                                <div class="form-group">
                                    <label for="group_name">Group name</label>
                                    <input type="text" class="form-control" id="group_name" name="group_name" required>
                                </div>

                                <div class="form-group">
                                    <label for="district_name">District name</label>
                                    <input type="text" class="form-control" id="district_name" name="district_name">
                                </div>

                                <div class="form-group">
                                    <label for="lead_volunteer_name">Lead volunteer name</label>
                                    <input type="text" class="form-control" id="lead_volunteer_name" name="lead_volunteer_name">
                                </div>

                                <div class="form-group">
                                    <label for="lead_volunteer_email">Lead volunteer email</label>
                                    <input type="email" class="form-control" id="lead_volunteer_email" name="lead_volunteer_email">
                                </div>

                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="notify_lead_on_event_created" name="notify_lead_on_event_created" value="1">
                                    <label class="form-check-label" for="notify_lead_on_event_created">
                                        Notify lead volunteer when events are created/reviewed
                                    </label>
                                </div>

                                <button type="submit" class="btn btn-primary btn-block">Add group</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-xl-8 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h2 class="h4 mb-3">Groups</h2>

                            <?php if (empty($groups)): ?>
                                <p class="text-muted mb-0">No groups added yet.</p>
                            <?php else: ?>
                                <div class="accordion" id="groupsAccordion">
                                    <?php foreach ($groups as $group): ?>
                                        <?php
                                        $groupLink = full_group_link($group);
                                        $collapseId = 'groupCollapse' . (int)$group['id'];
                                        ?>
                                        <div class="card mb-2">
                                            <div class="card-header d-md-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?= e($group['group_name']) ?></strong>
                                                    <?php if ((int)$group['is_active'] === 1): ?>
                                                        <span class="badge badge-success ml-2">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary ml-2">Disabled</span>
                                                    <?php endif; ?>

                                                    <?php if (!empty($group['district_name'])): ?>
                                                        <br><small class="text-muted"><?= e($group['district_name']) ?></small>
                                                    <?php endif; ?>
                                                </div>

                                                <button class="btn btn-outline-primary btn-sm mt-2 mt-md-0"
                                                        type="button"
                                                        data-toggle="collapse"
                                                        data-target="#<?= e($collapseId) ?>">
                                                    Edit
                                                </button>
                                            </div>

                                            <div id="<?= e($collapseId) ?>" class="collapse" data-parent="#groupsAccordion">
                                                <div class="card-body">
                                                    <form method="post">
                                                        <input type="hidden" name="form_type" value="update_group">
                                                        <input type="hidden" name="active_tab" value="groups">
                                                        <input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>">

                                                        <div class="form-row">
                                                            <div class="form-group col-md-6">
                                                                <label>Group name</label>
                                                                <input type="text" class="form-control" name="group_name" value="<?= e($group['group_name']) ?>" required>
                                                            </div>

                                                            <div class="form-group col-md-6">
                                                                <label>District name</label>
                                                                <input type="text" class="form-control" name="district_name" value="<?= e((string)$group['district_name']) ?>">
                                                            </div>
                                                        </div>

                                                        <div class="form-row">
                                                            <div class="form-group col-md-6">
                                                                <label>Lead volunteer name</label>
                                                                <input type="text" class="form-control" name="lead_volunteer_name" value="<?= e((string)$group['lead_volunteer_name']) ?>">
                                                            </div>

                                                            <div class="form-group col-md-6">
                                                                <label>Lead volunteer email</label>
                                                                <input type="email" class="form-control" name="lead_volunteer_email" value="<?= e((string)$group['lead_volunteer_email']) ?>">
                                                            </div>
                                                        </div>

                                                        <div class="form-check mb-3">
                                                            <input type="checkbox"
                                                                   class="form-check-input"
                                                                   id="notify_<?= (int)$group['id'] ?>"
                                                                   name="notify_lead_on_event_created"
                                                                   value="1"
                                                                   <?= (int)$group['notify_lead_on_event_created'] === 1 ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="notify_<?= (int)$group['id'] ?>">
                                                                Notify lead volunteer when events are created/reviewed
                                                            </label>
                                                        </div>

                                                        <div class="form-group">
                                                            <label>Portal access link</label>
                                                            <div class="input-group">
                                                                <input type="text" class="form-control" readonly value="<?= e($groupLink) ?>">
                                                                <div class="input-group-append">
                                                                    <a href="<?= e($groupLink) ?>" target="_blank" class="btn btn-outline-secondary">Open</a>
                                                                </div>
                                                            </div>
                                                            <small class="text-muted">
                                                                Regenerating this link will invalidate the existing group portal link.
                                                            </small>
                                                        </div>

                                                        <button type="submit" class="btn btn-primary">
                                                            Save group
                                                        </button>
                                                    </form>

                                                    <hr>

                                                    <div class="d-md-flex justify-content-between">
                                                        <form method="post" class="mb-2 mb-md-0">
                                                            <input type="hidden" name="form_type" value="set_group_status">
                                                            <input type="hidden" name="active_tab" value="groups">
                                                            <input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>">
                                                            <input type="hidden" name="is_active" value="<?= (int)$group['is_active'] === 1 ? 0 : 1 ?>">

                                                            <button type="submit"
                                                                    class="btn <?= (int)$group['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                                                    onclick="return confirm('Are you sure?');">
                                                                <?= (int)$group['is_active'] === 1 ? 'Disable group' : 'Enable group' ?>
                                                            </button>
                                                        </form>

                                                        <form method="post">
                                                            <input type="hidden" name="form_type" value="regenerate_group_token">
                                                            <input type="hidden" name="active_tab" value="groups">
                                                            <input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>">

                                                            <button type="submit"
                                                                    class="btn btn-outline-warning"
                                                                    onclick="return confirm('Regenerate this group access link? The old link will stop working.');">
                                                                Regenerate portal link
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?= $activeTab === 'users' ? 'show active' : '' ?>" id="users-tab" role="tabpanel">
            <div class="row">
                <div class="col-xl-4 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h2 class="h4 mb-3">Add Reviewer / Admin User</h2>

                            <form method="post">
                                <input type="hidden" name="form_type" value="add_user">
                                <input type="hidden" name="active_tab" value="users">

                                <div class="form-group">
                                    <label for="full_name">Full name</label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" required>
                                </div>

                                <div class="form-group">
                                    <label for="email">Email address</label>
                                    <input type="email" class="form-control" id="email" name="email" required>
                                </div>

                                <div class="form-group">
                                    <label for="role">Role</label>
                                    <select class="form-control" id="role" name="role" required>
                                        <option value="reviewer">Reviewer</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="password">Temporary password</label>
                                    <input type="text" class="form-control" id="password" name="password" required minlength="12">
                                    <small class="text-muted">Minimum 12 characters.</small>
                                </div>

                                <button type="submit" class="btn btn-primary btn-block">Add user</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-xl-8 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h2 class="h4 mb-3">Reviewer / Admin Users</h2>

                            <?php if (empty($users)): ?>
                                <p class="text-muted mb-0">No users found.</p>
                            <?php else: ?>
                                <div class="accordion" id="usersAccordion">
                                    <?php foreach ($users as $user): ?>
                                        <?php $collapseId = 'userCollapse' . (int)$user['id']; ?>
                                        <div class="card mb-2">
                                            <div class="card-header d-md-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?= e($user['full_name']) ?></strong>
                                                    <span class="badge badge-info ml-2"><?= e(ucfirst($user['role'])) ?></span>

                                                    <?php if ((int)$user['is_active'] === 1): ?>
                                                        <span class="badge badge-success ml-1">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary ml-1">Disabled</span>
                                                    <?php endif; ?>

                                                    <br>
                                                    <small class="text-muted">
                                                        <?= e($user['email']) ?> ·
                                                        Last login:
                                                        <?= !empty($user['last_login_at']) ? e(date('d M Y H:i', strtotime((string)$user['last_login_at']))) : 'Never' ?>
                                                    </small>
                                                </div>

                                                <button class="btn btn-outline-primary btn-sm mt-2 mt-md-0"
                                                        type="button"
                                                        data-toggle="collapse"
                                                        data-target="#<?= e($collapseId) ?>">
                                                    Edit
                                                </button>
                                            </div>

                                            <div id="<?= e($collapseId) ?>" class="collapse" data-parent="#usersAccordion">
                                                <div class="card-body">
                                                    <form method="post">
                                                        <input type="hidden" name="form_type" value="update_user">
                                                        <input type="hidden" name="active_tab" value="users">
                                                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

                                                        <div class="form-row">
                                                            <div class="form-group col-md-6">
                                                                <label>Full name</label>
                                                                <input type="text" class="form-control" name="full_name" value="<?= e($user['full_name']) ?>" required>
                                                            </div>

                                                            <div class="form-group col-md-6">
                                                                <label>Email address</label>
                                                                <input type="email" class="form-control" name="email" value="<?= e($user['email']) ?>" required>
                                                            </div>
                                                        </div>

                                                        <div class="form-row">
                                                            <div class="form-group col-md-6">
                                                                <label>Role</label>
                                                                <select class="form-control" name="role" required>
                                                                    <option value="reviewer" <?= $user['role'] === 'reviewer' ? 'selected' : '' ?>>Reviewer</option>
                                                                    <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                                </select>
                                                            </div>

                                                            <div class="form-group col-md-6">
                                                                <label>New password</label>
                                                                <input type="text" class="form-control" name="password" minlength="12">
                                                                <small class="text-muted">Leave blank to keep current password.</small>
                                                            </div>
                                                        </div>

                                                        <button type="submit" class="btn btn-primary">
                                                            Save user
                                                        </button>
                                                    </form>

                                                    <hr>

                                                    <form method="post">
                                                        <input type="hidden" name="form_type" value="set_user_status">
                                                        <input type="hidden" name="active_tab" value="users">
                                                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                                        <input type="hidden" name="is_active" value="<?= (int)$user['is_active'] === 1 ? 0 : 1 ?>">

                                                        <button type="submit"
                                                                class="btn <?= (int)$user['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                                                onclick="return confirm('Are you sure?');"
                                                                <?= (int)$user['id'] === current_admin_id() && (int)$user['is_active'] === 1 ? 'disabled' : '' ?>>
                                                            <?= (int)$user['is_active'] === 1 ? 'Disable user' : 'Enable user' ?>
                                                        </button>

                                                        <?php if ((int)$user['id'] === current_admin_id()): ?>
                                                            <small class="text-muted ml-2">You cannot disable your own account.</small>
                                                        <?php endif; ?>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#settingsTabs a[data-toggle="tab"]').forEach(function (tab) {
        tab.addEventListener('shown.bs.tab', function (event) {
            const href = event.target.getAttribute('href');
            const tabName = href === '#users-tab' ? 'users' : 'groups';
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url.toString());
        });
    });
});
</script>

<?php render_page_end(); ?>