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

    /*
     * WordPress must define these itself from wp-config.php.
     * If the Leader Tool bootstrap has already defined them, WordPress may try
     * to connect to the wrong database and show "Error establishing a database connection".
     */
    $reservedConstants = [
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
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
            '. Rename the Leader Tool database constants to APP_DB_NAME, APP_DB_USER, APP_DB_PASSWORD and APP_DB_HOST.'
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

function gwa_section_example_json(): string
{
    return json_encode([
        [
            'day' => '2',
            'type' => 'Beavers',
            'time_start' => '18:00',
            'time_finish' => '19:00',
            'name' => 'Beaver Scouts',
            'key' => 'beavers',
        ],
        [
            'day' => '2',
            'type' => 'Cubs',
            'time_start' => '19:00',
            'time_finish' => '20:30',
            'name' => 'Cub Scouts',
            'key' => 'cubs',
        ],
        [
            'day' => '2',
            'type' => 'Scouts',
            'time_start' => '20:00',
            'time_finish' => '21:30',
            'name' => 'Scouts',
            'key' => 'scouts',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function gwa_normalise_section_json(string $rawJson): string
{
    $rawJson = trim($rawJson);

    if ($rawJson === '') {
        return '[]';
    }

    $decoded = json_decode($rawJson, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Section details JSON is not valid.');
    }

    $normalised = [];

    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }

        $normalised[] = [
            'day' => (string) ($row['day'] ?? '0'),
            'type' => (string) ($row['type'] ?? '0'),
            'time_start' => (string) ($row['time_start'] ?? ''),
            'time_finish' => (string) ($row['time_finish'] ?? ''),
            'name' => trim((string) ($row['name'] ?? '')),
            'key' => trim((string) ($row['key'] ?? '')),
        ];
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
        'post_status' => 'draft',
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

    update_post_meta($postId, 'wpsl_group_website', $websiteUrl);
    update_post_meta($postId, 'wpsl_group_contact', $publicEmail);
    update_post_meta($postId, 'wpsl_group_type', '0');
    update_post_meta($postId, 'wpsl_section_details', '[]');

    gwa_update_group_linked_post_id((int) $group['id'], $postId);

    do_action('irval_leader_tool_group_store_created', $postId, $group);

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
        // Do not block saving if geocoding fails.
    }
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

    $postStatus = (string) ($input['post_status'] ?? 'publish');
    $postStatus = in_array($postStatus, ['publish', 'draft', 'pending'], true) ? $postStatus : 'publish';

    $updated = wp_update_post([
        'ID' => $postId,
        'post_title' => $title,
        'post_name' => gwa_slugify($title),
        'post_content' => trim((string) ($input['post_content'] ?? '')),
        'post_status' => $postStatus,
    ], true);

    if (is_wp_error($updated)) {
        throw new RuntimeException('WordPress could not update the Scout Group post: ' . $updated->get_error_message());
    }

    $sectionDetails = gwa_normalise_section_json((string) ($input['wpsl_section_details'] ?? ''));

    $meta = [
        'wpsl_address' => trim((string) ($input['wpsl_address'] ?? '')),
        'wpsl_city' => trim((string) ($input['wpsl_city'] ?? '')),
        'wpsl_state' => trim((string) ($input['wpsl_state'] ?? '')),
        'wpsl_zip' => trim((string) ($input['wpsl_zip'] ?? '')),
        'wpsl_country' => trim((string) ($input['wpsl_country'] ?? 'United Kingdom')),
        'wpsl_email' => trim((string) ($input['wpsl_email'] ?? '')),
        'wpsl_phone' => trim((string) ($input['wpsl_phone'] ?? '')),
        'wpsl_url' => trim((string) ($input['wpsl_url'] ?? '')),

        'wpsl_group_website' => trim((string) ($input['wpsl_group_website'] ?? '')),
        'wpsl_group_contact' => trim((string) ($input['wpsl_group_contact'] ?? '')),
        'wpsl_group_type' => trim((string) ($input['wpsl_group_type'] ?? '0')),
        'wpsl_group_link' => trim((string) ($input['wpsl_group_link'] ?? '')),
        'wpsl_group_link2' => trim((string) ($input['wpsl_group_link2'] ?? '')),
        'wpsl_group_link3' => trim((string) ($input['wpsl_group_link3'] ?? '')),
        'wpsl_section_scarf' => trim((string) ($input['wpsl_section_scarf'] ?? '')),
        'wpsl_section_details' => $sectionDetails,
    ];

    foreach ($meta as $key => $value) {
        if ($value === '') {
            delete_post_meta($postId, $key);
        } else {
            update_post_meta($postId, $key, $value);
        }
    }

    do_action('irval_leader_tool_group_store_updated', $postId, $meta, $groupId);

    gwa_try_geocode_store($postId, $meta);

    gwa_update_flexible('groups', 'id', $groupId, [
        'website_post_id' => $postId,
        'website_url' => $meta['wpsl_group_website'] ?: $meta['wpsl_url'] ?: null,
        'public_email' => $meta['wpsl_email'] ?: $meta['wpsl_group_contact'] ?: null,
        'contact_email' => $meta['wpsl_email'] ?: $meta['wpsl_group_contact'] ?: null,
        'meeting_place' => $meta['wpsl_address'] ?: null,
        'postcode' => $meta['wpsl_zip'] ?: null,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    clean_post_cache($postId);

    gwa_log_action($actorPersonId, 'group_website_details_updated', 'group', $groupId, [
        'website_post_id' => $postId,
    ]);
}

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
            $success = 'WordPress Scout Group post linked.';

        } elseif ($action === 'create_website_post') {
            $postId = gwa_create_wordpress_store_for_group($selectedGroup, $actorPersonId);

            $selectedGroup = gwa_fetch_group($selectedGroupId);
            $success = 'New WordPress Scout Group post created as a draft and linked.';

        } elseif ($action === 'save_website_details') {
            gwa_update_wordpress_store_from_form($selectedGroupId, $_POST, $actorPersonId);

            $selectedGroup = gwa_fetch_group($selectedGroupId);
            $success = 'Website details saved.';
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

$metaValue = static function (array $meta, string $key, string $fallback = ''): string {
    $value = $meta[$key] ?? $fallback;

    if (is_array($value)) {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    return (string) $value;
};

$pageTitle = 'Group Website Admin | ' . $appName;
$heroTitle = 'Group Website Admin';
$heroText = 'Manage the Scout Group details shown on the public District website.';
$breadcrumb = '<a href="/index.php">Home</a> / Group Website Admin';

include __DIR__ . '/header.php';
?>

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

    .gwa-json {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        min-height: 14rem;
    }

    .gwa-status {
        display: inline-block;
        padding: .2rem .5rem;
        border-radius: .25rem;
        font-weight: 800;
        background: #e7f1ff;
        color: #084298;
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

    <section class="lt-card">
        <h2 class="lt-section-title">WordPress link</h2>

        <?php if ($websiteLoadError): ?>
            <div class="alert alert-danger">
                <strong>WordPress connection problem:</strong>
                <?= e($websiteLoadError) ?>
            </div>
        <?php endif; ?>

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
                        value="<?= e((string) $websitePostId) ?>"
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

                <h3 class="h5 font-weight-bold">Create new website post</h3>

                <p>
                    Creates a new draft WordPress Store Locator post for this Group and links it to the Leader Tool record.
                </p>

                <button class="btn btn-secondary lt-btn" type="submit">
                    Create draft post
                </button>
            </form>
        </div>

        <?php if ($websitePost): ?>
            <p class="gwa-muted">
                Linked post:
                <strong><?= e((string) $websitePost['post_title']) ?></strong>
                <span class="gwa-status"><?= e((string) $websitePost['post_status']) ?></span>
                ·
                <a href="<?= e(gwa_wp_store_permalink($websitePostId)) ?>" target="_blank" rel="noopener">
                    open public page
                </a>
            </p>
        <?php elseif ($websitePostId > 0 && !$websiteLoadError): ?>
            <div class="alert alert-warning">
                A post ID is stored for this Group, but the WordPress post could not be found as a <code>wpsl_stores</code> post.
            </div>
        <?php endif; ?>
    </section>

    <?php if ($websitePost): ?>
        <form method="post">
            <input type="hidden" name="action" value="save_website_details">
            <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
            <input type="hidden" name="website_post_id" value="<?= (int) $websitePostId ?>">

            <section class="lt-card">
                <h2 class="lt-section-title">Public page content</h2>

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
                    <label for="post_status">Website status</label>
                    <select class="form-control" id="post_status" name="post_status">
                        <?php
                            $status = (string) ($websitePost['post_status'] ?? 'draft');
                            $statuses = [
                                'publish' => 'Published',
                                'draft' => 'Draft',
                                'pending' => 'Pending review',
                            ];
                        ?>
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="post_content">Public description</label>
                    <textarea
                        class="form-control"
                        id="post_content"
                        name="post_content"
                        rows="7"
                    ><?= e((string) ($websitePost['post_content'] ?? '')) ?></textarea>
                </div>
            </section>

            <section class="lt-card">
                <h2 class="lt-section-title">Meeting and contact details</h2>

                <div class="form-group">
                    <label for="wpsl_address">Meeting place / address</label>
                    <textarea
                        class="form-control"
                        id="wpsl_address"
                        name="wpsl_address"
                        rows="3"
                    ><?= e($metaValue($websiteMeta, 'wpsl_address', (string) ($selectedGroup['meeting_place'] ?? ''))) ?></textarea>
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
                        <label for="wpsl_zip">Postcode</label>
                        <input
                            class="form-control"
                            type="text"
                            id="wpsl_zip"
                            name="wpsl_zip"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_zip', (string) ($selectedGroup['postcode'] ?? ''))) ?>"
                        >
                    </div>

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
                </div>

                <input type="hidden" name="wpsl_state" value="<?= e($metaValue($websiteMeta, 'wpsl_state')) ?>">

                <div class="gwa-form-row gwa-form-row-2">
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
                        <label for="wpsl_phone">Public phone</label>
                        <input
                            class="form-control"
                            type="text"
                            id="wpsl_phone"
                            name="wpsl_phone"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_phone')) ?>"
                        >
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

                <div class="form-group">
                    <label for="wpsl_group_contact">Contact text / contact URL</label>
                    <input
                        class="form-control"
                        type="text"
                        id="wpsl_group_contact"
                        name="wpsl_group_contact"
                        value="<?= e($metaValue($websiteMeta, 'wpsl_group_contact')) ?>"
                    >
                </div>
            </section>

            <section class="lt-card">
                <h2 class="lt-section-title">Scout display fields</h2>

                <div class="form-group">
                    <label for="wpsl_group_type">Group type</label>
                    <?php $groupType = $metaValue($websiteMeta, 'wpsl_group_type', '0'); ?>
                    <select class="form-control" id="wpsl_group_type" name="wpsl_group_type">
                        <option value="0" <?= $groupType === '0' ? 'selected' : '' ?>>Scout Group</option>
                        <option value="1" <?= $groupType === '1' ? 'selected' : '' ?>>Explorer Unit</option>
                        <option value="2" <?= $groupType === '2' ? 'selected' : '' ?>>Network</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="wpsl_section_scarf">Scarf data</label>
                    <textarea
                        class="form-control gwa-json"
                        id="wpsl_section_scarf"
                        name="wpsl_section_scarf"
                        rows="5"
                    ><?= e($metaValue($websiteMeta, 'wpsl_section_scarf')) ?></textarea>
                </div>

                <div class="form-group">
                    <label for="wpsl_section_details">Section details JSON</label>
                    <textarea
                        class="form-control gwa-json"
                        id="wpsl_section_details"
                        name="wpsl_section_details"
                        rows="14"
                    ><?= e($metaValue($websiteMeta, 'wpsl_section_details', gwa_section_example_json())) ?></textarea>
                    <small class="form-text text-muted">
                        Must be a JSON array. Each row can include <code>day</code>, <code>type</code>, <code>time_start</code>, <code>time_finish</code>, <code>name</code>, and <code>key</code>.
                    </small>
                </div>
            </section>

            <section class="lt-card">
                <h2 class="lt-section-title">Linked / partner Groups</h2>

                <div class="gwa-form-row gwa-form-row-3">
                    <div class="form-group">
                        <label for="wpsl_group_link">Partner post ID 1</label>
                        <input
                            class="form-control"
                            type="number"
                            min="0"
                            id="wpsl_group_link"
                            name="wpsl_group_link"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_group_link')) ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="wpsl_group_link2">Partner post ID 2</label>
                        <input
                            class="form-control"
                            type="number"
                            min="0"
                            id="wpsl_group_link2"
                            name="wpsl_group_link2"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_group_link2')) ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="wpsl_group_link3">Partner post ID 3</label>
                        <input
                            class="form-control"
                            type="number"
                            min="0"
                            id="wpsl_group_link3"
                            name="wpsl_group_link3"
                            value="<?= e($metaValue($websiteMeta, 'wpsl_group_link3')) ?>"
                        >
                    </div>
                </div>

                <button class="btn btn-primary lt-btn" type="submit">
                    Save website details
                </button>
            </section>
        </form>
    <?php else: ?>
        <section class="lt-card">
            <h2 class="lt-section-title">No linked website post yet</h2>
            <p class="mb-0">
                Link an existing WordPress Store Locator post above, or create a new draft post from this Group.
            </p>
        </section>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>