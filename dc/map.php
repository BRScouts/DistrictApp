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

function dc_map_event_risk_link_table(): ?string
{
    foreach (['event_risk_assessments', 'calendar_event_risk_assessments'] as $table) {
        if (
            dc_map_table_exists($table)
            && dc_map_column_exists($table, 'event_id')
            && dc_map_column_exists($table, 'risk_assessment_id')
        ) {
            return $table;
        }
    }

    return null;
}

function dc_map_js_event(array $event, array $risks): array
{
    $canOpen = dc_user_can_access_group((int) $event['group_id']);

    $startsAt = !empty($event['starts_at']) && strtotime((string) $event['starts_at'])
        ? date('D j M Y, H:i', strtotime((string) $event['starts_at']))
        : 'Unknown start';

    $endsAt = !empty($event['ends_at']) && strtotime((string) $event['ends_at'])
        ? date('D j M Y, H:i', strtotime((string) $event['ends_at']))
        : '';

    return [
        'id' => (int) $event['id'],
        'title' => (string) ($event['title'] ?? 'Untitled event'),
        'group_name' => (string) ($event['group_name'] ?? 'Unknown Group'),
        'status' => (string) ($event['status'] ?? ''),
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'location_name' => (string) ($event['location_name'] ?? ''),
        'location_address' => (string) ($event['location_address'] ?? ''),
        'lat' => (float) $event['location_lat'],
        'lng' => (float) $event['location_lng'],
        'leader_name' => (string) ($event['leader_name'] ?? ''),
        'leader_email' => (string) ($event['leader_email'] ?? ''),
        'leader_phone' => (string) ($event['leader_phone'] ?? ''),
        'can_open' => $canOpen,
        'manage_url' => $canOpen ? '/dc/manage-event.php?id=' . (int) $event['id'] : '',
        'risks' => array_map(static function (array $risk): array {
            return [
                'id' => (int) $risk['id'],
                'title' => (string) ($risk['title'] ?? 'Risk assessment'),
                'group_name' => (string) ($risk['group_name'] ?? ''),
                'visibility' => (string) ($risk['visibility'] ?? ''),
                'download_url' => '/dc/download-risk-assessment.php?id=' . (int) $risk['id'],
            ];
        }, $risks),
    ];
}

function dc_map_compact(string $value, int $limit = 120): string
{
    $value = trim($value);

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

$selectedGroupId = isset($_GET['group_id']) && (int) $_GET['group_id'] > 0
    ? (int) $_GET['group_id']
    : 0;

if ($selectedGroupId > 0 && !dc_context_has_reviewer_access($ctx) && !dc_user_can_access_group($selectedGroupId)) {
    $selectedGroupId = 0;
}

$search = trim((string) ($_GET['q'] ?? ''));
$dateRange = (string) ($_GET['date_range'] ?? 'upcoming');
$statusFilter = (string) ($_GET['status'] ?? 'active');

if (!in_array($dateRange, ['upcoming', '90', '365', 'all'], true)) {
    $dateRange = 'upcoming';
}

if (!in_array($statusFilter, ['active', 'approved', 'submitted', 'all'], true)) {
    $statusFilter = 'active';
}

/*
 * Map visibility policy:
 * - Reviewer/admin: all active Groups.
 * - Group-link/user: all non-cancelled mapped events are visible on the map,
 *   but manage/open links are only available where dc_user_can_access_group()
 *   returns true.
 */
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

$whereSql = implode("\n      AND ", $where);

$sql = "
    SELECT
        ce.*,
        g.group_name
    FROM calendar_events ce
    JOIN groups g
      ON g.id = ce.group_id
    WHERE {$whereSql}
    ORDER BY ce.starts_at ASC
    LIMIT 500
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$eventIds = array_values(array_map(static fn(array $event): int => (int) $event['id'], $events));
$riskByEvent = [];

$linkTable = dc_map_event_risk_link_table();

if ($linkTable && $eventIds) {
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));

    $riskSql = "
        SELECT
            er.event_id,
            ra.id,
            ra.title,
            ra.visibility,
            ra.original_filename,
            g.group_name
        FROM {$linkTable} er
        JOIN risk_assessments ra
          ON ra.id = er.risk_assessment_id
        JOIN groups g
          ON g.id = ra.group_id
        WHERE er.event_id IN ({$placeholders})
          AND ra.status = 'active'
          AND ra.admin_review_status = 'available'
        ORDER BY ra.title ASC
    ";

    try {
        $riskStmt = db()->prepare($riskSql);
        $riskStmt->execute($eventIds);

        foreach ($riskStmt->fetchAll(PDO::FETCH_ASSOC) as $risk) {
            $riskByEvent[(int) $risk['event_id']][] = $risk;
        }
    } catch (Throwable $e) {
        $riskByEvent = [];
    }
}

