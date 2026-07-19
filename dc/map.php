<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$ctx = dc_require_access();

function dc_map_table_exists(string $table): bool
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

function dc_map_column_exists(string $table, string $column): bool
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

function dc_map_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function dc_map_find_event_risk_mapping(): ?array
{
    static $mapping = null;
    static $checked = false;

    if ($checked) {
        return $mapping;
    }

    $checked = true;

    $preferredTables = [
        'event_risk_assessments',
        'calendar_event_risk_assessments',
        'calendar_event_risk_assessment_links',
        'event_risk_assessment_links',
        'risk_assessment_event_links',
        'event_risk_links',
    ];

    foreach ($preferredTables as $table) {
        if (!dc_map_table_exists($table)) {
            continue;
        }

        if (dc_map_column_exists($table, 'event_id') && dc_map_column_exists($table, 'risk_assessment_id')) {
            return $mapping = [
                'table' => $table,
                'event_column' => 'event_id',
                'risk_column' => 'risk_assessment_id',
            ];
        }

        if (dc_map_column_exists($table, 'calendar_event_id') && dc_map_column_exists($table, 'risk_assessment_id')) {
            return $mapping = [
                'table' => $table,
                'event_column' => 'calendar_event_id',
                'risk_column' => 'risk_assessment_id',
            ];
        }
    }

    try {
        $stmt = db()->query("
            SELECT DISTINCT TABLE_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND COLUMN_NAME IN ('event_id', 'calendar_event_id', 'risk_assessment_id')
            ORDER BY TABLE_NAME ASC
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $table = (string) $table;

            if (!preg_match('/risk|assessment|event/i', $table)) {
                continue;
            }

            if (dc_map_column_exists($table, 'event_id') && dc_map_column_exists($table, 'risk_assessment_id')) {
                return $mapping = [
                    'table' => $table,
                    'event_column' => 'event_id',
                    'risk_column' => 'risk_assessment_id',
                ];
            }

            if (dc_map_column_exists($table, 'calendar_event_id') && dc_map_column_exists($table, 'risk_assessment_id')) {
                return $mapping = [
                    'table' => $table,
                    'event_column' => 'calendar_event_id',
                    'risk_column' => 'risk_assessment_id',
                ];
            }
        }
    } catch (Throwable $e) {
        return $mapping = null;
    }

    return $mapping = null;
}

function dc_map_fetch_event_risks(array $eventIds): array
{
    $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds))));

    if (!$eventIds) {
        return [];
    }

    $mapping = dc_map_find_event_risk_mapping();

    if (!$mapping) {
        return [];
    }

    $table = dc_map_quote_identifier((string) $mapping['table']);
    $eventColumn = dc_map_quote_identifier((string) $mapping['event_column']);
    $riskColumn = dc_map_quote_identifier((string) $mapping['risk_column']);

    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));

    $sql = "
        SELECT
            er.{$eventColumn} AS event_id,
            ra.id,
            ra.title,
            ra.visibility,
            ra.original_filename,
            g.group_name
        FROM {$table} er
        JOIN risk_assessments ra
          ON ra.id = er.{$riskColumn}
        JOIN groups g
          ON g.id = ra.group_id
        WHERE er.{$eventColumn} IN ({$placeholders})
          AND ra.status = 'active'
          AND ra.admin_review_status = 'available'
        ORDER BY ra.title ASC
    ";

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($eventIds);

        $out = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['event_id']][] = $row;
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function dc_map_user_can_manage_event(array $ctx, int $groupId): bool
{
    if (!empty($ctx['is_reviewer'])) {
        return true;
    }

    return in_array($groupId, array_map('intval', (array) ($ctx['group_ids'] ?? [])), true);
}

function dc_map_compact(?string $value, int $limit = 140): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strlen') && mb_strlen($value) > $limit) {
        return mb_substr($value, 0, $limit - 1) . '…';
    }

    if (strlen($value) > $limit) {
        return substr($value, 0, $limit - 1) . '…';
    }

    return $value;
}

$accessibleGroups = dc_accessible_groups();
$showGroupPicker = count($accessibleGroups) > 1;

$selectedGroupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : 0;
$search = trim((string) ($_GET['q'] ?? ''));
$dateRange = (string) ($_GET['date_range'] ?? 'upcoming');
$statusFilter = (string) ($_GET['status'] ?? 'active');

if (!in_array($dateRange, ['upcoming', '90', '365', 'all'], true)) {
    $dateRange = 'upcoming';
}

if (!in_array($statusFilter, ['active', 'approved', 'submitted', 'all'], true)) {
    $statusFilter = 'active';
}

