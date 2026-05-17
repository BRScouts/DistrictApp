<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Optional:
 * If someone visits login.php with a valid group token, capture it too.
 * That way they stay group-authenticated while also being able to log in as reviewer/admin.
 */
auth_capture_group_token_from_request();

$error = '';
$email = '';

/**
 * Determine where to send the user after successful login.
 * Prefer explicit ?redirect=..., otherwise fall back to calendar.
 */
$redirectTarget = $_GET['redirect'] ?? $_POST['redirect'] ?? ROUTE_CALENDAR;

/**
 * Prevent open redirects by only allowing local paths.
 */
function safe_redirect_target(string $target): string
{
    $target = trim($target);

    if ($target === '') {
        return ROUTE_CALENDAR;
    }

    if (preg_match('#^https?://#i', $target)) {
        return ROUTE_CALENDAR;
    }

    if (!str_starts_with($target, '/')) {
        $target = '/' . ltrim($target, '/');
    }

    return $target;
}

$redirectTarget = safe_redirect_target($redirectTarget);

/**
 * Handle login submit
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Please enter your email address and password.';
    } else {
        $stmt = db()->prepare("
            SELECT id, full_name, email, password_hash, role, is_active
            FROM admin_users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || (int)$user['is_active'] !== 1) {
            $error = 'Invalid login details.';
        } elseif (!password_verify($password, $user['password_hash'])) {
            $error = 'Invalid login details.';
        } else {
            /**
             * Regenerate session ID on privilege change
             */
            session_regenerate_id(true);

            $_SESSION['admin_auth'] = [
                'admin_user_id' => (int)$user['id'],
                'full_name'     => $user['full_name'],
                'email'         => $user['email'],
                'role'          => $user['role'],
            ];

            /**
             * Update last login timestamp
             */
            $update = db()->prepare("
                UPDATE admin_users
                SET last_login_at = NOW()
                WHERE id = :id
            ");
            $update->execute(['id' => (int)$user['id']]);

            redirect($redirectTarget);
        }
    }
}

render_page_start('Login');
render_header('');
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-6 col-xl-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h3 mb-3">Reviewer / Admin Login</h1>
                    <p class="text-muted mb-4">
                        Sign in with your reviewer or admin account.
                    </p>

                    <?php if (auth_group()): ?>
                        <div class="alert alert-info">
                            You are currently browsing as
                            <strong><?= e(auth_group()['group_name']) ?></strong>
                            via a group access link.
                            Signing in here will switch you to your reviewer/admin account for this session.
                        </div>
                    <?php endif; ?>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger">
                            <?= e($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(ROUTE_LOGIN) ?>" novalidate>
                        <input type="hidden" name="redirect" value="<?= e($redirectTarget) ?>">

                        <div class="form-group">
                            <label for="email">Email address</label>
                            <input
                                type="email"
                                class="form-control"
                                id="email"
                                name="email"
                                value="<?= e($email) ?>"
                                autocomplete="username"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                autocomplete="current-password"
                                required
                            >
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">
                            Log in
                        </button>
                    </form>

                    <hr class="my-4">

                    <div class="mb-2">
                        <h2 class="h5 mb-2">Single Sign-On</h2>
                        <p class="text-muted mb-3">
                            SSO is not connected yet. Add your Microsoft or Google SSO flow here later.
                        </p>
                    </div>

                    <button type="button" class="btn btn-outline-secondary btn-block" disabled>
                        Continue with SSO (coming soon)
                    </button>

                    <hr class="my-4">

                    <div class="small text-muted">
                        Group users should use their unique group link rather than this login form.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php render_page_end(); ?>