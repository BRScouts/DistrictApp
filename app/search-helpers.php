<?php

declare(strict_types=1);

/**
 * Global search engine shared by the header autocomplete endpoint
 * (/search.php) and the full results page (/search-results.php).
 *
 * Every result is returned in a normalised shape:
 *   [
 *     'category' => 'module'|'calendar'|'risk'|'person'|'help',
 *     'badge'    => 'Module'|'Calendar'|'Risk Assessment'|'Person'|'Help',
 *     'title'    => string,
 *     'subtitle' => string,   // group / date / email etc.
 *     'url'      => string,    // where the result opens
 *     'external' => bool,      // open in new tab
 *   ]
 *
 * Permission rules are intentionally identical to the pages the
 * results link to, so a user can never surface something they are
 * not allowed to see:
 *   - Calendar events:  own groups' events + all district events
 *                       (reviewers/admins see everything).
 *   - Risk assessments: district-visibility to all, group-visibility
 *                       only to members of that group.
 *   - People:           active people in the directory.
 *   - Modules:          only tiles visible to the current user.
 */

require_once __DIR__ . '/../dc/auth.php';

/**
 * The searchable source categories and their display badge labels.
 */
function search_categories(): array
{
    return [
        'module'   => 'Module',
        'calendar' => 'Calendar',
        'risk'     => 'Risk Assessment',
        'person'   => 'Person',
        'help'     => 'Help',
    ];
}

/**
 * Catalogue of portal "modules" (the dashboard tiles) that can be
 * searched by name. Visibility flags mirror index.php's role gating
 * so a user only matches tiles they can actually open.
 */
function search_module_catalogue(array $flags): array
{
    $isSystemAdmin   = !empty($flags['is_system_admin']);
    $isDistrictAdmin = $isSystemAdmin || !empty($flags['is_district_admin']);
    $isGroupAdmin    = $isDistrictAdmin || !empty($flags['is_group_admin']);

    $modules = [
        ['title' => 'District Calendar',   'description' => 'Away-from-hut notifications, Group activity and risk assessments.', 'url' => '/dc/',                       'visible' => true],
        ['title' => 'District Directory',  'description' => 'Find leaders and volunteers by name, Group, role or section.',      'url' => '/directory.php',             'visible' => true],
        ['title' => 'Risk Assessments',    'description' => 'Browse and download shared District risk assessments.',             'url' => '/dc/risk-assessments.php',   'visible' => true],
        ['title' => 'Technical Support',   'description' => 'Report a problem or request a dashboard change.',                   'url' => '/technical-support.php',     'visible' => true],
        ['title' => 'My District Email / OneDrive', 'description' => 'Microsoft 365 email, OneDrive and apps.',                  'url' => 'https://outlook.cloud.microsoft/', 'visible' => true, 'external' => true],
        ['title' => 'Roll of Honour',      'description' => 'Nominate a volunteer for the District Roll of Honour.',             'url' => 'https://forms.cloud.microsoft/Pages/ResponsePage.aspx?id=o0n0PQcw006x9bzynIL4rBErZYBckIpEmBAYxB6OaWlUN1FDRDI2NlhEQzJFSVQ0N1FTMU9QSE9SSS4u', 'visible' => true, 'external' => true],
        ['title' => 'Group Manager',       'description' => 'Manage leaders in your Group and request M365 accounts.',           'url' => '/group-manager.php',         'visible' => $isGroupAdmin],
        ['title' => 'District Admin',      'description' => 'Create Groups, assign GLVs and manage permissions.',                'url' => '/district-admin.php',        'visible' => $isDistrictAdmin],
        ['title' => 'Comms Tool',          'description' => 'Prepare District communications and targeted messages.',            'url' => '/comms-tool.php',            'visible' => $isDistrictAdmin],
        ['title' => 'System Admin',        'description' => 'System settings, audit logs, cron jobs and configuration.',         'url' => '/system-admin-dashboard.php','visible' => $isSystemAdmin],
    ];

    return array_values(array_filter($modules, static fn (array $m): bool => (bool) ($m['visible'] ?? false)));
}

/**
 * Resolve the current user's role flags from the portal session user.
 */
function search_user_flags(): array
{
    $user = current_user() ?? [];
    $level = (string) ($user['highest_access_level'] ?? $user['role'] ?? 'member');

    return [
        'is_system_admin'   => $level === 'system_admin',
        'is_district_admin' => in_array($level, ['system_admin', 'district_admin'], true),
        'is_group_admin'    => in_array($level, ['system_admin', 'district_admin', 'group_admin'], true),
    ];
}

/**
 * Case-insensitive "needle in haystack" used to rank module matches.
 */
function search_text_matches(string $haystack, string $needle): bool
{
    return $needle === '' || stripos($haystack, $needle) !== false;
}

/**
 * Run the global search.
 *
 * @param string   $query       The raw search term.
 * @param string[] $categories  Which categories to include (defaults to all).
 * @param int      $perCategory Max rows per category (e.g. 3 for the dropdown).
 * @return array{results: array<int, array<string, mixed>>, counts: array<string, int>}
 */
