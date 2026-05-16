<?php

declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['portal_user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('/login.php');
    }
}

function login_user(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['portal_user'] = [
        'id' => (int) $user['id'],
        'full_name' => $user['full_name'] ?? '',
        'email' => $user['email'] ?? '',
        'role' => $user['role'] ?? 'user',
        'microsoft_oid' => $user['microsoft_oid'] ?? null,
        'auth_provider' => $user['auth_provider'] ?? 'microsoft',
    ];
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

function find_or_create_microsoft_user(array $claims): array
{
    $microsoftOid = $claims['oid'] ?? null;
    $email = $claims['preferred_username'] ?? $claims['email'] ?? null;
    $fullName = $claims['name'] ?? $email;

    if (!$microsoftOid || !$email) {
        throw new RuntimeException('Microsoft did not return a usable user identity.');
    }

    $pdo = db();

    /*
     * 1. Match by Microsoft Object ID.
     */
    $stmt = $pdo->prepare("
        SELECT *
        FROM admin_users
        WHERE microsoft_oid = :microsoft_oid
        LIMIT 1
    ");

    $stmt->execute([
        'microsoft_oid' => $microsoftOid,
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        touch_sso_login((int) $user['id']);
        return get_user_by_id((int) $user['id']);
    }

    /*
     * 2. Match by email.
     * This links existing admin_users records to Microsoft SSO.
     */
    $stmt = $pdo->prepare("
        SELECT *
        FROM admin_users
        WHERE LOWER(email) = LOWER(:email)
        LIMIT 1
    ");

    $stmt->execute([
        'email' => $email,
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $stmt = $pdo->prepare("
            UPDATE admin_users
            SET microsoft_oid = :microsoft_oid,
                auth_provider = 'microsoft',
                full_name = CASE
                    WHEN full_name IS NULL OR full_name = '' THEN :full_name
                    ELSE full_name
                END,
                last_sso_login_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'microsoft_oid' => $microsoftOid,
            'full_name' => $fullName,
            'id' => (int) $user['id'],
        ]);

        return get_user_by_id((int) $user['id']);
    }

    /*
     * 3. Create new user.
     * New Microsoft users start with role = user.
     */
    $stmt = $pdo->prepare("
        INSERT INTO admin_users (
            full_name,
            email,
            role,
            microsoft_oid,
            auth_provider,
            last_sso_login_at
        ) VALUES (
            :full_name,
            :email,
            'user',
            :microsoft_oid,
            'microsoft',
            NOW()
        )
    ");

    $stmt->execute([
        'full_name' => $fullName,
        'email' => $email,
        'microsoft_oid' => $microsoftOid,
    ]);

    return get_user_by_id((int) $pdo->lastInsertId());
}

function get_user_by_id(int $userId): array
{
    $stmt = db()->prepare("
        SELECT *
        FROM admin_users
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        'id' => $userId,
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException('User could not be found after sign-in.');
    }

    return $user;
}

function touch_sso_login(int $userId): void
{
    $stmt = db()->prepare("
        UPDATE admin_users
        SET last_sso_login_at = NOW()
        WHERE id = :id
    ");

    $stmt->execute([
        'id' => $userId,
    ]);
}

function user_has_group_mapping(int $userId): bool
{
    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM user_groups
        WHERE user_id = :user_id
    ");

    $stmt->execute([
        'user_id' => $userId,
    ]);

    return ((int) $stmt->fetchColumn()) > 0;
}

function user_needs_group_onboarding(): bool
{
    $user = current_user();

    if (!$user) {
        return false;
    }

    if (($user['role'] ?? '') === ROLE_ADMIN) {
        return false;
    }

    return !user_has_group_mapping((int) $user['id']);
}