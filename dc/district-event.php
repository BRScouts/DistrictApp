<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// Any signed-in user or group-link holder who can reach the calendar can view
// a District Event's details — it is shared with every group.
$ctx = dc_require_access();

$eventId = (int) ($_GET['id'] ?? 0);

if ($eventId <= 0) {
    require __DIR__ . '/404.php';
    exit;
}

$event = dc_get_event($eventId);

if (!$event || !dc_event_is_district($event)) {
    require __DIR__ . '/404.php';
    exit;
}

$title = (string) ($event['title'] ?? 'District Event');
$description = (string) ($event['description'] ?? '');
$locationName = trim((string) ($event['location_name'] ?? ''));
$locationAddress = trim((string) ($event['location_address'] ?? ''));

$lat = isset($event['location_lat']) && $event['location_lat'] !== null && $event['location_lat'] !== ''
    ? (float) $event['location_lat']
    : null;
$lng = isset($event['location_lng']) && $event['location_lng'] !== null && $event['location_lng'] !== ''
    ? (float) $event['location_lng']
    : null;

$hasCoords = $lat !== null && $lng !== null && ($lat !== 0.0 || $lng !== 0.0);

$bookingUrl = trim((string) ($event['booking_url'] ?? ''));
$infoUrl = trim((string) ($event['info_url'] ?? ''));

// Validate stored URLs before rendering as links.
$bookingUrl = ($bookingUrl !== '' && filter_var($bookingUrl, FILTER_VALIDATE_URL)) ? $bookingUrl : '';
$infoUrl = ($infoUrl !== '' && filter_var($infoUrl, FILTER_VALIDATE_URL)) ? $infoUrl : '';

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

/**
 * Human friendly date/time range: collapses a same-day event to one line.
 */
function dce_view_when(?DateTimeImmutable $start, ?DateTimeImmutable $end): string
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

$whenLabel = dce_view_when($startsAt, $endsAt);

$mapQueryParts = array_filter([$locationName, $locationAddress]);
$mapQuery = $mapQueryParts ? implode(', ', $mapQueryParts) : '';

if ($hasCoords) {
    $googleMapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($lat . ',' . $lng);
} elseif ($mapQuery !== '') {
    $googleMapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($mapQuery);
} else {
    $googleMapsUrl = '';
}

$canManage = dc_user_can_manage_group((int) $event['group_id']);

$pageTitle = $title;
$heroTitle = $title;
$heroText = 'District Event · ' . $whenLabel;
$active = 'home';

require __DIR__ . '/layout.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">