function search_run(string $query, array $categories = [], int $perCategory = 25): array
{
    $query = trim($query);
    $allCategories = array_keys(search_categories());

    if (!$categories) {
        $categories = $allCategories;
    } else {
        $categories = array_values(array_intersect($allCategories, $categories));
        if (!$categories) {
            $categories = $allCategories;
        }
    }

    $results = [];
    $counts = [];

    foreach ($categories as $category) {
        $rows = match ($category) {
            'module'   => search_modules($query, $perCategory),
            'calendar' => search_calendar_events($query, $perCategory),
            'risk'     => search_risk_assessments($query, $perCategory),
            'person'   => search_people($query, $perCategory),
            'help'     => search_help_articles($query, $perCategory),
            default    => [],
        };

        $counts[$category] = count($rows);
        $results = array_merge($results, $rows);
    }

    return ['results' => $results, 'counts' => $counts];
}

/**
 * Search the dashboard module/tile names (name + description).
 */
function search_modules(string $query, int $limit): array
{
    if ($query === '') {
        return [];
    }

    $flags = search_user_flags();
    $catalogue = search_module_catalogue($flags);
    $out = [];

    foreach ($catalogue as $module) {
        $haystack = $module['title'] . ' ' . ($module['description'] ?? '');

        if (!search_text_matches($haystack, $query)) {
            continue;
        }

        $out[] = [
            'category' => 'module',
            'badge'    => 'Module',
            'title'    => (string) $module['title'],
            'subtitle' => (string) ($module['description'] ?? ''),
            'url'      => (string) $module['url'],
            'external' => (bool) ($module['external'] ?? false),
        ];

        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

/**
 * Search calendar events the user is allowed to SEE.
 * Own groups' events + all district events; reviewers/admins see all.
 */
function search_calendar_events(string $query, int $limit): array
{
    if ($query === '') {
        return [];
    }

    $ctx = dc_context(false);
    if (empty($ctx['is_signed_in'])) {
        return [];
    }

    $hasDistrictColumn = dc_table_has_column('calendar_events', 'is_district_event');
    $isDistrictLevel = dc_context_has_reviewer_access($ctx) || dc_context_has_admin_access($ctx);
    $groupIds = array_values(array_unique(array_map('intval', (array) ($ctx['group_ids'] ?? []))));

    $where = [];
    $params = [];

    $like = '%' . $query . '%';
    $where[] = '(ce.title LIKE ? OR ce.description LIKE ? OR ce.location_name LIKE ? OR ce.location_address LIKE ? OR ce.leader_name LIKE ? OR g.group_name LIKE ?)';
    for ($i = 0; $i < 6; $i++) {
        $params[] = $like;
    }

    if (!$isDistrictLevel) {
        if (!$groupIds) {
            $groupIds = [0];
        }

        $groupClause = 'ce.group_id IN (' . implode(',', array_fill(0, count($groupIds), '?')) . ')';
        if ($hasDistrictColumn) {
            $groupClause = '(' . $groupClause . ' OR ce.is_district_event = 1)';
        }

        $where[] = $groupClause;
        foreach ($groupIds as $gid) {
            $params[] = $gid;
        }
    }

    $whereSql = implode("\n          AND ", $where);

    $sql = "
        SELECT ce.id, ce.title, ce.group_id, ce.starts_at, g.group_name"
        . ($hasDistrictColumn ? ', ce.is_district_event' : '') . "
        FROM calendar_events ce
        JOIN groups g ON g.id = ce.group_id
        WHERE {$whereSql}
        ORDER BY ce.starts_at DESC
        LIMIT " . (int) $limit;

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];

    foreach ($rows as $row) {
        $groupId = (int) ($row['group_id'] ?? 0);
        $isDistrict = $hasDistrictColumn && (int) ($row['is_district_event'] ?? 0) === 1;
        $canEdit = dc_user_can_manage_group($groupId);

        $when = !empty($row['starts_at']) ? date('j M Y', strtotime((string) $row['starts_at'])) : '';
        $subParts = [];
        if ($when !== '') {
            $subParts[] = $when;
        }
        $subParts[] = $isDistrict ? 'District event' : (string) ($row['group_name'] ?? '');
        if ($canEdit) {
            $subParts[] = 'you can edit';
        }

        $out[] = [
            'category'  => 'calendar',
            'badge'     => 'Calendar',
            'title'     => (string) ($row['title'] ?? 'Untitled event'),
            'subtitle'  => implode(' · ', array_filter($subParts)),
            'url'       => '/dc/event.php?id=' . (int) $row['id'],
            'external'  => false,
            'can_edit'  => $canEdit,
        ];
    }

    return $out;
}

/**
 * Search risk assessments the user is allowed to see.
 * District-visibility to everyone; group-visibility only to members.
 */
function search_risk_assessments(string $query, int $limit): array
{
    if ($query === '') {
        return [];
    }

    $ctx = dc_context(false);
    if (empty($ctx['is_signed_in'])) {
        return [];
    }

    $isDistrictLevel = dc_context_has_reviewer_access($ctx) || dc_context_has_admin_access($ctx);
    $groupIds = array_values(array_unique(array_map('intval', (array) ($ctx['group_ids'] ?? []))));

    $where = ["ra.status = 'active'"];
    $params = [];

    // Only surface risk assessments that have cleared admin review, matching
    // the behaviour of /dc/risk-assessments.php (guarded for older schemas).
    if (dc_table_has_column('risk_assessments', 'admin_review_status')) {
        $where[] = "ra.admin_review_status = 'available'";
    }

    $like = '%' . $query . '%';
    $where[] = '(ra.title LIKE ? OR ra.description LIKE ? OR g.group_name LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;

    if (!$isDistrictLevel) {
        // District-visibility is readable by everyone; otherwise only own groups.
        $visibilityClause = "ra.visibility = 'district'";

        if ($groupIds) {
            $visibilityClause = '(' . $visibilityClause
                . ' OR ra.group_id IN (' . implode(',', array_fill(0, count($groupIds), '?')) . '))';
        }

        $where[] = $visibilityClause;
        foreach ($groupIds as $gid) {
            $params[] = $gid;
        }
    }

    $whereSql = implode("\n          AND ", $where);

    $sql = "
        SELECT ra.id, ra.title, ra.group_id, ra.visibility, g.group_name
        FROM risk_assessments ra
        JOIN groups g ON g.id = ra.group_id
        WHERE {$whereSql}
        ORDER BY ra.title ASC
        LIMIT " . (int) $limit;

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];

    foreach ($rows as $row) {
        $visibility = (string) ($row['visibility'] ?? 'group');
        $scope = $visibility === 'district' ? 'District-wide' : (string) ($row['group_name'] ?? '');

        $out[] = [
            'category' => 'risk',
            'badge'    => 'Risk Assessment',
            'title'    => (string) ($row['title'] ?? 'Risk assessment'),
            'subtitle' => $scope,
            'url'      => '/dc/risk-assessments.php?q=' . rawurlencode((string) ($row['title'] ?? '')),
            'external' => false,
        ];
    }

    return $out;
}

