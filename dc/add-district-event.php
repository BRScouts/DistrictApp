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

function dce_old(string $key, string $default = ''): string
{
    if (!array_key_exists($key, $_POST) || is_array($_POST[$key])) {
        return $default;
    }

    return (string) $_POST[$key];
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    $locationName = trim((string) ($_POST['location_name'] ?? ''));
    $locationAddress = trim((string) ($_POST['location_address'] ?? ''));

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

            $stmt = $pdo->prepare("
                INSERT INTO calendar_events (
                    group_id,
                    title,
                    description,
                    event_type,
                    location_name,
                    location_address,
                    starts_at,
                    ends_at,
                    leader_name,
                    leader_email,
                    submitted_by_person_id,
                    submitted_via,
                    status,
                    is_district_event"
                    . ($hasBookingColumn ? ",\n                    booking_url" : "")
                    . ($hasInfoColumn ? ",\n                    info_url" : "")
                    . "
                ) VALUES (
                    :group_id,
                    :title,
                    :description,
                    'other',
                    :location_name,
                    :location_address,
                    :starts_at,
                    :ends_at,
                    :leader_name,
                    :leader_email,
                    :submitted_by_person_id,
                    'sso',
                    'approved',
                    1"
                    . ($hasBookingColumn ? ",\n                    :booking_url" : "")
                    . ($hasInfoColumn ? ",\n                    :info_url" : "")
                    . "
                )
            ");

            $params = [
                'group_id' => $districtGroupId,
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'location_name' => $locationName !== '' ? $locationName : null,
                'location_address' => $locationAddress !== '' ? $locationAddress : null,
                'starts_at' => $startsAt?->format('Y-m-d H:i:s'),
                'ends_at' => $endsAt?->format('Y-m-d H:i:s'),
                'leader_name' => (string) ($ctx['name'] ?? 'District'),
                'leader_email' => (string) ($ctx['email'] ?? ''),
                'submitted_by_person_id' => !empty($ctx['person_id']) ? (int) $ctx['person_id'] : null,
            ];

            if ($hasBookingColumn) {
                $params['booking_url'] = $bookingUrl;
            }

            if ($hasInfoColumn) {
                $params['info_url'] = $infoUrl;
            }

            $stmt->execute($params);

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

            if ($notifyAudience !== 'none') {
                dc_queue_district_event_notifications($eventId, $notifyAudience, $selectedPeopleIds);
            }

            $redirect = '/dc/?month=' . ($startsAt ? $startsAt->format('Y-m') : date('Y-m'));
            $redirect .= '&district_event=created';

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

$pageTitle = 'Add District Event';
$heroTitle = 'Add a District Event';
$heroText = 'Create an event that every group sees on the District Calendar by default.';
$active = 'home';

require __DIR__ . '/layout.php';
?>

<style>
    .dce-form {
        display: grid;
        gap: 1.25rem;
        max-width: 760px;
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

        <div class="form-group mb-0">
            <label for="location_name">Location name</label>
            <input id="location_name" name="location_name" class="form-control" value="<?= e(dce_old('location_name')) ?>" placeholder="e.g. District HQ">
        </div>

        <div class="form-group mb-0">
            <label for="location_address">Address</label>
            <input id="location_address" name="location_address" class="form-control" value="<?= e(dce_old('location_address')) ?>" placeholder="Full address or postcode">
        </div>
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
            Choose who gets an email letting them know this District Event has been added.
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
        <button type="submit" class="btn btn-primary lt-btn">Add District Event</button>
        <a class="btn lt-btn lt-btn-secondary" href="/dc/">Cancel</a>
    </div>
</form>

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
}());
</script>