<style>
    .dce-view {
        display: grid;
        gap: 1.5rem;
    }

    @media (min-width: 900px) {
        .dce-view {
            grid-template-columns: minmax(0, 1fr) 320px;
            align-items: start;
        }
    }

    .dce-view-banner {
        height: 10px;
        margin-bottom: 0;
        background-image: repeating-linear-gradient(
            45deg,
            var(--dc-scouts-purple-dark, #4d0b93),
            var(--dc-scouts-purple-dark, #4d0b93) 7px,
            var(--dc-scouts-purple, #7413dc) 7px,
            var(--dc-scouts-purple, #7413dc) 14px
        );
    }

    .dce-view-back {
        display: inline-block;
        margin-bottom: 1.25rem;
        font-weight: 800;
        color: var(--dc-muted, #64748b);
        text-decoration: none;
    }

    .dce-view-main .dce-view-section {
        margin-bottom: 1.75rem;
    }

    .dce-view-section h2 {
        margin: 0 0 0.6rem;
        font-size: 1.25rem;
        font-weight: 800;
        color: var(--dc-ink, #1d2939);
    }

    .dce-view-meta {
        display: grid;
        gap: 0.4rem;
        padding: 1rem 1.25rem;
        background: #f5efff;
        border: 1px solid var(--dc-border, #e2e8f0);
        border-left: 4px solid var(--dc-scouts-purple, #7413dc);
        margin-bottom: 1.75rem;
    }

    .dce-view-meta div {
        display: flex;
        gap: 0.5rem;
        font-weight: 600;
        color: var(--dc-ink, #1d2939);
    }

    .dce-view-meta strong {
        min-width: 90px;
        color: var(--dc-muted, #64748b);
        font-weight: 700;
    }

    .dce-view-body {
        font-size: 1rem;
        line-height: 1.65;
        color: var(--dc-ink, #1d2939);
        white-space: pre-wrap;
    }

    .dce-view-map-wrap {
        position: relative;
        width: 100%;
        overflow: hidden;
        border: 1px solid var(--dc-border, #e2e8f0);
        background: var(--dc-canvas, #f8fafc);
        isolation: isolate;
    }

    .dce-view-map {
        display: block;
        width: 100%;
        height: 300px;
        position: relative;
        z-index: 0;
    }

    .dce-view-aside {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 900px) {
        .dce-view-aside {
            position: sticky;
            top: 1.25rem;
        }
    }

    .dce-view-card {
        border: 1px solid var(--dc-border, #e2e8f0);
        background: #fff;
        padding: 1.25rem;
    }

    .dce-view-card h3 {
        margin: 0 0 0.75rem;
        font-size: 1.05rem;
        font-weight: 800;
    }

    .dce-view-card .lt-btn {
        display: block;
        width: 100%;
        text-align: center;
        margin-bottom: 0.6rem;
    }

    .dce-view-card .lt-btn:last-child {
        margin-bottom: 0;
    }

    .dce-view-card p {
        margin: 0;
        color: var(--dc-muted, #64748b);
        font-size: 0.9rem;
        font-weight: 600;
    }
</style>

<a class="dce-view-back" href="/dc/">&larr; Back to the calendar</a>

<div class="dce-view-banner" aria-hidden="true"></div>

<div class="dce-view" style="margin-top:1.25rem;">
    <div class="dce-view-main">
        <div class="dce-view-meta">
            <div><strong>When</strong> <span><?= e($whenLabel) ?></span></div>
            <?php if ($locationName !== '' || $locationAddress !== ''): ?>
                <div><strong>Where</strong> <span><?= e($locationName !== '' ? $locationName : $locationAddress) ?></span></div>
            <?php endif; ?>
            <div><strong>Audience</strong> <span>Open to all groups across the District</span></div>
        </div>

        <?php if ($description !== ''): ?>
            <section class="dce-view-section">
                <h2>About this event</h2>
                <div class="dce-view-body"><?= e($description) ?></div>
            </section>
        <?php endif; ?>

        <?php if ($locationName !== '' || $locationAddress !== '' || $hasCoords): ?>
            <section class="dce-view-section">
                <h2>Location</h2>

                <?php if ($locationName !== ''): ?>
                    <p style="font-weight:700;margin:0 0 0.25rem;"><?= e($locationName) ?></p>
                <?php endif; ?>
                <?php if ($locationAddress !== ''): ?>
                    <p style="margin:0 0 0.75rem;color:var(--dc-muted,#64748b);font-weight:600;"><?= e($locationAddress) ?></p>
                <?php endif; ?>

                <?php if ($hasCoords): ?>
                    <div class="dce-view-map-wrap">
                        <div
                            id="dce-view-map"
                            class="dce-view-map"
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
    </div>

    <aside class="dce-view-aside">
        <?php if ($bookingUrl !== '' || $infoUrl !== ''): ?>
            <div class="dce-view-card">
                <h3>Book or find out more</h3>
                <?php if ($bookingUrl !== ''): ?>
                    <a class="btn btn-primary lt-btn" href="<?= e($bookingUrl) ?>" target="_blank" rel="noopener noreferrer">Book onto this event</a>
                <?php endif; ?>
                <?php if ($infoUrl !== ''): ?>
                    <a class="btn lt-btn lt-btn-secondary" href="<?= e($infoUrl) ?>" target="_blank" rel="noopener noreferrer">More information</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="dce-view-card">
            <h3>District Event</h3>
            <p>This event has been added by the District and is visible to every group on the calendar.</p>
            <?php if ($canManage): ?>
                <a class="btn lt-btn lt-btn-secondary" style="display:block;width:100%;text-align:center;margin-top:0.75rem;" href="/dc/add-district-event.php?id=<?= (int) $eventId ?>">Edit or delete this event</a>
            <?php endif; ?>
        </div>
    </aside>
</div>

<?php if ($hasCoords): ?>
    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" <?= csp_nonce_attr() ?>></script>
    <script <?= csp_nonce_attr() ?>>
    (function () {
        var el = document.getElementById('dce-view-map');
        if (!el || typeof L === 'undefined') return;

        var lat = parseFloat(el.getAttribute('data-lat'));
        var lng = parseFloat(el.getAttribute('data-lng'));
        var label = el.getAttribute('data-label') || 'Event location';

        if (Number.isNaN(lat) || Number.isNaN(lng)) return;

        var map = L.map(el, { scrollWheelZoom: false }).setView([lat, lng], 15);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        L.marker([lat, lng]).addTo(map).bindPopup(label);

        setTimeout(function () { map.invalidateSize(); }, 200);
    }());
    </script>
<?php endif; ?>

<?php require __DIR__ . '/layout-footer.php'; ?>
