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
    if (is_logged_in()) {
        return;
    }

    // Remember where the user was heading so we can return them there after
    // sign-in (e.g. a deep link from an email). Only forward safe same-site
    // GET destinations; microsoft-start.php validates this again before use.
    $target = login_return_target();

    if ($target !== null) {
        redirect('/login.php?return=' . rawurlencode($target));
    }

    redirect('/login.php');
}

/**
 * Build a safe, same-site relative URL (path + query) for the current request,
 * suitable for use as a post-login return destination. Returns null when the
 * request is not a plain GET or the URI cannot be safely forwarded.
 */
function login_return_target(): ?string
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return null;
    }

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

    if (
        $uri === ''
        || $uri[0] !== '/'
        || str_starts_with($uri, '//')
        || str_contains($uri, '://')
        || preg_match('/[\x00-\x1f\x7f]/', $uri)
        || str_starts_with($uri, '/login.php')
        || str_starts_with($uri, '/dc/login.php')
        || str_starts_with($uri, '/auth/')
    ) {
        return null;
    }

    return $uri;
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
    $microsoftOid = trim((string) ($claims['oid'] ?? ''));
    $tenantId = trim((string) ($claims['tid'] ?? ''));
    $email = strtolower(trim((string) (
        $claims['preferred_username']
        ?? $claims['email']
        ?? $claims['upn']
        ?? ''
    )));
    $fullName = trim((string) ($claims['name'] ?? $email));

    if ($microsoftOid === '' || $email === '') {
        throw new RuntimeException('Microsoft did not return a usable user identity.');
    }

    $subjectsToTry = array_values(array_unique(array_filter([
        $microsoftOid,
        $tenantId !== '' ? $tenantId . ':' . $microsoftOid : null,
    ])));

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $personId = null;

        /*
         * 1. Existing linked Microsoft account.
         */
        foreach ($subjectsToTry as $subject) {
            $stmt = $pdo->prepare("
                SELECT p.id
                FROM user_accounts ua
                JOIN people p ON p.id = ua.person_id
                WHERE ua.provider = 'microsoft'
                  AND ua.provider_subject = :provider_subject
                LIMIT 1
            ");
            $stmt->execute(['provider_subject' => $subject]);
            $personId = $stmt->fetchColumn();

            if ($personId) {
                break;
            }
        }

        /*
         * 2. If the cron stored the Microsoft Graph user ID on people, use that.
         */
        if (!$personId) {
            try {
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM people
                    WHERE microsoft_user_id = :oid
                       OR m365_user_id = :oid
                    LIMIT 1
                ");
                $stmt->execute(['oid' => $microsoftOid]);
                $personId = $stmt->fetchColumn();
            } catch (Throwable $e) {
                // Columns may not exist on older installs.
            }
        }

        /*
         * 3. If the cron stored the UPN on people, use that.
         */
        if (!$personId) {
            try {
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM people
                    WHERE LOWER(microsoft_user_principal_name) = LOWER(:email)
                       OR LOWER(m365_user_principal_name) = LOWER(:email)
                       OR LOWER(district_email) = LOWER(:email)
                    LIMIT 1
                ");
                $stmt->execute(['email' => $email]);
                $personId = $stmt->fetchColumn();
            } catch (Throwable $e) {
                // Columns may not exist on older installs.
            }
        }

        /*
         * 4. Match from m365_account_requests.
         * This is the important bit for accounts created by the cron.
         */
        if (!$personId) {
            try {
                $stmt = $pdo->prepare("
                    SELECT person_id
                    FROM m365_account_requests
                    WHERE graph_user_id = :oid
                       OR LOWER(graph_user_principal_name) = LOWER(:email)
                       OR LOWER(requested_upn) = LOWER(:email)
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $stmt->execute([
                    'oid' => $microsoftOid,
                    'email' => $email,
                ]);
                $personId = $stmt->fetchColumn();
            } catch (Throwable $e) {
                // Table may not exist on older installs.
            }
        }

        /*
         * 5. Fall back to people.primary_email.
         */
        if (!$personId) {
            $stmt = $pdo->prepare("
                SELECT id
                FROM people
                WHERE LOWER(TRIM(primary_email)) = LOWER(TRIM(:email))
                LIMIT 1
            ");
            $stmt->execute(['email' => $email]);
            $personId = $stmt->fetchColumn();
        }

        /*
         * 6. If still not found, create a people row.
         * The existing onboarding redirect will then send them to onboarding
         * because they will not have a Group membership yet.
         */
        if ($personId) {
            /*
             * Only move primary_email to the token's email when no OTHER person
             * already owns it. Microsoft UPN renames (and duplicate people rows)
             * mean the incoming email can collide with a different person's
             * primary_email, which would violate uq_people_primary_email and
             * abort sign-in. In that case keep this person's existing email and
             * log it for an admin to reconcile, rather than failing the login.
             */
            $stmt = $pdo->prepare("
                SELECT id
                FROM people
                WHERE LOWER(TRIM(primary_email)) = LOWER(TRIM(:email))
                  AND id <> :person_id
                LIMIT 1
            ");
            $stmt->execute([
                'email' => $email,
                'person_id' => (int) $personId,
            ]);
            $emailOwnedByOther = (bool) $stmt->fetchColumn();

            if ($emailOwnedByOther) {
                error_log(sprintf(
                    'Microsoft sign-in: email %s already belongs to another person; keeping person %d\'s existing email. Needs admin merge.',
                    $email,
                    (int) $personId
                ));

                $stmt = $pdo->prepare("
                    UPDATE people
                    SET full_name = CASE
                            WHEN full_name IS NULL OR full_name = '' THEN :full_name
                            ELSE full_name
                        END,
                        status = CASE WHEN status = 'inactive' THEN status ELSE 'active' END
                    WHERE id = :person_id
                ");
                $stmt->execute([
                    'full_name' => $fullName,
                    'person_id' => (int) $personId,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE people
                    SET full_name = CASE
                            WHEN full_name IS NULL OR full_name = '' THEN :full_name
                            ELSE full_name
                        END,
                        primary_email = :email,
                        status = CASE WHEN status = 'inactive' THEN status ELSE 'active' END
                    WHERE id = :person_id
                ");
                $stmt->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'person_id' => (int) $personId,
                ]);
            }
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO people (
                        full_name,
                        primary_email,
                        status
                    )
                    VALUES (
                        :full_name,
                        :email,
                        'active'
                    )
                ");
                $stmt->execute([
                    'full_name' => $fullName !== '' ? $fullName : $email,
                    'email' => $email,
                ]);

                $personId = (int) $pdo->lastInsertId();
            } catch (PDOException $e) {
                /*
                 * A person with this primary_email already exists but the
                 * lookups above did not find it. This happens with a
                 * concurrent sign-in (the other request committed between our
                 * SELECT and INSERT) or any subtle normalisation mismatch.
                 * Recover by re-selecting the existing row rather than failing
                 * the whole sign-in.
                 */
                if ($e->getCode() !== '23000') {
                    throw $e;
                }

                $stmt = $pdo->prepare("
                    SELECT id
                    FROM people
                    WHERE LOWER(TRIM(primary_email)) = LOWER(TRIM(:email))
                    LIMIT 1
                ");
                $stmt->execute(['email' => $email]);
                $personId = (int) $stmt->fetchColumn();

                if (!$personId) {
                    throw $e;
                }
            }
        }

        /*
         * 7. Link Microsoft sign-in to the person.
         * Store the normal oid subject and, if available, tid:oid as well.
         */
        foreach ($subjectsToTry as $subject) {
            $stmt = $pdo->prepare("
                INSERT INTO user_accounts (
                    person_id,
                    provider,
                    provider_subject,
                    email,
                    last_login_at
                )
                VALUES (
                    :person_id,
                    'microsoft',
                    :provider_subject,
                    :email,
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    person_id = VALUES(person_id),
                    email = VALUES(email),
                    last_login_at = NOW()
            ");
            $stmt->execute([
                'person_id' => (int) $personId,
                'provider_subject' => $subject,
                'email' => $email,
            ]);
        }

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
