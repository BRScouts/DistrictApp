<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

if (user_needs_group_onboarding()) {
    redirect('/onboarding.php');
}

$pdo = db();
$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

function gm_table_exists(string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = :table_name\n        ");
        $stmt->execute(['table_name' => $table]);
        $cache[$table] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function gm_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = :table_name\n              AND COLUMN_NAME = :column_name\n        ");
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

function gm_table_columns(string $table): array
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("\n            SELECT COLUMN_NAME\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = :table_name\n        ");
        $stmt->execute(['table_name' => $table]);
        $cache[$table] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        $cache[$table] = [];
    }

    return $cache[$table];
}

function gm_insert_flexible(string $table, array $values): bool
{
    if (!gm_table_exists($table)) {
        return false;
    }

    $columns = gm_table_columns($table);
    $insert = [];

    foreach ($values as $column => $value) {
        if (in_array((string) $column, $columns, true)) {
            $insert[(string) $column] = $value;
        }
    }

    if (!$insert) {
        return false;
    }

    $quotedColumns = array_map(static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`', array_keys($insert));
    $placeholders = array_map(static fn(string $column): string => ':' . $column, array_keys($insert));

    $stmt = db()->prepare('INSERT INTO `' . str_replace('`', '``', $table) . '` (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $placeholders) . ')');
    return $stmt->execute($insert);
}

function gm_actor_is_district_admin(array $user, array $memberships): bool
{
    $levels = [(string) ($user['highest_access_level'] ?? $user['role'] ?? 'member')];

    foreach ($memberships as $membership) {
        $levels[] = (string) ($membership['access_level'] ?? 'member');
    }

    $levels = array_values(array_unique($levels));

    return (bool) array_intersect($levels, ['district_admin', 'system_admin']);
}

