<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// Anyone who can reach the calendar can open an event's direct view page.
// Logged-out users are bounced to login and returned here afterwards
// (handled by dc_require_access -> dc_context redirect).
$ctx = dc_require_access();

$eventId = (int) ($_GET['id'] ?? 0);

if ($eventId <= 0) {
    require __DIR__ . '/404.php';
    exit;
}

$event = dc_get_event($eventId);

if (!$event) {
    require __DIR__ . '/404.php';
    exit;
}

// District Events have their own richer page — send them there.
if (dc_event_is_district($event)) {
    redirect('/dc/district-event.php?id=' . $eventId);
}

$groupId = (int) $event['group_id'];

// The viewer must be able to see this group's events.
if (!dc_user_can_access_group($groupId)) {
    http_response_code(403);
    require __DIR__ . '/403.php';
    exit;
}

$canManage = dc_user_can_manage_group($groupId);

// Linked risk assessments (read-only list).
$risks = [];

try {
    $stmt = db()->prepare("
        SELECT ra.id, ra.title, g.group_name
        FROM event_risk_assessments era
        JOIN risk_assessments ra ON ra.id = era.risk_assessment_id
        JOIN groups g ON g.id = ra.group_id
        WHERE era.calendar_event_id = :id
        ORDER BY ra.title ASC
    ");
    $stmt->execute(['id' => $eventId]);
    $risks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $risks = [];
}

$title = (string) ($event['title'] ?? 'Event');
$status = (string) ($event['status'] ?? '');
$description = (string) ($event['description'] ?? '');
$locationName = trim((string) ($event['location_name'] ?? ''));
$locationAddress = trim((string) ($event['location_address'] ?? ''));

$lat = isset($event['location_lat']) && $event['location_lat'] !== null && $event['location_lat'] !== ''
    ? (float) $event['location_lat'] : null;
$lng = isset($event['location_lng']) && $event['location_lng'] !== null && $event['location_lng'] !== ''
    ? (float) $event['location_lng'] : null;
$hasCoords = $lat !== null && $lng !== null && ($lat !== 0.0 || $lng !== 0.0);

$startsAt = null;
$endsAt = null;

try {
    if (!empty($event['starts_at'])) {
        $startsAt = new DateTimeImmutable((string) $event['starts_at']);
    }
} catch (Throwable $e) {
    $startsAt = null;
}

try {
    if (!empty($event['ends_at'])) {
        $endsAt = new DateTimeImmutable((string) $event['ends_at']);
    }
} catch (Throwable $e) {
    $endsAt = null;
}

function dc_event_when(?DateTimeImmutable $start, ?DateTimeImmutable $end): string
{
    if (!$start) {
        return 'Date to be confirmed';
    }

    $sameDay = $end && $start->format('Y-m-d') === $end->format('Y-m-d');

    if ($sameDay) {
        return $start->format('D j M Y, H:i') . ' – ' . $end->format('H:i');
    }

    $out = $start->format('D j M Y, H:i');

    if ($end) {
        $out .= ' to ' . $end->format('D j M Y, H:i');
    }

    return $out;
}

$whenLabel = dc_event_when($startsAt, $endsAt);
$statusLabel = ucwords(str_replace('_', ' ', $status));

$mapParts = array_filter([$locationName, $locationAddress]);
$mapQuery = $mapParts ? implode(', ', $mapParts) : '';

if ($hasCoords) {
    $googleMapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($lat . ',' . $lng);
} elseif ($mapQuery !== '') {
    $googleMapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($mapQuery);
} else {
    $googleMapsUrl = '';
}

$leaderName = trim((string) ($event['leader_name'] ?? ''));
$leaderEmail = trim((string) ($event['leader_email'] ?? ''));
$leaderPhone = trim((string) ($event['leader_phone'] ?? ''));

$pageTitle = $title;
$heroTitle = $title;
$heroText = (string) ($event['group_name'] ?? '') . ' · ' . $whenLabel;
$active = 'home';

require __DIR__ . '/layout.php';
?>

<?php if ($hasCoords): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
<?php endif; ?>

<style>
    .dc-ev { display: grid; gap: 1.5rem; }

    @media (min-width: 900px) {
        .dc-ev { grid-template-columns: minmax(0, 1fr) 320px; align-items: start; }
    }

    .dc-ev-back {
        display: inline-block;
        margin-bottom: 1.25rem;
        font-weight: 800;
        color: var(--dc-muted, #64748b);
        text-decoration: none;
    }

    .dc-ev-meta {
        display: grid;
        gap: 0.4rem;
        padding: 1rem 1.25rem;
        background: #f8fafc;
        border: 1px solid var(--dc-border, #e2e8f0);
        border-left: 4px solid var(--dc-scouts-purple, #7413dc);
        margin-bottom: 1.75rem;
    }

    .dc-ev-meta div { display: flex; gap: 0.5rem; font-weight: 600; color: var(--dc-ink, #1d2939); }
    .dc-ev-meta strong { min-width: 90px; color: var(--dc-muted, #64748b); font-weight: 700; }

    .dc-ev-section { margin-bottom: 1.75rem; }
    .dc-ev-section h2 { margin: 0 0 0.6rem; font-size: 1.2rem; font-weight: 800; color: var(--dc-ink, #1d2939); }
    .dc-ev-body { font-size: 1rem; line-height: 1.65; color: var(--dc-ink, #1d2939); white-space: pre-wrap; }

    .dc-ev-map-wrap {
        position: relative; width: 100%; overflow: hidden;
        border: 1px solid var(--dc-border, #e2e8f0); background: var(--dc-canvas, #f8fafc); isolation: isolate;
    }
    .dc-ev-map { display: block; width: 100%; height: 300px; position: relative; z-index: 0; }

    .dc-ev-aside { display: grid; gap: 1rem; }
    @media (min-width: 900px) { .dc-ev-aside { position: sticky; top: 1.25rem; } }

    .dc-ev-card { border: 1px solid var(--dc-border, #e2e8f0); background: #fff; padding: 1.25rem; }
    .dc-ev-card h3 { margin: 0 0 0.75rem; font-size: 1.05rem; font-weight: 800; }
    .dc-ev-card .lt-btn { display: block; width: 100%; text-align: center; }

    .dc-ev-risk-list { margin: 0; padding-left: 1.1rem; }
    .dc-ev-risk-list li { margin-bottom: 0.35rem; }
</style>

<a class="dc-ev-back" href="/dc/">&larr; Back to the calendar</a>

<div class="dc-ev">
    <div class="dc-ev-main">
        <div class="dc-ev-meta">
            <div><strong>Group</strong> <span><?= e((string) ($event['group_name'] ?? '')) ?></span></div>
            <div><strong>When</strong> <span><?= e($whenLabel) ?></span></div>
            <?php if ($locationName !== '' || $locationAddress !== ''): ?>
                <div><strong>Where</strong> <span><?= e($locationName !== '' ? $locationName : $locationAddress) ?></span></div>
            <?php endif; ?>
            <div><strong>Status</strong> <span><?= e($statusLabel) ?></span></div>
        </div>

        <?php if ($description !== ''): ?>
            <section class="dc-ev-section">
                <h2>About this event</h2>
                <div class="dc-ev-body"><?= e($description) ?></div>
            </section>
        <?php endif; ?>

        <?php if ($locationName !== '' || $locationAddress !== '' || $hasCoords): ?>
            <section class="dc-ev-section">
                <h2>Location</h2>
                <?php if ($locationName !== ''): ?>
                    <p style="font-weight:700;margin:0 0 0.25rem;"><?= e($locationName) ?></p>
                <?php endif; ?>
                <?php if ($locationAddress !== ''): ?>
                    <p style="margin:0 0 0.75rem;color:var(--dc-muted,#64748b);font-weight:600;"><?= e($locationAddress) ?></p>
                <?php endif; ?>

                <?php if ($hasCoords): ?>
                    <div class="dc-ev-map-wrap">
                        <div
                            id="dc-ev-map"
                            class="dc-ev-map"
                            data-lat="<?= e((string) $lat) ?>"
                            data-lng="<?= e((string) $lng) ?>"
                            data-label="<?= e($locationName !== '' ? $locationName : ($locationAddress !== '' ? $locationAddress : 'Event location')) ?>"
                            aria-label="Map showing the event location"
                        ></div>
                    </div>
                <?php endif; ?>

                <?php if ($googleMapsUrl !== ''): ?>
                    <p style="margin-top:0.75rem;">
                        <a href="<?= e($googleMapsUrl) ?>" target="_blank" rel="noopener noreferrer">Open in Google Maps</a>
                    </p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($risks): ?>
            <section class="dc-ev-section">
                <h2>Risk assessments</h2>
                <ul class="dc-ev-risk-list">
                    <?php foreach ($risks as $risk): ?>
                        <li>
                            <a href="/dc/download-risk-assessment.php?id=<?= (int) $risk['id'] ?>"><?= e((string) $risk['title']) ?></a>
                            <?php if (!empty($risk['group_name'])): ?>
                                <span>(<?= e((string) $risk['group_name']) ?>)</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>

    <aside class="dc-ev-aside">
        <div class="dc-ev-card">
            <h3>Main contact</h3>
            <p style="margin:0;font-weight:700;"><?= e($leaderName !== '' ? $leaderName : 'Not set') ?></p>
            <?php if ($leaderEmail !== ''): ?>
                <p style="margin:0.35rem 0 0;"><a href="mailto:<?= e($leaderEmail) ?>"><?= e($leaderEmail) ?></a></p>
            <?php endif; ?>
            <?php if ($leaderPhone !== ''): ?>
                <p style="margin:0.35rem 0 0;color:var(--dc-muted,#64748b);font-weight:600;"><?= e($leaderPhone) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($canManage): ?>
            <div class="dc-ev-card">
                <h3>Manage</h3>
                <a class="btn btn-primary lt-btn" href="/dc/manage-event.php?id=<?= (int) $eventId ?>">Open &amp; edit event</a>
            </div>
        <?php endif; ?>
    </aside>
</div>

<?php if ($hasCoords): ?>
    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" <?= csp_nonce_attr() ?>></script>
    <script <?= csp_nonce_attr() ?>>
    (function () {
        var el = document.getElementById('dc-ev-map');
        if (!el || typeof L === 'undefined') return;

        var lat = parseFloat(el.getAttribute('data-lat'));
        var lng = parseFloat(el.getAttribute('data-lng'));
        var label = el.getAttribute('data-label') || 'Event location';

        if (Number.isNaN(lat) || Number.isNaN(lng)) return;

        var map = L.map(el, { scrollWheelZoom: false }).setView([lat, lng], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);
        L.marker([lat, lng]).addTo(map).bindPopup(label);
        setTimeout(function () { map.invalidateSize(); }, 200);
    }());
    </script>
<?php endif; ?>

<?php require __DIR__ . '/layout-footer.php'; ?>
