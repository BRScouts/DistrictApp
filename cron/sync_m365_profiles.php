<?php

declare(strict_types=1);

/**
 * Cron: Sync Microsoft 365 user profiles (Movers & Leavers).
 *
 * For each active person with a linked M365 account, this script ensures
 * their Office 365 profile stays in sync with the Leader Tool:
 *
 *   - department  → group name (their primary/active group)
 *   - jobTitle    → membership role label (e.g. Section Leader)
 *   - manager     → the Group Lead Volunteer of their group
 *
 * Run manually:
 *   php /home/brscouts/app.irvalscouts.org.uk/cron/sync_m365_profiles.php
 *
 * Suggested cron (once daily, early morning):
 *   15 5 * * * /usr/local/bin/php /home/brscouts/app.irvalscouts.org.uk/cron/sync_m365_profiles.php >> /home/brscouts/app.irvalscouts.org.uk/storage/logs/sync-m365-profiles.log 2>&1
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (is_file(__DIR__ . '/../app/group-manager-helpers.php')) {
    require_once __DIR__ . '/../app/group-manager-helpers.php';
}

$pdo = db();

// ─── Helpers ────────────────────────────────────────────────────────────────

function sync_log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function sync_config(string $key, ?string $fallbackKey = null, string $default = ''): string
{
    if (defined($key)) {
        $value = (string) constant($key);
        if ($value !== '') {
            return $value;
        }
    }

    if (function_exists('app_config')) {
        $value = (string) app_config($key, '');
        if ($value !== '') {
            return $value;
        }
    }

    if ($fallbackKey !== null && defined($fallbackKey)) {
        $value = (string) constant($fallbackKey);
        if ($value !== '') {
            return $value;
        }
    }

    if ($fallbackKey !== null && function_exists('app_config')) {
        $value = (string) app_config($fallbackKey, '');
        if ($value !== '') {
            return $value;
        }
    }

    return $default;
}

function sync_table_exists(string $table): bool
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

function sync_column_exists(string $table, string $column): bool
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

// ─── Graph API ──────────────────────────────────────────────────────────────

function sync_graph_client(): \GuzzleHttp\Client
{
    return new \GuzzleHttp\Client([
        'timeout' => 30,
        'http_errors' => false,
    ]);
}

function sync_graph_token(\GuzzleHttp\Client $client): string
{
    $tenantId = sync_config('M365_GRAPH_TENANT_ID', 'MS_TENANT_ID');
    $clientId = sync_config('M365_GRAPH_CLIENT_ID', 'MS_CLIENT_ID');
    $clientSecret = sync_config('M365_GRAPH_CLIENT_SECRET', 'MS_CLIENT_SECRET');

    if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
        throw new RuntimeException('Microsoft Graph credentials are not configured.');
    }

    $response = $client->post('https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token', [
        'form_params' => [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ],
    ]);

    $status = $response->getStatusCode();
    $payload = json_decode((string) $response->getBody(), true) ?: [];

    if ($status < 200 || $status >= 300 || empty($payload['access_token'])) {
        $message = $payload['error_description'] ?? $payload['error'] ?? 'Unable to obtain Microsoft Graph token.';
        throw new RuntimeException((string) $message);
    }

    return (string) $payload['access_token'];
}

function sync_graph_request(\GuzzleHttp\Client $client, string $method, string $url, string $token, ?array $json = null): array
{
    $options = [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ],
    ];

    if ($json !== null) {
        $options['headers']['Content-Type'] = 'application/json';
        $options['json'] = $json;
    }

    $response = $client->request($method, $url, $options);
    $body = (string) $response->getBody();

    return [
        'status' => $response->getStatusCode(),
        'payload' => $body !== '' ? (json_decode($body, true) ?: []) : [],
        'body' => $body,
    ];
}

/**
 * Get the current M365 profile for a user so we only PATCH when something changed.
 */