$mapEvents = [];

foreach ($events as $event) {
    $eventId = (int) $event['id'];
    $mapEvents[] = dc_map_js_event($event, $riskByEvent[$eventId] ?? []);
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
    .dc-map-layout {
        display: grid;
        gap: 1rem;
    }

    .dc-map-toolbar {
        background: #fff;
        border: 1px solid #e6e6e6;
        border-radius: .75rem;
        padding: 1rem;
    }

    .dc-map-toolbar-grid {
        display: grid;
        gap: .75rem;
    }

    @media (min-width: 900px) {
        .dc-map-toolbar-grid {
            grid-template-columns: minmax(220px, 1.7fr) repeat(3, minmax(140px, 1fr)) auto;
            align-items: end;
        }
    }

    .dc-map-shell {
        background: #fff;
        border: 1px solid #e6e6e6;
        border-radius: .75rem;
        overflow: hidden;
    }

    #dc-map {
        width: 100%;
        height: 68vh;
        min-height: 420px;
        background: #f3f2f1;
    }

    @media (max-width: 600px) {
        #dc-map {
            height: 60vh;
            min-height: 360px;
        }
    }

    .dc-map-summary {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem 1rem;
        align-items: center;
        padding: .85rem 1rem;
        border-bottom: 1px solid #e6e6e6;
        background: #f7f5fb;
        font-weight: 800;
    }

    .dc-map-empty {
        padding: 1rem;
    }

    .dc-map-popup {
        min-width: 240px;
        max-width: 320px;
    }

    .dc-map-popup h3 {
        margin: 0 0 .35rem;
        color: #4d0b93;
        font-size: 1.05rem;
        font-weight: 900;
        line-height: 1.2;
    }

    .dc-map-popup p {
        margin: .25rem 0;
        font-size: .9rem;
    }

    .dc-map-popup ul {
        margin: .35rem 0 0;
        padding-left: 1.1rem;
    }

    .dc-map-popup li {
        margin-bottom: .25rem;
    }

    .dc-map-popup a {
        font-weight: 900;
    }

    .dc-map-popup-badge {
        display: inline-block;
        margin: .35rem .25rem .35rem 0;
        padding: .15rem .45rem;
        border-radius: 999px;
        background: #f3f2f1;
        color: #333;
        font-size: .78rem;
        font-weight: 900;
    }

    .dc-map-popup-action {
        display: inline-block;
        margin-top: .45rem;
        padding: .35rem .55rem;
        border-radius: .3rem;
        background: #7413dc;
        color: #fff !important;
        text-decoration: none;
        font-weight: 900;
    }

    .dc-map-list {
        display: grid;
        gap: .5rem;
        margin-top: 1rem;
    }

    .dc-map-list-item {
        background: #fff;
        border: 1px solid #e6e6e6;
        border-radius: .6rem;
        padding: .8rem;
        display: grid;
        gap: .5rem;
    }

    @media (min-width: 760px) {
        .dc-map-list-item {
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
        }
    }

    .dc-map-list-item h3 {
        margin: 0;
        color: #4d0b93;
        font-size: 1rem;
        font-weight: 900;
    }

    .dc-map-list-item p {
        margin: .25rem 0 0;
        color: #555;
        font-weight: 700;
    }

    .dc-map-risk-links {
        margin-top: .3rem;
        font-size: .9rem;
    }

    .dc-map-risk-links a {
        font-weight: 900;
    }

    .dc-map-list-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 38px;
        padding: .4rem .7rem;
        border-radius: .35rem;
        background: #7413dc;
        color: #fff;
        text-decoration: none;
        font-weight: 900;
        border: 0;
        cursor: pointer;
    }

    .dc-map-list-button:hover,
    .dc-map-list-button:focus {
        background: #4d0b93;
        color: #fff;
        text-decoration: none;
    }
