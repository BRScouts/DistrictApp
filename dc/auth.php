<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * ------------------------------------------------------------
 * Session model
 * ------------------------------------------------------------
 *
 * Group-link session:
 * $_SESSION['group_auth'] = [
 *   'group_id' => 1,
 *   'group_name' => '1st Example Scouts',
 *   'access_token' => '...',
 * ]
 *
 * Admin/reviewer session:
 * $_SESSION['admin_auth'] = [
 *   'admin_user_id' => 1,
 *   'full_name' => 'District Reviewer',
 *   'email' => 'reviewer@example.org',
 *   'role' => 'reviewer' | 'admin'
 * ]
 */

/**
 * Attempt group auth from ?token=...
 * If valid, persist group session.
 */
function auth_capture_group_token_from_request(): void
{
    $tokenKey = TOKEN_QUERY_KEY;
    $token = isset($_GET[$tokenKey]) ? trim((string)$_GET[$tokenKey]) : '';

    if ($token === '') {
        return;
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id, group_name, access_token
        FROM groups
        WHERE access_token = :token
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute(['token' => $token]);
    $group = $stmt->fetch();

    if (!$group) {
        redirect(ROUTE_403);
    }

    $_SESSION['group_auth'] = [
        'group_id'      => (int)$group['id'],
        'group_name'    => $group['group_name'],
        'access_token'  => $group['access_token'],
    ];
}

/**
 * Returns admin auth array or null
 */
function auth_admin(): ?array
{
    return $_SESSION['admin_auth'] ?? null;
}

/**
 * Returns group auth array or null
 */
function auth_group(): ?array
{
    return $_SESSION['group_auth'] ?? null;
}

/**
 * Effective auth context
 * Admin/reviewer takes precedence over group-link session.
 */
function auth_context(): ?array
{
    $admin = auth_admin();

    if ($admin) {
        return [
            'type' => 'admin',
            'role' => $admin['role'],
            'name' => $admin['full_name'],
        ];
    }

    $group = auth_group();

    if ($group) {
        return [
            'type' => ROLE_GROUP_LINK,
            'role' => ROLE_GROUP_LINK,
            'group_id' => $group['group_id'],
            'group_name' => $group['group_name'],
            'name' => $group['group_name'],
        ];
    }

    return null;
}

/**
 * Whether user is authenticated at all
 */
function is_authenticated(): bool
{
    return auth_context() !== null;
}

/**
 * Whether current user is reviewer/admin
 */
function is_reviewer_or_admin(): bool
{
    $admin = auth_admin();
    return $admin && in_array($admin['role'], [ROLE_REVIEWER, ROLE_ADMIN], true);
}

/**
 * Whether current user is admin
 */
function is_admin(): bool
{
    $admin = auth_admin();
    return $admin && $admin['role'] === ROLE_ADMIN;
}

/**
 * Require any valid auth:
 * - group session OR
 * - reviewer/admin session
 *
 * If ?token= is present, capture it first.
 */
function require_auth(): void
{
    auth_capture_group_token_from_request();

    if (!is_authenticated()) {
        redirect(ROUTE_403);
    }
}

/**
 * Require reviewer/admin
 */
function require_reviewer_or_admin(): void
{
    auth_capture_group_token_from_request();

    if (!is_reviewer_or_admin()) {
        redirect(ROUTE_403);
    }
}

/**
 * Require admin only
 */
function require_admin(): void
{
    auth_capture_group_token_from_request();

    if (!is_admin()) {
        redirect(ROUTE_403);
    }
}

/**
 * Returns display label for top-right auth summary
 */
function auth_display_label(): string
{
    $admin = auth_admin();

    if ($admin) {
        return $admin['full_name'];
    }

    $group = auth_group();

    if ($group) {
        return $group['group_name'];
    }

    return 'Guest';
}

/**
 * Returns all active groups
 */
function get_all_active_groups(): array
{
    $pdo = db();

    $stmt = $pdo->query("
        SELECT id, group_name
        FROM groups
        WHERE is_active = 1
        ORDER BY group_name ASC
    ");

    return $stmt->fetchAll();
}

/**
 * Resolve group filters from GET.
 * If no filter passed:
 * - group-link user defaults to their own group
 * - admin/reviewer defaults to all groups
 */
function get_selected_group_ids(): array
{
    $selected = $_GET['groups'] ?? null;

    if (is_array($selected) && !empty($selected)) {
        $clean = array_values(array_unique(array_map('intval', $selected)));
        return array_filter($clean, fn($v) => $v > 0);
    }

    $group = auth_group();
    $admin = auth_admin();

    if ($admin) {
        $all = get_all_active_groups();
        return array_map(fn($g) => (int)$g['id'], $all);
    }

    if ($group) {
        return [(int)$group['group_id']];
    }

    return [];
}

/**
 * Basic page layout start
 */
function render_page_start(string $title = ''): void
{
    $fullTitle = $title !== '' ? $title . ' | ' . APP_NAME : APP_NAME;

    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($fullTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- AFH favicon/icon URL -->
    <link rel="icon" href="https://www.brscouts.org.uk/wp-content/uploads/2021/03/download.png" type="image/png">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css"
          
          crossorigin="anonymous">

    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,600&display=swap"
          rel="stylesheet">

    <style>
        body {
            font-family: 'Nunito Sans', sans-serif;
            background: #f8fafc;
        }

        .afh-navbar {
            min-height: 74px;
        }

        .afh-navbar-brand {
            font-weight: 900;
            letter-spacing: -0.02em;
        }

        .afh-brand-icon {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            background: #7413dc;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            margin-right: 0.55rem;
            font-size: 1rem;
        }

        .afh-current-group {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            text-align: center;
            max-width: 38%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .afh-current-group-title {
            font-weight: 900;
            line-height: 1.1;
        }

        .afh-current-group-subtitle {
            font-size: 0.78rem;
            color: #6c757d;
            line-height: 1.1;
        }

        .afh-user-icon {
            width: 36px;
            height: 36px;
            border-radius: 999px;
            background: #f1e8ff;
            color: #7413dc;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            margin-right: 0.65rem;
            flex-shrink: 0;
        }

        .afh-top-meta {
            font-size: 0.9rem;
            line-height: 1.15;
        }

        .afh-badge-role {
            font-size: 0.78rem;
        }

        .afh-sidebar {
            border-right: 1px solid #dee2e6;
            min-height: calc(100vh - 80px);
            padding-right: 1rem;
        }

        .afh-group-list {
            max-height: 70vh;
            overflow-y: auto;
        }

        .afh-calendar-day {
            min-height: 140px;
            vertical-align: top;
            background: #fff;
        }

        .afh-calendar-day-number {
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .afh-event-pill {
            display: block;
            font-size: 0.85rem;
            margin-bottom: 0.35rem;
            padding: 0.35rem 0.5rem;
            border-radius: 0.35rem;
            text-decoration: none;
            color: #fff;
            background: #006ddf;
        }

        .afh-muted-day {
            background: #f8f9fa;
            color: #6c757d;
        }

        @media (max-width: 991.98px) {
            .afh-current-group {
                position: static;
                transform: none;
                max-width: 100%;
                text-align: left;
                margin: 0.75rem 0;
                white-space: normal;
            }

            .afh-navbar-actions {
                align-items: flex-start !important;
                margin-top: 0.75rem;
            }

            .afh-top-meta {
                text-align: left !important;
            }
        }
        /* Global badge contrast fix */
.badge-success,
.badge-primary,
.badge-info,
.badge-danger,
.badge-dark,
.badge-secondary {
    color: #fff !important;
}

.badge-warning,
.badge-light {
    color: #212529 !important;
}

.badge-success {
    background-color: #28a745 !important;
}

.badge-primary {
    background-color: #006ddf !important;
}

.badge-info {
    background-color: #17a2b8 !important;
}

.badge-danger {
    background-color: #dc3545 !important;
}

.badge-dark {
    background-color: #343a40 !important;
}

.badge-secondary {
    background-color: #6c757d !important;
}

.badge-warning {
    background-color: #ffc107 !important;
}

.badge-light {
    background-color: #f8f9fa !important;
}
    </style>
</head>
<body>
    <?php
}

/**
 * Navbar / header
 */
function render_header(string $active = ''): void
{
    $context = auth_context();
    $isLoggedIn = $context !== null;
    $admin = auth_admin();
    $group = auth_group();

    $centralName = APP_NAME;
    $centralSubtitle = 'Away From Hut';

    if ($admin) {
        $centralName = 'District Lead Volunteer Portal';
        $centralSubtitle = ucfirst((string)$admin['role']);
    } elseif ($group) {
        $centralName = (string)$group['group_name'];
        $centralSubtitle = 'Group portal';
    }

    ?>
<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom mb-4 afh-navbar">
    <div class="container-fluid position-relative">

        <a class="navbar-brand afh-navbar-brand d-flex align-items-center" href="<?= e(ROUTE_CALENDAR) ?>">
<img
    src="https://www.brscouts.org.uk/wp-content/uploads/2021/03/download.png"
    alt="<?= e(APP_NAME) ?> icon"
    class="afh-brand-icon"
>            <span><?= e(APP_NAME) ?></span>
        </a>

        <?php if ($isLoggedIn): ?>
            <div class="afh-current-group">
                <div class="afh-current-group-title"><?= e($centralName) ?></div>
                <div class="afh-current-group-subtitle"><?= e($centralSubtitle) ?></div>
            </div>
        <?php endif; ?>

        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#mainNav"
                aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">

            <ul class="navbar-nav mr-auto">
                <?php if ($isLoggedIn): ?>

                    <li class="nav-item <?= $active === 'calendar' ? 'active' : '' ?>">
                        <a class="nav-link font-weight-bold" href="<?= e(ROUTE_CALENDAR) ?>">
                            Calendar
                        </a>
                    </li>

                    <li class="nav-item <?= $active === 'add-event' ? 'active' : '' ?>">
                        <a class="nav-link font-weight-bold" href="<?= e(ROUTE_ADD_EVENT) ?>">
                            Add event
                        </a>
                    </li>

                    <li class="nav-item <?= $active === 'glv' ? 'active' : '' ?>">
                        <a class="nav-link" href="<?= e(ROUTE_GLV) ?>">
                            GLV
                        </a>
                    </li>

                    <li class="nav-item <?= $active === 'risk-assessments' ? 'active' : '' ?>">
                        <a class="nav-link" href="<?= e(ROUTE_RISK_ASSESSMENTS) ?>">
                            Risk Assessments
                        </a>
                    </li>

                    <?php if (is_reviewer_or_admin()): ?>
                        <li class="nav-item <?= $active === 'reviewer' ? 'active' : '' ?>">
                            <a class="nav-link" href="<?= e(ROUTE_REVIEWER) ?>">
                                Reviewer
                            </a>
                        </li>
                    <?php endif; ?>

                <?php endif; ?>
            </ul>

            <div class="d-flex align-items-center ml-auto afh-navbar-actions">
                <?php if ($isLoggedIn): ?>

                    <?php if (is_admin()): ?>
                        <a href="<?= e(ROUTE_SETTINGS) ?>"
                           class="btn btn-outline-secondary btn-sm mr-3 <?= $active === 'settings' ? 'active' : '' ?>">
                            Settings
                        </a>
                    <?php endif; ?>

                    <div class="text-right mr-3 afh-top-meta">
                        <div><strong><?= e(auth_display_label()) ?></strong></div>

                        <?php if ($admin): ?>
                            <span class="badge badge-primary afh-badge-role">
                                <?= e(ucfirst((string)$admin['role'])) ?>
                            </span>
                        <?php else: ?>
                            <span class="badge badge-secondary afh-badge-role">
                                Group link
                            </span>
                        <?php endif; ?>
                    </div>

                    <a href="<?= e(ROUTE_LOGOUT) ?>" class="btn btn-outline-secondary btn-sm">
                        Logout
                    </a>

                <?php else: ?>

                    <a href="<?= e(ROUTE_LOGIN) ?>" class="btn btn-primary btn-sm">
                        DLV Login
                    </a>

                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
    <?php
}

/**
 * Basic page layout end
 */
function render_page_end(): void
{
    ?>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"
    
        crossorigin="anonymous"></script>

<script src="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/js/bootstrap.min.js"
     
        crossorigin="anonymous"></script>
</body>
</html>
    <?php
}