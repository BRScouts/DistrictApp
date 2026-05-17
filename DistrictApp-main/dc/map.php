<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_auth();

$pdo = db();

$currentGroup = auth_group();
$isAdminOrReviewer = is_reviewer_or_admin();
$currentGroupId = $currentGroup ? (int)$currentGroup['group_id'] : null;

if (!$isAdminOrReviewer && !$currentGroupId) {
    redirect(ROUTE_403);
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/
$search = trim((string)($_GET['q'] ?? ''));
$timeframe = trim((string)($_GET['timeframe'] ?? 'upcoming'));
$statusFilter = trim((string)($_GET['status'] ?? 'approved'));
$section = trim((string)($_GET['section'] ?? ''));
$groupFilter = (int)($_GET['group_id'] ?? 0);
$limit = (int)($_GET['limit'] ?? 100);

if (!in_array($timeframe, ['upcoming', 'past', 'all'], true)) {
    $timeframe = 'upcoming';
}

if (!in_array($statusFilter, ['approved', 'submitted', 'all'], true)) {
    $statusFilter = 'approved';
}

if (!in_array($section, ['', 'squirrels', 'beavers', 'cubs', 'scouts', 'explorers', 'network', 'adults'], true)) {
    $section = '';
}

if (!in_array($limit, [25, 50, 100, 250], true)) {
    $limit = 100;
}

/*
|--------------------------------------------------------------------------
| Groups for admin/reviewer filter
|--------------------------------------------------------------------------
*/
$allGroups = [];

if ($isAdminOrReviewer) {
    $stmt = $pdo->query("
        SELECT id, group_name
        FROM groups
        WHERE is_active = 1
        ORDER BY group_name ASC
    ");
    $allGroups = $stmt->fetchAll();
}

/*
|--------------------------------------------------------------------------
| Build event query
|--------------------------------------------------------------------------
*/
$where = "
    e.event_location_lat IS NOT NULL
    AND e.event_location_lng IS NOT NULL
";

$params = [];

if (!$isAdminOrReviewer) {
    /*
     * Group users can use this as an ideas map, but only for approved events.
     * They can see high-level event/activity information from other groups.
     */
    $where .= " AND e.status = 'approved' ";
} elseif ($statusFilter === 'approved') {
    $where .= " AND e.status = 'approved' ";
} elseif ($statusFilter === 'submitted') {
    $where .= " AND e.status IN ('submitted', 'under_review', 'changes_requested') ";
}

if ($timeframe === 'upcoming') {
    $where .= " AND e.starts_at >= NOW() ";
} elseif ($timeframe === 'past') {
    $where .= " AND e.starts_at < NOW() ";
}

if ($groupFilter > 0 && $isAdminOrReviewer) {
    $where .= " AND e.group_id = :group_id ";
    $params['group_id'] = $groupFilter;
}

if ($search !== '') {
    $where .= "
        AND (
            e.event_title LIKE :search
            OR e.event_description LIKE :search
            OR e.event_location LIKE :search
            OR e.contact_name LIKE :search
            OR g.group_name LIKE :search
        )
    ";
    $params['search'] = '%' . $search . '%';
}

if ($section !== '') {
    $column = match ($section) {
        'squirrels' => 'e.squirrels_count',
        'beavers' => 'e.beavers_count',
        'cubs' => 'e.cubs_count',
        'scouts' => 'e.scouts_count',
        'explorers' => 'e.explorers_count',
        'network' => 'e.network_count',
        'adults' => 'e.adults_count',
        default => null,
    };

    if ($column !== null) {
        $where .= " AND {$column} > 0 ";
    }
}

$sql = "
    SELECT
        e.id,
        e.group_id,
        g.group_name,
        g.access_token,
        e.event_title,
        e.event_description,
        e.event_location,
        e.event_location_lat,
        e.event_location_lng,
        e.starts_at,
        e.ends_at,
        e.status,
        e.contact_name,
        e.squirrels_count,
        e.beavers_count,
        e.cubs_count,
        e.scouts_count,
        e.explorers_count,
        e.network_count,
        e.young_people_count,
        e.adults_count
    FROM events e
    INNER JOIN groups g ON g.id = e.group_id
    WHERE {$where}
    ORDER BY
        CASE WHEN e.starts_at >= NOW() THEN 0 ELSE 1 END,
        e.starts_at ASC
    LIMIT :limit
";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}

$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$events = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Fetch linked risk assessments
|--------------------------------------------------------------------------
*/
$eventRiskAssessments = [];
$eventIds = array_map(fn($event) => (int)$event['id'], $events);

if (!empty($eventIds)) {
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));

    $raSql = "
        SELECT
            era.event_id,
            ra.id AS risk_assessment_id,
            ra.group_id AS ra_group_id,
            ra.title,
            ra.original_filename,
            ra.file_extension,
            ra.visibility,
            ra.updated_at,
            g.group_name
        FROM event_risk_assessments era
        INNER JOIN risk_assessments ra ON ra.id = era.risk_assessment_id
        INNER JOIN groups g ON g.id = ra.group_id
        WHERE era.event_id IN ($placeholders)
          AND ra.is_active = 1
          AND ra.admin_review_status = 'available'
        ORDER BY ra.updated_at DESC, ra.original_filename ASC, ra.title ASC
    ";

    $stmt = $pdo->prepare($raSql);
    $stmt->execute($eventIds);

    foreach ($stmt->fetchAll() as $ra) {
        $eventRiskAssessments[(int)$ra['event_id']][] = $ra;
    }
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function map_status_badge_html(string $status): string
{
    return match ($status) {
        'approved' => '<span class="badge" style="background:#28a745;color:#fff;">Approved</span>',
        'submitted' => '<span class="badge" style="background:#ffc107;color:#212529;">Submitted</span>',
        'under_review' => '<span class="badge" style="background:#17a2b8;color:#fff;">Under review</span>',
        'changes_requested' => '<span class="badge" style="background:#ffc107;color:#212529;">Changes requested</span>',
        'rejected' => '<span class="badge" style="background:#dc3545;color:#fff;">Rejected</span>',
        'cancelled' => '<span class="badge" style="background:#343a40;color:#fff;">Cancelled</span>',
        default => '<span class="badge" style="background:#6c757d;color:#fff;">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>',
    };
}

function map_ra_name(array $ra): string
{
    $filename = trim((string)($ra['original_filename'] ?? ''));

    if ($filename !== '') {
        return $filename;
    }

    $title = trim((string)($ra['title'] ?? ''));
    return $title !== '' ? $title : 'Risk assessment';
}

function map_can_download_ra(array $ra, bool $isAdminOrReviewer, ?int $currentGroupId): bool
{
    if ($isAdminOrReviewer) {
        return true;
    }

    return (int)$ra['ra_group_id'] === (int)$currentGroupId || (string)$ra['visibility'] === 'district';
}

function map_event_url(array $event, bool $isAdminOrReviewer, ?array $currentGroup): ?string
{
    if ($isAdminOrReviewer) {
        return BASE_URL . '/add-event.php?event_id=' . (int)$event['id'] . '&group_id=' . (int)$event['group_id'];
    }

    if ($currentGroup && (int)$event['group_id'] === (int)$currentGroup['group_id']) {
        return BASE_URL . '/add-event.php?event_id=' . (int)$event['id'] . '&token=' . urlencode((string)$currentGroup['access_token']);
    }

    return null;
}

function map_attending_sections(array $event): array
{
    $sections = [
        'Squirrels' => (int)($event['squirrels_count'] ?? 0),
        'Beavers' => (int)($event['beavers_count'] ?? 0),
        'Cubs' => (int)($event['cubs_count'] ?? 0),
        'Scouts' => (int)($event['scouts_count'] ?? 0),
        'Explorers' => (int)($event['explorers_count'] ?? 0),
        'Network' => (int)($event['network_count'] ?? 0),
        'Adults' => (int)($event['adults_count'] ?? 0),
    ];

    return array_filter($sections, fn($count) => $count > 0);
}

$mapEvents = [];

foreach ($events as $event) {
    $linkedRas = $eventRiskAssessments[(int)$event['id']] ?? [];
    $downloadableRas = [];

    foreach ($linkedRas as $ra) {
        if (map_can_download_ra($ra, $isAdminOrReviewer, $currentGroupId)) {
            $downloadableRas[] = [
                'id' => (int)$ra['risk_assessment_id'],
                'name' => map_ra_name($ra),
                'group_name' => (string)$ra['group_name'],
                'updated_at' => date('d M Y', strtotime((string)$ra['updated_at'])),
                'download_url' => BASE_URL . '/download-risk-assessment.php?id=' . (int)$ra['risk_assessment_id'],
                'preview_url' => strtolower((string)$ra['file_extension']) === 'pdf'
                    ? BASE_URL . '/preview-risk-assessment.php?id=' . (int)$ra['risk_assessment_id']
                    : null,
            ];
        }
    }

    $sections = map_attending_sections($event);
    $eventUrl = map_event_url($event, $isAdminOrReviewer, $currentGroup);

    $mapEvents[] = [
        'id' => (int)$event['id'],
        'group_id' => (int)$event['group_id'],
        'group_name' => (string)$event['group_name'],
        'title' => (string)$event['event_title'],
        'description' => mb_strimwidth((string)($event['event_description'] ?? ''), 0, 260, '...'),
        'location' => (string)$event['event_location'],
        'lat' => (float)$event['event_location_lat'],
        'lng' => (float)$event['event_location_lng'],
        'starts_at' => date('d M Y H:i', strtotime((string)$event['starts_at'])),
        'ends_at' => date('d M Y H:i', strtotime((string)$event['ends_at'])),
        'status' => (string)$event['status'],
        'contact_name' => (string)$event['contact_name'],
        'sections_text' => implode(', ', array_map(
            fn($name, $count) => $name . ' (' . $count . ')',
            array_keys($sections),
            array_values($sections)
        )),
        'total_attending' => (int)$event['young_people_count'] + (int)$event['adults_count'],
        'risk_assessments' => $downloadableRas,
        'risk_assessment_count' => count($linkedRas),
        'event_url' => $eventUrl,
    ];
}

render_page_start('Activity Map');
render_header('map');
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    .map-page {
        display: grid;
        grid-template-columns: 360px minmax(0, 1fr);
        gap: 1rem;
        height: calc(100vh - 120px);
        min-height: 680px;
    }

    .map-sidebar {
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: .85rem;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .map-sidebar-header {
        padding: 1rem;
        border-bottom: 1px solid #dee2e6;
    }

    .map-event-list {
        overflow-y: auto;
        padding: .75rem;
    }

    .map-event-card {
        border: 1px solid #e9ecef;
        border-radius: .75rem;
        padding: .75rem;
        margin-bottom: .75rem;
        background: #fff;
        cursor: pointer;
        transition: box-shadow .15s ease, transform .15s ease;
    }

    .map-event-card:hover {
        box-shadow: 0 .35rem .9rem rgba(0,0,0,.08);
        transform: translateY(-1px);
    }

    .map-event-card.is-hidden {
        display: none;
    }

    .map-event-title {
        font-weight: 800;
        line-height: 1.25;
    }

    .map-main {
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: .85rem;
        overflow: hidden;
        position: relative;
    }

    #activityMap {
        height: 100%;
        width: 100%;
        min-height: 680px;
    }

    .map-filters {
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: .85rem;
        padding: .75rem;
        margin-bottom: 1rem;
    }

    .map-popup-title {
        font-weight: 900;
        font-size: 1rem;
        margin-bottom: .25rem;
    }

    .map-popup-meta {
        color: #6c757d;
        font-size: .86rem;
        margin-bottom: .5rem;
    }

    .map-popup-ra {
        border-top: 1px solid #e9ecef;
        padding-top: .5rem;
        margin-top: .5rem;
    }

    .map-count-pill {
        position: absolute;
        right: 1rem;
        top: 1rem;
        z-index: 450;
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 999px;
        padding: .35rem .75rem;
        box-shadow: 0 .25rem .75rem rgba(0,0,0,.08);
        font-size: .9rem;
        font-weight: 800;
    }

    @media (max-width: 991.98px) {
        .map-page {
            display: block;
            height: auto;
            min-height: 0;
        }

        .map-sidebar {
            margin-bottom: 1rem;
            max-height: 520px;
        }

        #activityMap {
            min-height: 520px;
            height: 70vh;
        }
    }