function sync_graph_get_user(\GuzzleHttp\Client $client, string $token, string $userId): ?array
{
    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userId)
        . '?$select=id,displayName,userPrincipalName,department,jobTitle';

    $result = sync_graph_request($client, 'GET', $url, $token);

    if ((int) $result['status'] === 404) {
        return null;
    }

    if ((int) $result['status'] < 200 || (int) $result['status'] >= 300) {
        return null;
    }

    return $result['payload'];
}

/**
 * Get the current manager for a user (returns the manager's id or null).
 */
function sync_graph_get_manager(\GuzzleHttp\Client $client, string $token, string $userId): ?string
{
    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userId) . '/manager?$select=id';

    $result = sync_graph_request($client, 'GET', $url, $token);

    if ((int) $result['status'] === 404) {
        return null;
    }

    if ((int) $result['status'] < 200 || (int) $result['status'] >= 300) {
        return null;
    }

    return $result['payload']['id'] ?? null;
}

/**
 * Update user profile properties (department, jobTitle).
 */
function sync_graph_update_user(\GuzzleHttp\Client $client, string $token, string $userId, array $properties): bool
{
    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userId);

    $result = sync_graph_request($client, 'PATCH', $url, $token, $properties);

    return (int) $result['status'] >= 200 && (int) $result['status'] < 300;
}

/**
 * Set the manager for a user.
 */
function sync_graph_set_manager(\GuzzleHttp\Client $client, string $token, string $userId, string $managerId): bool
{
    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userId) . '/manager/$ref';

    $result = sync_graph_request($client, 'PUT', $url, $token, [
        '@odata.id' => 'https://graph.microsoft.com/v1.0/users/' . $managerId,
    ]);

    return (int) $result['status'] >= 200 && (int) $result['status'] < 300;
}

/**
 * Remove the manager assignment for a user.
 */
function sync_graph_remove_manager(\GuzzleHttp\Client $client, string $token, string $userId): bool
{
    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userId) . '/manager/$ref';

    $result = sync_graph_request($client, 'DELETE', $url, $token);

    // 204 No Content or 404 (already removed) are both acceptable.
    return (int) $result['status'] < 300 || (int) $result['status'] === 404;
}

// ─── Membership role → job title mapping ────────────────────────────────────

/**
 * Maps internal membership_role values to human-readable job titles for M365.
 *
 * These must match the values used in gm_membership_role_options() and
 * gm_district_role_options() from group-manager-helpers.php.
 */
function sync_role_to_job_title(string $membershipRole): string
{
    $map = [
        // Standard group roles
        'group_lead_volunteer' => 'Group Lead Volunteer',
        'section_leader' => 'Section Leader',
        'assistant_section_leader' => 'Assistant Section Leader',
        'section_assistant' => 'Section Assistant',
        'trustee' => 'Trustee',
        'district_volunteer' => 'District Volunteer',
        'group_link' => 'Volunteer',
        'other' => 'Volunteer',

        // District team roles (group 3)
        'district_lead_volunteer' => 'District Lead Volunteer',
        'district_youth_lead' => 'District Youth Lead',
        'district_leadership_team_member' => 'District Leadership Team Member',
        'district_14_24_team_leader' => 'District 14–24 Team Leader',
        'district_14_24_team_member' => 'District 14–24 Team Member',
        'explorer_section_team_leader' => 'Explorer Section Team Leader',
        'explorer_section_team_member' => 'Explorer Section Team Member',
        'young_leader_unit_team_leader' => 'Young Leader Unit Team Leader',
        'young_leader_unit_team_member' => 'Young Leader Unit Team Member',
        'scout_network_section_team_leader' => 'Scout Network Section Team Leader',
        'scout_network_section_team_member' => 'Scout Network Section Team Member',
        'district_programme_team_leader' => 'District Programme Team Leader',
        'district_programme_team_member' => 'District Programme Team Member',
        'district_volunteering_development_team_leader' => 'District Volunteering Development Team Leader',
        'district_volunteering_development_team_member' => 'District Volunteering Development Team Member',
        'district_support_team_leader' => 'District Support Team Leader',
        'district_support_team_member' => 'District Support Team Member',
        'district_sub_team_leader' => 'District Sub-team Leader',
        'district_chair' => 'District Chair',
        'district_treasurer' => 'District Treasurer',
        'district_trustee' => 'District Trustee',
        'co_opted_district_trustee' => 'Co-opted District Trustee',
        'district_president' => 'District President',
        'district_vice_president' => 'District Vice President',
    ];

    return $map[$membershipRole] ?? 'Volunteer';
}

