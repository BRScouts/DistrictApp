<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// District Events may only be created by district or system admins.
$ctx = dc_require_admin();

$errors = [];

$districtGroupId = dc_district_events_group_id();

if (!$districtGroupId) {
    $errors[] = 'The District Events group has not been set up yet. Run database migration 004 (004_district_events.sql) and try again.';
}

$hasDistrictColumn = dc_table_has_column('calendar_events', 'is_district_event');

if (!$hasDistrictColumn) {
    $errors[] = 'The calendar_events table is missing the is_district_event column. Run database migration 004 (004_district_events.sql) and try again.';
}

$hasBookingColumn = dc_table_has_column('calendar_events', 'booking_url');
$hasInfoColumn = dc_table_has_column('calendar_events', 'info_url');

$districtPeople = dc_fetch_all_district_people();

$validAudiences = ['none', 'glvs', 'leaders', 'custom'];

// ── Edit mode ──────────────────────────────────────────────────────────────
// When an id is supplied we load that District Event and switch to editing it.
$eventId = (int) ($_GET['id'] ?? $_POST['event_id'] ?? 0);
$existingEvent = null;

if ($eventId > 0) {
    $loaded = dc_get_event($eventId);

    if (!$loaded || !dc_event_is_district($loaded)) {
        require __DIR__ . '/404.php';
        exit;
    }

    $existingEvent = $loaded;
}

$isEditing = $existingEvent !== null;

/**
 * Convert a stored "Y-m-d H:i:s" value to the datetime-local input format.
 */
function dce_to_input_datetime(?string $value): string
{
    if (!$value) {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d\TH:i');
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Field value for the form: posted value wins, otherwise the existing event's
 * value (edit mode), otherwise the default.
 */
function dce_old(string $key, string $default = ''): string
{
    global $existingEvent;

    if (array_key_exists($key, $_POST) && !is_array($_POST[$key])) {
        return (string) $_POST[$key];
    }

    if ($existingEvent !== null) {
        if ($key === 'starts_at' || $key === 'ends_at') {
            return dce_to_input_datetime((string) ($existingEvent[$key] ?? ''));
        }

        if (array_key_exists($key, $existingEvent) && $existingEvent[$key] !== null) {
            return (string) $existingEvent[$key];
        }
    }

    return $default;
}

function dce_parse_datetime(string $value): ?DateTimeImmutable
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);

    if ($dt instanceof DateTimeImmutable) {
        return $dt;
    }

    try {
        return new DateTimeImmutable($value);
    } catch (Throwable $e) {
        return null;
    }
}

function dce_validate_url(string $value, string $label, array &$errors): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    if (!preg_match('~^https?://~i', $value)) {
        $value = 'https://' . $value;
    }

    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        $errors[] = $label . ' must be a valid web address.';
        return null;
    }

    return $value;
}