</style>

<div class="container-fluid">
    <div class="d-md-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="mb-1">Activity Map</h1>
            <p class="text-muted mb-0">
                Explore event locations and linked risk assessments to find activity ideas.
            </p>
        </div>

        <div class="mt-3 mt-md-0">
            <a href="<?= e(ROUTE_RISK_ASSESSMENTS) ?>" class="btn btn-outline-primary">
                Risk assessments
            </a>
            <a href="<?= e(ROUTE_CALENDAR) ?>" class="btn btn-outline-secondary">
                Calendar
            </a>
        </div>
    </div>

    <div class="map-filters">
        <form method="get" action="<?= e(BASE_URL . '/map.php') ?>">
            <div class="form-row align-items-end">
                <div class="form-group col-lg-3 col-md-6 mb-2">
                    <label for="q" class="small text-muted mb-1">Search</label>
                    <input
                        type="search"
                        class="form-control form-control-sm"
                        id="q"
                        name="q"
                        value="<?= e($search) ?>"
                        placeholder="Event, activity, location, group..."
                    >
                </div>

                <div class="form-group col-lg-2 col-md-6 mb-2">
                    <label for="section" class="small text-muted mb-1">Section</label>
                    <select class="form-control form-control-sm" id="section" name="section">
                        <option value="">Any section</option>
                        <option value="squirrels" <?= $section === 'squirrels' ? 'selected' : '' ?>>Squirrels</option>
                        <option value="beavers" <?= $section === 'beavers' ? 'selected' : '' ?>>Beavers</option>
                        <option value="cubs" <?= $section === 'cubs' ? 'selected' : '' ?>>Cubs</option>
                        <option value="scouts" <?= $section === 'scouts' ? 'selected' : '' ?>>Scouts</option>
                        <option value="explorers" <?= $section === 'explorers' ? 'selected' : '' ?>>Explorers</option>
                        <option value="network" <?= $section === 'network' ? 'selected' : '' ?>>Network</option>
                        <option value="adults" <?= $section === 'adults' ? 'selected' : '' ?>>Adults</option>
                    </select>
                </div>

                <div class="form-group col-lg-2 col-md-6 mb-2">
                    <label for="timeframe" class="small text-muted mb-1">When</label>
                    <select class="form-control form-control-sm" id="timeframe" name="timeframe">
                        <option value="upcoming" <?= $timeframe === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                        <option value="past" <?= $timeframe === 'past' ? 'selected' : '' ?>>Past ideas</option>
                        <option value="all" <?= $timeframe === 'all' ? 'selected' : '' ?>>All</option>
                    </select>
                </div>

                <?php if ($isAdminOrReviewer): ?>
                    <div class="form-group col-lg-2 col-md-6 mb-2">
                        <label for="status" class="small text-muted mb-1">Status</label>
                        <select class="form-control form-control-sm" id="status" name="status">
                            <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="submitted" <?= $statusFilter === 'submitted' ? 'selected' : '' ?>>Submitted / review</option>
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
                        </select>
                    </div>

                    <div class="form-group col-lg-2 col-md-6 mb-2">
                        <label for="group_id" class="small text-muted mb-1">Group</label>
                        <select class="form-control form-control-sm" id="group_id" name="group_id">
                            <option value="0">All groups</option>
                            <?php foreach ($allGroups as $group): ?>
                                <option value="<?= (int)$group['id'] ?>" <?= $groupFilter === (int)$group['id'] ? 'selected' : '' ?>>
                                    <?= e($group['group_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-group col-lg-1 col-md-6 mb-2">
                    <label for="limit" class="small text-muted mb-1">Max</label>
                    <select class="form-control form-control-sm" id="limit" name="limit">
                        <option value="25" <?= $limit === 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
                        <option value="250" <?= $limit === 250 ? 'selected' : '' ?>>250</option>
                    </select>
                </div>

                <div class="form-group col-lg-12 mb-0">
                    <button type="submit" class="btn btn-primary btn-sm">Apply filters</button>
                    <a href="<?= e(BASE_URL . '/map.php') ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <div class="map-page">
        <aside class="map-sidebar">
            <div class="map-sidebar-header">
                <h2 class="h5 mb-2">Activities</h2>
                <input
                    type="search"
                    class="form-control form-control-sm"
                    id="sidebarSearch"
                    placeholder="Filter this list..."
                >
                <div class="small text-muted mt-2">
                    <span id="visibleListCount"><?= e((string)count($mapEvents)) ?></span>
                    of <?= e((string)count($mapEvents)) ?> shown
                </div>
            </div>

            <div class="map-event-list" id="mapEventList">
                <?php if (empty($mapEvents)): ?>
                    <div class="text-muted p-3">
                        No mapped events found. Try widening the filters.
                    </div>
                <?php else: ?>
                    <?php foreach ($mapEvents as $event): ?>
                        <div
                            class="map-event-card"
                            data-event-id="<?= (int)$event['id'] ?>"
                            data-search="<?= e(strtolower($event['title'] . ' ' . $event['group_name'] . ' ' . $event['location'] . ' ' . $event['sections_text'] . ' ' . $event['description'])) ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="map-event-title"><?= e($event['title']) ?></div>
                                <?= map_status_badge_html($event['status']) ?>
                            </div>

                            <div class="small text-muted mt-1">
                                <?= e($event['group_name']) ?>
                            </div>

                            <div class="small mt-2">
                                <strong>Where:</strong> <?= e($event['location']) ?><br>
                                <strong>When:</strong> <?= e($event['starts_at']) ?>
                            </div>

                            <?php if ($event['sections_text'] !== ''): ?>
                                <div class="small text-muted mt-2">
                                    <?= e($event['sections_text']) ?>
                                </div>
                            <?php endif; ?>

                            <div class="small text-muted mt-2">
                                <?= e((string)$event['risk_assessment_count']) ?>
                                linked risk assessment<?= $event['risk_assessment_count'] === 1 ? '' : 's' ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>

        <main class="map-main">
            <div class="map-count-pill">
                <?= e((string)count($mapEvents)) ?> location<?= count($mapEvents) === 1 ? '' : 's' ?>
            </div>
            <div id="activityMap"></div>
        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const events = <?= json_encode($mapEvents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const sidebarSearch = document.getElementById('sidebarSearch');
    const visibleListCount = document.getElementById('visibleListCount');
    const cards = Array.from(document.querySelectorAll('.map-event-card'));

    const defaultCentre = [53.4808, -2.2426];
    const map = L.map('activityMap').setView(defaultCentre, 8);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const markers = new Map();
    const bounds = [];

    function escapeHtml(value) {
        return String(value || '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function statusLabel(status) {
        switch (status) {
            case 'approved': return 'Approved';
            case 'submitted': return 'Submitted';
            case 'under_review': return 'Under review';
            case 'changes_requested': return 'Changes requested';
            case 'rejected': return 'Rejected';
            case 'cancelled': return 'Cancelled';
            default: return String(status || '').replaceAll('_', ' ');
        }
    }

    function popupHtml(event) {
        const ras = event.risk_assessments || [];

        let raHtml = '';

        if (ras.length) {
            raHtml = `
                <div class="map-popup-ra">
                    <strong>Risk assessments</strong>
                    ${ras.map(ra => `
                        <div class="mt-2">
                            <div>${escapeHtml(ra.name)}</div>
                            <small class="text-muted">${escapeHtml(ra.group_name)} · updated ${escapeHtml(ra.updated_at)}</small><br>
                            ${ra.preview_url ? `<a href="${escapeHtml(ra.preview_url)}" target="_blank">View</a> · ` : ''}
                            <a href="${escapeHtml(ra.download_url)}" target="_blank">Download</a>
                        </div>
                    `).join('')}
                </div>
            `;
        } else if (event.risk_assessment_count > 0) {
            raHtml = `
                <div class="map-popup-ra">
                    <strong>Risk assessments</strong><br>
                    <small class="text-muted">
                        ${event.risk_assessment_count} linked, but none are downloadable for your current access.
                    </small>
                </div>
            `;
        }

        const openEvent = event.event_url
            ? `<a href="${escapeHtml(event.event_url)}" class="btn btn-outline-primary btn-sm mt-2">Open event</a>`
            : '';

        return `
            <div style="min-width:260px;max-width:360px;">
                <div class="map-popup-title">${escapeHtml(event.title)}</div>
                <div class="map-popup-meta">
                    ${escapeHtml(event.group_name)} · ${escapeHtml(statusLabel(event.status))}
                </div>

                <div><strong>Location:</strong> ${escapeHtml(event.location)}</div>
                <div><strong>Start:</strong> ${escapeHtml(event.starts_at)}</div>
                <div><strong>End:</strong> ${escapeHtml(event.ends_at)}</div>

                ${event.sections_text ? `<div class="mt-2"><strong>Sections:</strong> ${escapeHtml(event.sections_text)}</div>` : ''}
                ${event.description ? `<div class="mt-2 text-muted">${escapeHtml(event.description)}</div>` : ''}

                ${raHtml}
                ${openEvent}
            </div>
        `;
    }

    events.forEach(event => {
        if (!event.lat || !event.lng) return;

        const marker = L.marker([event.lat, event.lng]).addTo(map);
        marker.bindPopup(popupHtml(event), {
            maxWidth: 420
        });

        markers.set(Number(event.id), marker);
        bounds.push([event.lat, event.lng]);
    });

    if (bounds.length > 0) {
        map.fitBounds(bounds, {
            padding: [40, 40],
            maxZoom: 13
        });
    }

    cards.forEach(card => {
        card.addEventListener('click', function () {
            const id = Number(card.dataset.eventId);
            const marker = markers.get(id);

            if (marker) {
                map.setView(marker.getLatLng(), Math.max(map.getZoom(), 14));
                marker.openPopup();
            }
        });
    });

    function applySidebarFilter() {
        const query = String(sidebarSearch.value || '').trim().toLowerCase();
        let visible = 0;

        cards.forEach(card => {
            const text = card.dataset.search || '';
            const show = query === '' || text.includes(query);

            card.classList.toggle('is-hidden', !show);

            const id = Number(card.dataset.eventId);
            const marker = markers.get(id);

            if (marker) {
                if (show) {
                    marker.addTo(map);
                } else {
                    marker.remove();
                }
            }

            if (show) {
                visible++;
            }
        });

        if (visibleListCount) {
            visibleListCount.textContent = String(visible);
        }
    }

    if (sidebarSearch) {
        sidebarSearch.addEventListener('input', applySidebarFilter);
    }

    setTimeout(() => {
        map.invalidateSize();
    }, 250);
});
</script>

<?php render_page_end(); ?>