$where = [
    "ce.status <> 'cancelled'",
    "ce.location_lat IS NOT NULL",
    "ce.location_lng IS NOT NULL",
    "ce.location_lat <> ''",
    "ce.location_lng <> ''",
    "g.is_active = 1",
];

$params = [];

if ($selectedGroupId > 0) {
    $where[] = "ce.group_id = :group_id";
    $params['group_id'] = $selectedGroupId;
}

if ($statusFilter === 'approved') {
    $where[] = "ce.status = 'approved'";
} elseif ($statusFilter === 'submitted') {
    $where[] = "ce.status IN ('submitted', 'under_review', 'changes_requested')";
} elseif ($statusFilter === 'active') {
    $where[] = "ce.status IN ('submitted', 'under_review', 'approved', 'changes_requested')";
}

if ($dateRange === 'upcoming') {
    $where[] = "ce.ends_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $where[] = "ce.starts_at <= DATE_ADD(NOW(), INTERVAL 365 DAY)";
} elseif ($dateRange === '90') {
    $where[] = "ce.ends_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
} elseif ($dateRange === '365') {
    $where[] = "ce.ends_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)";
}

if ($search !== '') {
    $where[] = "(
        ce.title LIKE :search
        OR ce.description LIKE :search
        OR ce.location_name LIKE :search
        OR ce.location_address LIKE :search
        OR ce.leader_name LIKE :search
        OR ce.leader_email LIKE :search
        OR g.group_name LIKE :search
    )";
    $params['search'] = '%' . $search . '%';
}

$sql = "
    SELECT ce.*, g.group_name
    FROM calendar_events ce
    JOIN groups g ON g.id = ce.group_id
    WHERE " . implode("\n      AND ", $where) . "
    ORDER BY ce.starts_at ASC
    LIMIT 500
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$eventIds = array_map(static fn(array $event): int => (int) $event['id'], $events);
$risksByEvent = dc_map_fetch_event_risks($eventIds);

$mapEvents = [];

foreach ($events as $event) {
    $eventId = (int) $event['id'];
    $canManage = dc_map_user_can_manage_event($ctx, (int) $event['group_id']);

    $mapEvents[] = [
        'id' => $eventId,
        'title' => (string) ($event['title'] ?? 'Untitled event'),
        'description' => dc_map_compact((string) ($event['description'] ?? ''), 220),
        'group_name' => (string) ($event['group_name'] ?? ''),
        'status' => (string) ($event['status'] ?? ''),
        'starts_at' => !empty($event['starts_at']) ? date('D j M Y, H:i', strtotime((string) $event['starts_at'])) : '',
        'ends_at' => !empty($event['ends_at']) ? date('D j M Y, H:i', strtotime((string) $event['ends_at'])) : '',
        'location_name' => (string) ($event['location_name'] ?? ''),
        'location_address' => (string) ($event['location_address'] ?? ''),
        'lat' => (float) $event['location_lat'],
        'lng' => (float) $event['location_lng'],
        'leader_name' => (string) ($event['leader_name'] ?? ''),
        'leader_email' => (string) ($event['leader_email'] ?? ''),
        'leader_phone' => (string) ($event['leader_phone'] ?? ''),
        'can_manage' => $canManage,
        'manage_url' => $canManage ? '/dc/manage-event.php?id=' . $eventId : '',
        'risks' => array_map(static fn(array $risk): array => [
            'id' => (int) $risk['id'],
            'title' => (string) $risk['title'],
            'download_url' => '/dc/download-risk-assessment.php?id=' . (int) $risk['id'],
            'group_name' => (string) ($risk['group_name'] ?? ''),
            'visibility' => (string) ($risk['visibility'] ?? ''),
        ], $risksByEvent[$eventId] ?? []),
    ];
}

$pageTitle = 'Map';
$heroTitle = 'Calendar map';
$heroText = 'See where Groups have been and open event details, contacts and linked risk assessments.';
$active = 'map';