$notifyAudience = 'none';
$selectedPeopleIds = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'delete' && $isEditing) {
    csrf_validate();

    try {
        $pdo = db();
        $pdo->beginTransaction();

        // Remove any risk-assessment links defensively (district events don't
        // create them, but this keeps the delete clean if any ever exist).
        try {
            $pdo->prepare('DELETE FROM event_risk_assessments WHERE calendar_event_id = :id')
                ->execute(['id' => $eventId]);
        } catch (Throwable $e) {
            // Table/links may not exist — ignore.
        }

        $pdo->prepare('DELETE FROM calendar_events WHERE id = :id AND is_district_event = 1')
            ->execute(['id' => $eventId]);

        $pdo->commit();

        dc_log(
            'district_event.deleted',
            'calendar_event',
            $eventId,
            ['title' => (string) ($existingEvent['title'] ?? '')],
            $districtGroupId
        );

        if (defined('AUDIT_EVENT_DELETED')) {
            audit_log(
                AUDIT_EVENT_DELETED,
                'calendar_event',
                $eventId,
                !empty($ctx['person_id']) ? (int) $ctx['person_id'] : null,
                [
                    'title' => (string) ($existingEvent['title'] ?? ''),
                    'is_district_event' => true,
                ],
                $districtGroupId
            );
        }

        redirect('/dc/?district_event=deleted');
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $errors[] = 'The District Event could not be deleted. ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') !== 'delete') {
    csrf_validate();

    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    $locationName = trim((string) ($_POST['location_name'] ?? ''));
    $locationAddress = trim((string) ($_POST['location_address'] ?? ''));

    $locationLat = trim((string) ($_POST['location_lat'] ?? ''));
    $locationLng = trim((string) ($_POST['location_lng'] ?? ''));

    if ($locationLat !== '' && !is_numeric($locationLat)) {
        $errors[] = 'The selected latitude is invalid.';
    }

    if ($locationLng !== '' && !is_numeric($locationLng)) {
        $errors[] = 'The selected longitude is invalid.';
    }

    $startsAt = dce_parse_datetime((string) ($_POST['starts_at'] ?? ''));
    $endsAt = dce_parse_datetime((string) ($_POST['ends_at'] ?? ''));

    $bookingUrl = dce_validate_url((string) ($_POST['booking_url'] ?? ''), 'The booking link', $errors);
    $infoUrl = dce_validate_url((string) ($_POST['info_url'] ?? ''), 'The information link', $errors);

    $notifyAudience = (string) ($_POST['notify_audience'] ?? 'none');

    if (!in_array($notifyAudience, $validAudiences, true)) {
        $notifyAudience = 'none';
    }

    $selectedPeopleIds = array_values(array_unique(array_filter(array_map(
        'intval',
        (array) ($_POST['notify_people'] ?? [])
    ))));

    if ($title === '') {
        $errors[] = 'Enter an event title.';
    }

    if (!$startsAt) {
        $errors[] = 'Enter a valid start date and time.';
    }

    if (!$endsAt) {
        $errors[] = 'Enter a valid end date and time.';
    }

    if ($startsAt && $endsAt && $endsAt <= $startsAt) {
        $errors[] = 'The end date and time must be after the start date and time.';
    }

    if ($notifyAudience === 'custom' && !$selectedPeopleIds) {
        $errors[] = 'Choose at least one person to notify, or change who gets emailed.';
    }

    if (!$errors) {
        try {
            $pdo = db();

            $commonParams = [
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'location_name' => $locationName !== '' ? $locationName : null,
                'location_address' => $locationAddress !== '' ? $locationAddress : null,
                'location_lat' => $locationLat !== '' ? (float) $locationLat : null,
                'location_lng' => $locationLng !== '' ? (float) $locationLng : null,
                'starts_at' => $startsAt?->format('Y-m-d H:i:s'),
                'ends_at' => $endsAt?->format('Y-m-d H:i:s'),
            ];

            if ($isEditing) {
                // ── Update an existing District Event ──
                $setParts = [
                    'title = :title',
                    'description = :description',
                    'location_name = :location_name',
                    'location_address = :location_address',
                    'location_lat = :location_lat',
                    'location_lng = :location_lng',
                    'starts_at = :starts_at',
                    'ends_at = :ends_at',
                    'updated_at = NOW()',
                ];

                if ($hasBookingColumn) {
                    $setParts[] = 'booking_url = :booking_url';
                }

                if ($hasInfoColumn) {
                    $setParts[] = 'info_url = :info_url';
                }

                $sql = 'UPDATE calendar_events SET ' . implode(', ', $setParts)
                    . ' WHERE id = :id AND is_district_event = 1 LIMIT 1';

                $params = $commonParams;
                $params['id'] = $eventId;

                if ($hasBookingColumn) {
                    $params['booking_url'] = $bookingUrl;
                }

                if ($hasInfoColumn) {
                    $params['info_url'] = $infoUrl;
                }

                $pdo->prepare($sql)->execute($params);

                dc_log(
                    'district_event.updated',
                    'calendar_event',
                    $eventId,
                    [
                        'title' => $title,
                        'notify_audience' => $notifyAudience,
                    ],
                    $districtGroupId
                );

                if (defined('AUDIT_EVENT_UPDATED')) {
                    audit_log(
                        AUDIT_EVENT_UPDATED,
                        'calendar_event',
                        $eventId,
                        !empty($ctx['person_id']) ? (int) $ctx['person_id'] : null,
                        ['title' => $title, 'is_district_event' => true],
                        $districtGroupId
                    );
                }

                $savedWord = 'updated';
            } else {
                // ── Create a new District Event ──
                $sql = "
                    INSERT INTO calendar_events (
                        group_id,
                        title,
                        description,
                        event_type,
                        location_name,
                        location_address,
                        location_lat,
                        location_lng,
                        starts_at,
                        ends_at,
                        leader_name,
                        leader_email,
                        submitted_by_person_id,
                        submitted_via,
                        status,
                        is_district_event"
                        . ($hasBookingColumn ? ",\n                        booking_url" : "")
                        . ($hasInfoColumn ? ",\n                        info_url" : "")
                        . "
                    ) VALUES (
                        :group_id,
                        :title,
                        :description,
                        'other',
                        :location_name,
                        :location_address,
                        :location_lat,
                        :location_lng,
                        :starts_at,
                        :ends_at,
                        :leader_name,
                        :leader_email,
                        :submitted_by_person_id,
                        'sso',
                        'approved',
                        1"
                        . ($hasBookingColumn ? ",\n                        :booking_url" : "")
                        . ($hasInfoColumn ? ",\n                        :info_url" : "")
                        . "
                    )
                ";

                $params = $commonParams;
                $params['group_id'] = $districtGroupId;
                $params['leader_name'] = (string) ($ctx['name'] ?? 'District');
                $params['leader_email'] = (string) ($ctx['email'] ?? '');
                $params['submitted_by_person_id'] = !empty($ctx['person_id']) ? (int) $ctx['person_id'] : null;

                if ($hasBookingColumn) {
                    $params['booking_url'] = $bookingUrl;
                }

                if ($hasInfoColumn) {
                    $params['info_url'] = $infoUrl;
                }

                $pdo->prepare($sql)->execute($params);

                $eventId = (int) $pdo->lastInsertId();

                dc_log(
                    'district_event.created',
                    'calendar_event',
                    $eventId,
                    [
                        'title' => $title,
                        'notify_audience' => $notifyAudience,
                        'notify_count' => $notifyAudience === 'custom' ? count($selectedPeopleIds) : null,
                    ],
                    $districtGroupId
                );

                if (defined('AUDIT_EVENT_CREATED')) {
                    audit_log(
                        AUDIT_EVENT_CREATED,
                        'calendar_event',
                        $eventId,
                        !empty($ctx['person_id']) ? (int) $ctx['person_id'] : null,
                        [
                            'title' => $title,
                            'status' => 'approved',
                            'event_type' => 'district_event',
                            'is_district_event' => true,
                        ],
                        $districtGroupId
                    );
                }

                $savedWord = 'created';
            }

            if ($notifyAudience !== 'none') {
                dc_queue_district_event_notifications($eventId, $notifyAudience, $selectedPeopleIds);
            }

            $redirect = '/dc/?month=' . ($startsAt ? $startsAt->format('Y-m') : date('Y-m'));
            $redirect .= '&district_event=' . $savedWord;

            if ($notifyAudience !== 'none') {
                $redirect .= '&notified=1';
            }

            redirect($redirect);
        } catch (Throwable $e) {
            $errors[] = 'The District Event could not be saved. ' . $e->getMessage();
        }
    }
}

