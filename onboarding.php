<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
$pdo = db();
$error = null;

$personId = (int) $user['id'];
$email = strtolower(trim((string) ($user['email'] ?? '')));
$displayName = trim((string) ($user['full_name'] ?? $email));

$roleOptions = array_values(array_filter(
    portal_role_options(),
    static function (string $role): bool {
        $normalised = strtolower(trim($role));

        if ($normalised === '') {
            return false;
        }

        if ($normalised === 'other') {
            return false;
        }

        if (str_contains($normalised, 'permit holder')) {
            return false;
        }

        if (str_contains($normalised, 'nights away')) {
            return false;
        }

        if (str_contains($normalised, 'skill instructor') || str_contains($normalised, 'skills instructor')) {
            return false;
        }

        return true;
    }
));

$accreditationOptions = portal_accreditation_options();
$allowedAccreditations = portal_flatten_options($accreditationOptions);

function onboarding_table_exists(string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute(['table_name' => $table]);

        return $cache[$table] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function onboarding_column_exists(string $table, string $column): bool
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

        return $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function onboarding_table_columns(string $table): array
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute(['table_name' => $table]);

        return $cache[$table] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

function onboarding_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function onboarding_insert_flexible(string $table, array $values): bool
{
    if (!onboarding_table_exists($table)) {
        return false;
    }

    $columns = onboarding_table_columns($table);
    $insert = [];

    foreach ($values as $column => $value) {
        if (in_array((string) $column, $columns, true)) {
            $insert[(string) $column] = $value;
        }
    }

    if (!$insert) {
        return false;
    }

    $quotedColumns = array_map('onboarding_quote_identifier', array_keys($insert));
    $placeholders = array_map(static fn(string $column): string => ':' . $column, array_keys($insert));

    $stmt = db()->prepare("
        INSERT INTO " . onboarding_quote_identifier($table) . "
        (" . implode(', ', $quotedColumns) . ")
        VALUES
        (" . implode(', ', $placeholders) . ")
    ");

    return $stmt->execute($insert);
}

function onboarding_profile_photo_url(array $user, array $profile): string
{
    foreach ([
        'profile_photo_url',
        'photo_url',
        'avatar_url',
        'picture_url',
        'microsoft_photo_url',
        'ms_photo_url',
    ] as $field) {
        if (!empty($user[$field])) {
            return (string) $user[$field];
        }

        if (!empty($profile[$field])) {
            return (string) $profile[$field];
        }
    }

    if (!empty($user['id'])) {
        $localPath = '/uploads/profile-photos/' . (int) $user['id'] . '.jpg';
        $localFile = __DIR__ . $localPath;

        if (is_file($localFile)) {
            return $localPath;
        }
    }

    return '';
}

function onboarding_audit_action(int $actorPersonId, string $action, string $entityType, int $entityId, array $details = []): void
{
    if (!onboarding_table_exists('audit_log')) {
        return;
    }

    try {
        onboarding_insert_flexible('audit_log', [
            'actor_type' => 'person',
            'actor_person_id' => $actorPersonId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // Do not fail onboarding because audit logging failed.
    }
}

function onboarding_audit(int $personId, array $details): void
{
    onboarding_audit_action(
        $personId,
        'self_onboarding_completed',
        'person',
        $personId,
        $details
    );
}

function onboarding_fetch_claim_candidates(int $currentPersonId): array
{
    $sectionSelect = "'' AS section_names";
    $sectionJoin = '';

    if (
        onboarding_table_exists('group_membership_sections')
        && onboarding_table_exists('group_sections')
    ) {
        $sectionSelect = "GROUP_CONCAT(DISTINCT gs.section_name ORDER BY gs.section_name SEPARATOR ', ') AS section_names";
        $sectionJoin = "
            LEFT JOIN group_membership_sections gms
              ON gms.group_membership_id = gm.id
            LEFT JOIN group_sections gs
              ON gs.id = gms.group_section_id
             AND gs.is_active = 1
        ";
    }

    $microsoftUnclaimedCondition = onboarding_table_exists('user_accounts')
        ? "AND NOT EXISTS (
                SELECT 1
                FROM user_accounts ua_claimed
                WHERE ua_claimed.person_id = p.id
                  AND ua_claimed.provider = 'microsoft'
            )"
        : '';

    $stmt = db()->prepare("
        SELECT
            p.id AS person_id,
            p.full_name,
            p.primary_email,
            p.phone,
            gm.group_id,
            g.group_name,
            gm.membership_role,
            gm.access_level,
            dp.role_title,
            {$sectionSelect},
            0 AS has_microsoft_account
        FROM group_memberships gm
        JOIN people p
          ON p.id = gm.person_id
        JOIN groups g
          ON g.id = gm.group_id
         AND g.is_active = 1
        LEFT JOIN directory_profiles dp
          ON dp.person_id = p.id
        {$sectionJoin}
        WHERE p.id <> :current_person_id
          AND p.status = 'active'
          AND gm.status = 'active'
          {$microsoftUnclaimedCondition}
        GROUP BY
            p.id,
            p.full_name,
            p.primary_email,
            p.phone,
            gm.group_id,
            g.group_name,
            gm.membership_role,
            gm.access_level,
            dp.role_title
        ORDER BY g.group_name ASC, p.full_name ASC
    ");
    $stmt->execute(['current_person_id' => $currentPersonId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function onboarding_fetch_person(int $personId): ?array
{
    $stmt = db()->prepare("
        SELECT *
        FROM people
        WHERE id = :person_id
        LIMIT 1
    ");
    $stmt->execute(['person_id' => $personId]);

    $person = $stmt->fetch(PDO::FETCH_ASSOC);

    return $person ?: null;
}

function onboarding_candidate_is_in_selected_group(int $candidatePersonId, array $groupIds): bool
{
    $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));

    if (!$groupIds) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));

    $params = [$candidatePersonId];
    foreach ($groupIds as $groupId) {
        $params[] = $groupId;
    }

    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM group_memberships
        WHERE person_id = ?
          AND status = 'active'
          AND group_id IN ({$placeholders})
    ");
    $stmt->execute($params);

    return ((int) $stmt->fetchColumn()) > 0;
}

function onboarding_person_has_microsoft_login(int $personId): bool
{
    if (!onboarding_table_exists('user_accounts')) {
        return false;
    }

    try {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM user_accounts
            WHERE person_id = :person_id
              AND provider = 'microsoft'
        ");
        $stmt->execute(['person_id' => $personId]);

        return ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function onboarding_preserve_old_email_on_target(int $targetPersonId, string $oldEmail): void
{
    $oldEmail = strtolower(trim($oldEmail));

    if ($oldEmail === '' || !filter_var($oldEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $candidateColumns = [
        'personal_email',
        'scouting_email',
        'alternate_email',
        'secondary_email',
        'previous_email',
    ];

    foreach ($candidateColumns as $column) {
        if (!onboarding_column_exists('people', $column)) {
            continue;
        }

        try {
            $stmt = db()->prepare("
                UPDATE people
                SET " . onboarding_quote_identifier($column) . " = COALESCE(NULLIF(" . onboarding_quote_identifier($column) . ", ''), :old_email)
                WHERE id = :target_person_id
                LIMIT 1
            ");
            $stmt->execute([
                'old_email' => $oldEmail,
                'target_person_id' => $targetPersonId,
            ]);

            return;
        } catch (Throwable $e) {
            // Try the next compatible column.
        }
    }
}

function onboarding_update_current_session_person(int $targetPersonId): void
{
    foreach (['person_id', 'user_id', 'current_person_id'] as $key) {
        if (array_key_exists($key, $_SESSION)) {
            $_SESSION[$key] = $targetPersonId;
        }
    }

    foreach (['user', 'current_user'] as $key) {
        if (isset($_SESSION[$key]) && is_array($_SESSION[$key])) {
            $_SESSION[$key]['id'] = $targetPersonId;
        }
    }

    if (function_exists('refresh_current_user_session')) {
        refresh_current_user_session();
    }
}

function onboarding_claim_existing_person(
    int $currentPersonId,
    int $targetPersonId,
    string $ssoEmail,
    array $selectedGroupIds
): void {
    if ($currentPersonId < 1 || $targetPersonId < 1 || $currentPersonId === $targetPersonId) {
        throw new RuntimeException('Choose a valid existing record.');
    }

    if ($ssoEmail === '' || !filter_var($ssoEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Your Microsoft sign-in email could not be verified.');
    }

    if (!onboarding_candidate_is_in_selected_group($targetPersonId, $selectedGroupIds)) {
        throw new RuntimeException('The selected existing record is not active in the Group you selected.');
    }

    if (onboarding_person_has_microsoft_login($targetPersonId)) {
        throw new RuntimeException('That existing record is already linked to a Microsoft sign-in. Please contact your Group Lead Volunteer.');
    }

    $currentPerson = onboarding_fetch_person($currentPersonId);
    $targetPerson = onboarding_fetch_person($targetPersonId);

    if (!$currentPerson || !$targetPerson) {
        throw new RuntimeException('One of the person records could not be found.');
    }

    $targetOldEmail = strtolower(trim((string) ($targetPerson['primary_email'] ?? '')));
    $currentOldEmail = strtolower(trim((string) ($currentPerson['primary_email'] ?? '')));

    $placeholderEmail = 'merged-person-' . $currentPersonId . '-' . time() . '@merged.invalid';

    $stmt = db()->prepare("
        UPDATE people
        SET primary_email = :placeholder_email,
            status = 'inactive'
        WHERE id = :current_person_id
        LIMIT 1
    ");
    $stmt->execute([
        'placeholder_email' => $placeholderEmail,
        'current_person_id' => $currentPersonId,
    ]);

    onboarding_preserve_old_email_on_target($targetPersonId, $targetOldEmail);

    $stmt = db()->prepare("
        UPDATE people
        SET primary_email = :sso_email,
            status = 'active'
        WHERE id = :target_person_id
        LIMIT 1
    ");
    $stmt->execute([
        'sso_email' => $ssoEmail,
        'target_person_id' => $targetPersonId,
    ]);

    if (onboarding_table_exists('user_accounts')) {
        $stmt = db()->prepare("
            UPDATE user_accounts
            SET person_id = :target_person_id
            WHERE person_id = :current_person_id
        ");
        $stmt->execute([
            'target_person_id' => $targetPersonId,
            'current_person_id' => $currentPersonId,
        ]);
    }

    if (onboarding_table_exists('group_memberships')) {
        $stmt = db()->prepare("
            SELECT group_id, membership_role, access_level, status, is_primary, approved_at
            FROM group_memberships
            WHERE person_id = :current_person_id
        ");
        $stmt->execute(['current_person_id' => $currentPersonId]);
        $temporaryMemberships = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($temporaryMemberships as $membership) {
            try {
                $insert = db()->prepare("
                    INSERT INTO group_memberships (
                        person_id,
                        group_id,
                        membership_role,
                        access_level,
                        status,
                        is_primary,
                        approved_at
                    )
                    VALUES (
                        :target_person_id,
                        :group_id,
                        :membership_role,
                        :access_level,
                        :status,
                        :is_primary,
                        :approved_at
                    )
                    ON DUPLICATE KEY UPDATE
                        status = CASE
                            WHEN status = 'active' OR VALUES(status) = 'active' THEN 'active'
                            ELSE status
                        END,
                        approved_at = COALESCE(approved_at, VALUES(approved_at))
                ");
                $insert->execute([
                    'target_person_id' => $targetPersonId,
                    'group_id' => (int) $membership['group_id'],
                    'membership_role' => (string) ($membership['membership_role'] ?? 'section_leader'),
                    'access_level' => (string) ($membership['access_level'] ?? 'member'),
                    'status' => (string) ($membership['status'] ?? 'active'),
                    'is_primary' => (int) ($membership['is_primary'] ?? 0),
                    'approved_at' => $membership['approved_at'] ?? null,
                ]);
            } catch (Throwable $e) {
                // Ignore duplicate/incompatible membership transfer issues.
            }
        }

        $stmt = db()->prepare("
            UPDATE group_memberships
            SET status = 'inactive',
                is_primary = 0
            WHERE person_id = :current_person_id
        ");
        $stmt->execute(['current_person_id' => $currentPersonId]);
    }

    if (onboarding_table_exists('directory_profiles')) {
        $stmt = db()->prepare("
            UPDATE directory_profiles
            SET person_id = :target_person_id
            WHERE person_id = :current_person_id
              AND NOT EXISTS (
                    SELECT 1
                    FROM (
                        SELECT person_id
                        FROM directory_profiles
                        WHERE person_id = :target_person_id_check
                    ) existing_profile
              )
        ");

        try {
            $stmt->execute([
                'target_person_id' => $targetPersonId,
                'current_person_id' => $currentPersonId,
                'target_person_id_check' => $targetPersonId,
            ]);
        } catch (Throwable $e) {
            // Target already has a profile or DB does not support this pattern.
        }
    }

    if (onboarding_table_exists('calendar_events')) {
        if (onboarding_column_exists('calendar_events', 'submitted_by_person_id')) {
            $stmt = db()->prepare("
                UPDATE calendar_events
                SET submitted_by_person_id = :target_person_id
                WHERE submitted_by_person_id = :current_person_id
            ");
            $stmt->execute([
                'target_person_id' => $targetPersonId,
                'current_person_id' => $currentPersonId,
            ]);
        }

        if (onboarding_column_exists('calendar_events', 'leader_email')) {
            $stmt = db()->prepare("
                UPDATE calendar_events
                SET leader_email = :sso_email
                WHERE LOWER(leader_email) = LOWER(:current_old_email)
            ");
            $stmt->execute([
                'sso_email' => $ssoEmail,
                'current_old_email' => $currentOldEmail,
            ]);
        }
    }

    if (onboarding_table_exists('risk_assessments') && onboarding_column_exists('risk_assessments', 'uploaded_by_person_id')) {
        $stmt = db()->prepare("
            UPDATE risk_assessments
            SET uploaded_by_person_id = :target_person_id
            WHERE uploaded_by_person_id = :current_person_id
        ");
        $stmt->execute([
            'target_person_id' => $targetPersonId,
            'current_person_id' => $currentPersonId,
        ]);
    }

    onboarding_audit_action($targetPersonId, 'self_claimed_existing_person', 'person', $targetPersonId, [
        'temporary_person_id' => $currentPersonId,
        'claimed_person_id' => $targetPersonId,
        'sso_email' => $ssoEmail,
        'previous_imported_email' => $targetOldEmail,
        'selected_group_ids' => array_values(array_map('intval', $selectedGroupIds)),
    ]);

    onboarding_update_current_session_person($targetPersonId);
}

$stmt = $pdo->query("
    SELECT id, group_name
    FROM groups
    WHERE is_active = 1
    ORDER BY group_name ASC
");
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("
    SELECT id, group_id, section_type, section_name
    FROM group_sections
    WHERE is_active = 1
    ORDER BY group_id ASC, sort_order ASC, section_name ASC
");

$sectionsByGroup = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $section) {
    $sectionsByGroup[(int) $section['group_id']][] = $section;
}

$stmt = $pdo->prepare("
    SELECT
        p.*,
        dp.role_title,
        dp.about_me,
        dp.accreditations_json,
        dp.share_phone,
        dp.visible_in_directory
    FROM people p
    LEFT JOIN directory_profiles dp
      ON dp.person_id = p.id
    WHERE p.id = :person_id
    LIMIT 1
");
$stmt->execute(['person_id' => $personId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$existingMemberships = user_group_memberships($personId, false);

$activeExistingMemberships = array_values(array_filter(
    $existingMemberships,
    static fn(array $membership): bool => ($membership['status'] ?? 'active') === 'active'
));

$existingGroupIds = array_map(static fn(array $m): int => (int) $m['group_id'], $activeExistingMemberships);
$claimCandidates = onboarding_fetch_claim_candidates($personId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'complete_onboarding');

    $postedGroupId = (int) ($_POST['group_id'] ?? 0);
    $groupIds = $postedGroupId > 0 ? [$postedGroupId] : [];

    if ($action === 'claim_existing_person') {
        $targetPersonId = (int) ($_POST['existing_person_id'] ?? 0);
        $confirmedClaim = isset($_POST['confirm_existing_person_claim']);

        if (!$groupIds) {
            $error = 'Choose your Group before selecting an existing record.';
        } elseif ($targetPersonId < 1) {
            $error = 'Choose the existing leader record that belongs to you, or choose “None of these are me”.';
        } elseif (!$confirmedClaim) {
            $error = 'Confirm that the existing record is yours before continuing.';
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM groups WHERE is_active = 1 AND id = ?");
            $stmt->execute([$postedGroupId]);

            if ((int) $stmt->fetchColumn() !== 1) {
                $error = 'Choose a valid active Group.';
            }
        }

        if (!$error) {
            $pdo->beginTransaction();

            try {
                onboarding_claim_existing_person($personId, $targetPersonId, $email, $groupIds);
                $pdo->commit();

                redirect('/index.php');
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = $e->getMessage() ?: 'The existing record could not be linked. Please contact your Group Lead Volunteer.';
            }
        }
    } else {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $roleTitle = trim((string) ($_POST['role_title'] ?? ''));
        $aboutMe = trim((string) ($_POST['about_me'] ?? ''));
        $sharePhone = isset($_POST['share_phone']) ? 1 : 0;
        $visibleInDirectory = isset($_POST['visible_in_directory']) ? 1 : 0;

        $postedSectionIds = $_POST['section_ids'] ?? [];

        if (!is_array($postedSectionIds)) {
            $postedSectionIds = [];
        }

        $sectionIds = array_values(array_unique(array_filter(
            array_map('intval', $postedSectionIds),
            static fn(int $id): bool => $id > 0
        )));

        $postedAccreditations = $_POST['accreditations'] ?? [];

        if (!is_array($postedAccreditations)) {
            $postedAccreditations = [];
        }

        $cleanAccreditations = array_values(array_intersect(
            array_map('strval', $postedAccreditations),
            $allowedAccreditations
        ));

        sort($cleanAccreditations);

        $accreditationsJson = json_encode($cleanAccreditations, JSON_UNESCAPED_UNICODE) ?: '[]';

        if ($fullName === '') {
            $error = 'Enter your name.';
        } elseif ($roleTitle === '' || !in_array($roleTitle, $roleOptions, true)) {
            $error = 'Choose your main role.';
        } elseif (!$groupIds) {
            $error = 'Choose your Group.';
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM groups WHERE is_active = 1 AND id = ?");
            $stmt->execute([$postedGroupId]);

            if ((int) $stmt->fetchColumn() !== 1) {
                $error = 'Choose a valid active Group.';
            }
        }

        if (!$error) {
            $membershipRole = portal_membership_role_from_title($roleTitle);
            $accessLevel = portal_access_level_from_membership_role($membershipRole);

            $pdo->beginTransaction();

            try {
                $stmt = $pdo->prepare("
                    UPDATE people
                    SET full_name = :full_name,
                        phone = :phone,
                        status = 'active'
                    WHERE id = :person_id
                ");
                $stmt->execute([
                    'full_name' => $fullName,
                    'phone' => $phone !== '' ? $phone : null,
                    'person_id' => $personId,
                ]);

                $stmt = $pdo->prepare("
                    INSERT INTO directory_profiles (
                        person_id,
                        role_title,
                        about_me,
                        accreditations_json,
                        visible_in_directory,
                        share_phone,
                        profile_updated_at
                    )
                    VALUES (
                        :person_id,
                        :role_title,
                        :about_me,
                        :accreditations_json,
                        :visible_in_directory,
                        :share_phone,
                        NOW()
                    )
                    ON DUPLICATE KEY UPDATE
                        role_title = VALUES(role_title),
                        about_me = VALUES(about_me),
                        accreditations_json = VALUES(accreditations_json),
                        visible_in_directory = VALUES(visible_in_directory),
                        share_phone = VALUES(share_phone),
                        profile_updated_at = NOW()
                ");
                $stmt->execute([
                    'person_id' => $personId,
                    'role_title' => $roleTitle,
                    'about_me' => $aboutMe !== '' ? $aboutMe : null,
                    'accreditations_json' => $accreditationsJson,
                    'visible_in_directory' => $visibleInDirectory,
                    'share_phone' => $sharePhone,
                ]);

                $membershipIdsByGroup = [];

                foreach ($groupIds as $index => $groupId) {
                    $stmt = $pdo->prepare("
                        INSERT INTO group_memberships (
                            person_id,
                            group_id,
                            membership_role,
                            access_level,
                            status,
                            is_primary,
                            approved_at
                        )
                        VALUES (
                            :person_id,
                            :group_id,
                            :membership_role,
                            :access_level,
                            'active',
                            :is_primary,
                            NOW()
                        )
                        ON DUPLICATE KEY UPDATE
                            membership_role = VALUES(membership_role),
                            access_level = VALUES(access_level),
                            status = 'active',
                            is_primary = VALUES(is_primary),
                            approved_at = COALESCE(approved_at, NOW())
                    ");
                    $stmt->execute([
                        'person_id' => $personId,
                        'group_id' => $groupId,
                        'membership_role' => $membershipRole,
                        'access_level' => $accessLevel,
                        'is_primary' => $index === 0 ? 1 : 0,
                    ]);

                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM group_memberships
                        WHERE person_id = :person_id
                          AND group_id = :group_id
                        LIMIT 1
                    ");
                    $stmt->execute([
                        'person_id' => $personId,
                        'group_id' => $groupId,
                    ]);

                    $membershipId = (int) $stmt->fetchColumn();

                    if ($membershipId > 0) {
                        $membershipIdsByGroup[$groupId] = $membershipId;
                    }
                }

                foreach ($membershipIdsByGroup as $membershipId) {
                    $stmt = $pdo->prepare("
                        DELETE FROM group_membership_sections
                        WHERE group_membership_id = :membership_id
                    ");
                    $stmt->execute(['membership_id' => $membershipId]);
                }

                if ($sectionIds) {
                    $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
                    $stmt = $pdo->prepare("
                        SELECT id, group_id
                        FROM group_sections
                        WHERE is_active = 1
                          AND id IN ({$placeholders})
                    ");
                    $stmt->execute($sectionIds);
                    $validSections = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($validSections as $section) {
                        $groupId = (int) $section['group_id'];

                        if (!isset($membershipIdsByGroup[$groupId])) {
                            continue;
                        }

                        $stmt = $pdo->prepare("
                            INSERT IGNORE INTO group_membership_sections (
                                group_membership_id,
                                group_section_id
                            )
                            VALUES (
                                :membership_id,
                                :section_id
                            )
                        ");
                        $stmt->execute([
                            'membership_id' => $membershipIdsByGroup[$groupId],
                            'section_id' => (int) $section['id'],
                        ]);
                    }
                }

                onboarding_audit($personId, [
                    'group_ids' => $groupIds,
                    'section_ids' => $sectionIds,
                    'role_title' => $roleTitle,
                    'accreditation_count' => count($cleanAccreditations),
                ]);

                $pdo->commit();

                refresh_current_user_session();
                redirect('/index.php');
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Your details could not be saved. Please try again.';
            }
        }
    }
}

$formFullName = trim((string) ($_POST['full_name'] ?? ($profile['full_name'] ?? $displayName)));
$formPhone = trim((string) ($_POST['phone'] ?? ($profile['phone'] ?? '')));
$formRoleTitle = trim((string) ($_POST['role_title'] ?? ($profile['role_title'] ?? '')));
$formAboutMe = trim((string) ($_POST['about_me'] ?? ($profile['about_me'] ?? '')));
$formGroupId = (int) ($_POST['group_id'] ?? ($existingGroupIds[0] ?? 0));
$formGroupIds = $formGroupId > 0 ? [$formGroupId] : [];
$formSectionIds = $_POST['section_ids'] ?? [];
$formAccreditations = $_POST['accreditations'] ?? portal_decode_json_list($profile['accreditations_json'] ?? null);
$formSharePhone = isset($_POST['share_phone']) ? 1 : (int) ($profile['share_phone'] ?? 0);
$formVisible = isset($_POST['visible_in_directory']) ? 1 : (int) ($profile['visible_in_directory'] ?? 1);

if (!is_array($formSectionIds)) {
    $formSectionIds = [];
}

if (!is_array($formAccreditations)) {
    $formAccreditations = [];
}

$formSectionIds = array_map('intval', $formSectionIds);

$formAccreditations = array_values(array_intersect(
    array_map('strval', $formAccreditations),
    $allowedAccreditations
));

sort($formAccreditations);

$initials = strtoupper(substr($displayName !== '' ? $displayName : 'U', 0, 1));
$photoUrl = onboarding_profile_photo_url($user, $profile);
$microsoftProfileUrl = 'https://myaccount.microsoft.com/';

$pageTitle = 'Complete your profile | ' . $appName;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css" integrity="sha384-5Kguc7IDQdynmm22yUyn9psYyP8LQhAWCCKJT/RrZJAWqdUAw5eADwc25JoYsXH6" crossorigin="anonymous">
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/leader-tool.css">

    <style>
        .onboarding-header {
            background: #ffffff;
            border-bottom: 2px solid #e6e6e6;
        }

        .onboarding-header-inner {
            width: min(1180px, calc(100% - 1rem));
            margin: 0 auto;
            min-height: 68px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: .5rem 0;
        }

        .onboarding-brand {
            display: inline-flex;
            align-items: center;
            gap: .75rem;
            text-decoration: none;
            color: #1d1d1b;
        }

        .onboarding-brand:hover,
        .onboarding-brand:focus {
            color: #1d1d1b;
            text-decoration: none;
        }

        .onboarding-brand img {
            display: block;
            height: 52px;
            width: auto;
            max-width: 210px;
            object-fit: contain;
        }

        .onboarding-brand span {
            display: none;
            font-weight: 900;
        }

        .onboarding-signout {
            font-weight: 900;
            color: #4d0b93;
        }

        .onboarding-hero {
            background: #f7f5fb;
            border-bottom: 2px solid #e6e6e6;
        }

        .onboarding-hero-inner {
            width: min(1180px, calc(100% - 1rem));
            margin: 0 auto;
            padding: 1.05rem 0;
        }

        .onboarding-hero h1 {
            margin: 0;
            color: #4d0b93;
            font-size: clamp(1.85rem, 5vw, 2.75rem);
            line-height: 1.05;
            font-weight: 900;
        }

        .onboarding-hero p {
            margin: .45rem 0 0;
            max-width: 780px;
            color: #333;
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.4;
        }

        .onboarding-layout {
            display: grid;
            gap: 1rem;
        }

        @media (min-width: 992px) {
            .onboarding-layout {
                grid-template-columns: minmax(260px, 330px) minmax(0, 1fr);
                align-items: start;
            }
        }

        .onboarding-side-card,
        .onboarding-panel,
        .onboarding-candidate,
        .onboarding-no-candidates,
        .onboarding-claim-confirm,
        .onboarding-selected-summary,
        .onboarding-accreditation-category,
        .onboarding-selected-tag {
            border-radius: 0;
        }

        .onboarding-side-card {
            background: #ffffff;
            border: 2px solid #e6e6e6;
            padding: 1.25rem;
            box-shadow: none;
        }

        @media (min-width: 992px) {
            .onboarding-side-card {
                position: sticky;
                top: 1rem;
            }
        }

        .onboarding-photo-link {
            position: relative;
            display: block;
            width: 124px;
            height: 124px;
            margin: 0 auto 1rem;
            overflow: hidden;
            background: #7413dc;
            color: #ffffff;
            text-decoration: none;
            box-shadow: none;
        }

        .onboarding-photo-link:hover,
        .onboarding-photo-link:focus {
            color: #ffffff;
            text-decoration: none;
            outline: 4px solid #ffdd00;
            outline-offset: 3px;
        }

        .onboarding-photo {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 900;
        }

        .onboarding-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .onboarding-photo-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: .75rem;
            text-align: center;
            background: rgba(0, 0, 0, .68);
            color: #ffffff;
            font-weight: 900;
            opacity: 0;
            transition: opacity .15s ease-in-out;
        }

        .onboarding-photo-link:hover .onboarding-photo-overlay,
        .onboarding-photo-link:focus .onboarding-photo-overlay {
            opacity: 1;
        }

        .onboarding-side-card h2 {
            margin: 0;
            color: #4d0b93;
            font-size: 1.35rem;
            font-weight: 900;
            text-align: center;
            line-height: 1.15;
        }

        .onboarding-email {
            margin: .35rem 0 1rem;
            color: #555;
            font-weight: 700;
            text-align: center;
            overflow-wrap: anywhere;
        }

        .onboarding-steps {
            margin: 1rem 0 0;
            padding-left: 1.25rem;
            font-weight: 800;
        }

        .onboarding-steps li + li {
            margin-top: .35rem;
        }

        .onboarding-photo-note {
            background: #f7f5fb;
            border-left: 6px solid #7413dc;
            padding: .9rem;
            margin-top: 1rem;
            font-weight: 700;
        }

        .onboarding-main,
        .onboarding-normal-flow {
            display: grid;
            gap: 1rem;
        }

        .onboarding-normal-flow.is-hidden {
            display: none;
        }

        .onboarding-panel {
            background: #ffffff;
            border: 2px solid #e6e6e6;
            padding: 1.25rem;
            box-shadow: none;
        }

        .onboarding-panel h2 {
            margin: 0 0 .85rem;
            color: #4d0b93;
            font-size: 1.35rem;
            font-weight: 900;
        }

        .onboarding-panel-intro {
            margin-top: -.35rem;
            color: #555;
            font-weight: 700;
        }

        .onboarding-check-grid {
            display: grid;
            gap: .55rem;
        }

        @media (min-width: 700px) {
            .onboarding-check-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        .onboarding-claim-panel {
            display: none;
            background: #fff8d6;
            border-left: 8px solid #ffdd00;
        }

        .onboarding-claim-panel.is-visible {
            display: block;
        }

        .onboarding-warning {
            background: #ffffff;
            border: 2px solid #b1b4b6;
            border-left: 8px solid #d4351c;
            padding: .85rem;
            margin: 1rem 0;
            font-weight: 800;
        }

        .onboarding-candidate-list {
            display: grid;
            gap: .75rem;
            margin-top: 1rem;
        }

        .onboarding-candidate {
            display: block;
            background: #ffffff;
            border: 2px solid #d8d8d8;
            padding: .9rem;
        }

        .onboarding-candidate.is-hidden {
            display: none;
        }

        .onboarding-candidate input {
            margin-right: .5rem;
        }

        .onboarding-candidate strong {
            color: #4d0b93;
            font-weight: 900;
        }

        .onboarding-candidate-meta {
            display: block;
            margin-top: .25rem;
            color: #555;
            font-weight: 700;
        }

        .onboarding-no-candidates {
            display: none;
            margin-top: 1rem;
            padding: .85rem;
            background: #ffffff;
            border: 2px solid #d8d8d8;
            font-weight: 700;
        }

        .onboarding-no-candidates.is-visible {
            display: block;
        }

        .onboarding-claim-confirm {
            margin-top: 1rem;
            padding: .85rem;
            background: #ffffff;
            border: 2px solid #d8d8d8;
        }

        .onboarding-claim-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            align-items: center;
        }

        .onboarding-group-section {
            display: none;
        }

        .onboarding-group-section.is-visible {
            display: block;
        }

        .onboarding-accreditation-toolbar {
            display: grid;
            gap: .75rem;
            margin-bottom: 1rem;
        }

        @media (min-width: 768px) {
            .onboarding-accreditation-toolbar {
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: end;
            }
        }

        .onboarding-selected-summary {
            background: #f7f5fb;
            border: 2px solid #e6e6e6;
            padding: .75rem;
            margin-bottom: 1rem;
        }

        .onboarding-selected-summary strong {
            display: block;
            color: #4d0b93;
            font-weight: 900;
            margin-bottom: .35rem;
        }

        .onboarding-selected-tags {
            display: flex;
            flex-wrap: wrap;
            gap: .35rem;
        }

        .onboarding-selected-tag {
            display: inline-flex;
            align-items: center;
            background: #ffffff;
            border: 2px solid #d8d8d8;
            color: #333;
            padding: .25rem .55rem;
            font-size: .82rem;
            font-weight: 800;
        }

        .onboarding-accreditation-category {
            border: 2px solid #e6e6e6;
            margin-bottom: .75rem;
            background: #ffffff;
            overflow: hidden;
        }

        .onboarding-accreditation-category summary {
            cursor: pointer;
            padding: .85rem 1rem;
            background: #f7f5fb;
            color: #4d0b93;
            font-weight: 900;
            list-style: none;
        }

        .onboarding-accreditation-category summary::-webkit-details-marker {
            display: none;
        }

        .onboarding-accreditation-category summary::after {
            content: "Show";
            float: right;
            color: #555;
            font-size: .9rem;
        }

        .onboarding-accreditation-category[open] summary::after {
            content: "Hide";
        }

        .onboarding-accreditation-list {
            padding: 1rem;
        }

        .onboarding-accreditation-item.is-hidden {
            display: none;
        }

        .onboarding-accreditation-empty {
            display: none;
            color: #555;
            font-weight: 700;
            margin: 0;
        }

        .onboarding-accreditation-category.no-results .onboarding-accreditation-empty {
            display: block;
        }

        .onboarding-save-row {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            align-items: center;
        }

        @media (max-width: 575.98px) {
            .onboarding-save-row .btn,
            .onboarding-claim-actions .btn {
                width: 100%;
            }
        }

        @media (max-width: 520px) {
            .onboarding-brand img {
                height: 46px;
                max-width: 160px;
            }
        }
    </style>
</head>
<body>
<header class="onboarding-header">
    <div class="onboarding-header-inner">
        <a class="onboarding-brand" href="/index.php">
            <img src="/assets/img/black-ir-logo.png" alt="Irwell Valley District Scouts" onerror="this.style.display='none';">
            <span>Account setup</span>
        </a>

        <a class="onboarding-signout" href="/logout.php">Sign out</a>
    </div>
</header>

<section class="onboarding-hero">
    <div class="onboarding-hero-inner">
        <h1>Complete your profile</h1>
        <p>
            Tell us who you are and which Group you work with.
            This sets up your District Dashboard access.
        </p>
    </div>
</section>

<main class="lt-main">
    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert">
            <strong>There is a problem:</strong> <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="onboarding-layout">
        <aside class="onboarding-side-card">
            <a
                class="onboarding-photo-link"
                href="<?= e($microsoftProfileUrl) ?>"
                target="_blank"
                rel="noopener noreferrer"
                aria-label="Update your profile photo in Microsoft 365"
            >
                <span class="onboarding-photo">
                    <?php if ($photoUrl !== ''): ?>
                        <img
                            src="<?= e($photoUrl) ?>"
                            alt=""
                            onerror="this.remove(); this.parentElement.textContent='<?= e($initials) ?>';"
                        >
                    <?php else: ?>
                        <?= e($initials) ?>
                    <?php endif; ?>
                </span>
                <span class="onboarding-photo-overlay">Update in Microsoft 365</span>
            </a>

            <h2><?= e($formFullName !== '' ? $formFullName : $displayName) ?></h2>
            <p class="onboarding-email"><?= e($email) ?></p>

            <ol class="onboarding-steps">
                <li>Choose your Group.</li>
                <li>Check whether an existing leader record is yours.</li>
                <li>Complete your directory details.</li>
                <li>Save to open the dashboard.</li>
            </ol>

            <div class="onboarding-photo-note">
                Profile photos are managed through Microsoft 365.
                Changes can take up to 24 hours to appear fully across Microsoft services and this dashboard.
            </div>
        </aside>

        <section class="onboarding-main">
            <form method="post" novalidate>
                <section class="onboarding-panel">
                    <h2>Your details</h2>

                    <div class="form-group">
                        <label for="full_name">Name</label>
                        <input
                            type="text"
                            class="form-control"
                            id="full_name"
                            name="full_name"
                            value="<?= e($formFullName) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="email">Microsoft account email</label>
                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            value="<?= e($email) ?>"
                            disabled
                        >
                        <small class="form-text text-muted">
                            This comes from your Microsoft sign-in.
                        </small>
                    </div>

                    <div class="form-group mb-0">
                        <label for="phone">Contact number</label>
                        <input
                            type="text"
                            class="form-control"
                            id="phone"
                            name="phone"
                            value="<?= e($formPhone) ?>"
                        >
                    </div>
                </section>

                <section class="onboarding-panel">
                    <h2>Group access</h2>
                    <p class="onboarding-panel-intro">
                        Choose the main Group you need access to. After setup, changes to Group access must be made by your Group Lead Volunteer.
                    </p>

                    <div class="onboarding-check-grid mt-3">
                        <?php foreach ($groups as $group): ?>
                            <?php $groupId = (int) $group['id']; ?>
                            <label class="lt-check">
                                <input
                                    type="radio"
                                    name="group_id"
                                    value="<?= $groupId ?>"
                                    data-group-toggle="<?= $groupId ?>"
                                    <?= $groupId === $formGroupId ? 'checked' : '' ?>
                                    required
                                >
                                <span><?= e($group['group_name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>

                <?php if ($claimCandidates): ?>
                    <section class="onboarding-panel onboarding-claim-panel" id="existing-record-panel">
                        <h2>Are any of the below names you?</h2>
                        <p class="onboarding-panel-intro">
                            These are unclaimed leader records in the Group you selected. Only link a record if you are certain it is yours.
                        </p>

                        <div class="onboarding-warning">
                            Do not claim someone else’s record. If none of these records are you, use the button below to continue with a new profile.
                        </div>

                        <div class="onboarding-candidate-list" id="existing-record-list">
                            <?php foreach ($claimCandidates as $candidate): ?>
                                <?php
                                $candidatePersonId = (int) $candidate['person_id'];
                                $candidateGroupId = (int) $candidate['group_id'];
                                ?>
                                <label
                                    class="onboarding-candidate"
                                    data-existing-record
                                    data-group-id="<?= $candidateGroupId ?>"
                                >
                                    <input
                                        type="radio"
                                        name="existing_person_id"
                                        value="<?= $candidatePersonId ?>"
                                    >
                                    <strong><?= e((string) $candidate['full_name']) ?></strong>
                                    <span class="onboarding-candidate-meta">
                                        <?= e((string) $candidate['group_name']) ?>
                                        <?php if (!empty($candidate['role_title'])): ?>
                                            · <?= e((string) $candidate['role_title']) ?>
                                        <?php elseif (!empty($candidate['membership_role'])): ?>
                                            · <?= e(ucwords(str_replace('_', ' ', (string) $candidate['membership_role']))) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="onboarding-candidate-meta">
                                        Email currently on record:
                                        <?= e((string) ($candidate['primary_email'] ?: 'not set')) ?>
                                    </span>
                                    <?php if (!empty($candidate['section_names'])): ?>
                                        <span class="onboarding-candidate-meta">
                                            Sections: <?= e((string) $candidate['section_names']) ?>
                                        </span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="onboarding-claim-confirm">
                            <label class="lt-check mb-3">
                                <input
                                    type="checkbox"
                                    name="confirm_existing_person_claim"
                                    value="1"
                                >
                                <span>I confirm the selected existing record is me.</span>
                            </label>

                            <div class="onboarding-claim-actions">
                                <button
                                    type="submit"
                                    name="action"
                                    value="claim_existing_person"
                                    class="btn btn-primary lt-btn"
                                    formnovalidate
                                    onclick="return confirm('Only continue if the selected existing record is definitely yours.');"
                                >
                                    This is me — link my Microsoft sign-in
                                </button>

                                <button
                                    type="button"
                                    class="btn lt-btn lt-btn-secondary"
                                    id="none-of-these-button"
                                >
                                    None of these are me — continue
                                </button>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <div class="onboarding-normal-flow" id="onboarding-normal-flow">
                    <section class="onboarding-panel">
                        <h2>Role and section information</h2>
                        <p class="onboarding-panel-intro">
                            This helps with directory search and targeted emails. It does not limit your access inside a Group.
                        </p>

                        <div class="form-group">
                            <label for="role_title">Main role</label>
                            <select class="form-control" id="role_title" name="role_title" required>
                                <option value="">Choose your role</option>
                                <?php foreach ($roleOptions as $roleOption): ?>
                                    <option value="<?= e($roleOption) ?>" <?= $formRoleTitle === $roleOption ? 'selected' : '' ?>>
                                        <?= e($roleOption) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($sectionsByGroup): ?>
                            <fieldset class="mt-4">
                                <legend class="h5 font-weight-bold">Sections you work with</legend>
                                <p class="form-text text-muted">
                                    Optional. Used for targeted emails such as Cub leaders or Explorer volunteers.
                                </p>

                                <?php foreach ($groups as $group): ?>
                                    <?php $groupId = (int) $group['id']; ?>

                                    <?php if (empty($sectionsByGroup[$groupId])) {
                                        continue;
                                    } ?>

                                    <div
                                        class="lt-panel-grey mb-3 onboarding-group-section"
                                        data-section-group="<?= $groupId ?>"
                                    >
                                        <h3 class="h6 font-weight-bold"><?= e($group['group_name']) ?></h3>

                                        <div class="onboarding-check-grid">
                                            <?php foreach ($sectionsByGroup[$groupId] as $section): ?>
                                                <?php $sectionId = (int) $section['id']; ?>
                                                <label class="lt-check">
                                                    <input
                                                        type="checkbox"
                                                        name="section_ids[]"
                                                        value="<?= $sectionId ?>"
                                                        <?= in_array($sectionId, $formSectionIds, true) ? 'checked' : '' ?>
                                                    >
                                                    <span><?= e($section['section_name']) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </fieldset>
                        <?php endif; ?>
                    </section>

                    <section class="onboarding-panel">
                        <h2>Directory details</h2>

                        <div class="form-group form-check">
                            
                        </div>

                        <div class="form-group form-check">
                            <input
                                type="checkbox"
                                class="form-check-input"
                                id="share_phone"
                                name="share_phone"
                                value="1"
                                <?= $formSharePhone === 1 ? 'checked' : '' ?>
                            >
                            <label class="form-check-label" for="share_phone">
                                Share my contact number in the District Directory
                            </label>
                        </div>

                        <div class="form-group mb-0">
                            <label for="about_me">About me</label>
                            <textarea
                                class="form-control"
                                id="about_me"
                                name="about_me"
                                rows="3"
                            ><?= e($formAboutMe) ?></textarea>
                        </div>
                    </section>

                    <section class="onboarding-panel">
                        <h2>Permits and accreditations</h2>
                        <p class="onboarding-panel-intro">
                            Search or open a category to select the permits, skills and accreditations you want shown in the Directory.
                        </p>

                        <div class="onboarding-accreditation-toolbar">
                            <div class="form-group mb-md-0">
                                <label for="accreditation_search">Search accreditations</label>
                                <input
                                    type="search"
                                    id="accreditation_search"
                                    class="form-control"
                                    placeholder="Search permits, skills or accreditations"
                                >
                            </div>

                            <div>
                                <button type="button" class="btn lt-btn lt-btn-secondary" id="clear_accreditation_search">
                                    Clear search
                                </button>
                            </div>
                        </div>

                        <div class="onboarding-selected-summary">
                            <strong>
                                Selected accreditations:
                                <span id="selected_accreditation_count"><?= count($formAccreditations) ?></span>
                            </strong>

                            <div class="onboarding-selected-tags" id="selected_accreditation_tags">
                                <?php if ($formAccreditations): ?>
                                    <?php foreach ($formAccreditations as $item): ?>
                                        <span class="onboarding-selected-tag"><?= e($item) ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="onboarding-selected-tag">No accreditations selected</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php foreach ($accreditationOptions as $category => $items): ?>
                            <?php $selectedInCategory = count(array_intersect($items, $formAccreditations)); ?>

                            <details class="onboarding-accreditation-category" <?= $selectedInCategory > 0 ? 'open' : '' ?>>
                                <summary>
                                    <?= e($category) ?>
                                    <span>
                                        (<span data-category-count="<?= e($category) ?>"><?= (int) $selectedInCategory ?></span> selected)
                                    </span>
                                </summary>

                                <div class="onboarding-accreditation-list">
                                    <p class="onboarding-accreditation-empty">
                                        No matching accreditations in this category.
                                    </p>

                                    <div class="onboarding-check-grid">
                                        <?php foreach ($items as $item): ?>
                                            <label
                                                class="lt-check onboarding-accreditation-item"
                                                data-accreditation-item="<?= e(strtolower($item . ' ' . $category)) ?>"
                                                data-accreditation-category="<?= e($category) ?>"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="accreditations[]"
                                                    value="<?= e($item) ?>"
                                                    <?= in_array($item, $formAccreditations, true) ? 'checked' : '' ?>
                                                >
                                                <span><?= e($item) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </section>

                    <section class="onboarding-panel">
                        <div class="onboarding-save-row">
                            <button type="submit" name="action" value="complete_onboarding" class="btn btn-primary btn-lg lt-btn">
                                Save and continue
                            </button>

                            <span class="text-muted font-weight-bold">
                                You can update directory details later from My profile.
                            </span>
                        </div>
                    </section>
                </div>
            </form>
        </section>
    </div>
</main>

<script>
(function () {
    var searchInput = document.getElementById('accreditation_search');
    var clearButton = document.getElementById('clear_accreditation_search');
    var selectedCount = document.getElementById('selected_accreditation_count');
    var selectedTags = document.getElementById('selected_accreditation_tags');
    var existingRecordPanel = document.getElementById('existing-record-panel');
    var normalFlow = document.getElementById('onboarding-normal-flow');
    var noneButton = document.getElementById('none-of-these-button');
    var dismissedGroups = {};

    function normalise(value) {
        return String(value || '').toLowerCase().trim();
    }

    function selectedGroupId() {
        var checked = document.querySelector('[data-group-toggle]:checked');
        return checked ? checked.getAttribute('data-group-toggle') : '';
    }

    function updateSectionVisibility() {
        var groupId = selectedGroupId();

        document.querySelectorAll('[data-section-group]').forEach(function (panel) {
            var visible = groupId !== '' && panel.getAttribute('data-section-group') === groupId;

            panel.classList.toggle('is-visible', visible);

            if (!visible) {
                panel.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
                    checkbox.checked = false;
                });
            }
        });
    }

    function updateExistingRecordVisibility() {
        if (!existingRecordPanel) {
            return;
        }

        var groupId = selectedGroupId();
        var visibleCount = 0;

        document.querySelectorAll('[data-existing-record]').forEach(function (candidate) {
            var visible = groupId !== '' && candidate.getAttribute('data-group-id') === groupId;
            candidate.classList.toggle('is-hidden', !visible);

            var input = candidate.querySelector('input[type="radio"]');

            if (input) {
                input.disabled = !visible;

                if (!visible && input.checked) {
                    input.checked = false;
                }
            }

            if (visible) {
                visibleCount++;
            }
        });

        var shouldShowClaimPanel = groupId !== '' && visibleCount > 0 && !dismissedGroups[groupId];

        existingRecordPanel.classList.toggle('is-visible', shouldShowClaimPanel);

        if (normalFlow) {
            normalFlow.classList.toggle('is-hidden', shouldShowClaimPanel);
        }
    }

    function updateSearch() {
        var query = normalise(searchInput ? searchInput.value : '');

        document.querySelectorAll('.onboarding-accreditation-category').forEach(function (category) {
            var visibleCount = 0;

            category.querySelectorAll('.onboarding-accreditation-item').forEach(function (item) {
                var haystack = normalise(item.getAttribute('data-accreditation-item'));
                var visible = query === '' || haystack.indexOf(query) !== -1;

                item.classList.toggle('is-hidden', !visible);

                if (visible) {
                    visibleCount++;
                }
            });

            category.classList.toggle('no-results', visibleCount === 0);

            if (query !== '' && visibleCount > 0) {
                category.open = true;
            }
        });
    }

    function updateSelectedSummary() {
        var checked = Array.prototype.slice.call(
            document.querySelectorAll('input[name="accreditations[]"]:checked')
        );

        if (selectedCount) {
            selectedCount.textContent = String(checked.length);
        }

        if (selectedTags) {
            selectedTags.innerHTML = '';

            if (checked.length === 0) {
                var empty = document.createElement('span');
                empty.className = 'onboarding-selected-tag';
                empty.textContent = 'No accreditations selected';
                selectedTags.appendChild(empty);
            } else {
                checked.forEach(function (checkbox) {
                    var tag = document.createElement('span');
                    tag.className = 'onboarding-selected-tag';
                    tag.textContent = checkbox.value;
                    selectedTags.appendChild(tag);
                });
            }
        }

        var categoryCounts = {};

        document.querySelectorAll('.onboarding-accreditation-item').forEach(function (item) {
            var category = item.getAttribute('data-accreditation-category') || '';
            var checkbox = item.querySelector('input[type="checkbox"]');

            if (!categoryCounts[category]) {
                categoryCounts[category] = 0;
            }

            if (checkbox && checkbox.checked) {
                categoryCounts[category]++;
            }
        });

        document.querySelectorAll('[data-category-count]').forEach(function (countNode) {
            var category = countNode.getAttribute('data-category-count') || '';
            countNode.textContent = String(categoryCounts[category] || 0);
        });
    }

    document.querySelectorAll('[data-group-toggle]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            updateSectionVisibility();
            updateExistingRecordVisibility();
        });
    });

    if (noneButton) {
        noneButton.addEventListener('click', function () {
            var groupId = selectedGroupId();

            if (groupId !== '') {
                dismissedGroups[groupId] = true;
            }

            document.querySelectorAll('input[name="existing_person_id"]').forEach(function (input) {
                input.checked = false;
            });

            updateExistingRecordVisibility();

            var roleSelect = document.getElementById('role_title');
            if (roleSelect) {
                roleSelect.focus();
            }
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', updateSearch);
    }

    if (clearButton && searchInput) {
        clearButton.addEventListener('click', function () {
            searchInput.value = '';
            updateSearch();
            searchInput.focus();
        });
    }

    document.querySelectorAll('input[name="accreditations[]"]').forEach(function (checkbox) {
        checkbox.addEventListener('change', updateSelectedSummary);
    });

    updateSectionVisibility();
    updateExistingRecordVisibility();
    updateSearch();
    updateSelectedSummary();
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>