/**
 * Search published help centre articles (title + summary + keywords).
 * Visible to any signed-in user; returns nothing if the table is absent.
 */
function search_help_articles(string $query, int $limit): array
{
    if ($query === '') {
        return [];
    }

    $ctx = dc_context(false);
    if (empty($ctx['is_signed_in'])) {
        return [];
    }

    $like = '%' . $query . '%';
    $sql = "
        SELECT slug, title, summary
        FROM help_articles
        WHERE status = 'published'
          AND (title LIKE ? OR summary LIKE ? OR keywords LIKE ?)
        ORDER BY title ASC
        LIMIT " . (int) $limit;

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute([$like, $like, $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];

    foreach ($rows as $row) {
        $out[] = [
            'category' => 'help',
            'badge'    => 'Help',
            'title'    => (string) ($row['title'] ?? ''),
            'subtitle' => (string) ($row['summary'] ?? ''),
            'url'      => '/help-centre.php?slug=' . rawurlencode((string) ($row['slug'] ?? '')),
            'external' => false,
        ];
    }

    return $out;
}

/**
 * Search active people in the District directory.
 */
function search_people(string $query, int $limit): array
{
    if ($query === '') {
        return [];
    }

    $where = ["p.status = 'active'"];
    $params = [];

    $like = '%' . $query . '%';
    $where[] = '(p.full_name LIKE ? OR p.preferred_name LIKE ? OR p.primary_email LIKE ? OR dp.role_title LIKE ? OR g.group_name LIKE ?)';
    for ($i = 0; $i < 5; $i++) {
        $params[] = $like;
    }

    $whereSql = implode("\n          AND ", $where);

    $sql = "
        SELECT
            p.id,
            p.full_name,
            p.primary_email,
            dp.role_title,
            GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.group_name SEPARATOR ', ') AS group_names
        FROM people p
        LEFT JOIN directory_profiles dp ON dp.person_id = p.id
        LEFT JOIN group_memberships gm ON gm.person_id = p.id AND gm.status = 'active'
        LEFT JOIN groups g ON g.id = gm.group_id AND g.is_active = 1
        WHERE {$whereSql}
        GROUP BY p.id, p.full_name, p.primary_email, dp.role_title
        ORDER BY p.full_name ASC
        LIMIT " . (int) $limit;

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];

    foreach ($rows as $row) {
        $subParts = array_filter([
            (string) ($row['role_title'] ?? ''),
            (string) ($row['group_names'] ?? ''),
        ]);

        $out[] = [
            'category' => 'person',
            'badge'    => 'Person',
            'title'    => (string) ($row['full_name'] ?? 'Unknown'),
            'subtitle' => implode(' · ', $subParts) ?: (string) ($row['primary_email'] ?? ''),
            'url'      => '/directory.php?q=' . rawurlencode((string) ($row['full_name'] ?? '')),
            'external' => false,
        ];
    }

    return $out;
}