$nowLocalMin = (new DateTimeImmutable('now'))->format('Y-m-d\TH:i');

$pageTitle = $isEditing ? 'Edit District Event' : 'Add District Event';
$heroTitle = $isEditing ? 'Edit District Event' : 'Add a District Event';
$heroText = $isEditing
    ? 'Update this event. It stays visible to every group on the District Calendar.'
    : 'Create an event that every group sees on the District Calendar by default.';
$active = 'home';

require __DIR__ . '/layout.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">

<style>
    .dce-form {
        display: grid;
        gap: 1.25rem;
        max-width: 760px;
    }

    .dce-map-search {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.75rem;
    }

    @media (min-width: 768px) {
        .dce-map-search {
            grid-template-columns: 1fr auto;
            align-items: end;
        }
    }

    .dce-location-map-wrap {
        position: relative;
        width: 100%;
        overflow: hidden;
        border: 1px solid var(--dc-border, #e2e8f0);
        background: var(--dc-canvas, #f8fafc);
        margin-top: 1rem;
        isolation: isolate;
    }

    .dce-location-map {
        display: block;
        width: 100%;
        height: 320px;
        position: relative;
        z-index: 0;
    }

    .dce-location-results {
        margin-top: 1rem;
        border: 1px solid var(--dc-border, #e2e8f0);
        background: #fff;
    }

    .dce-location-results button {
        display: block;
        width: 100%;
        padding: 0.75rem;
        border: 0;
        border-bottom: 1px solid var(--dc-border, #e2e8f0);
        background: #fff;
        text-align: left;
        cursor: pointer;
        font-weight: 600;
    }

    .dce-location-results button:hover,
    .dce-location-results button:focus {
        background: var(--dc-canvas, #f8fafc);
        outline: 3px solid #ffdd00;
        outline-offset: -3px;
    }

    .dce-form .lt-panel {
        display: grid;
        gap: 1rem;
    }

    .dce-grid-2 {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    @media (min-width: 640px) {
        .dce-grid-2 {
            grid-template-columns: 1fr 1fr;
        }
    }

    .dce-form label {
        display: block;
        margin-bottom: 0.35rem;
        font-weight: 700;
        color: var(--dc-ink, #1d2939);
    }

    .dce-form .form-text {
        margin-top: 0.35rem;
        font-size: 0.85rem;
        color: var(--dc-muted, #64748b);
        font-weight: 600;
    }

    .dce-radio-list {
        display: grid;
        gap: 0.6rem;
    }

    .dce-radio {
        display: flex;
        gap: 0.6rem;
        align-items: flex-start;
        padding: 0.65rem 0.75rem;
        border: 1px solid var(--dc-border, #e2e8f0);
        background: #fff;
        font-weight: 600;
    }

    .dce-radio input {
        margin-top: 0.2rem;
    }

    .dce-people {
        margin-top: 0.75rem;
        border: 1px solid var(--dc-border, #e2e8f0);
        background: #fff;
        padding: 0.75rem;
        max-height: 320px;
        overflow-y: auto;
        display: none;
    }

    .dce-people.is-visible {
        display: block;
    }

    .dce-person {
        display: flex;
        gap: 0.55rem;
        align-items: flex-start;
        padding: 0.35rem 0;
        font-weight: 600;
        border-bottom: 1px solid var(--dc-border, #e2e8f0);
    }

    .dce-person:last-child {
        border-bottom: 0;
    }

    .dce-person small {
        display: block;
        color: var(--dc-muted, #64748b);
        font-weight: 600;
    }

    .dce-people-search {
        margin-bottom: 0.75rem;
    }

    .dce-ooo-preview {
        border: 1px solid var(--dc-border, #e2e8f0);
        background: #fff;
        max-width: 260px;
    }

    .dce-ooo-preview-head {
        height: 18px;
        background-image: repeating-linear-gradient(
            45deg,
            #4d0b93,
            #4d0b93 6px,
            #7413dc 6px,
            #7413dc 12px
        );
    }

    .dce-ooo-preview-body {
        padding: 0.5rem 0.6rem;
    }

    .dce-ooo-preview-body strong {
        display: block;
        font-size: 0.85rem;
    }

    .dce-ooo-preview-body span {
        display: block;
        font-size: 0.75rem;
        color: var(--dc-muted, #64748b);
    }

    .dc-error-summary {
        border: 2px solid #dc2626;
        padding: 1rem 1.25rem;
        margin-bottom: 1.5rem;
        background: #fef2f2;
    }

    .dc-error-summary h2 {
        margin: 0 0 0.5rem;
        color: #dc2626;
        font-size: 1.1rem;
    }

    .dce-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .dce-delete-form {
        max-width: 760px;
        margin-top: 2rem;
        padding: 1.25rem;
        border: 1px solid #fecaca;
        border-left: 4px solid #dc2626;
        background: #fef2f2;
    }

    .dce-delete-btn {
        border-radius: 0;
        font-weight: 800;
        padding: 0.65rem 1rem;
        background: #fff;
        color: #dc2626;
        border: 2px solid #dc2626;
    }

    .dce-delete-btn:hover,
    .dce-delete-btn:focus {
        background: #dc2626;
        color: #fff;
    }
</style>

<?php if ($errors): ?>
    <div class="dc-error-summary" role="alert" tabindex="-1">
        <h2>Check the form</h2>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="dce-form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="save">
    <?php if ($isEditing): ?>
        <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
    <?php endif; ?>

    <section class="lt-panel">
        <h2 class="lt-section-title">Event details</h2>

        <div class="form-group mb-0">
            <label for="title">Event title</label>
            <input id="title" name="title" class="form-control" required value="<?= e(dce_old('title')) ?>">
        </div>

        <div class="form-group mb-0">
            <label for="description">Description</label>
            <textarea id="description" name="description" class="form-control" rows="4"><?= e(dce_old('description')) ?></textarea>
            <p class="form-text">Shown to every group when they open the event. Add any details leaders need.</p>
        </div>

        <div class="dce-grid-2">
            <div class="form-group mb-0">
                <label for="starts_at">Starts</label>
                <input
                    id="starts_at"
                    name="starts_at"
                    type="datetime-local"
                    class="form-control"
                    required
                    value="<?= e(dce_old('starts_at')) ?>"
                >
            </div>

            <div class="form-group mb-0">
                <label for="ends_at">Ends</label>
                <input
                    id="ends_at"
                    name="ends_at"
                    type="datetime-local"
                    class="form-control"
                    required
                    value="<?= e(dce_old('ends_at')) ?>"
                >
            </div>
        </div>
    </section>

    <section class="lt-panel">
        <h2 class="lt-section-title">Location</h2>

        <div class="dce-map-search">
            <div class="form-group mb-0">
                <label for="location_search">Search for a location</label>
                <input
                    id="location_search"
                    class="form-control"
                    autocomplete="off"
                    placeholder="Example: Bibby's Farm, Chorley"
                >
            </div>
            <div class="form-group mb-0">
                <button type="button" class="btn btn-primary lt-btn" id="location_search_button">Search map</button>
            </div>
        </div>

        <div id="location_results" class="dce-location-results" hidden></div>

        <div class="dce-location-map-wrap">
            <div id="location_map" class="dce-location-map" aria-label="Selected event location map"></div>
        </div>

        <p class="form-text" style="margin-top:0.5rem;">Search, or click the map to drop a pin. This is shown on the event page.</p>

        <div class="form-group mb-0">
            <label for="location_name">Location name</label>
            <input id="location_name" name="location_name" class="form-control" value="<?= e(dce_old('location_name')) ?>" placeholder="e.g. District HQ">
        </div>

        <div class="form-group mb-0">
            <label for="location_address">Address</label>
            <textarea id="location_address" name="location_address" class="form-control" rows="2" placeholder="Full address or postcode"><?= e(dce_old('location_address')) ?></textarea>
        </div>

        <input type="hidden" id="location_lat" name="location_lat" value="<?= e(dce_old('location_lat')) ?>">
        <input type="hidden" id="location_lng" name="location_lng" value="<?= e(dce_old('location_lng')) ?>">
    </section>

    <section class="lt-panel">
        <h2 class="lt-section-title">Links</h2>

        <div class="form-group mb-0">
            <label for="booking_url">Booking link</label>
            <input id="booking_url" name="booking_url" class="form-control" value="<?= e(dce_old('booking_url')) ?>" placeholder="https://...">
            <p class="form-text">Optional. A link groups can use to book onto the event.</p>
        </div>

        <div class="form-group mb-0">
            <label for="info_url">More information link</label>
            <input id="info_url" name="info_url" class="form-control" value="<?= e(dce_old('info_url')) ?>" placeholder="https://...">
            <p class="form-text">Optional. Any other link — a flyer, programme, or details page.</p>
        </div>
    </section>

    <section class="lt-panel">
        <h2 class="lt-section-title">How it looks on the calendar</h2>
        <p class="form-text" style="margin-top:0;">
            District Events are shown to every group with a distinct hatched header so they stand out from
            normal group events.
        </p>
        <div class="dce-ooo-preview" aria-hidden="true">
            <div class="dce-ooo-preview-head"></div>
            <div class="dce-ooo-preview-body">
                <strong><?= e(dce_old('title') !== '' ? dce_old('title') : 'District Event') ?></strong>
                <span>District Events</span>
            </div>
        </div>
    </section>

    <section class="lt-panel">
        <h2 class="lt-section-title">Notify people</h2>
        <p class="form-text" style="margin-top:0;">
            <?= $isEditing
                ? 'Choose whether to email anyone about this change. Leave as "Do not email anyone" to save quietly.'
                : 'Choose who gets an email letting them know this District Event has been added.' ?>
        </p>

        <div class="dce-radio-list" role="radiogroup" aria-label="Who to notify">
            <?php
                $audienceOptions = [
                    'none' => ['Do not email anyone', 'Just add it to the calendar.'],
                    'glvs' => ['Email all GLVs', 'Every Group Lead Volunteer across the District.'],
                    'leaders' => ['Email all leaders', 'Every active leader in every group.'],
                    'custom' => ['Email specific people', 'Pick exactly who to notify below.'],
                ];
                $currentAudience = dce_old('notify_audience', 'none');
            ?>
            <?php foreach ($audienceOptions as $value => $labels): ?>
                <label class="dce-radio">
                    <input
                        type="radio"
                        name="notify_audience"
                        value="<?= e($value) ?>"
                        data-notify-audience
                        <?= $currentAudience === $value ? 'checked' : '' ?>
                    >
                    <span>
                        <strong><?= e($labels[0]) ?></strong><br>
                        <small class="form-text" style="margin-top:0;"><?= e($labels[1]) ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="dce-people <?= $currentAudience === 'custom' ? 'is-visible' : '' ?>" data-people-panel>
            <div class="dce-people-search">
                <label for="dce-people-filter" class="sr-only">Search people</label>
                <input id="dce-people-filter" class="form-control" type="search" placeholder="Search by name" data-people-filter autocomplete="off">
            </div>

            <?php if (!$districtPeople): ?>
                <p class="form-text" style="margin-top:0;">No active people with email addresses were found.</p>
            <?php else: ?>
                <?php foreach ($districtPeople as $person): ?>
                    <?php $pid = (int) $person['id']; ?>
                    <label class="dce-person" data-person-name="<?= e(strtolower((string) $person['full_name'])) ?>">
                        <input
                            type="checkbox"
                            name="notify_people[]"
                            value="<?= $pid ?>"
                            <?= in_array($pid, $selectedPeopleIds, true) ? 'checked' : '' ?>
                        >
                        <span>
                            <?= e((string) $person['full_name']) ?>
                            <small><?= e((string) ($person['group_name'] ?? '')) ?> · <?= e((string) $person['primary_email']) ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <div class="dce-actions">
        <button type="submit" class="btn btn-primary lt-btn"><?= $isEditing ? 'Save changes' : 'Add District Event' ?></button>
        <?php if ($isEditing): ?>
            <a class="btn lt-btn lt-btn-secondary" href="/dc/district-event.php?id=<?= (int) $eventId ?>">Cancel</a>
        <?php else: ?>
            <a class="btn lt-btn lt-btn-secondary" href="/dc/">Cancel</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($isEditing): ?>
    <form method="post" class="dce-delete-form" data-delete-form>
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="delete">
        <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
        <h2 class="lt-section-title" style="margin-top:0;">Delete this event</h2>
        <p class="form-text" style="margin-top:0;">
            Permanently remove this District Event from the calendar for every group. This cannot be undone.
        </p>
        <button type="submit" class="btn dce-delete-btn" data-delete-button>Delete District Event</button>
    </form>
<?php endif; ?>

<script <?= csp_nonce_attr() ?>>
(function () {
    var radios = document.querySelectorAll('[data-notify-audience]');
    var panel = document.querySelector('[data-people-panel]');
    var filter = document.querySelector('[data-people-filter]');

    function sync() {
        if (!panel) return;
        var checked = document.querySelector('[data-notify-audience]:checked');
        var isCustom = checked && checked.value === 'custom';
        panel.classList.toggle('is-visible', isCustom);
    }

    radios.forEach(function (radio) {
        radio.addEventListener('change', sync);
    });

    if (filter) {
        filter.addEventListener('input', function () {
            var term = filter.value.toLowerCase().trim();
            var people = panel.querySelectorAll('[data-person-name]');
            people.forEach(function (person) {
                var name = person.getAttribute('data-person-name') || '';
                person.style.display = name.indexOf(term) !== -1 ? '' : 'none';
            });
        });
    }

    sync();

    // Keep the end date/time on or after the start date/time.
    var startsAt = document.getElementById('starts_at');
    var endsAt = document.getElementById('ends_at');

    if (startsAt && endsAt) {
        var syncEndMin = function () {
            if (startsAt.value) {
                endsAt.min = startsAt.value;

                // If the current end is now before the start, clear it so the
                // user has to re-pick a valid value.
                if (endsAt.value && endsAt.value < startsAt.value) {
                    endsAt.value = '';
                }
            } else {
                endsAt.removeAttribute('min');
            }
        };

        var checkEndAfterStart = function () {
            if (startsAt.value && endsAt.value && endsAt.value <= startsAt.value) {
                endsAt.setCustomValidity('The end date and time must be after the start date and time.');
            } else {
                endsAt.setCustomValidity('');
            }
        };

        startsAt.addEventListener('change', function () {
            syncEndMin();
            checkEndAfterStart();
        });
        endsAt.addEventListener('change', checkEndAfterStart);
        endsAt.addEventListener('input', checkEndAfterStart);

        // Apply constraints on load (covers repopulated values after an error).
        syncEndMin();
        checkEndAfterStart();

        var dceForm = document.querySelector('.dce-form');
        if (dceForm) {
            dceForm.addEventListener('submit', function (event) {
                checkEndAfterStart();
                if (!endsAt.checkValidity()) {
                    event.preventDefault();
                    endsAt.reportValidity();
                }
            });
        }
    }

    // Confirm before deleting.
    var deleteForm = document.querySelector('[data-delete-form]');
    if (deleteForm) {
        deleteForm.addEventListener('submit', function (event) {
            if (!window.confirm('Delete this District Event? This removes it from every group\u2019s calendar and cannot be undone.')) {
                event.preventDefault();
            }
        });
    }
}());
</script>

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" <?= csp_nonce_attr() ?>></script>

<script <?= csp_nonce_attr() ?>>
(function () {
    var mapElement = document.getElementById('location_map');
    var searchInput = document.getElementById('location_search');
    var searchButton = document.getElementById('location_search_button');
    var resultsElement = document.getElementById('location_results');

    var nameInput = document.getElementById('location_name');
    var addressInput = document.getElementById('location_address');
    var latInput = document.getElementById('location_lat');
    var lngInput = document.getElementById('location_lng');

    if (!mapElement || typeof L === 'undefined') {
        return;
    }

    var defaultLat = parseFloat(latInput.value || '53.647');
    var defaultLng = parseFloat(lngInput.value || '-2.316');

    var map = L.map(mapElement, { scrollWheelZoom: false }).setView([defaultLat, defaultLng], 11);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var marker = null;

    function setLocation(lat, lng, name, address) {
        latInput.value = String(lat);
        lngInput.value = String(lng);

        if (name && !nameInput.value) nameInput.value = name;
        if (address && !addressInput.value) addressInput.value = address;

        if (marker) {
            marker.setLatLng([lat, lng]);
        } else {
            marker = L.marker([lat, lng]).addTo(map);
        }

        map.setView([lat, lng], 15);
        setTimeout(function () { map.invalidateSize(); }, 100);
    }

    if (latInput.value && lngInput.value && !Number.isNaN(defaultLat) && !Number.isNaN(defaultLng)) {
        setLocation(defaultLat, defaultLng, nameInput.value, addressInput.value);
    }

    map.on('click', function (event) {
        var lat = Number(event.latlng.lat.toFixed(7));
        var lng = Number(event.latlng.lng.toFixed(7));
        setLocation(lat, lng, nameInput.value, addressInput.value);
    });

    // Build a tidy "Location name" from a Nominatim result rather than echoing
    // the raw search query (e.g. a bare postcode) back into the field.
    function buildLocationName(result) {
        var a = result.address || {};
        var primary = a.amenity || a.building || a.leisure || a.tourism
            || a.village || a.hamlet || a.suburb || a.town || a.city || result.name;
        if (primary) return primary;
        if (result.display_name) return result.display_name.split(',')[0].trim();
        return '';
    }

    // The user has explicitly chosen a search result, so overwrite the fields
    // (setLocation only fills blanks to preserve manual edits).
    function applyResult(result) {
        if (!result) return;
        var lat = Number(parseFloat(result.lat).toFixed(7));
        var lng = Number(parseFloat(result.lon).toFixed(7));
        var name = buildLocationName(result);
        var address = result.display_name || name;
        if (name) nameInput.value = name;
        if (address) addressInput.value = address;
        setLocation(lat, lng, name, address);
    }

    function searchLocation() {
        var query = searchInput.value.trim();
        if (!query) { searchInput.focus(); return; }

        searchButton.disabled = true;
        searchButton.textContent = 'Searching...';
        resultsElement.hidden = true;
        resultsElement.innerHTML = '';

        var url = new URL('/dc/geocode-search.php', window.location.origin);
        url.searchParams.set('q', query);

        fetch(url.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (response) {
                if (response.status === 429) {
                    resultsElement.hidden = false;
                    resultsElement.innerHTML = '<div style="padding:0.75rem;">The map service is busy. Please wait a few seconds and search again.</div>';
                    return null;
                }
                if (!response.ok) throw new Error('search failed');
                return response.json();
            })
            .then(function (results) {
                if (results === null) return;
                if (!Array.isArray(results) || results.length === 0) {
                    resultsElement.hidden = false;
                    resultsElement.innerHTML = '<div style="padding:0.75rem;">No matching locations found. Try a postcode, town or venue name.</div>';
                    return;
                }

                // Apply the best match straight away so a postcode search fills
                // the address fields without a second click. The list still lets
                // the user pick a different result if the top one is wrong.
                applyResult(results[0]);

                results.forEach(function (result) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = result.display_name || 'Unnamed location';
                    button.addEventListener('click', function () {
                        applyResult(result);
                        resultsElement.hidden = true;
                    });
                    resultsElement.appendChild(button);
                });

                resultsElement.hidden = false;
            })
            .catch(function () {
                resultsElement.hidden = false;
                resultsElement.innerHTML = '<div style="padding:0.75rem;">The location search is unavailable. You can still click the map to choose a location.</div>';
            })
            .finally(function () {
                searchButton.disabled = false;
                searchButton.textContent = 'Search map';
                setTimeout(function () { map.invalidateSize(); }, 100);
            });
    }

    searchButton.addEventListener('click', searchLocation);
    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); searchLocation(); }
    });

    window.addEventListener('resize', function () { map.invalidateSize(); });
    setTimeout(function () { map.invalidateSize(); }, 250);
}());
</script>