require __DIR__ . '/layout.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    .dc-map-toolbar { background:#fff; border:1px solid var(--dc-border, #e2e8f0); border-radius:0.375rem; padding:1rem; margin-bottom:1rem; }
    .dc-map-toolbar-grid { display:grid; gap:.75rem; }
    @media (min-width: 900px) { .dc-map-toolbar-grid { grid-template-columns:minmax(220px,1.7fr) repeat(3,minmax(140px,1fr)) auto; align-items:end; } }
    .dc-map-shell { background:#fff; border:1px solid var(--dc-border, #e2e8f0); border-radius:0.375rem; overflow:hidden; }
    .dc-map-summary { padding:.75rem 1rem; background:var(--dc-canvas, #f8fafc); border-bottom:1px solid var(--dc-border, #e2e8f0); font-weight:600; font-size:0.88rem; display:flex; flex-wrap:wrap; gap:.5rem 1rem; color:var(--dc-muted, #64748b); }
    #dc-map { width:100%; height:68vh; min-height:420px; background:#f3f2f1; }
    @media (max-width:600px) { #dc-map { height:60vh; min-height:360px; } }
    .dc-map-empty { padding:1rem; }
    .dc-map-popup { min-width:240px; max-width:340px; }
    .dc-map-popup h3 { margin:0 0 .35rem; color:var(--dc-scouts-purple-dark, #4d0b93); font-size:1rem; font-weight:700; line-height:1.2; }
    .dc-map-popup p { margin:.25rem 0; font-size:.85rem; }
    .dc-map-popup ul { margin:.35rem 0 0; padding-left:1.1rem; }
    .dc-map-popup-badge { display:inline-block; margin:.35rem .25rem .35rem 0; padding:.15rem .45rem; border-radius:0.25rem; background:var(--dc-canvas, #f8fafc); border:1px solid var(--dc-border, #e2e8f0); color:var(--dc-ink, #1d2939); font-size:.75rem; font-weight:700; }
    .dc-map-popup-action { display:inline-block; margin-top:.45rem; padding:.35rem .55rem; border-radius:0.375rem; background:var(--dc-scouts-purple, #7413dc); color:#fff !important; text-decoration:none; font-weight:700; font-size:.85rem; }
    .dc-map-list { display:grid; gap:.5rem; margin-top:1rem; }
    .dc-map-list-item { background:#fff; border:1px solid var(--dc-border, #e2e8f0); border-radius:0.375rem; padding:.8rem; display:grid; gap:.5rem; }
    @media (min-width:760px) { .dc-map-list-item { grid-template-columns:minmax(0,1fr) auto; align-items:center; } }
    .dc-map-list-item h3 { margin:0; color:var(--dc-scouts-purple-dark, #4d0b93); font-size:.95rem; font-weight:700; }
    .dc-map-list-item p { margin:.25rem 0 0; color:var(--dc-muted, #64748b); font-weight:500; font-size:0.88rem; }
    .dc-map-risk-links { margin-top:.3rem; font-size:.85rem; }
    .dc-map-risk-links a { font-weight:700; }
    .dc-map-list-button { display:inline-flex; align-items:center; justify-content:center; min-height:36px; padding:.35rem .65rem; border-radius:0.375rem; background:var(--dc-scouts-purple, #7413dc); color:#fff; text-decoration:none; font-weight:700; font-size:0.85rem; border:0; cursor:pointer; }
</style>

<section class="dc-map-toolbar" aria-label="Map filters">
    <form method="get">
        <div class="dc-map-toolbar-grid">
            <div class="form-group mb-0">
                <label for="q">Search</label>
                <input type="search" id="q" name="q" class="form-control" value="<?= e($search) ?>" placeholder="Event, Group, location or contact">
            </div>

            <?php if ($showGroupPicker): ?>
                <div class="form-group mb-0">
                    <label for="group_id">Group</label>
                    <select id="group_id" name="group_id" class="form-control">
                        <option value="0" <?= $selectedGroupId === 0 ? 'selected' : '' ?>>All Groups</option>
                        <?php foreach ($accessibleGroups as $group): ?>
                            <?php $gid = (int) ($group['id'] ?? $group['group_id'] ?? 0); ?>
                            <option value="<?= $gid ?>" <?= $selectedGroupId === $gid ? 'selected' : '' ?>>
                                <?= e((string) ($group['group_name'] ?? 'Unknown Group')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="form-group mb-0">
                <label for="date_range">Date range</label>
                <select id="date_range" name="date_range" class="form-control">
                    <option value="upcoming" <?= $dateRange === 'upcoming' ? 'selected' : '' ?>>Upcoming and recent</option>
                    <option value="90" <?= $dateRange === '90' ? 'selected' : '' ?>>Last 90 days</option>
                    <option value="365" <?= $dateRange === '365' ? 'selected' : '' ?>>Last year</option>
                    <option value="all" <?= $dateRange === 'all' ? 'selected' : '' ?>>All mapped events</option>
                </select>
            </div>

            <div class="form-group mb-0">
                <label for="status">Status</label>
                <select id="status" name="status" class="form-control">
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active statuses</option>
                    <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved only</option>
                    <option value="submitted" <?= $statusFilter === 'submitted' ? 'selected' : '' ?>>In review</option>
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All except cancelled</option>
                </select>
            </div>

            <div class="form-group mb-0">
                <button class="btn btn-primary lt-btn" type="submit">Update map</button>
            </div>
        </div>
    </form>
</section>

<section class="dc-map-shell" aria-label="Event map">
    <div class="dc-map-summary">
        <span><?= count($mapEvents) ?> mapped event<?= count($mapEvents) === 1 ? '' : 's' ?></span>
        <span>Click a marker to view event details and linked risk assessments.</span>
    </div>

    <?php if (!$mapEvents): ?>
        <div class="dc-map-empty">
            <h2 class="lt-section-title">No mapped events found</h2>
            <p class="mb-0">Try widening the date range, clearing the search, or checking that events have latitude and longitude saved.</p>
        </div>
    <?php else: ?>
        <div id="dc-map"></div>
    <?php endif; ?>
</section>

<?php if ($mapEvents): ?>
    <section aria-labelledby="mapped-events-heading">
        <h2 id="mapped-events-heading" class="lt-section-title mt-4">Mapped events list</h2>

        <div class="dc-map-list">
            <?php foreach ($mapEvents as $event): ?>
                <article class="dc-map-list-item">
                    <div>
                        <h3><?= e($event['title']) ?></h3>
                        <p><strong><?= e($event['group_name']) ?></strong> · <?= e($event['starts_at']) ?> · <?= e($event['location_name'] ?: 'Location not named') ?></p>

                        <?php if ($event['risks']): ?>
                            <div class="dc-map-risk-links">
                                Risk assessments:
                                <?php foreach ($event['risks'] as $index => $risk): ?>
                                    <?= $index > 0 ? ' · ' : '' ?>
                                    <a href="<?= e($risk['download_url']) ?>"><?= e($risk['title']) ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <button type="button" class="dc-map-list-button" data-event-id="<?= (int) $event['id'] ?>">Show on map</button>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($mapEvents): ?>
<script>
(function () {
    var events = <?= json_encode($mapEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var mapElement = document.getElementById('dc-map');

    if (!mapElement || !events.length || typeof L === 'undefined') {
        return;
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
        });
    }

    function popupHtml(event) {
        var location = event.location_name || event.location_address || 'Location not named';

        var risks = event.risks && event.risks.length
            ? '<p><strong>Risk assessments used</strong></p><ul>' + event.risks.map(function (risk) {
                return '<li><a href="' + escapeHtml(risk.download_url) + '">' + escapeHtml(risk.title) + '</a></li>';
            }).join('') + '</ul>'
            : '<p><strong>Risk assessments:</strong> none linked</p>';

        var contact = '<p><strong>Main contact</strong><br>';
        contact += event.leader_name ? escapeHtml(event.leader_name) + '<br>' : 'Not set<br>';
        if (event.leader_email) contact += '<a href="mailto:' + escapeHtml(event.leader_email) + '">' + escapeHtml(event.leader_email) + '</a><br>';
        if (event.leader_phone) contact += escapeHtml(event.leader_phone);
        contact += '</p>';

        var manage = event.can_manage && event.manage_url
            ? '<a class="dc-map-popup-action" href="' + escapeHtml(event.manage_url) + '">Open event</a>'
            : '<p><em>You can view basic details, but only the owning Group or District reviewers can manage this event.</em></p>';

        return '<div class="dc-map-popup">' +
            '<h3>' + escapeHtml(event.title) + '</h3>' +
            '<span class="dc-map-popup-badge">' + escapeHtml(event.group_name) + '</span>' +
            '<span class="dc-map-popup-badge">' + escapeHtml(event.status) + '</span>' +
            '<p><strong>When:</strong><br>' + escapeHtml(event.starts_at) + (event.ends_at ? '<br>to ' + escapeHtml(event.ends_at) : '') + '</p>' +
            '<p><strong>Where:</strong><br>' + escapeHtml(location) + '</p>' +
            (event.description ? '<p>' + escapeHtml(event.description) + '</p>' : '') +
            contact + risks + manage + '</div>';
    }

    var map = L.map('dc-map', { scrollWheelZoom: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var markers = {};
    var bounds = [];

    events.forEach(function (event) {
        var marker = L.marker([event.lat, event.lng]).addTo(map);
        marker.bindPopup(popupHtml(event), { maxWidth: 360 });
        markers[event.id] = marker;
        bounds.push([event.lat, event.lng]);
    });

    if (bounds.length === 1) {
        map.setView(bounds[0], 12);
    } else {
        map.fitBounds(bounds, { padding: [28, 28], maxZoom: 13 });
    }

    document.querySelectorAll('[data-event-id]').forEach(function (button) {
        button.addEventListener('click', function () {
            var marker = markers[button.getAttribute('data-event-id')];
            if (!marker) return;
            map.setView(marker.getLatLng(), Math.max(map.getZoom(), 13));
            marker.openPopup();
            mapElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });
}());
</script>
<?php endif; ?>

<?php require __DIR__ . '/layout-footer.php'; ?>