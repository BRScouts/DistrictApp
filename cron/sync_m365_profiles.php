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

function sync_role_to_job_title(string $membershipRole): string
{
    $map = [
        'group_lead_volunteer' => 'Group Lead Volunteer',
        'section_leader' => 'Section Leader',
        'assistant_section_leader' => 'Assistant Section Leader',
        'section_assistant' => 'Section Assistant',
        'trustee' => 'Trustee',
        'district_volunteer' => 'District Volunteer',
        'other' => 'Volunteer',
    ];

    return $map[$membershipRole] ?? 'Volunteer';
}

// ─── Fetch people to sync ───────────────────────────────────────────────────

/**
 * Get all active people who have a Microsoft 365 user ID stored,
 * along with their primary group membership and group lead volunteer info.
 */
function sync_fetch_people_to_sync(): array
{
    $pdo = db();

    // Determine which column stores the M365 user ID on the people table.
    $m365IdColumn = null;
    foreach (['m365_user_id', 'microsoft_user_id'] as $candidate) {
        if (sync_column_exists('people', $candidate)) {
            $m365IdColumn = $candidate;
            break;
        }
    }

    if ($m365IdColumn === null) {
        sync_log('ERROR: No m365_user_id or microsoft_user_id column found on people table.');
        return [];
    }

    // Fetch active people with an M365 account along with their primary active membership.
    // We pick the first active membership ordered by role priority (GLV first) as the "primary".
    $sql = "
        SELECT
            p.id AS person_id,
            p.full_name,
            p.`{$m365IdColumn}` AS m365_user_id,
            gm.membership_role,
            gm.group_id,
            g.group_name,
            glv_ua_subject.m365_id AS glv_m365_id
        FROM people p
        INNER JOIN group_memberships gm
            ON gm.person_id = p.id
            AND gm.status = 'active'
        INNER JOIN groups g
            ON g.id = gm.group_id
            AND g.is_active = 1
        LEFT JOIN (
            -- Find the Group Lead Volunteer for each group and their M365 ID
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
        ) glv_ua_subject ON glv_ua_subject.group_id = gm.group_id
        WHERE p.status = 'active'
          AND p.`{$m365IdColumn}` IS NOT NULL
          AND p.`{$m365IdColumn}` <> ''
        ORDER BY
            p.id ASC,
            FIELD(gm.membership_role, 'group_lead_volunteer', 'section_leader', 'assistant_section_leader', 'section_assistant', 'trustee', 'district_volunteer', 'other') ASC
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // De-duplicate: keep only the first (highest priority) membership per person.
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

    // Don't set a person as their own manager.
    if ($glvM365Id === $m365UserId) {
        $glvM365Id = null;
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

        if ($glvM365Id !== null && $currentManagerId !== $glvM365Id) {
            $managerSet = sync_graph_set_manager($client, $token, $m365UserId, $glvM365Id);
            if ($managerSet) {
                sync_log("  UPDATED [{$fullName}]: manager set to GLV (ID: {$glvM365Id}).");
            } else {
                sync_log("  WARNING [{$fullName}]: Could not set manager.");
            }
        } elseif ($glvM365Id === null && $currentManagerId !== null) {
            // No GLV with M365 account for this group — remove stale manager.
            sync_graph_remove_manager($client, $token, $m365UserId);
            sync_log("  UPDATED [{$fullName}]: manager removed (no GLV with M365 in group).");
        }

        if ($patch || ($glvM365Id !== null && $currentManagerId !== $glvM365Id)) {
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
