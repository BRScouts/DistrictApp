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

// Audit history. Only people who can manage the group see the audit trail;
// it's an administrative record, not viewer-facing content. The query mirrors
// the one on manage-event.php so both pages show the same history, including
// risk-assessment actions linked to this event via details_json.
$auditRows = [];

if ($canManage) {
    try {
        $stmt = db()->prepare("
            SELECT
                al.*,
                p.full_name AS actor_name,
                g.group_name
            FROM audit_log al
            LEFT JOIN people p
              ON p.id = al.actor_person_id
            LEFT JOIN groups g
              ON g.id = al.group_id
            WHERE
              (al.entity_type = 'calendar_event' AND al.entity_id = :event_id)
              OR
              (al.entity_type = 'risk_assessment' AND JSON_EXTRACT(al.details_json, '$.event_id') = CAST(:event_id_json AS JSON))
            ORDER BY al.created_at DESC, al.id DESC
            LIMIT 100
        ");
        $stmt->execute([
            'event_id' => $eventId,
            'event_id_json' => (string) $eventId,
        ]);
        $auditRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        try {
            $stmt = db()->prepare("
                SELECT
                    al.*,
                    p.full_name AS actor_name,
                    g.group_name
                FROM audit_log al
                LEFT JOIN people p
                  ON p.id = al.actor_person_id
                LEFT JOIN groups g
                  ON g.id = al.group_id
                WHERE al.entity_type = 'calendar_event'
                  AND al.entity_id = :event_id
                ORDER BY al.created_at DESC, al.id DESC
                LIMIT 100
            ");
            $stmt->execute(['event_id' => $eventId]);
            $auditRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $auditRows = [];
        }
    }
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
$isCancelled = $status === 'cancelled';

/**
 * Map an event lifecycle status to a badge tone so the status reads at a
 * glance: green when approved, amber while it needs attention, red when
 * rejected/cancelled, neutral for drafts.
 */
function dc_event_status_tone(string $status): string
{
    switch ($status) {
        case 'approved':
            return 'positive';
        case 'submitted':
        case 'changes_requested':
            return 'warning';
        case 'rejected':
        case 'cancelled':
            return 'critical';
        default:
            return 'neutral';
    }
}

$statusTone = dc_event_status_tone($status);

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

    /* Status card + badge. Colour reflects the event lifecycle state;
       the label text carries the meaning too, so it's not colour-only. */
    .dc-ev-status-badge {
        display: inline-block;
        padding: 0.35rem 0.75rem;
        font-weight: 800;
        font-size: 0.95rem;
        border: 2px solid var(--dc-scouts-purple, #7413dc);
        border-radius: 999px;
        color: var(--dc-scouts-purple, #7413dc);
        background: #faf5ff;
    }

    .dc-ev-status-badge--positive {
        color: #2e7d32;
        border-color: #2e7d32;
        background: #edf7ee;
    }

    .dc-ev-status-badge--warning {
        color: #8a5300;
        border-color: #c77700;
        background: #fff6e6;
    }

    .dc-ev-status-badge--critical {
        color: #c0392b;
        border-color: #c0392b;
        background: #fcecea;
    }

    /* Audit trail — mirrors the ribbon/actor styling on manage-event.php. */
    .dc-audit-list { display: grid; gap: 0.65rem; }

    .dc-audit-item {
        border-left: 5px solid var(--dc-scouts-purple, #7413dc);
        background: #fff;
        padding: 0.75rem;
        border-top: 1px solid var(--dc-border, #e2e8f0);
        border-right: 1px solid var(--dc-border, #e2e8f0);
        border-bottom: 1px solid var(--dc-border, #e2e8f0);
    }

    .dc-audit-item--positive { border-left-color: #2e7d32; }
    .dc-audit-item--warning { border-left-color: #c77700; }
    .dc-audit-item--critical { border-left-color: #c0392b; }

    .dc-audit-meta {
        margin-top: 0.15rem;
        font-size: 0.85rem;
        color: var(--dc-muted, #64748b);
    }

    .dc-audit-actor {
        font-weight: 700;
        color: var(--dc-ink, #1d2939);
    }

    .dc-audit-actor::after {
        content: " · ";
        font-weight: 400;
        color: var(--dc-muted, #64748b);
    }

    .mt-2 { margin-top: 0.5rem; }
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

        <?php if ($canManage): ?>
            <section class="dc-ev-section">
                <h2>Audit trail</h2>

                <?php if (!$auditRows): ?>
                    <p class="dc-ev-body">No audit activity has been recorded for this event yet.</p>
                <?php else: ?>
                    <div class="dc-audit-list">
                        <?php foreach ($auditRows as $row): ?>
                            <?php
                                $details = json_decode((string) ($row['details_json'] ?? ''), true);
                                if (!is_array($details)) {
                                    $details = [];
                                }

                                $actor = $row['actor_type'] === 'person'
                                    ? ((string) ($row['actor_name'] ?? 'Signed-in user'))
                                    : ucwords(str_replace('_', ' ', (string) $row['actor_type']));
                                $tone = audit_event_tone((string) $row['action']);
                            ?>
                            <div class="dc-audit-item dc-audit-item--<?= e($tone) ?>">
                                <strong><?= e(ucwords(str_replace(['.', '_'], ' ', (string) $row['action']))) ?></strong>
                                <div class="dc-audit-meta">
                                    <span class="dc-audit-actor"><?= e($actor) ?></span>
                                    <span class="dc-audit-when">
                                        <?= e(date('d M Y H:i', strtotime((string) $row['created_at']))) ?>
                                        <?php if (!empty($row['group_name'])): ?>
                                            · <?= e((string) $row['group_name']) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>

                                <?php if ($details): ?>
                                    <div class="mt-2">
                                        <?php foreach ($details as $key => $value): ?>
                                            <?php if ($value === null || $value === '') continue; ?>
                                            <?php [$detailLabel, $detailValue] = audit_format_detail((string) $key, $value); ?>
                                            <div>
                                                <strong><?= e($detailLabel) ?>:</strong>
                                                <?= e($detailValue) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <aside class="dc-ev-aside">
        <div class="dc-ev-card dc-ev-status-card">
            <h3>Status</h3>
            <span class="dc-ev-status-badge dc-ev-status-badge--<?= e($statusTone) ?>">
                <?= e($statusLabel !== '' ? $statusLabel : 'Unknown') ?>
            </span>
        </div>

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
                <?php if ($isCancelled): ?>
                    <a class="btn btn-primary lt-btn" href="/dc/manage-event.php?id=<?= (int) $eventId ?>">Open event</a>
                <?php else: ?>
                    <a class="btn btn-primary lt-btn" href="/dc/manage-event.php?id=<?= (int) $eventId ?>&amp;edit=1">Edit event</a>
                    <a class="btn lt-btn lt-btn-secondary" href="/dc/manage-event.php?id=<?= (int) $eventId ?>" style="margin-top:0.5rem;">Open full management page</a>
                <?php endif; ?>
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
