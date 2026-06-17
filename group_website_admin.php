<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

if (function_exists('user_needs_group_onboarding') && user_needs_group_onboarding()) {
    redirect('/onboarding.php');
}

$pdo = db();
$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

/**
 * -------------------------------------------------------------------------
 * Leader Tool helpers
 * -------------------------------------------------------------------------
 */

function gwa_table_exists(string $table): bool
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

function gwa_column_exists(string $table, string $column): bool
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

function gwa_table_columns(string $table): array
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

function gwa_update_flexible(string $table, string $idColumn, int $id, array $values): bool
{
    if (!gwa_table_exists($table) || !gwa_column_exists($table, $idColumn)) {
        return false;
    }

    $columns = gwa_table_columns($table);
    $update = [];

    foreach ($values as $column => $value) {
        if ($column !== $idColumn && in_array((string) $column, $columns, true)) {
            $update[(string) $column] = $value;
        }
    }

    if (!$update) {
        return false;
    }

    $sets = array_map(
        static fn(string $column): string => '`' . str_replace('`', '``', $column) . '` = :' . $column,
        array_keys($update)
    );

    $update['_id'] = $id;

    $stmt = db()->prepare(
        'UPDATE `' . str_replace('`', '``', $table) . '` SET ' .
        implode(', ', $sets) .
        ' WHERE `' . str_replace('`', '``', $idColumn) . '` = :_id'
    );

    return $stmt->execute($update);
}

function gwa_actor_is_district_admin(array $user, array $memberships): bool
{
    $levels = [(string) ($user['highest_access_level'] ?? $user['role'] ?? 'member')];

    foreach ($memberships as $membership) {
        $levels[] = (string) ($membership['access_level'] ?? 'member');
    }

    return (bool) array_intersect(array_unique($levels), ['district_admin', 'system_admin']);
}