</style>

<div class="dc-map-layout">
    <section class="dc-map-toolbar" aria-label="Map filters">
        <form method="get">
            <div class="dc-map-toolbar-grid">
                <div class="form-group mb-0">
                    <label for="q">Search</label>
                    <input
                        type="search"
                        id="q"
                        name="q"
                        class="form-control"
                        value="<?= e($search) ?>"
                        placeholder="Event, Group, location or contact"
                    >
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
            <h2 id="mapped-events-heading" class="lt-section-title">Mapped events list</h2>

            <div class="dc-map-list">
                <?php foreach ($mapEvents as $event): ?>
                    <article class="dc-map-list-item">
                        <div>
                            <h3><?= e($event['title']) ?></h3>
                            <p>
                                <strong><?= e($event['group_name']) ?></strong>
                                · <?= e($event['starts_at']) ?>
                                · <?= e($event['location_name'] ?: 'Location not named') ?>
                            </p>

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

                        <div>
                            <button
                                type="button"
                                class="dc-map-list-button"
                                data-event-id="<?= (int) $event['id'] ?>"
                            >
                                Show on map
                            </button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>

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
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function popupHtml(event) {
        var location = event.location_name || event.location_address || 'Location not named';

        var risks = '';
        if (event.risks && event.risks.length) {
            risks = '<p><strong>Risk assessments used</strong></p><ul>' + event.risks.map(function (risk) {
                return '<li><a href="' + escapeHtml(risk.download_url) + '">' + escapeHtml(risk.title) + '</a></li>';
            }).join('') + '</ul>';
        } else {
            risks = '<p><strong>Risk assessments:</strong> none linked</p>';
        }

        var contact = '';
        if (event.leader_name || event.leader_email || event.leader_phone) {
            contact += '<p><strong>Main contact</strong><br>';
            if (event.leader_name) {
                contact += escapeHtml(event.leader_name) + '<br>';
            }
            if (event.leader_email) {
                contact += '<a href="mailto:' + escapeHtml(event.leader_email) + '">' + escapeHtml(event.leader_email) + '</a><br>';
            }
            if (event.leader_phone) {
                contact += escapeHtml(event.leader_phone);
            }
            contact += '</p>';
        }

        var manage = event.can_open && event.manage_url
            ? '<a class="dc-map-popup-action" href="' + escapeHtml(event.manage_url) + '">Open event</a>'
            : '<p><em>You can view this event on the map, but only the owning Group or District reviewers can manage it.</em></p>';

        return '' +
            '<div class="dc-map-popup">' +
            '<h3>' + escapeHtml(event.title) + '</h3>' +
            '<span class="dc-map-popup-badge">' + escapeHtml(event.group_name) + '</span>' +
            '<span class="dc-map-popup-badge">' + escapeHtml(event.status) + '</span>' +
            '<p><strong>When:</strong><br>' + escapeHtml(event.starts_at) + (event.ends_at ? '<br>to ' + escapeHtml(event.ends_at) : '') + '</p>' +
            '<p><strong>Where:</strong><br>' + escapeHtml(location) + '</p>' +
            (event.location_address && event.location_address !== event.location_name ? '<p>' + escapeHtml(event.location_address) + '</p>' : '') +
            contact +
            risks +
            manage +
            '</div>';
    }

    var map = L.map('dc-map', {
        scrollWheelZoom: false
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var markers = {};
    var bounds = [];

    events.forEach(function (event) {
        var marker = L.marker([event.lat, event.lng]).addTo(map);
        marker.bindPopup(popupHtml(event), {
            maxWidth: 340
        });

        markers[event.id] = marker;
        bounds.push([event.lat, event.lng]);
    });

    if (bounds.length === 1) {
        map.setView(bounds[0], 12);
    } else {
        map.fitBounds(bounds, {
            padding: [28, 28],
            maxZoom: 13
        });
    }

    document.querySelectorAll('[data-event-id]').forEach(function (button) {
        button.addEventListener('click', function () {
            var id = button.getAttribute('data-event-id');
            var marker = markers[id];

            if (!marker) {
                return;
            }

            map.setView(marker.getLatLng(), Math.max(map.getZoom(), 13));
            marker.openPopup();
            mapElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });
}());
</script>
<?php endif; ?>

<?php require __DIR__ . '/layout-footer.php'; ?>