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
        'person_id' => (int) $user['id'],
        'full_name' => $user['full_name'] ?? '',
        'preferred_name' => $user['preferred_name'] ?? null,
        'email' => $user['primary_email'] ?? $user['email'] ?? '',
        'role' => $user['highest_access_level'] ?? 'member',
        'highest_access_level' => $user['highest_access_level'] ?? 'member',
        'microsoft_oid' => $user['microsoft_oid'] ?? null,
        'auth_provider' => $user['auth_provider'] ?? 'microsoft',
        'status' => $user['status'] ?? 'pending',
    ];
}

function refresh_current_user_session(): void
{
    $user = current_user();

    if (!$user) {
        return;
    }

    login_user(get_user_by_id((int) $user['id']));
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
    $email = $claims['preferred_username'] ?? $claims['email'] ?? $claims['upn'] ?? null;
    $fullName = $claims['name'] ?? $email;

    if (!$microsoftOid || !$email) {
        throw new RuntimeException('Microsoft did not return a usable user identity.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("\n            SELECT p.id\n            FROM user_accounts ua\n            JOIN people p ON p.id = ua.person_id\n            WHERE ua.provider = 'microsoft'\n              AND ua.provider_subject = :provider_subject\n            LIMIT 1\n        ");

        $stmt->execute(['provider_subject' => $microsoftOid]);
        $personId = $stmt->fetchColumn();

        if (!$personId) {
            $stmt = $pdo->prepare("\n                SELECT id\n                FROM people\n                WHERE LOWER(primary_email) = LOWER(:email)\n                LIMIT 1\n            ");

            $stmt->execute(['email' => $email]);
            $personId = $stmt->fetchColumn();
        }

        if ($personId) {
            $stmt = $pdo->prepare("\n                UPDATE people\n                SET full_name = CASE\n                        WHEN full_name IS NULL OR full_name = '' THEN :full_name\n                        ELSE full_name\n                    END,\n                    primary_email = COALESCE(primary_email, :email),\n                    status = CASE WHEN status = 'inactive' THEN status ELSE 'active' END\n                WHERE id = :person_id\n            ");

            $stmt->execute([
                'full_name' => $fullName,
                'email' => $email,
                'person_id' => (int) $personId,
            ]);
        } else {
            $stmt = $pdo->prepare("\n                INSERT INTO people (full_name, primary_email, status)\n                VALUES (:full_name, :email, 'active')\n            ");

            $stmt->execute([
                'full_name' => $fullName,
                'email' => $email,
            ]);

            $personId = (int) $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("\n            INSERT INTO user_accounts (person_id, provider, provider_subject, email, last_login_at)\n            VALUES (:person_id, 'microsoft', :provider_subject, :email, NOW())\n            ON DUPLICATE KEY UPDATE\n                person_id = VALUES(person_id),\n                email = VALUES(email),\n                last_login_at = NOW()\n        ");

        $stmt->execute([
            'person_id' => (int) $personId,
            'provider_subject' => $microsoftOid,
            'email' => $email,
        ]);

        $pdo->commit();

        return get_user_by_id((int) $personId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function get_user_by_id(int $userId): array
{
    $stmt = db()->prepare("\n        SELECT\n            p.*,\n            ua.provider_subject AS microsoft_oid,\n            ua.provider AS auth_provider,\n            COALESCE((\n                SELECT gm.access_level\n                FROM group_memberships gm\n                WHERE gm.person_id = p.id\n                  AND gm.status = 'active'\n                ORDER BY FIELD(gm.access_level, 'system_admin', 'district_admin', 'district_reviewer', 'group_admin', 'member') ASC\n                LIMIT 1\n            ), 'member') AS highest_access_level\n        FROM people p\n        LEFT JOIN user_accounts ua\n            ON ua.person_id = p.id\n           AND ua.provider = 'microsoft'\n        WHERE p.id = :id\n        LIMIT 1\n    ");

    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException('User could not be found after sign-in.');
    }

    return $user;
}

function user_group_memberships(int $personId, bool $activeOnly = true): array
{
    $sql = "\n        SELECT gm.*, g.group_name, g.slug\n        FROM group_memberships gm\n        JOIN groups g ON g.id = gm.group_id\n        WHERE gm.person_id = :person_id\n    ";

    if ($activeOnly) {
        $sql .= " AND gm.status = 'active' AND g.is_active = 1";
    }

    $sql .= " ORDER BY gm.is_primary DESC, g.group_name ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute(['person_id' => $personId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function user_has_group_mapping(int $personId): bool
{
    $stmt = db()->prepare("\n        SELECT COUNT(*)\n        FROM group_memberships gm\n        JOIN groups g ON g.id = gm.group_id\n        WHERE gm.person_id = :person_id\n          AND gm.status = 'active'\n          AND g.is_active = 1\n    ");

    $stmt->execute(['person_id' => $personId]);

    return ((int) $stmt->fetchColumn()) > 0;
}

function user_needs_group_onboarding(): bool
{
    $user = current_user();

    if (!$user) {
        return false;
    }

    if (in_array(($user['highest_access_level'] ?? 'member'), ['district_admin', 'system_admin'], true)) {
        return false;
    }

    return !user_has_group_mapping((int) $user['id']);
}