function gm_manageable_groups(int $personId, bool $isDistrictAdmin): array
{
    if ($isDistrictAdmin) {
        $stmt = db()->query("\n            SELECT id, group_name, slug\n            FROM groups\n            WHERE is_active = 1\n            ORDER BY group_name ASC\n        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = db()->prepare("\n        SELECT DISTINCT g.id, g.group_name, g.slug\n        FROM group_memberships gm\n        JOIN groups g ON g.id = gm.group_id\n        WHERE gm.person_id = :person_id\n          AND gm.status = 'active'\n          AND g.is_active = 1\n          AND (\n                gm.membership_role = 'group_lead_volunteer'\n                OR gm.access_level = 'group_admin'\n              )\n        ORDER BY g.group_name ASC\n    ");
    $stmt->execute(['person_id' => $personId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function gm_group_is_manageable(int $groupId, array $groups): bool
{
    foreach ($groups as $group) {
        if ((int) $group['id'] === $groupId) {
            return true;
        }
    }

    return false;
}

function gm_absolute_url(string $path): string
{
    $base = rtrim((string) app_config('APP_URL', ''), '/');

    if ($base !== '') {
        return $base . '/' . ltrim($path, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'app.irvalscouts.org.uk';

    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

function gm_fetch_group(int $groupId): ?array
{
    $stmt = db()->prepare("\n        SELECT *\n        FROM groups\n        WHERE id = :group_id\n          AND is_active = 1\n        LIMIT 1\n    ");
    $stmt->execute(['group_id' => $groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    return $group ?: null;
}

function gm_fetch_leaders(int $groupId): array
{
    $stmt = db()->prepare("\n        SELECT\n            p.id AS person_id,\n            p.full_name,\n            p.primary_email,\n            p.phone,\n            p.status AS person_status,\n            gm.id AS membership_id,\n            gm.membership_role,\n            gm.access_level,\n            gm.status AS membership_status,\n            dp.role_title,\n            dp.visible_in_directory,\n            MAX(CASE WHEN ua.provider = 'microsoft' THEN 1 ELSE 0 END) AS has_microsoft_account,\n            COUNT(DISTINCT ce.id) AS total_events,\n            SUM(CASE WHEN ce.status IN ('submitted', 'under_review') THEN 1 ELSE 0 END) AS in_review_events,\n            SUM(CASE WHEN ce.status = 'approved' THEN 1 ELSE 0 END) AS approved_events,\n            MAX(ce.starts_at) AS latest_event_at\n        FROM group_memberships gm\n        JOIN people p ON p.id = gm.person_id\n        LEFT JOIN directory_profiles dp ON dp.person_id = p.id\n        LEFT JOIN user_accounts ua ON ua.person_id = p.id\n        LEFT JOIN calendar_events ce\n          ON ce.group_id = gm.group_id\n         AND (\n                ce.submitted_by_person_id = p.id\n                OR LOWER(ce.leader_email) = LOWER(p.primary_email)\n             )\n        WHERE gm.group_id = :group_id\n        GROUP BY\n            p.id, p.full_name, p.primary_email, p.phone, p.status,\n            gm.id, gm.membership_role, gm.access_level, gm.status,\n            dp.role_title, dp.visible_in_directory\n        ORDER BY\n            CASE WHEN gm.status = 'active' AND p.status = 'active' THEN 0 ELSE 1 END,\n            p.full_name ASC\n    ");
    $stmt->execute(['group_id' => $groupId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function gm_fetch_group_links(int $groupId): array
{
    if (!gm_table_exists('group_access_links')) {
        return [];
    }

    $columns = ['id', 'group_id', 'token_hash', 'status'];
    foreach (['label', 'token_plain', 'scope', 'created_at', 'expires_at', 'last_used_at'] as $column) {
        if (gm_column_exists('group_access_links', $column)) {
            $columns[] = $column;
        }
    }

    try {
        $hasExpiresAt = gm_column_exists('group_access_links', 'expires_at');
        $hasCreatedAt = gm_column_exists('group_access_links', 'created_at');
        $select = implode(', ', array_map(static fn(string $column): string => '`' . $column . '`', $columns));

        $stmt = db()->prepare("\n            SELECT {$select}\n            FROM group_access_links\n            WHERE group_id = :group_id\n              AND status = 'active'\n              AND (" . ($hasExpiresAt ? "expires_at IS NULL OR expires_at > NOW()" : "1 = 1") . ")\n            ORDER BY " . ($hasCreatedAt ? "created_at DESC," : "") . " id DESC\n            LIMIT 10\n        ");
        $stmt->execute(['group_id' => $groupId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function gm_group_link_url(array $link): ?string
{
    if (!empty($link['token_plain'])) {
        return gm_absolute_url('/dc/login.php?token=' . urlencode((string) $link['token_plain']));
    }

    return null;
}

function gm_find_person_by_email(string $email): ?array
{
    $stmt = db()->prepare("\n        SELECT *\n        FROM people\n        WHERE LOWER(primary_email) = LOWER(:email)\n        LIMIT 1\n    ");
    $stmt->execute(['email' => $email]);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);

    return $person ?: null;
}

function gm_find_possible_duplicates(string $fullName, string $email, int $excludePersonId = 0): array
{
    $terms = array_values(array_filter(preg_split('/\s+/', strtolower(trim($fullName))) ?: []));
    $nameNeedles = array_slice($terms, 0, 3);

    $sql = "\n        SELECT\n            p.id, p.full_name, p.primary_email, p.phone, p.status,\n            GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.group_name SEPARATOR ', ') AS group_names\n        FROM people p\n        LEFT JOIN group_memberships gm ON gm.person_id = p.id\n        LEFT JOIN groups g ON g.id = gm.group_id\n        WHERE p.id <> :exclude_person_id\n          AND (LOWER(p.primary_email) = LOWER(:email)";
    $params = [
        'exclude_person_id' => $excludePersonId,
        'email' => $email,
    ];

    foreach ($nameNeedles as $index => $needle) {
        if (strlen($needle) < 3) {
            continue;
        }
        $param = 'name_' . $index;
        $sql .= " OR LOWER(p.full_name) LIKE :{$param}";
        $params[$param] = '%' . $needle . '%';
    }

    $sql .= ")\n        GROUP BY p.id, p.full_name, p.primary_email, p.phone, p.status\n        ORDER BY p.full_name ASC\n        LIMIT 8\n    ";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function gm_membership_role_options(): array
{
    return [
        'group_lead_volunteer' => 'Group Lead Volunteer',
        'section_leader' => 'Section Leader',
        'assistant_section_leader' => 'Assistant Section Leader',
        'section_assistant' => 'Section Assistant',
        'trustee' => 'Trustee',
        'district_volunteer' => 'District Volunteer',
        'administrator' => 'Administrator',
        'other' => 'Other',
    ];
}

function gm_role_title_from_membership_role(string $membershipRole): string
{
    $options = gm_membership_role_options();
    return $options[$membershipRole] ?? 'Other';
}

function gm_access_level_for_membership_role(string $membershipRole): string
{
    return $membershipRole === 'group_lead_volunteer' ? 'group_admin' : 'member';
}

function gm_upsert_directory_profile(int $personId, string $roleTitle, int $visibleInDirectory, int $sharePhone): void
{
    if (!gm_table_exists('directory_profiles')) {
        return;
    }

    $stmt = db()->prepare("\n        INSERT INTO directory_profiles (person_id, role_title, visible_in_directory, share_phone, profile_updated_at)\n        VALUES (:person_id, :role_title, :visible_in_directory, :share_phone, NOW())\n        ON DUPLICATE KEY UPDATE\n            role_title = VALUES(role_title),\n            visible_in_directory = VALUES(visible_in_directory),\n            share_phone = VALUES(share_phone),\n            profile_updated_at = NOW()\n    ");
    $stmt->execute([
        'person_id' => $personId,
        'role_title' => $roleTitle,
        'visible_in_directory' => $visibleInDirectory,
        'share_phone' => $sharePhone,
    ]);
}

function gm_upsert_membership(int $personId, int $groupId, string $membershipRole): void
{
    $accessLevel = gm_access_level_for_membership_role($membershipRole);

    $stmt = db()->prepare("\n        INSERT INTO group_memberships (person_id, group_id, membership_role, access_level, status, is_primary, approved_at)\n        VALUES (:person_id, :group_id, :membership_role, :access_level, 'active', 1, NOW())\n        ON DUPLICATE KEY UPDATE\n            membership_role = VALUES(membership_role),\n            access_level = VALUES(access_level),\n            status = 'active',\n            approved_at = COALESCE(approved_at, NOW())\n    ");
    $stmt->execute([
        'person_id' => $personId,
        'group_id' => $groupId,
        'membership_role' => $membershipRole,
        'access_level' => $accessLevel,
    ]);
}

function gm_log_action(int $actorPersonId, string $action, string $entityType, int $entityId, array $details = []): void
{
    if (!gm_table_exists('audit_log')) {
        return;
    }

    try {
        $stmt = db()->prepare("\n            INSERT INTO audit_log (actor_type, actor_person_id, action, entity_type, entity_id, details_json)\n            VALUES ('person', :actor_person_id, :action, :entity_type, :entity_id, :details_json)\n        ");
        $stmt->execute([
            'actor_person_id' => $actorPersonId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        // Non-critical audit failure should not block GLV administration.
    }
}

function gm_create_district_email_request(int $actorPersonId, int $personId, int $groupId, string $requestedEmail, string $notes): bool
{
    $details = [
        'person_id' => $personId,
        'group_id' => $groupId,
        'requested_email' => $requestedEmail,
        'notes' => $notes,
        'request_context' => 'group_manager',
    ];

    foreach (['requests', 'account_requests', 'microsoft_account_requests'] as $table) {
        if (!gm_table_exists($table)) {
            continue;
        }

        try {
            $inserted = gm_insert_flexible($table, [
                'request_type' => 'district_email',
                'type' => 'district_email',
                'status' => 'pending',
                'person_id' => $personId,
                'requested_for_person_id' => $personId,
                'group_id' => $groupId,
                'requested_by_person_id' => $actorPersonId,
                'actor_person_id' => $actorPersonId,
                'requested_email' => $requestedEmail !== '' ? $requestedEmail : null,
                'email' => $requestedEmail !== '' ? $requestedEmail : null,
                'notes' => $notes !== '' ? $notes : null,
                'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            $inserted = false;
        }

        if ($inserted) {
            return true;
        }
    }

    return false;
}

function gm_create_unique_invite(int $actorPersonId, int $personId, int $groupId): ?string
{
    if (!gm_table_exists('group_user_invites')) {
        return null;
    }

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $hash = hash('sha256', $token);

    try {
        $inserted = gm_insert_flexible('group_user_invites', [
            'group_id' => $groupId,
            'person_id' => $personId,
            'token_hash' => $hash,
            'token_plain' => $token,
            'status' => 'active',
            'created_by_person_id' => $actorPersonId,
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+90 days')),
        ]);
    } catch (Throwable $e) {
        $inserted = false;
    }

    if (!$inserted) {
        return null;
    }

    return gm_absolute_url('/login.php?invite=' . urlencode($token));
}

function gm_merge_people(int $sourcePersonId, int $targetPersonId, int $groupId, int $actorPersonId): void
{
    if ($sourcePersonId === $targetPersonId) {
        throw new RuntimeException('Choose two different people to merge.');
    }

    // Fetch separately to avoid driver-specific IN-list placeholder behaviour.
    $stmt = db()->prepare("SELECT id, full_name, primary_email FROM people WHERE id = :person_id LIMIT 1");
    $stmt->execute(['person_id' => $sourcePersonId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->execute(['person_id' => $targetPersonId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$source || !$target) {
        throw new RuntimeException('One of the selected people could not be found.');
    }

    $stmt = db()->prepare("\n        SELECT COUNT(*)\n        FROM group_memberships\n        WHERE person_id = :person_id\n          AND group_id = :group_id\n    ");
    $stmt->execute(['person_id' => $sourcePersonId, 'group_id' => $groupId]);
    $sourceInGroup = ((int) $stmt->fetchColumn()) > 0;

    $stmt->execute(['person_id' => $targetPersonId, 'group_id' => $groupId]);
    $targetInGroup = ((int) $stmt->fetchColumn()) > 0;

    if (!$sourceInGroup || !$targetInGroup) {
        throw new RuntimeException('Both people must be linked to this Group before they can be merged here.');
    }

    $sourceEmail = (string) ($source['primary_email'] ?? '');
    $targetEmail = (string) ($target['primary_email'] ?? '');
    $targetName = (string) ($target['full_name'] ?? '');

    if (gm_table_exists('calendar_events')) {
        if (gm_column_exists('calendar_events', 'submitted_by_person_id')) {
            $stmt = db()->prepare("\n                UPDATE calendar_events\n                SET submitted_by_person_id = :target_id\n                WHERE submitted_by_person_id = :source_id\n                  AND group_id = :group_id\n            ");
            $stmt->execute(['target_id' => $targetPersonId, 'source_id' => $sourcePersonId, 'group_id' => $groupId]);
        }

        if ($sourceEmail !== '' && gm_column_exists('calendar_events', 'leader_email')) {
            $stmt = db()->prepare("\n                UPDATE calendar_events\n                SET leader_email = :target_email,\n                    leader_name = :target_name\n                WHERE group_id = :group_id\n                  AND LOWER(leader_email) = LOWER(:source_email)\n            ");
            $stmt->execute([
                'target_email' => $targetEmail,
                'target_name' => $targetName,
                'group_id' => $groupId,
                'source_email' => $sourceEmail,
            ]);
        }
    }

    if (gm_table_exists('risk_assessments') && gm_column_exists('risk_assessments', 'uploaded_by_person_id')) {
        $stmt = db()->prepare("\n            UPDATE risk_assessments\n            SET uploaded_by_person_id = :target_id\n            WHERE uploaded_by_person_id = :source_id\n              AND group_id = :group_id\n        ");
        $stmt->execute(['target_id' => $targetPersonId, 'source_id' => $sourcePersonId, 'group_id' => $groupId]);
    }

    if (gm_table_exists('user_accounts')) {
        $stmt = db()->prepare("UPDATE user_accounts SET person_id = :target_id WHERE person_id = :source_id");
        $stmt->execute(['target_id' => $targetPersonId, 'source_id' => $sourcePersonId]);
    }

    $stmt = db()->prepare("\n        UPDATE group_memberships\n        SET status = 'inactive', is_primary = 0\n        WHERE person_id = :source_id\n          AND group_id = :group_id\n    ");
    $stmt->execute(['source_id' => $sourcePersonId, 'group_id' => $groupId]);

    $stmt = db()->prepare("UPDATE people SET status = 'inactive' WHERE id = :source_id");
    $stmt->execute(['source_id' => $sourcePersonId]);

    gm_log_action($actorPersonId, 'person_merged', 'person', $targetPersonId, [
        'source_person_id' => $sourcePersonId,
        'target_person_id' => $targetPersonId,
        'group_id' => $groupId,
    ]);
}

$memberships = user_group_memberships((int) $user['id'], false);
$isDistrictAdmin = gm_actor_is_district_admin($user, $memberships);
$manageableGroups = gm_manageable_groups((int) $user['id'], $isDistrictAdmin);

if (!$manageableGroups) {
    http_response_code(403);
    $pageTitle = 'Group Manager | ' . $appName;
    $heroTitle = 'Group Manager';
    $heroText = 'This area is for Group Lead Volunteers and District administrators.';
    $breadcrumb = '<a href="/index.php">Home</a> / Group Manager';
    include __DIR__ . '/header.php';
    ?>
    <main class="lt-main">
        <div class="alert alert-danger"><strong>Access denied:</strong> You do not currently manage any Groups.</div>
    </main>
    <?php include __DIR__ . '/footer.php';
    exit;
}

$requestedGroupId = (int) ($_GET['group_id'] ?? $_POST['group_id'] ?? 0);
$selectedGroupId = $requestedGroupId > 0 && gm_group_is_manageable($requestedGroupId, $manageableGroups)
    ? $requestedGroupId
    : (int) $manageableGroups[0]['id'];

$selectedGroup = gm_fetch_group($selectedGroupId);
if (!$selectedGroup) {
    http_response_code(404);
    include __DIR__ . '/header.php';
    echo '<main class="lt-main"><div class="alert alert-danger">Group not found.</div></main>';
    include __DIR__ . '/footer.php';
    exit;
}

$tab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'people');
$allowedTabs = ['people', 'add', 'links', 'website'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'people';
}

$errors = [];
$success = null;
$createdInviteUrl = null;
$duplicateCandidates = [];
$posted = [];
$actorPersonId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'add_person') {
            $tab = 'add';
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $primaryEmail = strtolower(trim((string) ($_POST['primary_email'] ?? '')));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $membershipRole = (string) ($_POST['membership_role'] ?? 'section_leader');
            $visibleInDirectory = isset($_POST['visible_in_directory']) ? 1 : 0;
            $sharePhone = isset($_POST['share_phone']) ? 1 : 0;
            $requestDistrictEmail = isset($_POST['request_district_email']);
            $requestedDistrictEmail = strtolower(trim((string) ($_POST['requested_district_email'] ?? '')));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $confirmDuplicate = isset($_POST['confirm_duplicate']);

            $posted = $_POST;
            $roleOptions = gm_membership_role_options();

            if ($fullName === '') {
                $errors[] = 'Enter the person\'s full name.';
            }

            if ($primaryEmail === '' || !filter_var($primaryEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid personal or Scouting email address.';
            }

            if (!array_key_exists($membershipRole, $roleOptions)) {
                $errors[] = 'Choose a valid role.';
            }

            if ($requestDistrictEmail && $requestedDistrictEmail !== '' && !filter_var($requestedDistrictEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid requested District email address, or leave it blank for the District team to allocate.';
            }

            if (!$errors) {
                $existingPerson = gm_find_person_by_email($primaryEmail);
                $personId = $existingPerson ? (int) $existingPerson['id'] : 0;

                if (!$existingPerson) {
                    $duplicateCandidates = gm_find_possible_duplicates($fullName, $primaryEmail);
                    if ($duplicateCandidates && !$confirmDuplicate) {
                        $errors[] = 'Possible duplicate people were found. Review them below, then tick the confirmation box if this really is a new person.';
                    }
                }

                if (!$errors) {
                    $pdo->beginTransaction();

                    if ($existingPerson) {
                        $personId = (int) $existingPerson['id'];
                        $stmt = $pdo->prepare("\n                            UPDATE people\n                            SET full_name = CASE WHEN full_name IS NULL OR full_name = '' THEN :full_name ELSE full_name END,\n                                phone = COALESCE(NULLIF(phone, ''), :phone),\n                                status = 'active'\n                            WHERE id = :person_id\n                        ");
                        $stmt->execute([
                            'full_name' => $fullName,
                            'phone' => $phone !== '' ? $phone : null,
                            'person_id' => $personId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare("\n                            INSERT INTO people (full_name, primary_email, phone, status)\n                            VALUES (:full_name, :primary_email, :phone, 'active')\n                        ");
                        $stmt->execute([
                            'full_name' => $fullName,
                            'primary_email' => $primaryEmail,
                            'phone' => $phone !== '' ? $phone : null,
                        ]);
                        $personId = (int) $pdo->lastInsertId();
                    }

                    gm_upsert_membership($personId, $selectedGroupId, $membershipRole);
                    gm_upsert_directory_profile($personId, gm_role_title_from_membership_role($membershipRole), $visibleInDirectory, $sharePhone);

                    $requestRecorded = false;
                    if ($requestDistrictEmail) {
                        $requestRecorded = gm_create_district_email_request($actorPersonId, $personId, $selectedGroupId, $requestedDistrictEmail, $notes);
                    } else {
                        $createdInviteUrl = gm_create_unique_invite($actorPersonId, $personId, $selectedGroupId);
                    }

                    gm_log_action($actorPersonId, $existingPerson ? 'group_person_linked' : 'group_person_created', 'person', $personId, [
                        'group_id' => $selectedGroupId,
                        'membership_role' => $membershipRole,
                        'request_district_email' => $requestDistrictEmail,
                        'request_recorded' => $requestRecorded,
                    ]);

                    $pdo->commit();

                    if ($existingPerson) {
                        $success = 'Existing person found by email and linked to this Group.';
                    } else {
                        $success = 'Person added to this Group.';
                    }

                    if ($requestDistrictEmail && !$requestRecorded) {
                        $success .= ' The District email request was logged in the audit trail, but no compatible requests table was found.';
                    }

                    if (!$requestDistrictEmail && !$createdInviteUrl) {
                        $success .= ' No personal invite link table exists yet, so use the Group calendar link until the invite-email flow is added.';
                    }

                    $posted = [];
                }
            }
        } elseif ($action === 'set_status') {
            $personId = (int) ($_POST['person_id'] ?? 0);
            $newStatus = (string) ($_POST['new_status'] ?? 'inactive');
            $newStatus = $newStatus === 'active' ? 'active' : 'inactive';

            $stmt = $pdo->prepare("\n                SELECT COUNT(*)\n                FROM group_memberships\n                WHERE person_id = :person_id\n                  AND group_id = :group_id\n            ");
            $stmt->execute(['person_id' => $personId, 'group_id' => $selectedGroupId]);

            if ((int) $stmt->fetchColumn() < 1) {
                throw new RuntimeException('That person is not linked to this Group.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("\n                UPDATE group_memberships\n                SET status = :status, is_primary = CASE WHEN :primary_status = 'inactive' THEN 0 ELSE is_primary END\n                WHERE person_id = :person_id\n                  AND group_id = :group_id\n            ");
            $stmt->execute([
                'status' => $newStatus,
                'primary_status' => $newStatus,
                'person_id' => $personId,
                'group_id' => $selectedGroupId,
            ]);

            if ($newStatus === 'inactive') {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM group_memberships
                    WHERE person_id = :person_id
                      AND status = 'active'
                ");
                $stmt->execute(['person_id' => $personId]);

                if ((int) $stmt->fetchColumn() === 0) {
                    $stmt = $pdo->prepare("UPDATE people SET status = 'inactive' WHERE id = :person_id");
                    $stmt->execute(['person_id' => $personId]);
                }
            } else {
                $stmt = $pdo->prepare("UPDATE people SET status = 'active' WHERE id = :person_id");
                $stmt->execute(['person_id' => $personId]);
            }

            gm_log_action($actorPersonId, 'group_person_status_changed', 'person', $personId, [
                'group_id' => $selectedGroupId,
                'status' => $newStatus,
            ]);
            $pdo->commit();

            $success = $newStatus === 'active'
                ? 'Person reactivated for this Group.'
                : 'Person made inactive. They will no longer appear in active leader pickers.';
        } elseif ($action === 'merge_people') {
            $sourcePersonId = (int) ($_POST['source_person_id'] ?? 0);
            $targetPersonId = (int) ($_POST['target_person_id'] ?? 0);

            $pdo->beginTransaction();
            gm_merge_people($sourcePersonId, $targetPersonId, $selectedGroupId, $actorPersonId);
            $pdo->commit();

            $success = 'People merged. Events, Microsoft login records and Group calendar ownership have been moved to the retained person.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $e->getMessage() ?: 'The request could not be completed.';
    }
}

$leaders = gm_fetch_leaders($selectedGroupId);
$groupLinks = gm_fetch_group_links($selectedGroupId);
$roleOptions = gm_membership_role_options();
$activeLeaders = array_values(array_filter($leaders, static fn(array $leader): bool => (string) $leader['membership_status'] === 'active' && (string) $leader['person_status'] === 'active'));
$inactiveLeaders = count($leaders) - count($activeLeaders);
$totalEvents = array_sum(array_map(static fn(array $leader): int => (int) $leader['total_events'], $leaders));
$leadersWithSso = count(array_filter($leaders, static fn(array $leader): bool => (int) $leader['has_microsoft_account'] > 0));

$pageTitle = 'Group Manager | ' . $appName;
$heroTitle = 'Group Manager';
$heroText = 'Manage leaders for ' . (string) $selectedGroup['group_name'] . ', encourage District Microsoft 365 sign-in, and keep the District Directory accurate.';
$breadcrumb = '<a href="/index.php">Home</a> / Group Manager';
?>
<?php include __DIR__ . '/header.php'; ?>

<style>
    .gm-tabs { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; }
    .gm-tab { display: inline-block; padding: .75rem 1rem; border: 2px solid var(--iv-purple); color: var(--iv-purple); font-weight: 900; text-decoration: none; background: #fff; }
    .gm-tab:hover { color: var(--iv-purple-dark); text-decoration: none; }
    .gm-tab.active { background: var(--iv-purple); color: #fff; }
    .gm-grid { display: grid; gap: 1rem; }
    @media (min-width: 992px) { .gm-grid { grid-template-columns: minmax(0, 1fr) 340px; align-items: start; } }
    .gm-stat-grid { display: grid; gap: .75rem; margin-bottom: 1rem; }
    @media (min-width: 768px) { .gm-stat-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
    .gm-stat { border: 1px solid var(--iv-grey-300); padding: 1rem; background: #fff; }
    .gm-stat strong { display: block; font-size: 1.8rem; font-weight: 900; line-height: 1; }
    .gm-stat span { display: block; margin-top: .35rem; font-weight: 800; color: var(--iv-grey-700); }
    .gm-table-wrap { overflow-x: auto; border: 1px solid var(--iv-grey-300); background: #fff; }
    .gm-table { width: 100%; min-width: 920px; border-collapse: collapse; }
    .gm-table th, .gm-table td { padding: .8rem; border-bottom: 1px solid var(--iv-grey-300); text-align: left; vertical-align: top; }
    .gm-table th { background: var(--iv-grey-100); font-weight: 900; }
    .gm-muted { color: var(--iv-grey-700); font-weight: 700; }
    .gm-badge { display: inline-block; padding: .2rem .45rem; border: 1px solid var(--iv-grey-300); background: var(--iv-grey-100); font-weight: 900; font-size: .78rem; margin: .1rem .15rem .1rem 0; }
    .gm-badge-sso { background: var(--iv-green); color: #fff; border-color: var(--iv-green); }
    .gm-badge-warning { background: var(--iv-yellow); color: #111; border-color: var(--iv-yellow); }
    .gm-badge-inactive { background: var(--iv-red); color: #fff; border-color: var(--iv-red); }
    .gm-actions { display: flex; flex-wrap: wrap; gap: .35rem; }
    .gm-action-form { margin: 0; }
    .gm-card-list { display: grid; gap: .75rem; }
    .gm-link-box { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
    .gm-link-box input { flex: 1 1 260px; }
    .gm-duplicate { border-left: 6px solid var(--iv-yellow); }
    .gm-coming-soon { border: 2px dashed var(--iv-grey-300); background: var(--iv-grey-100); padding: 1.25rem; }
    @media (max-width: 767.98px) {
        .gm-table-wrap { border: 0; overflow: visible; }
        .gm-table, .gm-table thead, .gm-table tbody, .gm-table th, .gm-table td, .gm-table tr { display: block; min-width: 0; }
        .gm-table thead { display: none; }
        .gm-table tr { border: 1px solid var(--iv-grey-300); margin-bottom: .75rem; background: #fff; }
        .gm-table td { border-bottom: 0; padding: .55rem .75rem; }
        .gm-table td::before { content: attr(data-label); display: block; font-weight: 900; color: var(--iv-black); }
    }
</style>

<main class="lt-main">
    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <strong>There is a problem:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?><div class="alert alert-success" role="status"><?= e($success) ?></div><?php endif; ?>

    <?php if ($createdInviteUrl): ?>
        <div class="alert alert-info">
            <strong>Unique invite link created:</strong>
            <div class="gm-link-box mt-2">
                <input class="form-control" type="text" value="<?= e($createdInviteUrl) ?>" readonly>
                <button class="btn btn-secondary lt-btn gm-copy" type="button" data-copy="<?= e($createdInviteUrl) ?>">Copy</button>
            </div>
        </div>
    <?php endif; ?>

    <form method="get" class="lt-panel mb-4">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <div class="form-row align-items-end">
            <div class="form-group col-md-8 mb-md-0">
                <label for="group_id">Group</label>
                <select class="form-control" id="group_id" name="group_id" onchange="this.form.submit()">
                    <?php foreach ($manageableGroups as $groupOption): ?>
                        <option value="<?= (int) $groupOption['id'] ?>" <?= $selectedGroupId === (int) $groupOption['id'] ? 'selected' : '' ?>><?= e($groupOption['group_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-md-4 mb-md-0">
                <button class="btn btn-primary lt-btn btn-block" type="submit">Open Group</button>
            </div>
        </div>
    </form>

    <nav class="gm-tabs" aria-label="Group Manager sections">
        <a class="gm-tab <?= $tab === 'people' ? 'active' : '' ?>" href="/group-manager.php?group_id=<?= $selectedGroupId ?>&amp;tab=people">People</a>
        <a class="gm-tab <?= $tab === 'add' ? 'active' : '' ?>" href="/group-manager.php?group_id=<?= $selectedGroupId ?>&amp;tab=add">Add person</a>
        <a class="gm-tab <?= $tab === 'links' ? 'active' : '' ?>" href="/group-manager.php?group_id=<?= $selectedGroupId ?>&amp;tab=links">Calendar access</a>
        <a class="gm-tab <?= $tab === 'website' ? 'active' : '' ?>" href="/group-manager.php?group_id=<?= $selectedGroupId ?>&amp;tab=website">Website details</a>
    </nav>

    <?php if ($tab === 'people'): ?>
        <div class="gm-stat-grid">
            <div class="gm-stat"><strong><?= count($activeLeaders) ?></strong><span>Active people</span></div>
            <div class="gm-stat"><strong><?= (int) $leadersWithSso ?></strong><span>Using Microsoft SSO</span></div>
            <div class="gm-stat"><strong><?= (int) $totalEvents ?></strong><span>Linked events</span></div>
            <div class="gm-stat"><strong><?= (int) $inactiveLeaders ?></strong><span>Inactive records</span></div>
        </div>

        <div class="lt-panel mb-4">
            <h2 class="lt-section-title">People linked to <?= e($selectedGroup['group_name']) ?></h2>
            <p class="lt-lede">Active people appear in the District Calendar leader picker. Make someone inactive when they leave so new events cannot be assigned to them.</p>
            <div class="gm-table-wrap">
                <table class="gm-table">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th>Role/access</th>
                            <th>Calendar usage</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaders as $leader): ?>
                            <?php
                                $personId = (int) $leader['person_id'];
                                $isActive = (string) $leader['membership_status'] === 'active' && (string) $leader['person_status'] === 'active';
                            ?>
                            <tr>
                                <td data-label="Person">
                                    <strong><?= e($leader['full_name']) ?></strong><br>
                                    <a href="mailto:<?= e($leader['primary_email']) ?>"><?= e($leader['primary_email']) ?></a>
                                    <?php if (!empty($leader['phone'])): ?><br><span class="gm-muted"><?= e($leader['phone']) ?></span><?php endif; ?>
                                    <div class="mt-2">
                                        <?php if ((int) $leader['has_microsoft_account'] > 0): ?>
                                            <span class="gm-badge gm-badge-sso">Microsoft SSO</span>
                                        <?php else: ?>
                                            <span class="gm-badge gm-badge-warning">Needs SSO</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="Role/access">
                                    <?= e(gm_role_title_from_membership_role((string) $leader['membership_role'])) ?><br>
                                    <span class="gm-muted"><?= e(str_replace('_', ' ', (string) $leader['access_level'])) ?></span>
                                </td>
                                <td data-label="Calendar usage">
                                    <strong><?= (int) $leader['total_events'] ?></strong> total<br>
                                    <span class="gm-muted"><?= (int) $leader['in_review_events'] ?> in review, <?= (int) $leader['approved_events'] ?> approved</span>
                                    <?php if (!empty($leader['latest_event_at'])): ?><br><span class="gm-muted">Latest: <?= e(date('j M Y', strtotime((string) $leader['latest_event_at']))) ?></span><?php endif; ?>
                                </td>
                                <td data-label="Status">
                                    <?php if ($isActive): ?>
                                        <span class="gm-badge gm-badge-sso">Active</span>
                                    <?php else: ?>
                                        <span class="gm-badge gm-badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                    <?php if ((int) $leader['visible_in_directory'] === 1): ?>
                                        <span class="gm-badge">Directory</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Actions">
                                    <div class="gm-actions">
                                        <form class="gm-action-form" method="post">
                                            <input type="hidden" name="action" value="set_status">
                                            <input type="hidden" name="group_id" value="<?= $selectedGroupId ?>">
                                            <input type="hidden" name="tab" value="people">
                                            <input type="hidden" name="person_id" value="<?= $personId ?>">
                                            <input type="hidden" name="new_status" value="<?= $isActive ? 'inactive' : 'active' ?>">
                                            <button class="btn btn-sm <?= $isActive ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit"><?= $isActive ? 'Make inactive' : 'Reactivate' ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$leaders): ?>
                            <tr><td colspan="5">No people are linked to this Group yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="lt-panel">
            <h2 class="lt-section-title">Merge duplicate people</h2>
            <p class="lt-lede">Use this only when the same person has been added twice, for example once with a personal email and later through Microsoft 365. Calendar events and Microsoft sign-in records will move to the retained person.</p>
            <form method="post" class="form-row align-items-end">
                <input type="hidden" name="action" value="merge_people">
                <input type="hidden" name="group_id" value="<?= $selectedGroupId ?>">
                <input type="hidden" name="tab" value="people">
                <div class="form-group col-md-5">
                    <label for="source_person_id">Duplicate to remove</label>
                    <select class="form-control" id="source_person_id" name="source_person_id" required>
                        <option value="">Choose person</option>
                        <?php foreach ($leaders as $leader): ?><option value="<?= (int) $leader['person_id'] ?>"><?= e($leader['full_name'] . ' — ' . $leader['primary_email']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-5">
                    <label for="target_person_id">Person to keep</label>
                    <select class="form-control" id="target_person_id" name="target_person_id" required>
                        <option value="">Choose person</option>
                        <?php foreach ($leaders as $leader): ?><option value="<?= (int) $leader['person_id'] ?>"><?= e($leader['full_name'] . ' — ' . $leader['primary_email']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <button class="btn btn-primary lt-btn btn-block" type="submit">Merge</button>
                </div>
            </form>
        </div>
    <?php elseif ($tab === 'add'): ?>
        <div class="gm-grid">
            <section class="lt-panel">
                <h2 class="lt-section-title">Add a person</h2>
                <p class="lt-lede">Prefer District Microsoft 365. It gives the leader normal SSO access to the Leader Tool and avoids shared links where possible.</p>

                <?php if ($duplicateCandidates): ?>
                    <div class="lt-panel-grey gm-duplicate mb-4">
                        <h3 class="h5 font-weight-bold">Possible duplicates</h3>
                        <p>Check these before creating a new person.</p>
                        <ul class="mb-0">
                            <?php foreach ($duplicateCandidates as $candidate): ?>
                                <li><strong><?= e($candidate['full_name']) ?></strong> — <?= e($candidate['primary_email']) ?><?= !empty($candidate['group_names']) ? ' — ' . e($candidate['group_names']) : '' ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="add_person">
                    <input type="hidden" name="group_id" value="<?= $selectedGroupId ?>">
                    <input type="hidden" name="tab" value="add">

                    <div class="form-group">
                        <label for="full_name">Full name</label>
                        <input class="form-control" type="text" id="full_name" name="full_name" value="<?= e($posted['full_name'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="primary_email">Personal or Scouting email</label>
                        <input class="form-control" type="email" id="primary_email" name="primary_email" value="<?= e($posted['primary_email'] ?? '') ?>" required>
                        <small class="form-text text-muted">This must be unique to the person. Avoid shared inboxes unless there is no alternative.</small>
                    </div>

                    <div class="form-group">
                        <label for="phone">Contact number</label>
                        <input class="form-control" type="text" id="phone" name="phone" value="<?= e($posted['phone'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="membership_role">Role</label>
                        <select class="form-control" id="membership_role" name="membership_role" required>
                            <?php $selectedRole = (string) ($posted['membership_role'] ?? 'section_leader'); ?>
                            <?php foreach ($roleOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $selectedRole === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="lt-divider"></div>

                    <h3 class="h5 font-weight-bold">District account preference</h3>
                    <label class="lt-check mb-3">
                        <input type="checkbox" name="request_district_email" value="1" <?= isset($posted['request_district_email']) ? 'checked' : '' ?>>
                        <span>Request a District Microsoft 365 email/account for this person</span>
                    </label>
                    <div class="form-group">
                        <label for="requested_district_email">Preferred District email, if known</label>
                        <input class="form-control" type="email" id="requested_district_email" name="requested_district_email" value="<?= e($posted['requested_district_email'] ?? '') ?>" placeholder="firstname.lastname@irvalscouts.org.uk">
                        <small class="form-text text-muted">Leave blank if the District admin team should allocate the address.</small>
                    </div>

                    <div class="lt-divider"></div>

                    <h3 class="h5 font-weight-bold">Directory visibility</h3>
                    <label class="lt-check">
                        <input type="checkbox" name="visible_in_directory" value="1" <?= array_key_exists('visible_in_directory', $posted) || !$posted ? 'checked' : '' ?>>
                        <span>Show this person in the District Directory</span>
                    </label>
                    <label class="lt-check mt-2">
                        <input type="checkbox" name="share_phone" value="1" <?= isset($posted['share_phone']) ? 'checked' : '' ?>>
                        <span>Show their phone number in the Directory</span>
                    </label>

                    <div class="form-group mt-3">
                        <label for="notes">Notes for District admins</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"><?= e($posted['notes'] ?? '') ?></textarea>
                    </div>

                    <?php if ($duplicateCandidates): ?>
                        <label class="lt-check mb-3">
                            <input type="checkbox" name="confirm_duplicate" value="1" required>
                            <span>I have checked the possible duplicates and this is a new person.</span>
                        </label>
                    <?php endif; ?>

                    <button class="btn btn-primary lt-btn" type="submit">Add person</button>
                </form>
            </section>

            <aside class="lt-panel-grey">
                <h2 class="lt-section-title">Recommended access route</h2>
                <ol class="pl-3 font-weight-bold">
                    <li>Request a District Microsoft 365 account wherever possible.</li>
                    <li>Ask the leader to sign in through the Microsoft SSO link.</li>
                    <li>Use the Group calendar link only as a fallback for people without SSO.</li>
                </ol>
                <p class="mb-0">When a person signs in with Microsoft 365, the app matches them by Microsoft identity first and then by email. If a duplicate appears, use the merge tool on the People tab.</p>
            </aside>
        </div>
    <?php elseif ($tab === 'links'): ?>
        <div class="lt-panel mb-4">
            <h2 class="lt-section-title">Group calendar access</h2>
            <p class="lt-lede">Use SSO wherever possible. The Group calendar link is a fallback bearer link for leaders who do not yet use District Microsoft 365.</p>

            <?php if ($groupLinks): ?>
                <div class="gm-card-list">
                    <?php foreach ($groupLinks as $link): ?>
                        <?php $url = gm_group_link_url($link); ?>
                        <div class="lt-panel-grey">
                            <h3 class="h5 font-weight-bold"><?= e($link['label'] ?? 'Group calendar link') ?></h3>
                            <?php if ($url): ?>
                                <div class="gm-link-box">
                                    <input class="form-control" type="text" value="<?= e($url) ?>" readonly>
                                    <button class="btn btn-secondary lt-btn gm-copy" type="button" data-copy="<?= e($url) ?>">Copy</button>
                                </div>
                                <p class="gm-muted mt-2 mb-0">Share only with leaders in <?= e($selectedGroup['group_name']) ?>. If it is compromised, ask a District admin to rotate it.</p>
                            <?php else: ?>
                                <div class="alert alert-warning mb-0">This active link has no visible token. A District admin needs to rotate or recreate it before GLVs can share it.</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-warning mb-0">No active Group calendar link is available for this Group.</div>
            <?php endif; ?>
        </div>
    <?php elseif ($tab === 'website'): ?>
        <div class="gm-coming-soon">
            <h2 class="lt-section-title">Group website details</h2>
            <p class="lt-lede">Coming soon. This tab will let Group Lead Volunteers update the Group details shown on the public District website.</p>
            <p class="mb-0">Planned fields include meeting place, section times, public contact details, waiting-list information and website display notes.</p>
        </div>
    <?php endif; ?>
</main>

<script>
document.querySelectorAll('.gm-copy').forEach(function (button) {
    button.addEventListener('click', function () {
        var value = button.getAttribute('data-copy') || '';
        if (!value) { return; }
        navigator.clipboard.writeText(value).then(function () {
            var original = button.textContent;
            button.textContent = 'Copied';
            window.setTimeout(function () { button.textContent = original; }, 1500);
        });
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