function gwa_manageable_groups(int $personId, bool $isDistrictAdmin): array
{
    if ($isDistrictAdmin) {
        $stmt = db()->query("
            SELECT *
            FROM groups
            WHERE is_active = 1
            ORDER BY group_name ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = db()->prepare("
        SELECT DISTINCT g.*
        FROM group_memberships gm
        JOIN groups g ON g.id = gm.group_id
        WHERE gm.person_id = :person_id
          AND gm.status = 'active'
          AND g.is_active = 1
          AND (
              gm.membership_role = 'group_lead_volunteer'
              OR gm.access_level = 'group_admin'
          )
        ORDER BY g.group_name ASC
    ");
    $stmt->execute(['person_id' => $personId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function gwa_group_is_manageable(int $groupId, array $groups): bool
{
    foreach ($groups as $group) {
        if ((int) $group['id'] === $groupId) {
            return true;
        }
    }

    return false;
}

function gwa_fetch_group(int $groupId): ?array
{
    $stmt = db()->prepare("
        SELECT *
        FROM groups
        WHERE id = :group_id
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute(['group_id' => $groupId]);

    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    return $group ?: null;
}

function gwa_fetch_group_lead_volunteers(int $groupId): array
{
    if (!gwa_table_exists('group_memberships') || !gwa_table_exists('people')) {
        return [];
    }

    $stmt = db()->prepare("
        SELECT
            p.id,
            p.full_name,
            p.primary_email,
            p.phone
        FROM group_memberships gm
        JOIN people p ON p.id = gm.person_id
        WHERE gm.group_id = :group_id
          AND gm.status = 'active'
          AND p.status = 'active'
          AND (
              gm.membership_role = 'group_lead_volunteer'
              OR gm.access_level = 'group_admin'
          )
        ORDER BY p.full_name ASC
    ");
    $stmt->execute(['group_id' => $groupId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function gwa_log_action(int $actorPersonId, string $action, string $entityType, int $entityId, array $details = []): void
{
    if (!gwa_table_exists('audit_log')) {
        return;
    }

    try {
        $stmt = db()->prepare("
            INSERT INTO audit_log (
                actor_type,
                actor_person_id,
                action,
                entity_type,
                entity_id,
                details_json,
                created_at
            )
            VALUES (
                'person',
                :actor_person_id,
                :action,
                :entity_type,
                :entity_id,
                :details_json,
                NOW()
            )
        ");
        $stmt->execute([
            'actor_person_id' => $actorPersonId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        // Audit logging must not block a website update.
    }
}


function gwa_actor_summary(int $actorPersonId): array
{
    if ($actorPersonId < 1 || !gwa_table_exists('people')) {
        return [
            'name' => 'Unknown user',
            'email' => '',
        ];
    }

    try {
        $stmt = db()->prepare("\n            SELECT full_name, primary_email\n            FROM people\n            WHERE id = :person_id\n            LIMIT 1\n        ");
        $stmt->execute(['person_id' => $actorPersonId]);
        $person = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'name' => trim((string) ($person['full_name'] ?? '')) ?: 'Unknown user',
            'email' => trim((string) ($person['primary_email'] ?? '')),
        ];
    } catch (Throwable $e) {
        return [
            'name' => 'Unknown user',
            'email' => '',
        ];
    }
}

function gwa_website_log_labels(): array
{
    return [
        'post_title' => 'Public Group name',
        'post_name' => 'Page slug',
        'post_content' => 'About this Group',
        'featured_image_id' => 'Photo',
        'wpsl_address' => 'Address line 1',
        'wpsl_address2' => 'Address line 2',
        'wpsl_city' => 'Town / city',
        'wpsl_state' => 'County',
        'wpsl_zip' => 'Postcode',
        'wpsl_country' => 'Country',
        'wpsl_country_iso' => 'Country code',
        'wpsl_lat' => 'Map latitude',
        'wpsl_lng' => 'Map longitude',
        'wpsl_email' => 'Public email',
        'wpsl_phone' => 'Public phone',
        'wpsl_url' => 'Store Locator URL',
        'wpsl_group_website' => 'Group website',
        'wpsl_group_contact' => 'Primary contact',
        'wpsl_group_type' => 'Group type',
        'wpsl_section_details' => 'Section meeting times',
        'wpsl_section_scarf' => 'Group scarf / necker',
    ];
}

function gwa_log_value(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_array($value) || is_object($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    $value = trim((string) $value);

    if ($value === '[]' || $value === '{}') {
        return '';
    }

    return $value;
}

function gwa_normalise_lat_lng(?string $lat, ?string $lng): array
{
    $lat = trim((string) $lat);
    $lng = trim((string) $lng);

    if ($lat === '' || $lng === '') {
        return [null, null];
    }

    if (!is_numeric($lat) || !is_numeric($lng)) {
        throw new RuntimeException('The map pin location is not valid. Click the map again and save.');
    }

    $latFloat = (float) $lat;
    $lngFloat = (float) $lng;

    if ($latFloat < -90 || $latFloat > 90 || $lngFloat < -180 || $lngFloat > 180) {
        throw new RuntimeException('The map pin location is outside the valid latitude/longitude range.');
    }

    return [
        number_format($latFloat, 7, '.', ''),
        number_format($lngFloat, 7, '.', ''),
    ];
}

function gwa_website_snapshot(int $postId): array
{
    if ($postId < 1) {
        return [];
    }

    gwa_wp_bootstrap();

    $post = get_post($postId);

    if (!$post instanceof WP_Post) {
        return [];
    }

    $meta = gwa_wp_fetch_meta($postId);
    $labels = gwa_website_log_labels();

    $snapshot = [
        'post_title' => (string) $post->post_title,
        'post_name' => (string) $post->post_name,
        'post_content' => (string) $post->post_content,
        'featured_image_id' => function_exists('get_post_thumbnail_id') ? (string) ((int) get_post_thumbnail_id($postId)) : '',
    ];

    foreach ($labels as $key => $label) {
        if (array_key_exists($key, $snapshot)) {
            continue;
        }

        $snapshot[$key] = $meta[$key] ?? '';
    }

    return $snapshot;
}

function gwa_log_website_update(int $groupId, int $postId, int $actorPersonId, string $action, array $before, array $after): array
{
    if (!gwa_table_exists('group_website_update_log')) {
        return [];
    }

    $labels = gwa_website_log_labels();
    $changes = [];

    foreach ($labels as $key => $label) {
        $beforeValue = gwa_log_value($before[$key] ?? '');
        $afterValue = gwa_log_value($after[$key] ?? '');

        if ($beforeValue === $afterValue) {
            continue;
        }

        $changes[$key] = [
            'label' => $label,
            'before' => $beforeValue,
            'after' => $afterValue,
        ];
    }

    if (!$changes) {
        return [];
    }

    $actor = gwa_actor_summary($actorPersonId);
    $summary = count($changes) . ' field' . (count($changes) === 1 ? '' : 's') . ' changed';

    try {
        $stmt = db()->prepare("\n            INSERT INTO group_website_update_log (\n                group_id,\n                website_post_id,\n                actor_person_id,\n                actor_name,\n                actor_email,\n                action,\n                summary,\n                changed_fields_json,\n                before_json,\n                after_json,\n                created_at\n            )\n            VALUES (\n                :group_id,\n                :website_post_id,\n                :actor_person_id,\n                :actor_name,\n                :actor_email,\n                :action,\n                :summary,\n                :changed_fields_json,\n                :before_json,\n                :after_json,\n                NOW()\n            )\n        ");

        $stmt->execute([
            'group_id' => $groupId,
            'website_post_id' => $postId,
            'actor_person_id' => $actorPersonId,
            'actor_name' => $actor['name'],
            'actor_email' => $actor['email'],
            'action' => $action,
            'summary' => $summary,
            'changed_fields_json' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'before_json' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'after_json' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        // Website update logging must not block saving.
    }

    return $changes;
}

function gwa_log_website_event(int $groupId, int $postId, int $actorPersonId, string $action, string $summary, array $details = []): void
{
    if (!gwa_table_exists('group_website_update_log')) {
        return;
    }

    $actor = gwa_actor_summary($actorPersonId);

    try {
        $stmt = db()->prepare("\n            INSERT INTO group_website_update_log (\n                group_id,\n                website_post_id,\n                actor_person_id,\n                actor_name,\n                actor_email,\n                action,\n                summary,\n                changed_fields_json,\n                before_json,\n                after_json,\n                created_at\n            )\n            VALUES (\n                :group_id,\n                :website_post_id,\n                :actor_person_id,\n                :actor_name,\n                :actor_email,\n                :action,\n                :summary,\n                :changed_fields_json,\n                NULL,\n                NULL,\n                NOW()\n            )\n        ");

        $stmt->execute([
            'group_id' => $groupId,
            'website_post_id' => $postId > 0 ? $postId : null,
            'actor_person_id' => $actorPersonId,
            'actor_name' => $actor['name'],
            'actor_email' => $actor['email'],
            'action' => $action,
            'summary' => $summary,
            'changed_fields_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        // Website update logging must not block saving.
    }
}

function gwa_fetch_website_updates(int $groupId, int $limit = 25): array
{
    if ($groupId < 1 || !gwa_table_exists('group_website_update_log')) {
        return [];
    }

    $limit = max(1, min(100, $limit));

    try {
        $stmt = db()->prepare("\n            SELECT\n                id,\n                group_id,\n                website_post_id,\n                actor_person_id,\n                actor_name,\n                actor_email,\n                action,\n                summary,\n                changed_fields_json,\n                created_at\n            FROM group_website_update_log\n            WHERE group_id = :group_id\n            ORDER BY created_at DESC, id DESC\n            LIMIT {$limit}\n        ");
        $stmt->execute(['group_id' => $groupId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function gwa_decode_update_changes(?string $json): array
{
    if (!$json) {
        return [];
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

function gwa_config_first(array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
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
    }

    return $default;
}

function gwa_slugify(string $value): string
{
    $value = trim($value);
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'group';
}

/**
 * -------------------------------------------------------------------------
 * WordPress bootstrap/helpers
 * -------------------------------------------------------------------------
 */

function gwa_wp_path(): string
{
    return rtrim(gwa_config_first(['WORDPRESS_PATH', 'WP_PATH'], ''), '/');
}

function gwa_wp_site_url(): string
{
    return rtrim(gwa_config_first(['WORDPRESS_SITE_URL', 'WP_SITE_URL'], 'https://irvalscouts.org.uk'), '/');
}

function gwa_wp_bootstrap(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $reservedConstants = [
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'DB_PASS',
        'DB_HOST',
        'DB_CHARSET',
        'DB_COLLATE',
        'ABSPATH',
        'WPINC',
    ];

    $alreadyDefined = [];

    foreach ($reservedConstants as $constant) {
        if (defined($constant)) {
            $alreadyDefined[] = $constant;
        }
    }

    if ($alreadyDefined) {
        throw new RuntimeException(
            'WordPress cannot be safely loaded because these WordPress-reserved constants are already defined by the Leader Tool bootstrap: ' .
            implode(', ', $alreadyDefined) .
            '. Rename the Leader Tool database constants to APP_DB_HOST, APP_DB_NAME, APP_DB_USER, APP_DB_PASS and APP_DB_CHARSET.'
        );
    }

    $path = gwa_wp_path();

    if ($path === '') {
        throw new RuntimeException('WordPress path is not configured. Add WORDPRESS_PATH to the Leader Tool config.');
    }

    $wpLoad = $path . '/wp-load.php';

    if (!is_file($wpLoad)) {
        throw new RuntimeException('WordPress could not be loaded. wp-load.php was not found at: ' . $wpLoad);
    }

    if (!defined('WP_USE_THEMES')) {
        define('WP_USE_THEMES', false);
    }

    require_once $wpLoad;

    if (!function_exists('wp_insert_post') || !function_exists('update_post_meta')) {
        throw new RuntimeException('WordPress loaded, but the expected WordPress functions are unavailable.');
    }

    $loaded = true;
}

function gwa_wp_post_exists(int $postId): bool
{
    if ($postId < 1) {
        return false;
    }

    gwa_wp_bootstrap();

    $post = get_post($postId);

    return $post instanceof WP_Post && $post->post_type === 'wpsl_stores';
}

function gwa_wp_fetch_post(int $postId): ?array
{
    if ($postId < 1) {
        return null;
    }

    gwa_wp_bootstrap();

    $post = get_post($postId);

    if (!$post instanceof WP_Post || $post->post_type !== 'wpsl_stores') {
        return null;
    }

    return [
        'ID' => (int) $post->ID,
        'post_title' => (string) $post->post_title,
        'post_name' => (string) $post->post_name,
        'post_content' => (string) $post->post_content,
        'post_status' => (string) $post->post_status,
        'post_type' => (string) $post->post_type,
        'post_date' => (string) $post->post_date,
        'post_modified' => (string) $post->post_modified,
    ];
}

function gwa_wp_fetch_meta(int $postId): array
{
    if ($postId < 1) {
        return [];
    }

    gwa_wp_bootstrap();

    $allMeta = get_post_meta($postId);
    $meta = [];

    foreach ($allMeta as $key => $values) {
        $meta[(string) $key] = isset($values[0]) ? maybe_unserialize($values[0]) : '';
    }

    return $meta;
}

function gwa_wp_store_permalink(int $postId): string
{
    gwa_wp_bootstrap();

    $url = get_permalink($postId);

    if (is_string($url) && $url !== '') {
        return $url;
    }

    $post = get_post($postId);
    $slug = $post instanceof WP_Post ? $post->post_name : (string) $postId;

    return gwa_wp_site_url() . '/stores/' . rawurlencode($slug) . '/';
}

function gwa_wp_thumbnail_url(int $postId): string
{
    gwa_wp_bootstrap();

    $url = get_the_post_thumbnail_url($postId, 'medium_large');

    return is_string($url) ? $url : '';
}

/**
 * -------------------------------------------------------------------------
 * Store Locator field handling
 * -------------------------------------------------------------------------
 */

function gwa_section_example_json(): string
{
    return json_encode([
        [
            'day' => '1',
            'type' => '1',
            'time_start' => '72',
            'time_finish' => '76',
            'name' => 'Beaver Scouts',
            'key' => '0',
        ],
        [
            'day' => '1',
            'type' => '2',
            'time_start' => '76',
            'time_finish' => '82',
            'name' => 'Cub Scouts',
            'key' => '1',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function gwa_normalise_section_json(string $rawJson): string
{
    $rawJson = trim($rawJson);

    if ($rawJson === '') {
        return '[]';
    }

    $decoded = json_decode($rawJson, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('The section meetings could not be saved. Refresh the page and try again.');
    }

    $normalised = [];
    $index = 0;

    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }

        $normalised[] = [
            'day' => (string) ($row['day'] ?? '0'),
            'type' => (string) ($row['type'] ?? '0'),
            'time_start' => (string) ($row['time_start'] ?? '0'),
            'time_finish' => (string) ($row['time_finish'] ?? '0'),
            'name' => trim((string) ($row['name'] ?? '')),
            'key' => (string) $index,
        ];

        $index++;
    }

    return json_encode($normalised, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function gwa_normalise_scarf_json(string $rawJson): string
{
    $rawJson = trim($rawJson);

    if ($rawJson === '') {
        return json_encode([
            'scarf_type' => '0',
            'l' => '#39774e',
            'r' => '#39774e',
            'b1l' => '#000000',
            'b1r' => '#000000',
            'b2l' => '#ffffff',
            'b2r' => '#ffffff',
            'b3l' => '#000000',
            'b3r' => '#000000',
            's' => '#ffffff',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $decoded = json_decode($rawJson, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('The scarf colours could not be saved. Refresh the page and try again.');
    }

    $keys = ['scarf_type', 'l', 'r', 'b1l', 'b1r', 'b2l', 'b2r', 'b3l', 'b3r', 's'];
    $normalised = [];

    foreach ($keys as $key) {
        $normalised[$key] = (string) ($decoded[$key] ?? '');
    }

    if ($normalised['scarf_type'] === '') {
        $normalised['scarf_type'] = '0';
    }

    return json_encode($normalised, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function gwa_update_group_linked_post_id(int $groupId, int $postId): void
{
    if (!gwa_column_exists('groups', 'website_post_id')) {
        throw new RuntimeException('The groups.website_post_id column does not exist. Apply the migration first.');
    }

    gwa_update_flexible('groups', 'id', $groupId, [
        'website_post_id' => $postId,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function gwa_link_existing_wordpress_post(int $groupId, int $postId, int $actorPersonId): void
{
    if ($postId < 1) {
        throw new RuntimeException('Enter a valid WordPress post ID.');
    }

    if (!gwa_wp_post_exists($postId)) {
        throw new RuntimeException('That WordPress post ID does not exist or is not a WP Store Locator post.');
    }

    gwa_update_group_linked_post_id($groupId, $postId);

    gwa_log_website_event($groupId, $postId, $actorPersonId, 'group_website_post_linked', 'Website post linked to Group', [
        'website_post_id' => $postId,
    ]);

    gwa_log_action($actorPersonId, 'group_website_post_linked', 'group', $groupId, [
        'website_post_id' => $postId,
    ]);
}

function gwa_create_wordpress_store_for_group(array $group, int $actorPersonId): int
{
    gwa_wp_bootstrap();

    $title = trim((string) ($group['group_name'] ?? 'New Scout Group'));

    if ($title === '') {
        $title = 'New Scout Group';
    }

    $slug = gwa_slugify((string) ($group['slug'] ?? $title));

    $postId = wp_insert_post([
        'post_type' => 'wpsl_stores',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => '',
        'post_author' => 1,
        'comment_status' => 'closed',
        'ping_status' => 'closed',
    ], true);

    if (is_wp_error($postId)) {
        throw new RuntimeException('WordPress could not create the Scout Group post: ' . $postId->get_error_message());
    }

    $postId = (int) $postId;

    $websiteUrl = (string) ($group['website_url'] ?? '');
    $publicEmail = (string) ($group['public_email'] ?? $group['contact_email'] ?? '');
    $meetingPlace = (string) ($group['meeting_place'] ?? '');
    $postcode = (string) ($group['postcode'] ?? '');

    update_post_meta($postId, 'wpsl_address', $meetingPlace);
    update_post_meta($postId, 'wpsl_zip', $postcode);
    update_post_meta($postId, 'wpsl_email', $publicEmail);
    update_post_meta($postId, 'wpsl_url', $websiteUrl);

    if (!empty($group['latitude']) && !empty($group['longitude'])) {
        [$lat, $lng] = gwa_normalise_lat_lng((string) $group['latitude'], (string) $group['longitude']);

        if ($lat !== null && $lng !== null) {
            update_post_meta($postId, 'wpsl_lat', $lat);
            update_post_meta($postId, 'wpsl_lng', $lng);
            update_post_meta($postId, 'wpsl_latlng', $lat . ',' . $lng);
        }
    }

    update_post_meta($postId, 'wpsl_group_website', $websiteUrl);
    update_post_meta($postId, 'wpsl_group_contact', $publicEmail);
    update_post_meta($postId, 'wpsl_group_type', '0');
    update_post_meta($postId, 'wpsl_section_details', '[]');
    update_post_meta($postId, 'wpsl_section_scarf', gwa_normalise_scarf_json(''));

    gwa_update_group_linked_post_id((int) $group['id'], $postId);

    do_action('irval_leader_tool_group_store_created', $postId, $group);

    gwa_log_website_event((int) $group['id'], $postId, $actorPersonId, 'group_website_post_created', 'Website post created and linked to Group', [
        'website_post_id' => $postId,
    ]);

    gwa_log_action($actorPersonId, 'group_website_post_created', 'group', (int) $group['id'], [
        'website_post_id' => $postId,
    ]);

    return $postId;
}

function gwa_try_geocode_store(int $postId, array $meta): void
{
    global $wpsl_admin;

    if (
        !isset($wpsl_admin)
        || !is_object($wpsl_admin)
        || empty($wpsl_admin->geocode)
        || !method_exists($wpsl_admin->geocode, 'check_geocode_data')
    ) {
        return;
    }

    $storeData = [
        'address' => $meta['wpsl_address'] ?? '',
        'city' => $meta['wpsl_city'] ?? '',
        'state' => $meta['wpsl_state'] ?? '',
        'zip' => $meta['wpsl_zip'] ?? '',
        'country' => $meta['wpsl_country'] ?? '',
        'latlng' => get_post_meta($postId, 'wpsl_latlng', true),
    ];

    try {
        $wpsl_admin->geocode->check_geocode_data($postId, $storeData);
    } catch (Throwable $e) {
        // Saving the Group details should not fail just because geocoding failed.
    }
}

function gwa_attach_uploaded_image_as_featured(int $postId, array $file): void
{
    if (empty($file['tmp_name'])) {
        return;
    }

    gwa_wp_bootstrap();

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $_FILES['gwa_featured_upload'] = $file;

    $attachmentId = media_handle_upload('gwa_featured_upload', $postId);

    if (is_wp_error($attachmentId)) {
        throw new RuntimeException('The image upload failed: ' . $attachmentId->get_error_message());
    }

    set_post_thumbnail($postId, (int) $attachmentId);
}

function gwa_attach_image_url_as_featured(int $postId, string $url): void
{
    $url = trim($url);

    if ($url === '') {
        return;
    }

    gwa_wp_bootstrap();

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($url);

    if (is_wp_error($tmp)) {
        throw new RuntimeException('The image URL could not be downloaded: ' . $tmp->get_error_message());
    }

    $name = basename(parse_url($url, PHP_URL_PATH) ?: 'group-image.jpg');

    $file = [
        'name' => $name,
        'tmp_name' => $tmp,
    ];

    $attachmentId = media_handle_sideload($file, $postId);

    if (is_wp_error($attachmentId)) {
        @unlink($tmp);
        throw new RuntimeException('The image URL could not be attached: ' . $attachmentId->get_error_message());
    }

    set_post_thumbnail($postId, (int) $attachmentId);
}

function gwa_update_wordpress_store_from_form(int $groupId, array $input, int $actorPersonId): void
{
    gwa_wp_bootstrap();

    $postId = (int) ($input['website_post_id'] ?? 0);

    if ($postId < 1) {
        throw new RuntimeException('Link or create a WordPress Scout Group post first.');
    }

    if (!gwa_wp_post_exists($postId)) {
        throw new RuntimeException('The linked WordPress post could not be found.');
    }

    $title = trim((string) ($input['post_title'] ?? ''));

    if ($title === '') {
        throw new RuntimeException('Enter the public Group name.');
    }

    $beforeSnapshot = gwa_website_snapshot($postId);

    $updated = wp_update_post([
        'ID' => $postId,
        'post_title' => sanitize_text_field($title),
        'post_name' => gwa_slugify($title),
        'post_content' => wp_kses_post((string) ($input['post_content'] ?? '')),
        'post_status' => 'publish',
    ], true);

    if (is_wp_error($updated)) {
        throw new RuntimeException('WordPress could not update the Scout Group post: ' . $updated->get_error_message());
    }

    $sectionDetails = gwa_normalise_section_json((string) ($input['wpsl_section_details'] ?? ''));
    $scarfDetails = gwa_normalise_scarf_json((string) ($input['wpsl_section_scarf'] ?? ''));
    [$lat, $lng] = gwa_normalise_lat_lng(
        isset($input['wpsl_lat']) ? (string) $input['wpsl_lat'] : null,
        isset($input['wpsl_lng']) ? (string) $input['wpsl_lng'] : null
    );

    $meta = [
        'wpsl_address' => sanitize_text_field((string) ($input['wpsl_address'] ?? '')),
        'wpsl_address2' => sanitize_text_field((string) ($input['wpsl_address2'] ?? '')),
        'wpsl_city' => sanitize_text_field((string) ($input['wpsl_city'] ?? '')),
        'wpsl_state' => sanitize_text_field((string) ($input['wpsl_state'] ?? '')),
        'wpsl_zip' => sanitize_text_field((string) ($input['wpsl_zip'] ?? '')),
        'wpsl_country' => sanitize_text_field((string) ($input['wpsl_country'] ?? 'United Kingdom')),
        'wpsl_country_iso' => sanitize_text_field((string) ($input['wpsl_country_iso'] ?? 'GB')),
        'wpsl_lat' => $lat,
        'wpsl_lng' => $lng,
        'wpsl_latlng' => ($lat !== null && $lng !== null) ? $lat . ',' . $lng : null,
        'wpsl_email' => sanitize_email((string) ($input['wpsl_email'] ?? '')),
        'wpsl_phone' => sanitize_text_field((string) ($input['wpsl_phone'] ?? '')),
        'wpsl_url' => esc_url_raw((string) ($input['wpsl_url'] ?? '')),

        'wpsl_group_website' => esc_url_raw((string) ($input['wpsl_group_website'] ?? '')),
        'wpsl_group_contact' => sanitize_text_field((string) ($input['wpsl_group_contact'] ?? '')),
        'wpsl_group_type' => sanitize_text_field((string) ($input['wpsl_group_type'] ?? '0')),
        'wpsl_section_scarf' => $scarfDetails,
        'wpsl_section_details' => $sectionDetails,
    ];

    foreach ($meta as $key => $value) {
        if ($value === '' || $value === null) {
            delete_post_meta($postId, $key);
        } else {
            update_post_meta($postId, $key, $value);
        }
    }

    if (!empty($input['remove_featured_image'])) {
        delete_post_thumbnail($postId);
    }

    if (!empty($_FILES['featured_upload']) && is_array($_FILES['featured_upload']) && !empty($_FILES['featured_upload']['tmp_name'])) {
        gwa_attach_uploaded_image_as_featured($postId, $_FILES['featured_upload']);
    } elseif (!empty($input['featured_url'])) {
        gwa_attach_image_url_as_featured($postId, (string) $input['featured_url']);
    }

    do_action('irval_leader_tool_group_store_updated', $postId, $meta, $groupId);

    /*
     * If a manual map pin is set, it becomes the source of truth for the Store Locator location.
     * If no pin is set, we still let WP Store Locator try its own geocoding from the address.
     */
    if ($lat === null || $lng === null) {
        gwa_try_geocode_store($postId, $meta);
    }

    gwa_update_flexible('groups', 'id', $groupId, [
        'website_post_id' => $postId,
        'website_url' => $meta['wpsl_group_website'] ?: $meta['wpsl_url'] ?: null,
        'public_email' => $meta['wpsl_email'] ?: null,
        'contact_email' => $meta['wpsl_email'] ?: null,
        'meeting_place' => trim((string) $meta['wpsl_address'] . ' ' . (string) $meta['wpsl_address2']) ?: null,
        'postcode' => $meta['wpsl_zip'] ?: null,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    clean_post_cache($postId);

    $afterSnapshot = gwa_website_snapshot($postId);
    $changes = gwa_log_website_update($groupId, $postId, $actorPersonId, 'group_website_details_updated', $beforeSnapshot, $afterSnapshot);

    gwa_log_action($actorPersonId, 'group_website_details_updated', 'group', $groupId, [
        'website_post_id' => $postId,
        'changed_fields' => array_keys($changes),
    ]);
}

/**
 * -------------------------------------------------------------------------
 * Page setup
 * -------------------------------------------------------------------------
 */

$memberships = function_exists('user_group_memberships')
    ? user_group_memberships((int) $user['id'], false)
    : [];

$isDistrictAdmin = gwa_actor_is_district_admin($user, $memberships);
$manageableGroups = gwa_manageable_groups((int) $user['id'], $isDistrictAdmin);

if (!$manageableGroups) {
    http_response_code(403);

    $pageTitle = 'Group Website Admin | ' . $appName;
    $heroTitle = 'Group Website Admin';
    $heroText = 'This area is for Group Lead Volunteers and District administrators.';
    $breadcrumb = '<a href="/index.php">Home</a> / Group Website Admin';

    include __DIR__ . '/header.php';

    echo '<main class="lt-main"><div class="alert alert-danger"><strong>Access denied:</strong> You do not currently manage any Groups.</div></main>';

    include __DIR__ . '/footer.php';
    exit;
}

$requestedGroupId = (int) ($_GET['group_id'] ?? $_POST['group_id'] ?? 0);
$selectedGroupId = $requestedGroupId > 0 && gwa_group_is_manageable($requestedGroupId, $manageableGroups)
    ? $requestedGroupId
    : (int) $manageableGroups[0]['id'];

$selectedGroup = gwa_fetch_group($selectedGroupId);

if (!$selectedGroup) {
    http_response_code(404);

    $pageTitle = 'Group Website Admin | ' . $appName;
    $heroTitle = 'Group Website Admin';
    $heroText = 'Website details for Scout Groups.';
    $breadcrumb = '<a href="/index.php">Home</a> / Group Website Admin';

    include __DIR__ . '/header.php';

    echo '<main class="lt-main"><div class="alert alert-danger">Group not found.</div></main>';

    include __DIR__ . '/footer.php';
    exit;
}

$errors = [];
$success = null;
$actorPersonId = (int) $user['id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');

        if (!gwa_column_exists('groups', 'website_post_id')) {
            throw new RuntimeException('The groups.website_post_id column does not exist. Apply the migration first.');
        }

        if ($action === 'link_existing_post') {
            $postId = (int) ($_POST['website_post_id'] ?? 0);

            gwa_link_existing_wordpress_post($selectedGroupId, $postId, $actorPersonId);

            $selectedGroup = gwa_fetch_group($selectedGroupId);
            $success = 'Website post linked. You can now edit the public Group page below.';

        } elseif ($action === 'create_website_post') {
            gwa_create_wordpress_store_for_group($selectedGroup, $actorPersonId);

            $selectedGroup = gwa_fetch_group($selectedGroupId);
            $success = 'New public website post created and linked.';

        } elseif ($action === 'save_website_details') {
            gwa_update_wordpress_store_from_form($selectedGroupId, $_POST, $actorPersonId);

            $selectedGroup = gwa_fetch_group($selectedGroupId);
            $success = 'Website details saved and published.';
        }
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage() ?: 'The website details could not be saved.';
}

$websitePostId = gwa_column_exists('groups', 'website_post_id')
    ? (int) ($selectedGroup['website_post_id'] ?? 0)
    : 0;

$websitePost = null;
$websiteMeta = [];
$websiteLoadError = null;

if ($websitePostId > 0) {
    try {
        $websitePost = gwa_wp_fetch_post($websitePostId);

        if ($websitePost) {
            $websiteMeta = gwa_wp_fetch_meta($websitePostId);
        }
    } catch (Throwable $e) {
        $websiteLoadError = $e->getMessage();
    }
}

$leadVolunteers = gwa_fetch_group_lead_volunteers($selectedGroupId);
$primaryLeadVolunteer = $leadVolunteers[0] ?? null;

$metaValue = static function (array $meta, string $key, string $fallback = ''): string {
    $value = $meta[$key] ?? $fallback;

    if (is_array($value)) {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    return (string) $value;
};

$thumbnailUrl = '';
if ($websitePostId > 0 && $websitePost) {
    try {
        $thumbnailUrl = gwa_wp_thumbnail_url($websitePostId);
    } catch (Throwable $e) {
        $thumbnailUrl = '';
    }
}

$mapLatValue = $metaValue($websiteMeta, 'wpsl_lat');
$mapLngValue = $metaValue($websiteMeta, 'wpsl_lng');

if (($mapLatValue === '' || $mapLngValue === '') && !empty($websiteMeta['wpsl_latlng'])) {
    $latLngParts = array_map('trim', explode(',', (string) $websiteMeta['wpsl_latlng']));

    if (count($latLngParts) >= 2) {
        $mapLatValue = $mapLatValue !== '' ? $mapLatValue : $latLngParts[0];
        $mapLngValue = $mapLngValue !== '' ? $mapLngValue : $latLngParts[1];
    }
}

$websiteUpdates = gwa_fetch_website_updates($selectedGroupId, 25);

$pageTitle = 'Group Website Admin | ' . $appName;
$heroTitle = 'Group Website Admin';
$heroText = 'Manage the Scout Group details shown on the public District website.';
$breadcrumb = '<a href="/index.php">Home</a> / Group Website Admin';

include __DIR__ . '/header.php';
?>

<link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
>

<style>
    .gwa-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 992px) {
        .gwa-grid-2 {
            grid-template-columns: minmax(0, 1fr) minmax(360px, .75fr);
        }
    }

    .gwa-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        align-items: end;
        margin-bottom: 1rem;
    }

    .gwa-muted {
        color: #555;
    }

    .gwa-card {
        background: #fff;
        border: 2px solid #eee;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .gwa-form-row {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 768px) {
        .gwa-form-row-2 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .gwa-form-row-3 {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    .gwa-status {
        display: inline-block;
        padding: .2rem .5rem;
        border-radius: .25rem;
        font-weight: 800;
        background: #d1e7dd;
        color: #0f5132;
    }

    .gwa-meeting-row {
        border: 2px solid #eee;
        border-radius: .75rem;
        padding: 1rem;
        background: #fff;
        margin-bottom: 1rem;
    }

    .gwa-meeting-row-title {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: center;
        margin-bottom: .75rem;
    }

    .gwa-scarf-preview {
        border: 2px solid #eee;
        background: #f9f9f9;
        padding: .75rem;
        border-radius: .75rem;
        overflow: hidden;
    }

    .gwa-scarf-preview svg {
        width: 100%;
        max-width: 720px;
        height: auto;
        display: block;
        margin: 0 auto;
    }

    .gwa-colour-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: .75rem;
    }

    .gwa-colour-control {
        border: 1px solid #ddd;
        border-radius: .5rem;
        padding: .75rem;
        background: #fff;
    }

    .gwa-colour-control label {
        font-weight: 800;
        display: block;
        margin-bottom: .4rem;
    }

    .gwa-preset-grid {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
        margin-top: .5rem;
    }

    .gwa-preset {
        width: 1.8rem;
        height: 1.8rem;
        border-radius: .25rem;
        border: 1px solid rgba(0,0,0,.25);
        cursor: pointer;
        padding: 0;
    }

    .gwa-image-preview {
        max-width: 320px;
        border-radius: .75rem;
        border: 2px solid #eee;
        display: block;
    }

    .gwa-map {
        width: 100%;
        min-height: 420px;
        border: 2px solid #1d1d1b;
        background: #f3f2f1;
    }

    .gwa-map-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        align-items: center;
        margin-top: .75rem;
    }

    .gwa-history-list {
        display: grid;
        gap: 1rem;
    }

    .gwa-history-item {
        background: #fff;
        border: 2px solid #e6e6e6;
        border-left: 8px solid var(--iv-purple);
        padding: 1rem;
    }

    .gwa-history-head {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem 1rem;
        justify-content: space-between;
        align-items: baseline;
    }

    .gwa-history-head strong {
        color: var(--iv-purple);
        font-weight: 900;
    }

    .gwa-history-meta {
        color: #555;
        font-weight: 700;
    }

    .gwa-history-changes {
        margin: .75rem 0 0;
        padding-left: 1.25rem;
    }

    .gwa-history-changes li {
        margin-bottom: .4rem;
    }

    .gwa-change-value {
        display: inline-block;
        max-width: 100%;
        vertical-align: top;
        overflow-wrap: anywhere;
        background: #f7f5fb;
        padding: .1rem .35rem;
        font-weight: 800;
    }
</style>

<main class="lt-main">
    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <strong>There is a problem:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= e($success) ?>
            <?php if ($websitePostId > 0 && $websitePost): ?>
                <a href="<?= e(gwa_wp_store_permalink($websitePostId)) ?>" target="_blank" rel="noopener">
                    View public page
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!gwa_column_exists('groups', 'website_post_id')): ?>
        <div class="alert alert-warning">
            <strong>Migration needed:</strong>
            run the migration to add <code>groups.website_post_id</code> before using this page.
        </div>
    <?php endif; ?>

    <section class="lt-card">
        <h2 class="lt-section-title">Choose Group</h2>

        <form method="get" class="gwa-toolbar">
            <div class="form-group mb-0">
                <label for="group_id">Group</label>
                <select class="form-control" id="group_id" name="group_id" onchange="this.form.submit()">
                    <?php foreach ($manageableGroups as $group): ?>
                        <option value="<?= (int) $group['id'] ?>" <?= (int) $group['id'] === $selectedGroupId ? 'selected' : '' ?>>
                            <?= e((string) $group['group_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <noscript>
                <button class="btn btn-primary lt-btn" type="submit">Load Group</button>
            </noscript>
        </form>

        <p class="gwa-muted mb-0">
            Editing:
            <strong><?= e((string) ($selectedGroup['group_name'] ?? '')) ?></strong>
            <?php if ($websitePostId > 0): ?>
                · linked WordPress post ID <strong><?= (int) $websitePostId ?></strong>
            <?php else: ?>
                · no WordPress post linked yet
            <?php endif; ?>
        </p>
    </section>

    <?php if ($websiteLoadError): ?>
        <section class="lt-card">
            <div class="alert alert-danger mb-0">
                <strong>WordPress connection problem:</strong>
                <?= e($websiteLoadError) ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($websitePostId < 1 && !$websiteLoadError): ?>
        <section class="lt-card">
            <h2 class="lt-section-title">Connect this Group to the website</h2>

            <div class="gwa-grid gwa-grid-2">
                <form method="post" class="gwa-card">
                    <input type="hidden" name="action" value="link_existing_post">
                    <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">

                    <h3 class="h5 font-weight-bold">Link existing website post</h3>

                    <div class="form-group">
                        <label for="website_post_id">WordPress Store Locator post ID</label>
                        <input
                            class="form-control"
                            type="number"
                            min="1"
                            id="website_post_id"
                            name="website_post_id"
                            placeholder="e.g. 123"
                        >
                        <small class="form-text text-muted">
                            The post must be a <code>wpsl_stores</code> post.
                        </small>
                    </div>

                    <button class="btn btn-primary lt-btn" type="submit">
                        Link existing post
                    </button>
                </form>

                <form method="post" class="gwa-card">
                    <input type="hidden" name="action" value="create_website_post">
                    <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">

                    <h3 class="h5 font-weight-bold">Create website page now</h3>

                    <p>
                        Creates and publishes a new public Store Locator page for this Group.
                    </p>

                    <button class="btn btn-secondary lt-btn" type="submit">
                        Create public page
                    </button>
                </form>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($websitePost): ?>
        <form method="post" enctype="multipart/form-data" id="gwa-editor-form">
            <input type="hidden" name="action" value="save_website_details">
            <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
            <input type="hidden" name="website_post_id" value="<?= (int) $websitePostId ?>">

            <section class="lt-card">
                <h2 class="lt-section-title">Website page</h2>

                <p class="gwa-muted">
                    Status:
                    <span class="gwa-status">Published live</span>
                    ·
                    <a href="<?= e(gwa_wp_store_permalink($websitePostId)) ?>" target="_blank" rel="noopener">
                        View public page
                    </a>
                </p>

                <div class="form-group">
                    <label for="post_title">Public Group name</label>
                    <input
                        class="form-control"
                        type="text"
                        id="post_title"
                        name="post_title"
                        value="<?= e((string) ($websitePost['post_title'] ?? $selectedGroup['group_name'])) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="post_content">About this Group</label>
                    <textarea
                        class="form-control"
                        id="post_content"
                        name="post_content"
                        rows="7"
                    ><?= e((string) ($websitePost['post_content'] ?? '')) ?></textarea>
                </div>
            </section>

            <section class="lt-card">
                <h2 class="lt-section-title">Photo</h2>

                <?php if ($thumbnailUrl !== ''): ?>
                    <p>
                        <img class="gwa-image-preview" src="<?= e($thumbnailUrl) ?>" alt="">
                    </p>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" value="1" id="remove_featured_image" name="remove_featured_image">
                        <label class="form-check-label" for="remove_featured_image">
                            Remove current photo
                        </label>
                    </div>
                <?php else: ?>
                    <p class="gwa-muted">No photo has been set yet.</p>
                <?php endif; ?>

                <div class="gwa-form-row gwa-form-row-2">
                    <div class="form-group">
                        <label for="featured_upload">Upload a new photo</label>
                        <input class="form-control" type="file" id="featured_upload" name="featured_upload" accept="image/*">
                    </div>

                    <div class="form-group">
                        <label for="featured_url">Or paste an image URL</label>
                        <input class="form-control" type="url" id="featured_url" name="featured_url" placeholder="https://...">
                    </div>
                </div>
            </section>

            <section class="lt-card">
                <h2 class="lt-section-title">Meeting place</h2>

                <div class="gwa-form-row gwa-form-row-2">
                    <div class="form-group">
                        <label for="wpsl_address">Address line 1</label>
                        <input
                            class="form-control"
                            type="text"
                            id="wpsl_address"
                            name="wpsl_address"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_address', (string) ($selectedGroup['meeting_place'] ?? ''))) ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="wpsl_address2">Address line 2</label>
                        <input
                            class="form-control"
                            type="text"
                            id="wpsl_address2"
                            name="wpsl_address2"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_address2')) ?>"
                        >
                    </div>
                </div>

                <div class="gwa-form-row gwa-form-row-3">
                    <div class="form-group">
                        <label for="wpsl_city">Town / city</label>
                        <input
                            class="form-control"
                            type="text"
                            id="wpsl_city"
                            name="wpsl_city"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_city')) ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="wpsl_state">County</label>
                        <input
                            class="form-control"
                            type="text"
                            id="wpsl_state"
                            name="wpsl_state"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_state')) ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="wpsl_zip">Postcode</label>
                        <input
                            class="form-control"
                            type="text"
                            id="wpsl_zip"
                            name="wpsl_zip"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_zip', (string) ($selectedGroup['postcode'] ?? ''))) ?>"
                        >
                    </div>
                </div>

                <div class="gwa-form-row gwa-form-row-2">
                    <div class="form-group">
                        <label for="wpsl_country">Country</label>
                        <input
                            class="form-control"
                            type="text"
                            id="wpsl_country"
                            name="wpsl_country"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_country', 'United Kingdom')) ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="wpsl_country_iso">Country code</label>
                        <input
                            class="form-control"
                            type="text"
                            id="wpsl_country_iso"
                            name="wpsl_country_iso"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_country_iso', 'GB')) ?>"
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label>Map pin</label>
                    <div
                        id="gwa-map"
                        class="gwa-map"
                        data-lat="<?= e($mapLatValue) ?>"
                        data-lng="<?= e($mapLngValue) ?>"
                    ></div>
                    <small class="form-text text-muted">
                        Click the map or drag the pin to set the exact location shown by the Store Locator map.
                    </small>
                </div>

                <div class="gwa-form-row gwa-form-row-2">
                    <div class="form-group">
                        <label for="wpsl_lat">Latitude</label>
                        <input
                            class="form-control"
                            type="text"
                            id="wpsl_lat"
                            name="wpsl_lat"
                            value="<?= e($mapLatValue) ?>"
                            inputmode="decimal"
                        >
                    </div>

                    <div class="form-group">
                        <label for="wpsl_lng">Longitude</label>
                        <input
                            class="form-control"
                            type="text"
                            id="wpsl_lng"
                            name="wpsl_lng"
                            value="<?= e($mapLngValue) ?>"
                            inputmode="decimal"
                        >
                    </div>
                </div>

                <div class="gwa-map-actions">
                    <button class="btn btn-secondary lt-btn" type="button" id="gwa-centre-map-from-fields">
                        Centre map on saved pin
                    </button>
                    <button class="btn btn-outline-danger" type="button" id="gwa-clear-map-pin">
                        Clear map pin
                    </button>
                </div>
            </section>

            <section class="lt-card">
                <h2 class="lt-section-title">Contact details</h2>

                <?php
                    $glvName = $primaryLeadVolunteer ? (string) ($primaryLeadVolunteer['full_name'] ?? '') : '';
                    $glvEmail = $primaryLeadVolunteer ? (string) ($primaryLeadVolunteer['primary_email'] ?? '') : '';
                    $glvPhone = $primaryLeadVolunteer ? (string) ($primaryLeadVolunteer['phone'] ?? '') : '';
                ?>

                <?php if ($primaryLeadVolunteer): ?>
                    <div class="alert alert-info">
                        Group Lead Volunteer found:
                        <strong><?= e($glvName) ?></strong>
                        <?php if ($glvEmail !== ''): ?>
                            · <?= e($glvEmail) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="gwa-form-row gwa-form-row-3">
                    <div class="form-group">
                        <label for="wpsl_phone">Public phone</label>
                        <input
                            class="form-control"
                            type="text"
                            id="wpsl_phone"
                            name="wpsl_phone"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_phone')) ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="wpsl_email">Public email</label>
                        <input
                            class="form-control"
                            type="email"
                            id="wpsl_email"
                            name="wpsl_email"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_email', (string) ($selectedGroup['public_email'] ?? $selectedGroup['contact_email'] ?? ''))) ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="wpsl_group_contact">Primary contact</label>
                        <div class="input-group">
                            <input
                                class="form-control"
                                type="text"
                                id="wpsl_group_contact"
                                name="wpsl_group_contact"
                                value="<?= e($metaValue($websiteMeta, 'wpsl_group_contact')) ?>"
                                placeholder="e.g. Group Lead Volunteer"
                            >
                            <?php if ($primaryLeadVolunteer): ?>
                                <div class="input-group-append">
                                    <button
                                        class="btn btn-outline-secondary"
                                        type="button"
                                        id="insert-glv"
                                        data-name="<?= e($glvName) ?>"
                                        data-email="<?= e($glvEmail) ?>"
                                        data-phone="<?= e($glvPhone) ?>"
                                    >
                                        Insert GLV
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <small class="form-text text-muted">
                            Use the button to insert the Group Lead Volunteer if appropriate.
                        </small>
                    </div>
                </div>

                <div class="gwa-form-row gwa-form-row-2">
                    <div class="form-group">
                        <label for="wpsl_group_website">Group website</label>
                        <input
                            class="form-control"
                            type="url"
                            id="wpsl_group_website"
                            name="wpsl_group_website"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_group_website', (string) ($selectedGroup['website_url'] ?? ''))) ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="wpsl_url">Store Locator URL</label>
                        <input
                            class="form-control"
                            type="url"
                            id="wpsl_url"
                            name="wpsl_url"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_url', (string) ($selectedGroup['website_url'] ?? ''))) ?>"
                        >
                    </div>
                </div>
            </section>

            <section class="lt-card">
                <h2 class="lt-section-title">Group type</h2>

                <?php $groupType = $metaValue($websiteMeta, 'wpsl_group_type', '0'); ?>

                <div class="form-group">
                    <label for="wpsl_group_type">Type shown on the public website</label>
                    <select class="form-control" id="wpsl_group_type" name="wpsl_group_type">
                        <option value="0" <?= $groupType === '0' ? 'selected' : '' ?>>Scout Group</option>
                        <option value="1" <?= $groupType === '1' ? 'selected' : '' ?>>Explorer Unit</option>
                        <option value="2" <?= $groupType === '2' ? 'selected' : '' ?>>Network</option>
                    </select>
                </div>
            </section>

            <section class="lt-card">
                <h2 class="lt-section-title">Section meeting times</h2>

                <p class="gwa-muted">
                    Add each section, choose the meeting day, and set the start and finish time.
                </p>

                <input
                    type="hidden"
                    id="wpsl_section_details"
                    name="wpsl_section_details"
                    value="<?= e($metaValue($websiteMeta, 'wpsl_section_details', gwa_section_example_json())) ?>"
                >

                <div id="section-editor"></div>

                <button class="btn btn-secondary lt-btn" type="button" id="add-section-row">
                    Add section
                </button>
            </section>

            <section class="lt-card">
                <h2 class="lt-section-title">Group scarf / necker</h2>

                <p class="gwa-muted">
                    Choose the scarf style and colours. The preview updates before saving.
                </p>

                <input
                    type="hidden"
                    id="wpsl_section_scarf"
                    name="wpsl_section_scarf"
                    value="<?= e($metaValue($websiteMeta, 'wpsl_section_scarf', gwa_normalise_scarf_json(''))) ?>"
                >

                <div class="form-group">
                    <label for="scarf_type">Scarf style</label>
                    <select class="form-control" id="scarf_type">
                        <option value="0">Plain</option>
                        <option value="1">Single border</option>
                        <option value="2">Double border</option>
                        <option value="3">Triple border</option>
                        <option value="4">Centre stripe</option>
                        <option value="5">Centre stripe + border</option>
                        <option value="6">Centre stripe + 2 borders</option>
                        <option value="7">Centre stripe + 3 borders</option>
                        <option value="8">Large border</option>
                    </select>
                </div>

                <div class="gwa-colour-grid">
                    <div class="gwa-colour-control">
                        <label for="scarf_l">Left main</label>
                        <input type="color" id="scarf_l" data-key="l">
                    </div>
                    <div class="gwa-colour-control">
                        <label for="scarf_r">Right main</label>
                        <input type="color" id="scarf_r" data-key="r">
                    </div>
                    <div class="gwa-colour-control">
                        <label for="scarf_b1l">Border 1 left</label>
                        <input type="color" id="scarf_b1l" data-key="b1l">
                    </div>
                    <div class="gwa-colour-control">
                        <label for="scarf_b1r">Border 1 right</label>
                        <input type="color" id="scarf_b1r" data-key="b1r">
                    </div>
                    <div class="gwa-colour-control">
                        <label for="scarf_b2l">Border 2 left</label>
                        <input type="color" id="scarf_b2l" data-key="b2l">
                    </div>
                    <div class="gwa-colour-control">
                        <label for="scarf_b2r">Border 2 right</label>
                        <input type="color" id="scarf_b2r" data-key="b2r">
                    </div>
                    <div class="gwa-colour-control">
                        <label for="scarf_b3l">Border 3 left</label>
                        <input type="color" id="scarf_b3l" data-key="b3l">
                    </div>
                    <div class="gwa-colour-control">
                        <label for="scarf_b3r">Border 3 right</label>
                        <input type="color" id="scarf_b3r" data-key="b3r">
                    </div>
                    <div class="gwa-colour-control">
                        <label for="scarf_s">Centre stripe</label>
                        <input type="color" id="scarf_s" data-key="s">
                    </div>
                </div>

                <div class="mt-3">
                    <button class="btn btn-outline-secondary" type="button" id="match-scarf-sides">
                        Make right match left
                    </button>
                </div>

                <div class="mt-3">
                    <strong>Preset colours</strong>
                    <p class="gwa-muted mb-1">
                        Click a colour after selecting the field you want to change.
                    </p>
                    <select class="form-control mb-2" id="preset-target">
                        <option value="l">Left main</option>
                        <option value="r">Right main</option>
                        <option value="b1l">Border 1 left</option>
                        <option value="b1r">Border 1 right</option>
                        <option value="b2l">Border 2 left</option>
                        <option value="b2r">Border 2 right</option>
                        <option value="b3l">Border 3 left</option>
                        <option value="b3r">Border 3 right</option>
                        <option value="s">Centre stripe</option>
                    </select>
                    <div class="gwa-preset-grid" id="scarf-presets"></div>
                </div>

                <div class="mt-4">
                    <strong>Preview</strong>
                    <div class="gwa-scarf-preview mt-2" id="scarf-preview"></div>
                </div>
            </section>

            <section class="lt-card">
                <button class="btn btn-primary lt-btn btn-lg" type="submit">
                    Save and publish website details
                </button>

                <a class="btn btn-secondary lt-btn btn-lg" href="<?= e(gwa_wp_store_permalink($websitePostId)) ?>" target="_blank" rel="noopener">
                    View public page
                </a>
            </section>
        </form>
    <?php elseif ($websitePostId > 0 && !$websiteLoadError): ?>
        <section class="lt-card">
            <div class="alert alert-warning mb-0">
                A WordPress post ID is linked, but the post could not be found as a <code>wpsl_stores</code> post.
            </div>
        </section>
    <?php endif; ?>

    <section class="lt-card">
        <h2 class="lt-section-title">Website update history</h2>

        <?php if (!gwa_table_exists('group_website_update_log')): ?>
            <div class="alert alert-warning mb-0">
                Website update logging is not enabled yet. Run the SQL migration for <code>group_website_update_log</code>.
            </div>
        <?php elseif (!$websiteUpdates): ?>
            <p class="gwa-muted mb-0">No website changes have been logged for this Group yet.</p>
        <?php else: ?>
            <div class="gwa-history-list">
                <?php foreach ($websiteUpdates as $update): ?>
                    <?php $changes = gwa_decode_update_changes($update['changed_fields_json'] ?? null); ?>
                    <article class="gwa-history-item">
                        <div class="gwa-history-head">
                            <strong><?= e($update['summary'] ?? 'Website update') ?></strong>
                            <span class="gwa-history-meta"><?= e($update['created_at'] ?? '') ?></span>
                        </div>

                        <div class="gwa-history-meta">
                            <?= e($update['actor_name'] ?? 'Unknown user') ?>
                            <?php if (!empty($update['actor_email'])): ?>
                                · <?= e($update['actor_email']) ?>
                            <?php endif; ?>
                        </div>

                        <?php if ($changes): ?>
                            <ul class="gwa-history-changes">
                                <?php foreach ($changes as $change): ?>
                                    <?php if (!is_array($change) || empty($change['label'])) { continue; } ?>
                                    <li>
                                        <strong><?= e((string) $change['label']) ?></strong>
                                        <?php if (array_key_exists('before', $change) || array_key_exists('after', $change)): ?>
                                            changed from
                                            <span class="gwa-change-value"><?= e((string) ($change['before'] ?? '')) ?: 'blank' ?></span>
                                            to
                                            <span class="gwa-change-value"><?= e((string) ($change['after'] ?? '')) ?: 'blank' ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
(function () {
    const insertGlvButton = document.getElementById('insert-glv');

    if (insertGlvButton) {
        insertGlvButton.addEventListener('click', function () {
            const name = this.getAttribute('data-name') || '';
            const email = this.getAttribute('data-email') || '';
            const phone = this.getAttribute('data-phone') || '';

            const contact = document.getElementById('wpsl_group_contact');
            const emailInput = document.getElementById('wpsl_email');
            const phoneInput = document.getElementById('wpsl_phone');

            if (contact && name) {
                contact.value = name;
            }

            if (emailInput && email && emailInput.value.trim() === '') {
                emailInput.value = email;
            }

            if (phoneInput && phone && phoneInput.value.trim() === '') {
                phoneInput.value = phone;
            }
        });
    }

    /**
     * ---------------------------------------------------------------------
     * Map pin editor
     * ---------------------------------------------------------------------
     */

    const mapElement = document.getElementById('gwa-map');
    const latInput = document.getElementById('wpsl_lat');
    const lngInput = document.getElementById('wpsl_lng');
    const centreMapButton = document.getElementById('gwa-centre-map-from-fields');
    const clearMapButton = document.getElementById('gwa-clear-map-pin');

    function readCoordinate(input) {
        if (!input) {
            return null;
        }

        const value = String(input.value || '').trim();

        if (value === '') {
            return null;
        }

        const number = Number(value);

        return Number.isFinite(number) ? number : null;
    }

    function setCoordinateInputs(lat, lng) {
        if (latInput) {
            latInput.value = Number(lat).toFixed(7);
        }

        if (lngInput) {
            lngInput.value = Number(lng).toFixed(7);
        }
    }

    if (mapElement && typeof L !== 'undefined') {
        const storedLat = Number(mapElement.getAttribute('data-lat') || '');
        const storedLng = Number(mapElement.getAttribute('data-lng') || '');
        const hasStoredPin = Number.isFinite(storedLat) && Number.isFinite(storedLng);
  const defaultLat = 53.5933;
const defaultLng = -2.2966;

        const map = L.map(mapElement).setView(
            hasStoredPin ? [storedLat, storedLng] : [defaultLat, defaultLng],
            hasStoredPin ? 16 : 11
        );

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        let marker = null;

        function setMapPin(lat, lng, shouldCentre) {
            if (!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) {
                return;
            }

            lat = Number(lat);
            lng = Number(lng);

            if (!marker) {
                marker = L.marker([lat, lng], {
                    draggable: true
                }).addTo(map);

                marker.on('dragend', function () {
                    const position = marker.getLatLng();
                    setCoordinateInputs(position.lat, position.lng);
                });
            } else {
                marker.setLatLng([lat, lng]);
            }

            setCoordinateInputs(lat, lng);

            if (shouldCentre) {
                map.setView([lat, lng], Math.max(map.getZoom(), 16));
            }
        }

        if (hasStoredPin) {
            setMapPin(storedLat, storedLng, false);
        }

        map.on('click', function (event) {
            setMapPin(event.latlng.lat, event.latlng.lng, true);
        });

        if (centreMapButton) {
            centreMapButton.addEventListener('click', function () {
                const lat = readCoordinate(latInput);
                const lng = readCoordinate(lngInput);

                if (lat === null || lng === null) {
                    return;
                }

                setMapPin(lat, lng, true);
            });
        }

        if (clearMapButton) {
            clearMapButton.addEventListener('click', function () {
                if (marker) {
                    marker.remove();
                    marker = null;
                }

                if (latInput) {
                    latInput.value = '';
                }

                if (lngInput) {
                    lngInput.value = '';
                }
            });
        }

        if (latInput) {
            latInput.addEventListener('change', function () {
                const lat = readCoordinate(latInput);
                const lng = readCoordinate(lngInput);

                if (lat !== null && lng !== null) {
                    setMapPin(lat, lng, true);
                }
            });
        }

        if (lngInput) {
            lngInput.addEventListener('change', function () {
                const lat = readCoordinate(latInput);
                const lng = readCoordinate(lngInput);

                if (lat !== null && lng !== null) {
                    setMapPin(lat, lng, true);
                }
            });
        }

        window.setTimeout(function () {
            map.invalidateSize();
        }, 250);
    }

    /**
     * ---------------------------------------------------------------------
     * Section editor
     * ---------------------------------------------------------------------
     */

    const sectionJson = document.getElementById('wpsl_section_details');
    const sectionEditor = document.getElementById('section-editor');
    const addSectionRow = document.getElementById('add-section-row');

    const DAYS = [
        ['0', 'Monday'],
        ['1', 'Tuesday'],
        ['2', 'Wednesday'],
        ['3', 'Thursday'],
        ['4', 'Friday'],
        ['5', 'Saturday'],
        ['6', 'Sunday']
    ];

    const SECTION_TYPES = [
        ['0', 'Early Years'],
        ['1', 'Beavers'],
        ['2', 'Cubs'],
        ['3', 'Scouts'],
        ['4', 'Explorers'],
        ['5', 'Network'],
        ['6', 'SASU']
    ];

    function buildTimes() {
        const times = [];
        const hours = ['12:', '01:', '02:', '03:', '04:', '05:', '06:', '07:', '08:', '09:', '10:', '11:'];
        const minutes = ['00', '15', '30', '45'];
        const ampm = ['AM', 'PM'];

        for (let a = 0; a < ampm.length; a++) {
            for (let h = 0; h < hours.length; h++) {
                for (let m = 0; m < minutes.length; m++) {
                    times.push(hours[h] + minutes[m] + ' ' + ampm[a]);
                }
            }
        }

        return times;
    }

    const TIMES = buildTimes();

    function parseSections() {
        try {
            const parsed = JSON.parse(sectionJson.value || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function writeSections(rows) {
        const clean = rows.map((row, index) => ({
            day: String(row.day || '0'),
            type: String(row.type || '0'),
            time_start: String(row.time_start || '0'),
            time_finish: String(row.time_finish || '0'),
            name: String(row.name || ''),
            key: String(index)
        }));

        sectionJson.value = JSON.stringify(clean);
    }

    function optionHtml(options, selected) {
        return options.map(([value, label]) => {
            const isSelected = String(value) === String(selected) ? ' selected' : '';
            return `<option value="${escapeHtml(value)}"${isSelected}>${escapeHtml(label)}</option>`;
        }).join('');
    }

    function timeOptionHtml(selected) {
        return TIMES.map((label, index) => {
            const isSelected = String(index) === String(selected) ? ' selected' : '';
            return `<option value="${index}"${isSelected}>${escapeHtml(label)}</option>`;
        }).join('');
    }

    function renderSections() {
        if (!sectionEditor || !sectionJson) {
            return;
        }

        let rows = parseSections();

        if (rows.length === 0) {
            rows = [{
                day: '0',
                type: '1',
                time_start: '72',
                time_finish: '76',
                name: '',
                key: '0'
            }];
            writeSections(rows);
        }

        sectionEditor.innerHTML = '';

        rows.forEach((row, index) => {
            const div = document.createElement('div');
            div.className = 'gwa-meeting-row';
            div.innerHTML = `
                <div class="gwa-meeting-row-title">
                    <strong>Section ${index + 1}</strong>
                    <button type="button" class="btn btn-outline-danger btn-sm" data-remove="${index}">Remove</button>
                </div>

                <div class="gwa-form-row gwa-form-row-3">
                    <div class="form-group">
                        <label>Section</label>
                        <select class="form-control" data-field="type" data-index="${index}">
                            ${optionHtml(SECTION_TYPES, row.type)}
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Day</label>
                        <select class="form-control" data-field="day" data-index="${index}">
                            ${optionHtml(DAYS, row.day)}
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Display name / note</label>
                        <input class="form-control" type="text" data-field="name" data-index="${index}" value="${escapeHtml(row.name || '')}" placeholder="Optional">
                    </div>
                </div>

                <div class="gwa-form-row gwa-form-row-2">
                    <div class="form-group">
                        <label>Start time</label>
                        <select class="form-control" data-field="time_start" data-index="${index}">
                            ${timeOptionHtml(row.time_start)}
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Finish time</label>
                        <select class="form-control" data-field="time_finish" data-index="${index}">
                            ${timeOptionHtml(row.time_finish)}
                        </select>
                    </div>
                </div>
            `;

            sectionEditor.appendChild(div);
        });

        sectionEditor.querySelectorAll('[data-field]').forEach((input) => {
            input.addEventListener('input', function () {
                const rows = parseSections();
                const index = Number(this.getAttribute('data-index'));
                const field = this.getAttribute('data-field');

                if (!rows[index]) {
                    return;
                }

                rows[index][field] = this.value;
                writeSections(rows);
            });

            input.addEventListener('change', function () {
                const rows = parseSections();
                const index = Number(this.getAttribute('data-index'));
                const field = this.getAttribute('data-field');

                if (!rows[index]) {
                    return;
                }

                rows[index][field] = this.value;
                writeSections(rows);
            });
        });

        sectionEditor.querySelectorAll('[data-remove]').forEach((button) => {
            button.addEventListener('click', function () {
                const rows = parseSections();
                const index = Number(this.getAttribute('data-remove'));

                rows.splice(index, 1);
                writeSections(rows);
                renderSections();
            });
        });
    }

    if (addSectionRow) {
        addSectionRow.addEventListener('click', function () {
            const rows = parseSections();

            rows.push({
                day: '0',
                type: '1',
                time_start: '72',
                time_finish: '76',
                name: '',
                key: String(rows.length)
            });

            writeSections(rows);
            renderSections();
        });
    }

    renderSections();

    /**
     * ---------------------------------------------------------------------
     * Scarf editor
     * ---------------------------------------------------------------------
     */

    const scarfJson = document.getElementById('wpsl_section_scarf');
    const scarfType = document.getElementById('scarf_type');
    const scarfPreview = document.getElementById('scarf-preview');
    const presetTarget = document.getElementById('preset-target');
    const presetWrap = document.getElementById('scarf-presets');
    const matchSides = document.getElementById('match-scarf-sides');

    const DEFAULT_SCARF = {
        scarf_type: '0',
        l: '#39774e',
        r: '#39774e',
        b1l: '#000000',
        b1r: '#000000',
        b2l: '#ffffff',
        b2r: '#ffffff',
        b3l: '#000000',
        b3r: '#000000',
        s: '#ffffff'
    };

    const PRESETS = [
        ['Bright Yellow', '#EFE406'],
        ['Lemon', '#F3E747'],
        ['Orange', '#C98200'],
        ['Scarlet', '#B92f1f'],
        ['Dark Red', '#8f1937'],
        ['Maroon', '#480d2c'],
        ['Purple', '#5C068c'],
        ['Gold', '#e4b71b'],
        ['Khaki', '#826e57'],
        ['Chocolate', '#422310'],
        ['Grape', '#7b0065'],
        ['Emerald', '#1a6a30'],
        ['Pine Green', '#184f3f'],
        ['Scout Green', '#39774e'],
        ['Sky Blue', '#afc3d5'],
        ['Royal Blue', '#0A3786'],
        ['Navy Blue', '#152442'],
        ['White', '#f9f9f9'],
        ['Grey', '#7e7f84'],
        ['Black', '#000000'],
        ['Pink', '#cd6888'],
        ['Light Blue', '#b9cfe4'],
        ['Turquoise', '#44bce3'],
        ['Tangerine', '#ff5e00'],
        ['Lilac', '#c2bcec'],
        ['HiVis Yellow', '#e6ff15'],
        ['HiVis Orange', '#ff8418'],
        ['HiVis Green', '#8dff32'],
        ['HiVis Pink', '#ff51b5'],
        ['Reflective', '#e1e8e8']
    ];

    function parseScarf() {
        try {
            const parsed = JSON.parse(scarfJson.value || '{}');
            return Object.assign({}, DEFAULT_SCARF, parsed || {});
        } catch (e) {
            return Object.assign({}, DEFAULT_SCARF);
        }
    }

    function writeScarf(data) {
        scarfJson.value = JSON.stringify(data);
    }

    function setColourInputValues(data) {
        document.querySelectorAll('[data-key]').forEach((input) => {
            const key = input.getAttribute('data-key');
            input.value = normaliseColour(data[key] || DEFAULT_SCARF[key] || '#000000');
        });

        if (scarfType) {
            scarfType.value = String(data.scarf_type || '0');
        }
    }

    function readScarfFromInputs() {
        const data = parseScarf();

        document.querySelectorAll('[data-key]').forEach((input) => {
            const key = input.getAttribute('data-key');
            data[key] = input.value || DEFAULT_SCARF[key] || '#000000';
        });

        data.scarf_type = scarfType ? String(scarfType.value || '0') : '0';

        writeScarf(data);
        renderScarfPreview(data);
    }

    function renderPresets() {
        if (!presetWrap) {
            return;
        }

        presetWrap.innerHTML = '';

        PRESETS.forEach(([name, colour]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'gwa-preset';
            button.title = name;
            button.style.backgroundColor = colour;
            button.setAttribute('data-colour', colour);

            button.addEventListener('click', function () {
                const target = presetTarget ? presetTarget.value : 'l';
                const input = document.querySelector('[data-key="' + target + '"]');

                if (input) {
                    input.value = colour;
                    readScarfFromInputs();
                }
            });

            presetWrap.appendChild(button);
        });
    }

    function renderScarfPreview(data) {
        if (!scarfPreview) {
            return;
        }

        const type = Number(data.scarf_type || 0);

        const showB1 = [1, 2, 3, 5, 6, 7, 8].includes(type);
        const showB2 = [2, 3, 6, 7].includes(type);
        const showB3 = [3, 7].includes(type);
        const showStripe = [4, 5, 6, 7].includes(type);
        const largeBorder = type === 8;

        const borderWidth1 = largeBorder ? 34 : 14;
        const borderWidth2 = 26;
        const borderWidth3 = 38;

        scarfPreview.innerHTML = `
            <svg viewBox="0 0 900 300" role="img" aria-label="Scarf preview">
                <rect x="0" y="0" width="900" height="300" fill="#f9f9f9"></rect>

                <polygon points="90,30 450,250 450,55" fill="${escapeAttr(data.l)}" stroke="#111" stroke-width="3"></polygon>
                <polygon points="810,30 450,250 450,55" fill="${escapeAttr(data.r)}" stroke="#111" stroke-width="3"></polygon>

                ${showStripe ? `<polygon points="418,56 482,56 482,218 450,250 418,218" fill="${escapeAttr(data.s)}" stroke="#111" stroke-width="2"></polygon>` : ''}

                ${showB1 ? `<polyline points="105,34 450,238" fill="none" stroke="${escapeAttr(data.b1l)}" stroke-width="${borderWidth1}"></polyline>` : ''}
                ${showB1 ? `<polyline points="795,34 450,238" fill="none" stroke="${escapeAttr(data.b1r)}" stroke-width="${borderWidth1}"></polyline>` : ''}

                ${showB2 ? `<polyline points="140,45 450,225" fill="none" stroke="${escapeAttr(data.b2l)}" stroke-width="10"></polyline>` : ''}
                ${showB2 ? `<polyline points="760,45 450,225" fill="none" stroke="${escapeAttr(data.b2r)}" stroke-width="10"></polyline>` : ''}

                ${showB3 ? `<polyline points="175,56 450,212" fill="none" stroke="${escapeAttr(data.b3l)}" stroke-width="8"></polyline>` : ''}
                ${showB3 ? `<polyline points="725,56 450,212" fill="none" stroke="${escapeAttr(data.b3r)}" stroke-width="8"></polyline>` : ''}
            </svg>
        `;
    }

    function normaliseColour(value) {
        value = String(value || '').trim();

        if (/^#[0-9a-fA-F]{6}$/.test(value)) {
            return value;
        }

        return '#000000';
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function escapeAttr(value) {
        return escapeHtml(normaliseColour(value));
    }

    if (scarfJson && scarfType) {
        const initial = parseScarf();

        setColourInputValues(initial);
        renderScarfPreview(initial);
        renderPresets();

        document.querySelectorAll('[data-key]').forEach((input) => {
            input.addEventListener('input', readScarfFromInputs);
            input.addEventListener('change', readScarfFromInputs);
        });

        scarfType.addEventListener('change', readScarfFromInputs);

        if (matchSides) {
            matchSides.addEventListener('click', function () {
                const data = parseScarf();

                data.r = data.l;
                data.b1r = data.b1l;
                data.b2r = data.b2l;
                data.b3r = data.b3l;

                writeScarf(data);
                setColourInputValues(data);
                renderScarfPreview(data);
            });
        }

        readScarfFromInputs();
    }
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>