// ─── District team GLV (manager for all GLVs) ───────────────────────────────

/**
 * The group/team ID whose Group Lead Volunteer acts as the manager for
 * all other Group Lead Volunteers in the district.
 */
define('SYNC_DISTRICT_TEAM_ID', 3);

/**
 * Resolve the M365 user ID of the GLV of the district team (group ID 3).
 * This person is set as the manager for all other GLVs.
 */
function sync_resolve_district_glv_m365_id(): ?string
{
    $source = sync_resolve_m365_id_source();

    if ($source === '' ) {
        return null;
    }

    $pdo = db();
    $districtTeamId = SYNC_DISTRICT_TEAM_ID;

    if ($source === 'user_accounts') {
        $stmt = $pdo->prepare("
            SELECT ua.provider_subject
            FROM group_memberships gm
            INNER JOIN people p ON p.id = gm.person_id AND p.status = 'active'
            INNER JOIN user_accounts ua
                ON ua.person_id = p.id
                AND ua.provider = 'microsoft'
                AND ua.provider_subject IS NOT NULL
                AND ua.provider_subject <> ''
            WHERE gm.group_id = :group_id
              AND gm.membership_role = 'group_lead_volunteer'
              AND gm.status = 'active'
            LIMIT 1
        ");
        $stmt->execute(['group_id' => $districtTeamId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? (string) $result : null;
    }

    if ($source === 'm365_account_requests') {
        $stmt = $pdo->prepare("
            SELECT mar.graph_user_id
            FROM group_memberships gm
            INNER JOIN people p ON p.id = gm.person_id AND p.status = 'active'
            INNER JOIN m365_account_requests mar
                ON mar.person_id = p.id
                AND mar.graph_user_id IS NOT NULL
                AND mar.graph_user_id <> ''
            WHERE gm.group_id = :group_id
              AND gm.membership_role = 'group_lead_volunteer'
              AND gm.status = 'active'
            LIMIT 1
        ");
        $stmt->execute(['group_id' => $districtTeamId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? (string) $result : null;
    }

    // people:<column> fallback
    $m365IdColumn = substr($source, strlen('people:'));
    $stmt = $pdo->prepare("
        SELECT p.`{$m365IdColumn}`
        FROM group_memberships gm
        INNER JOIN people p ON p.id = gm.person_id AND p.status = 'active'
        WHERE gm.group_id = :group_id
          AND gm.membership_role = 'group_lead_volunteer'
          AND gm.status = 'active'
          AND p.`{$m365IdColumn}` IS NOT NULL
          AND p.`{$m365IdColumn}` <> ''
        LIMIT 1
    ");
    $stmt->execute(['group_id' => $districtTeamId]);
    $result = $stmt->fetchColumn();
    return $result !== false ? (string) $result : null;
}

// ─── Fetch people to sync ───────────────────────────────────────────────────

/**
 * Resolve the M365 Graph user ID for a person.
 *
 * Checks (in order):
 *   1. user_accounts table (provider = 'microsoft', provider_subject = Graph ID)
 *   2. m365_account_requests table (graph_user_id)
 *   3. people table columns (m365_user_id, microsoft_user_id) — if they exist
 */
function sync_resolve_m365_id_source(): string
{
    // Prefer user_accounts — this is the canonical link for Microsoft sign-in.
    if (sync_table_exists('user_accounts')
        && sync_column_exists('user_accounts', 'provider')
        && sync_column_exists('user_accounts', 'provider_subject')
        && sync_column_exists('user_accounts', 'person_id')
    ) {
        return 'user_accounts';
    }

    // Fallback: m365_account_requests.
    if (sync_table_exists('m365_account_requests')
        && sync_column_exists('m365_account_requests', 'graph_user_id')
        && sync_column_exists('m365_account_requests', 'person_id')
    ) {
        return 'm365_account_requests';
    }

    // Last resort: columns on people table.
    foreach (['m365_user_id', 'microsoft_user_id'] as $candidate) {
        if (sync_column_exists('people', $candidate)) {
            return 'people:' . $candidate;
        }
    }

    return '';
}

/**
 * Get all active people who have a Microsoft 365 user ID,
 * along with their primary group membership and group lead volunteer info.
 */
function sync_fetch_people_to_sync(): array
{
    $pdo = db();
    $source = sync_resolve_m365_id_source();

    if ($source === '') {
        sync_log('ERROR: Could not find M365 user IDs. Need user_accounts, m365_account_requests, or people.m365_user_id column.');
        return [];
    }

    sync_log("Using M365 ID source: {$source}");

    // If is_primary column exists, prefer it for sorting (primary first).
    $hasPrimaryCol = sync_column_exists('group_memberships', 'is_primary');
    $primaryOrder = $hasPrimaryCol ? 'gm.is_primary DESC, ' : '';
    $roleFieldOrder = "FIELD(gm.membership_role, 'group_lead_volunteer', 'section_leader', 'assistant_section_leader', 'section_assistant', 'trustee', 'district_volunteer', 'other') ASC";

    if ($hasPrimaryCol) {
        sync_log('is_primary column detected — will prefer primary memberships.');
    }

    if ($source === 'user_accounts') {
        // Join through user_accounts where provider = 'microsoft'.
        // provider_subject holds the Graph object ID (GUID).
        $sql = "
            SELECT
                p.id AS person_id,
                p.full_name,
                ua.provider_subject AS m365_user_id,
                gm.membership_role,
                gm.group_id,
                g.group_name,
                glv_ua.provider_subject AS glv_m365_id
            FROM people p
            INNER JOIN user_accounts ua
                ON ua.person_id = p.id
                AND ua.provider = 'microsoft'
                AND ua.provider_subject IS NOT NULL
                AND ua.provider_subject <> ''
            INNER JOIN group_memberships gm
                ON gm.person_id = p.id
                AND gm.status = 'active'
            INNER JOIN groups g
                ON g.id = gm.group_id
                AND g.is_active = 1
            LEFT JOIN (
                -- Find the GLV for each group and their M365 ID via user_accounts
                SELECT
                    glv_gm.group_id,
                    glv_ua_inner.provider_subject
                FROM group_memberships glv_gm
                INNER JOIN people glv_p ON glv_p.id = glv_gm.person_id AND glv_p.status = 'active'
                INNER JOIN user_accounts glv_ua_inner
                    ON glv_ua_inner.person_id = glv_p.id
                    AND glv_ua_inner.provider = 'microsoft'
                    AND glv_ua_inner.provider_subject IS NOT NULL
                    AND glv_ua_inner.provider_subject <> ''
                WHERE glv_gm.membership_role = 'group_lead_volunteer'
                  AND glv_gm.status = 'active'
                GROUP BY glv_gm.group_id
            ) glv_ua ON glv_ua.group_id = gm.group_id
            WHERE p.status = 'active'
            ORDER BY
                p.id ASC,
                {$primaryOrder}
                {$roleFieldOrder}
        ";
    } elseif ($source === 'm365_account_requests') {
        $sql = "
            SELECT
                p.id AS person_id,
                p.full_name,
                mar.graph_user_id AS m365_user_id,
                gm.membership_role,
                gm.group_id,
                g.group_name,
                glv_mar.graph_user_id AS glv_m365_id
            FROM people p
            INNER JOIN m365_account_requests mar
                ON mar.person_id = p.id
                AND mar.graph_user_id IS NOT NULL
                AND mar.graph_user_id <> ''
            INNER JOIN group_memberships gm
                ON gm.person_id = p.id
                AND gm.status = 'active'
            INNER JOIN groups g
                ON g.id = gm.group_id
                AND g.is_active = 1
            LEFT JOIN (
                SELECT
                    glv_gm.group_id,
                    glv_mar_inner.graph_user_id
                FROM group_memberships glv_gm
                INNER JOIN people glv_p ON glv_p.id = glv_gm.person_id AND glv_p.status = 'active'
                INNER JOIN m365_account_requests glv_mar_inner
                    ON glv_mar_inner.person_id = glv_p.id
                    AND glv_mar_inner.graph_user_id IS NOT NULL
                    AND glv_mar_inner.graph_user_id <> ''
                WHERE glv_gm.membership_role = 'group_lead_volunteer'
                  AND glv_gm.status = 'active'
                GROUP BY glv_gm.group_id
            ) glv_mar ON glv_mar.group_id = gm.group_id
            WHERE p.status = 'active'
            ORDER BY
                p.id ASC,
                {$primaryOrder}
                {$roleFieldOrder}
        ";
    } else {
        // people:<column> fallback
        $m365IdColumn = substr($source, strlen('people:'));
        $sql = "
            SELECT
                p.id AS person_id,
                p.full_name,
                p.`{$m365IdColumn}` AS m365_user_id,
                gm.membership_role,
                gm.group_id,
                g.group_name,
                glv_sub.m365_id AS glv_m365_id
            FROM people p
            INNER JOIN group_memberships gm
                ON gm.person_id = p.id
                AND gm.status = 'active'
            INNER JOIN groups g
                ON g.id = gm.group_id
                AND g.is_active = 1
            LEFT JOIN (
                SELECT
                    glv_gm.group_id,
                    p2.`{$m365IdColumn}` AS m365_id
                FROM group_memberships glv_gm
                INNER JOIN people p2 ON p2.id = glv_gm.person_id AND p2.status = 'active'
                WHERE glv_gm.membership_role = 'group_lead_volunteer'
                  AND glv_gm.status = 'active'
                  AND p2.`{$m365IdColumn}` IS NOT NULL
                  AND p2.`{$m365IdColumn}` <> ''
                GROUP BY glv_gm.group_id
            ) glv_sub ON glv_sub.group_id = gm.group_id
            WHERE p.status = 'active'
              AND p.`{$m365IdColumn}` IS NOT NULL
              AND p.`{$m365IdColumn}` <> ''
            ORDER BY
                p.id ASC,
                {$primaryOrder}
                {$roleFieldOrder}
        ";
    }

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // De-duplicate: keep only the first (highest priority) membership per person.
    // The SQL ORDER BY ensures the best membership is selected:
    //   1. is_primary = 1 (explicitly set by district admin, if column exists)
    //   2. Role priority via FIELD():
    //      GLV > Section Leader > ASL > Section Assistant > Trustee > District Volunteer > Other
    // This means if a district admin has set a primary, that wins. Otherwise,
    // the highest-ranking role is used for M365 job title and department.
    $people = [];
    foreach ($rows as $row) {
        $personId = (int) $row['person_id'];
        if (!isset($people[$personId])) {
            $people[$personId] = $row;
        }
    }

    return array_values($people);
}

// ─── Main execution ─────────────────────────────────────────────────────────

sync_log('=== Microsoft 365 Profile Sync started ===');

try {
    $client = sync_graph_client();
    $token = sync_graph_token($client);
} catch (Throwable $e) {
    sync_log('FATAL: Could not obtain Graph token: ' . $e->getMessage());
    exit(1);
}

$people = sync_fetch_people_to_sync();
$total = count($people);

sync_log("Found {$total} active people with M365 accounts to check.");

if ($total === 0) {
    sync_log('Nothing to do.');
    exit(0);
}

// Resolve the district team GLV — this person manages all other GLVs.
$districtGlvM365Id = sync_resolve_district_glv_m365_id();

if ($districtGlvM365Id !== null) {
    sync_log("District team (group " . SYNC_DISTRICT_TEAM_ID . ") GLV M365 ID: {$districtGlvM365Id}");
} else {
    sync_log("WARNING: Could not resolve district team GLV (group " . SYNC_DISTRICT_TEAM_ID . "). GLVs will have no manager set.");
}

$updated = 0;
$skipped = 0;
$errors = 0;

foreach ($people as $person) {
    $personId = (int) $person['person_id'];
    $m365UserId = (string) $person['m365_user_id'];
    $fullName = (string) $person['full_name'];
    $groupName = (string) $person['group_name'];
    $membershipRole = (string) $person['membership_role'];
    $glvM365Id = $person['glv_m365_id'] !== null ? (string) $person['glv_m365_id'] : null;

    $desiredDepartment = $groupName;
    $desiredJobTitle = sync_role_to_job_title($membershipRole);

    // Manager logic:
    // - Regular volunteers → managed by their group's GLV
    // - GLVs → managed by the district team (group 3) GLV
    // - Never set someone as their own manager
    if ($membershipRole === 'group_lead_volunteer') {
        // GLVs are managed by the district team GLV, not themselves.
        $desiredManagerId = $districtGlvM365Id;
    } else {
        $desiredManagerId = $glvM365Id;
    }

    // Safety: never set someone as their own manager.
    if ($desiredManagerId === $m365UserId) {
        $desiredManagerId = null;
    }

    try {
        // Fetch current Graph profile.
        $currentProfile = sync_graph_get_user($client, $token, $m365UserId);

        if ($currentProfile === null) {
            sync_log("  SKIP [{$fullName}]: M365 user not found in Graph (ID: {$m365UserId}).");
            $skipped++;
            continue;
        }

        $currentDepartment = (string) ($currentProfile['department'] ?? '');
        $currentJobTitle = (string) ($currentProfile['jobTitle'] ?? '');

        // Determine what needs updating on the user object.
        $patch = [];

        if ($currentDepartment !== $desiredDepartment) {
            $patch['department'] = $desiredDepartment;
        }

        if ($currentJobTitle !== $desiredJobTitle) {
            $patch['jobTitle'] = $desiredJobTitle;
        }

        // Apply profile patch if needed.
        if ($patch) {
            $success = sync_graph_update_user($client, $token, $m365UserId, $patch);
            if (!$success) {
                sync_log("  ERROR [{$fullName}]: Failed to update profile properties.");
                $errors++;
                continue;
            }
            sync_log("  UPDATED [{$fullName}]: " . implode(', ', array_map(
                fn($k, $v) => "{$k} = \"{$v}\"",
                array_keys($patch),
                array_values($patch)
            )));
        }

        // Check and update manager.
        $currentManagerId = sync_graph_get_manager($client, $token, $m365UserId);

        if ($desiredManagerId !== null && $currentManagerId !== $desiredManagerId) {
            $managerSet = sync_graph_set_manager($client, $token, $m365UserId, $desiredManagerId);
            if ($managerSet) {
                $managerLabel = $membershipRole === 'group_lead_volunteer' ? 'district GLV' : 'group GLV';
                sync_log("  UPDATED [{$fullName}]: manager set to {$managerLabel} (ID: {$desiredManagerId}).");
            } else {
                sync_log("  WARNING [{$fullName}]: Could not set manager.");
            }
        } elseif ($desiredManagerId === null && $currentManagerId !== null) {
            // No suitable manager available — remove stale manager.
            sync_graph_remove_manager($client, $token, $m365UserId);
            sync_log("  UPDATED [{$fullName}]: manager removed (no suitable manager available).");
        }

        if ($patch || ($desiredManagerId !== null && $currentManagerId !== $desiredManagerId)) {
            $updated++;
        } else {
            $skipped++;
        }
    } catch (Throwable $e) {
        sync_log("  ERROR [{$fullName}]: " . $e->getMessage());
        $errors++;
    }

    // Brief pause to respect Graph API rate limits.
    usleep(200000); // 200ms
}

sync_log("=== Sync complete: {$updated} updated, {$skipped} unchanged, {$errors} errors ===");
