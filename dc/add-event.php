<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$ctx = dc_require_access();

$requestedGroupId = isset($_GET['group_id'])
    ? (int) $_GET['group_id']
    : (isset($_POST['group_id']) ? (int) $_POST['group_id'] : null);

$groupId = dc_selected_group_id($requestedGroupId);
$groups = dc_accessible_groups();
$showGroupPicker = count($groups) > 1;

$sections = dc_fetch_sections($groupId);
$people = dc_fetch_group_people($groupId);

$errors = [];

$isSsoUser = ($ctx['actor_type'] ?? '') === 'person' && !empty($ctx['person_id']);
$currentPerson = null;

$validEventTypes = [
    'meeting_away_from_hut',
    'day_activity',
    'nights_away',
    'camp',
    'hike',
    'water_activity',
    'other',
];

/**
 * Local helper for sticky form values.
 */
function old_value(string $key, string $default = ''): string
{
    if (!array_key_exists($key, $_POST)) {
        return $default;
    }

    $value = $_POST[$key];

    if (is_array($value)) {
        return $default;
    }

    return (string) $value;
}

/**
 * Convert a datetime-local value into a DateTimeImmutable.
 */
function parse_local_datetime(string $value): ?DateTimeImmutable
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

/**
 * Return a single uploaded file from a multiple file input.
 */
function uploaded_file_from_multiple(array $files, int $index): array
{
    return [
        'name' => $files['name'][$index] ?? '',
        'type' => $files['type'][$index] ?? '',
        'tmp_name' => $files['tmp_name'][$index] ?? '',
        'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$index] ?? 0,
    ];
}

/**
 * Fetch the signed-in person, including their role for the selected Group where available.
 */
function dc_fetch_current_person_for_event(int $personId, int $groupId): ?array
{
    $stmt = db()->prepare("
        SELECT
            p.id,
            p.full_name,
            p.primary_email,
            p.phone,
            COALESCE(gm.membership_role, 'member') AS membership_role
        FROM people p
        LEFT JOIN group_memberships gm
            ON gm.person_id = p.id
           AND gm.group_id = :group_id
           AND gm.status = 'active'
        WHERE p.id = :person_id
        LIMIT 1
    ");

    $stmt->execute([
        'person_id' => $personId,
        'group_id' => $groupId,
    ]);

    $person = $stmt->fetch(PDO::FETCH_ASSOC);

    return $person ?: null;
}

if ($isSsoUser) {
    $currentPerson = dc_fetch_current_person_for_event((int) $ctx['person_id'], $groupId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupId = dc_selected_group_id((int) ($_POST['group_id'] ?? $groupId));
    $sections = dc_fetch_sections($groupId);
    $people = dc_fetch_group_people($groupId);

    $isSsoUser = ($ctx['actor_type'] ?? '') === 'person' && !empty($ctx['person_id']);
    $currentPerson = $isSsoUser
        ? dc_fetch_current_person_for_event((int) $ctx['person_id'], $groupId)
        : null;

    $selectedLeader = null;

    if ($isSsoUser) {
        $selectedLeader = $currentPerson;

        if (!$selectedLeader) {
            $errors[] = 'We could not find your user profile. Please complete onboarding before submitting an event.';
        } elseif (empty($selectedLeader['primary_email'])) {
            $errors[] = 'Your profile needs an email address before you can submit an event.';
        }
    } else {
        $leaderPersonId = (int) ($_POST['leader_person_id'] ?? 0);

        foreach ($people as $person) {
            if ((int) $person['id'] === $leaderPersonId) {
                $selectedLeader = $person;
                break;
            }
        }

        if (!$selectedLeader) {
            $errors[] = 'Choose the leader responsible for this event. If they are missing, ask a Group Lead Volunteer to add them.';
        }
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $eventType = (string) ($_POST['event_type'] ?? 'other');

    if (!in_array($eventType, $validEventTypes, true)) {
        $eventType = 'other';
    }

    $startsAtRaw = trim((string) ($_POST['starts_at'] ?? ''));
    $endsAtRaw = trim((string) ($_POST['ends_at'] ?? ''));

    $startsAt = parse_local_datetime($startsAtRaw);
    $endsAt = parse_local_datetime($endsAtRaw);

    $now = new DateTimeImmutable('now');

    if ($title === '') {
        $errors[] = 'Enter an event title.';
    }

    if (!$startsAt) {
        $errors[] = 'Enter a valid start date and time.';
    }

    if (!$endsAt) {
        $errors[] = 'Enter a valid end date and time.';
    }

    if ($startsAt && $startsAt < $now->modify('-1 minute')) {
        $errors[] = 'The start date and time cannot be in the past.';
    }

    if ($startsAt && $endsAt && $endsAt <= $startsAt) {
        $errors[] = 'The end date and time must be after the start date and time.';
    }

    $locationLat = trim((string) ($_POST['location_lat'] ?? ''));
    $locationLng = trim((string) ($_POST['location_lng'] ?? ''));

    if ($locationLat !== '' && !is_numeric($locationLat)) {
        $errors[] = 'The selected latitude is invalid.';
    }

    if ($locationLng !== '' && !is_numeric($locationLng)) {
        $errors[] = 'The selected longitude is invalid.';
    }

    $validSectionIds = array_map(
        static fn (array $section): int => (int) $section['id'],
        $sections
    );

    $selectedSectionIds = [];

    foreach ((array) ($_POST['section_ids'] ?? []) as $sectionId) {
        $sectionId = (int) $sectionId;

        if (in_array($sectionId, $validSectionIds, true)) {
            $selectedSectionIds[] = $sectionId;
        }
    }

    $selectedSectionIds = array_values(array_unique($selectedSectionIds));

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("
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
                    young_people_count,
                    adult_count,
                    leader_name,
                    leader_email,
                    leader_phone,
                    leader_role,
                    emergency_contact_name,
                    emergency_contact_phone,
                    submitted_by_person_id,
                    submitted_via,
                    status
                ) VALUES (
                    :group_id,
                    :title,
                    :description,
                    :event_type,
                    :location_name,
                    :location_address,
                    :location_lat,
                    :location_lng,
                    :starts_at,
                    :ends_at,
                    :young_people_count,
                    :adult_count,
                    :leader_name,
                    :leader_email,
                    :leader_phone,
                    :leader_role,
                    :emergency_contact_name,
                    :emergency_contact_phone,
                    :submitted_by_person_id,
                    :submitted_via,
                    'submitted'
                )
            ");

            $stmt->execute([
                'group_id' => $groupId,
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'event_type' => $eventType,
                'location_name' => trim((string) ($_POST['location_name'] ?? '')) ?: null,
                'location_address' => trim((string) ($_POST['location_address'] ?? '')) ?: null,
                'location_lat' => $locationLat !== '' ? (float) $locationLat : null,
                'location_lng' => $locationLng !== '' ? (float) $locationLng : null,
                'starts_at' => $startsAt?->format('Y-m-d H:i:s'),
                'ends_at' => $endsAt?->format('Y-m-d H:i:s'),
                'young_people_count' => ($_POST['young_people_count'] ?? '') !== '' ? (int) $_POST['young_people_count'] : null,
                'adult_count' => ($_POST['adult_count'] ?? '') !== '' ? (int) $_POST['adult_count'] : null,
                'leader_name' => (string) ($selectedLeader['full_name'] ?? ''),
                'leader_email' => (string) ($selectedLeader['primary_email'] ?? ''),
                'leader_phone' => $selectedLeader['phone'] ?? null,
                'leader_role' => str_replace('_', ' ', (string) ($selectedLeader['membership_role'] ?? '')),
                'emergency_contact_name' => trim((string) ($_POST['emergency_contact_name'] ?? '')) ?: null,
                'emergency_contact_phone' => trim((string) ($_POST['emergency_contact_phone'] ?? '')) ?: null,
                'submitted_by_person_id' => $isSsoUser ? (int) $ctx['person_id'] : null,
                'submitted_via' => $isSsoUser ? 'sso' : 'group_link',
            ]);

            $eventId = (int) $pdo->lastInsertId();

            foreach ($selectedSectionIds as $sectionId) {
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO calendar_event_sections (
                        calendar_event_id,
                        group_section_id
                    ) VALUES (
                        :event_id,
                        :section_id
                    )
                ");

                $stmt->execute([
                    'event_id' => $eventId,
                    'section_id' => $sectionId,
                ]);
            }

            foreach ((array) ($_POST['existing_risk_assessment_ids'] ?? []) as $riskId) {
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO event_risk_assessments (
                        calendar_event_id,
                        risk_assessment_id,
                        source_type
                    )
                    SELECT
                        :event_id,
                        id,
                        'selected_existing'
                    FROM risk_assessments
                    WHERE id = :risk_id
                      AND status = 'active'
                      AND admin_review_status = 'available'
                      AND (group_id = :group_id OR visibility = 'district')
                    LIMIT 1
                ");

                $stmt->execute([
                    'event_id' => $eventId,
                    'risk_id' => (int) $riskId,
                    'group_id' => $groupId,
                ]);
            }

            if (
                isset($_FILES['risk_files'])
                && is_array($_FILES['risk_files']['name'] ?? null)
            ) {
                $fileCount = count($_FILES['risk_files']['name']);
                $uploadedRiskCount = 0;

                for ($i = 0; $i < $fileCount; $i++) {
                    $file = uploaded_file_from_multiple($_FILES['risk_files'], $i);

                    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }

                    $uploadedRiskCount++;

                    $riskTitle = trim((string) ($_POST['risk_title'] ?? ''));

                    if ($riskTitle === '') {
                        $riskTitle = $title . ' risk assessment';
                    }

                    if ($fileCount > 1 && !empty($file['name'])) {
                        $riskTitle .= ' - ' . $file['name'];
                    }

                    $riskId = dc_store_risk_assessment_upload(
                        $file,
                        $groupId,
                        $riskTitle,
                        trim((string) ($_POST['risk_description'] ?? '')) ?: null,
                        (string) ($selectedLeader['full_name'] ?? ''),
                        (string) ($selectedLeader['primary_email'] ?? ''),
                        $isSsoUser ? (int) $ctx['person_id'] : null,
                        $isSsoUser ? 'sso' : 'group_link',
                        (string) ($_POST['risk_visibility'] ?? 'district')
                    );

                    $stmt = $pdo->prepare("
                        INSERT INTO event_risk_assessments (
                            calendar_event_id,
                            risk_assessment_id,
                            source_type
                        ) VALUES (
                            :event_id,
                            :risk_id,
                            'uploaded'
                        )
                    ");

                    $stmt->execute([
                        'event_id' => $eventId,
                        'risk_id' => $riskId,
                    ]);
                }
            }

            $pdo->commit();

            dc_log(
                'calendar_event.created',
                'calendar_event',
                $eventId,
                ['status' => 'submitted'],
                $groupId
            );

            dc_queue_event_notifications($eventId, 'submitted');

            redirect('/dc/manage-event.php?id=' . $eventId . '&created=1');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'The event could not be saved. ' . $e->getMessage();
        }
    }
}

$stmt = db()->prepare("
    SELECT
        id,
        title,
        visibility,
        uploaded_by_name,
        uploaded_at
    FROM risk_assessments
    WHERE status = 'active'
      AND admin_review_status = 'available'
      AND (group_id = :group_id OR visibility = 'district')
    ORDER BY visibility DESC, uploaded_at DESC
    LIMIT 75
");

$stmt->execute(['group_id' => $groupId]);
$riskAssessments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nowLocalMin = (new DateTimeImmutable('now'))->format('Y-m-d\TH:i');

$pageTitle = 'Add event';
$heroTitle = 'Add an event';
$heroText = 'Submit an away-from-hut notification or activity for review.';
$active = 'add';

require __DIR__ . '/layout.php';
?>

<link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
>

<style>
    .dc-map-search {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.75rem;
    }

    @media (min-width: 768px) {
        .dc-map-search {
            grid-template-columns: 1fr auto;
            align-items: end;
        }
    }

    .dc-location-map {
        min-height: 320px;
        border: 2px solid #000;
        margin-top: 1rem;
        background: #f5f5f5;
    }

    .dc-location-results {
        margin-top: 1rem;
        border: 1px solid #d8d8d8;
        background: #fff;
    }

    .dc-location-result {
        display: block;
        width: 100%;
        padding: 0.75rem;
        border: 0;
        border-bottom: 1px solid #d8d8d8;
        background: #fff;
        text-align: left;
        cursor: pointer;
    }

    .dc-location-result:hover,
    .dc-location-result:focus {
        background: #f5f5f5;
        outline: 3px solid #ffdd00;
        outline-offset: -3px;
    }

    .dc-selected-location {
        margin-top: 0.75rem;
        padding: 0.75rem;
        border-left: 5px solid #00a794;
        background: #f5f5f5;
    }

    .dc-hidden-field-summary {
        font-size: 0.95rem;
        color: #4a4a4a;
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

<form method="post" enctype="multipart/form-data" class="dc-form" novalidate>
    <?php if ($showGroupPicker): ?>
        <section class="lt-panel">
            <h2 class="lt-section-title">Group</h2>

            <label for="group_id">Choose Group</label>
            <select
                id="group_id"
                name="group_id"
                class="form-control"
                onchange="window.location='/dc/add-event.php?group_id=' + encodeURIComponent(this.value)"
            >
                <?= dc_group_options_html($groupId) ?>
            </select>
        </section>
    <?php else: ?>
        <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
    <?php endif; ?>

    <section class="lt-panel">
        <h2 class="lt-section-title">Who is leading this?</h2>

        <?php if ($isSsoUser): ?>
            <?php if ($currentPerson): ?>
                <p class="mb-1">
                    You are submitting this event as:
                </p>

                <div class="dc-selected-location">
                    <strong><?= e((string) $currentPerson['full_name']) ?></strong>
                    <?php if (!empty($currentPerson['primary_email'])): ?>
                        <br>
                        <span><?= e((string) $currentPerson['primary_email']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($currentPerson['phone'])): ?>
                        <br>
                        <span><?= e((string) $currentPerson['phone']) ?></span>
                    <?php endif; ?>
                </div>

                <p class="form-text mt-2">
                    Because you are signed in, the event will be linked to your profile.
                </p>
            <?php else: ?>
                <p class="dc-warning">
                    We could not find your profile. Please complete onboarding before submitting events.
                </p>
            <?php endif; ?>
        <?php else: ?>
            <?php if (!$people): ?>
                <p class="dc-warning">
                    No leaders have been added to this Group yet. Ask a Group Lead Volunteer to add leaders before submitting events.
                </p>
            <?php else: ?>
                <label for="leader_person_id">Leader responsible</label>
                <select id="leader_person_id" name="leader_person_id" class="form-control" required>
                    <option value="">Select a leader</option>

                    <?php foreach ($people as $person): ?>
                        <?php
                            $selected = old_value('leader_person_id') !== ''
                                && (int) old_value('leader_person_id') === (int) $person['id'];
                        ?>
                        <option value="<?= (int) $person['id'] ?>" <?= $selected ? 'selected' : '' ?>>
                            <?= e((string) $person['full_name']) ?>
                            <?= !empty($person['primary_email']) ? ' — ' . e((string) $person['primary_email']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <p class="form-text">
                    If the person is not listed, ask a Group Lead Volunteer to add them. Group-link users cannot add new people.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="lt-panel">
        <h2 class="lt-section-title">Event details</h2>

        <div class="form-group">
            <label for="title">Event title</label>
            <input
                id="title"
                name="title"
                class="form-control"
                required
                value="<?= e(old_value('title')) ?>"
            >
        </div>

        <div class="form-group">
            <label for="event_type">Event type</label>
            <select id="event_type" name="event_type" class="form-control">
                <?php
                    $selectedEventType = old_value('event_type', 'meeting_away_from_hut');
                    $eventTypeLabels = [
                        'meeting_away_from_hut' => 'Meeting away from hut',
                        'day_activity' => 'Day activity',
                        'nights_away' => 'Nights away',
                        'camp' => 'Camp',
                        'hike' => 'Hike',
                        'water_activity' => 'Water activity',
                        'other' => 'Other',
                    ];
                ?>

                <?php foreach ($eventTypeLabels as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $selectedEventType === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="description">Description</label>
            <textarea
                id="description"
                name="description"
                class="form-control"
                rows="4"
            ><?= e(old_value('description')) ?></textarea>
        </div>

        <div class="row">
            <div class="col-md-6 form-group">
                <label for="starts_at">Starts</label>
                <input
                    type="datetime-local"
                    id="starts_at"
                    name="starts_at"
                    class="form-control"
                    min="<?= e($nowLocalMin) ?>"
                    required
                    value="<?= e(old_value('starts_at')) ?>"
                >
            </div>

            <div class="col-md-6 form-group">
                <label for="ends_at">Ends</label>
                <input
                    type="datetime-local"
                    id="ends_at"
                    name="ends_at"
                    class="form-control"
                    min="<?= e($nowLocalMin) ?>"
                    required
                    value="<?= e(old_value('ends_at')) ?>"
                >
            </div>
        </div>

        <p class="form-text">
            Camps, sleepovers and expeditions default to a two-day event after you choose the start date.
        </p>

        <div class="row">
            <div class="col-md-6 form-group">
                <label for="young_people_count">Young people attending</label>
                <input
                    type="number"
                    min="0"
                    id="young_people_count"
                    name="young_people_count"
                    class="form-control"
                    value="<?= e(old_value('young_people_count')) ?>"
                >
            </div>

            <div class="col-md-6 form-group">
                <label for="adult_count">Adults attending</label>
                <input
                    type="number"
                    min="0"
                    id="adult_count"
                    name="adult_count"
                    class="form-control"
                    value="<?= e(old_value('adult_count')) ?>"
                >
            </div>
        </div>

        <?php if ($sections): ?>
            <fieldset class="form-group">
                <legend>Sections involved</legend>

                <div class="lt-check-list">
                    <?php foreach ($sections as $section): ?>
                        <?php
                            $checked = in_array(
                                (string) $section['id'],
                                array_map('strval', (array) ($_POST['section_ids'] ?? [])),
                                true
                            );
                        ?>
                        <label class="lt-check">
                            <input
                                type="checkbox"
                                name="section_ids[]"
                                value="<?= (int) $section['id'] ?>"
                                <?= $checked ? 'checked' : '' ?>
                            >
                            <?= e((string) $section['section_name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endif; ?>
    </section>

    <section class="lt-panel">
        <h2 class="lt-section-title">Location</h2>

        <div class="dc-map-search">
            <div class="form-group mb-md-0">
                <label for="location_search">Search for a location</label>
                <input
                    id="location_search"
                    name="location_search"
                    class="form-control"
                    autocomplete="off"
                    value="<?= e(old_value('location_search')) ?>"
                    placeholder="Example: Bibby's Farm, Chorley"
                >
            </div>

            <div class="form-group mb-md-0">
                <button type="button" class="btn btn-primary lt-btn" id="location_search_button">
                    Search map
                </button>
            </div>
        </div>

        <div id="location_results" class="dc-location-results" hidden></div>

        <div id="location_map" class="dc-location-map" aria-label="Selected event location map"></div>

        <div id="selected_location_summary" class="dc-selected-location" hidden>
            <strong>Selected location</strong>
            <div id="selected_location_text"></div>
            <div class="dc-hidden-field-summary" id="selected_location_coords"></div>
        </div>

        <div class="form-group mt-3">
            <label for="location_name">Location name</label>
            <input
                id="location_name"
                name="location_name"
                class="form-control"
                value="<?= e(old_value('location_name')) ?>"
            >
        </div>

        <div class="form-group">
            <label for="location_address">Address or meeting point</label>
            <textarea
                id="location_address"
                name="location_address"
                class="form-control"
                rows="3"
            ><?= e(old_value('location_address')) ?></textarea>
        </div>

        <input
            type="hidden"
            id="location_lat"
            name="location_lat"
            value="<?= e(old_value('location_lat')) ?>"
        >

        <input
            type="hidden"
            id="location_lng"
            name="location_lng"
            value="<?= e(old_value('location_lng')) ?>"
        >
    </section>

    <section class="lt-panel">
        <h2 class="lt-section-title">Emergency contact</h2>

        <div class="row">
            <div class="col-md-6 form-group">
                <label for="emergency_contact_name">Name</label>
                <input
                    id="emergency_contact_name"
                    name="emergency_contact_name"
                    class="form-control"
                    value="<?= e(old_value('emergency_contact_name')) ?>"
                >
            </div>

            <div class="col-md-6 form-group">
                <label for="emergency_contact_phone">Phone</label>
                <input
                    id="emergency_contact_phone"
                    name="emergency_contact_phone"
                    class="form-control"
                    value="<?= e(old_value('emergency_contact_phone')) ?>"
                >
            </div>
        </div>
    </section>

    <section class="lt-panel">
        <h2 class="lt-section-title">Risk assessments</h2>

        <?php if ($riskAssessments): ?>
            <fieldset class="form-group">
                <legend>Select existing risk assessments</legend>

                <div class="lt-check-list">
                    <?php foreach ($riskAssessments as $risk): ?>
                        <?php
                            $checked = in_array(
                                (string) $risk['id'],
                                array_map('strval', (array) ($_POST['existing_risk_assessment_ids'] ?? [])),
                                true
                            );
                        ?>
                        <label class="lt-check">
                            <input
                                type="checkbox"
                                name="existing_risk_assessment_ids[]"
                                value="<?= (int) $risk['id'] ?>"
                                <?= $checked ? 'checked' : '' ?>
                            >
                            <?= e((string) $risk['title']) ?>
                            <span class="lt-badge ml-1"><?= e((string) $risk['visibility']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endif; ?>

        <div class="form-group">
            <label for="risk_files">Upload new risk assessments</label>
            <input
                type="file"
                id="risk_files"
                name="risk_files[]"
                class="form-control"
                accept=".pdf,.doc,.docx"
                multiple
            >
            <p class="form-text">
                You can upload more than one file. Accepted formats: PDF, DOC and DOCX.
            </p>
        </div>

        <div class="form-group">
            <label for="risk_title">Risk assessment title</label>
            <input
                id="risk_title"
                name="risk_title"
                class="form-control"
                value="<?= e(old_value('risk_title')) ?>"
            >
            <p class="form-text">
                If you upload several files, the filename will be added to this title.
            </p>
        </div>

        <div class="form-group">
            <label for="risk_description">Risk assessment description</label>
            <textarea
                id="risk_description"
                name="risk_description"
                class="form-control"
                rows="3"
            ><?= e(old_value('risk_description')) ?></textarea>
        </div>

        <fieldset class="form-group">
            <legend>Sharing</legend>

            <?php $riskVisibility = old_value('risk_visibility', 'district'); ?>

            <label class="lt-check">
                <input
                    type="radio"
                    name="risk_visibility"
                    value="district"
                    <?= $riskVisibility === 'district' ? 'checked' : '' ?>
                >
                Share with the District by default
            </label>

            <label class="lt-check">
                <input
                    type="radio"
                    name="risk_visibility"
                    value="group"
                    <?= $riskVisibility === 'group' ? 'checked' : '' ?>
                >
                Keep to this Group only
            </label>
        </fieldset>
    </section>

    <div class="dc-sticky-actions">
        <button
            class="btn btn-primary lt-btn"
            type="submit"
            <?= (!$isSsoUser && !$people) || ($isSsoUser && !$currentPerson) ? 'disabled' : '' ?>
        >
            Submit for review
        </button>

        <a class="btn lt-btn lt-btn-secondary" href="/dc/">
            Cancel
        </a>
    </div>
</form>

<script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
    crossorigin=""
></script>

<script>
(function () {
    const startInput = document.getElementById('starts_at');
    const endInput = document.getElementById('ends_at');
    const eventTypeInput = document.getElementById('event_type');
    const titleInput = document.getElementById('title');
    const descriptionInput = document.getElementById('description');

    function pad(value) {
        return String(value).padStart(2, '0');
    }

    function toDateTimeLocal(date) {
        return [
            date.getFullYear(),
            '-',
            pad(date.getMonth() + 1),
            '-',
            pad(date.getDate()),
            'T',
            pad(date.getHours()),
            ':',
            pad(date.getMinutes())
        ].join('');
    }

    function looksLikeMultiDayEvent() {
        const eventType = eventTypeInput ? eventTypeInput.value : '';
        const text = [
            titleInput ? titleInput.value : '',
            descriptionInput ? descriptionInput.value : ''
        ].join(' ').toLowerCase();

        return eventType === 'camp'
            || eventType === 'nights_away'
            || text.includes('camp')
            || text.includes('sleepover')
            || text.includes('expedition');
    }

    function maybeDefaultEndDate() {
        if (!startInput || !endInput || !startInput.value) {
            return;
        }

        if (endInput.value && !looksLikeMultiDayEvent()) {
            return;
        }

        if (!looksLikeMultiDayEvent()) {
            return;
        }

        const startDate = new Date(startInput.value);

        if (Number.isNaN(startDate.getTime())) {
            return;
        }

        const endDate = new Date(startDate.getTime());
        endDate.setDate(endDate.getDate() + 2);

        endInput.value = toDateTimeLocal(endDate);
        endInput.min = startInput.value;
    }

    if (startInput) {
        startInput.addEventListener('change', maybeDefaultEndDate);
    }

    if (eventTypeInput) {
        eventTypeInput.addEventListener('change', maybeDefaultEndDate);
    }

    if (titleInput) {
        titleInput.addEventListener('blur', maybeDefaultEndDate);
    }

    if (descriptionInput) {
        descriptionInput.addEventListener('blur', maybeDefaultEndDate);
    }
})();
</script>

<script>
(function () {
    const mapElement = document.getElementById('location_map');
    const searchInput = document.getElementById('location_search');
    const searchButton = document.getElementById('location_search_button');
    const resultsElement = document.getElementById('location_results');

    const locationNameInput = document.getElementById('location_name');
    const locationAddressInput = document.getElementById('location_address');
    const locationLatInput = document.getElementById('location_lat');
    const locationLngInput = document.getElementById('location_lng');

    const selectedSummary = document.getElementById('selected_location_summary');
    const selectedText = document.getElementById('selected_location_text');
    const selectedCoords = document.getElementById('selected_location_coords');

    if (!mapElement || typeof L === 'undefined') {
        return;
    }

    const defaultLat = parseFloat(locationLatInput.value || '53.647');
    const defaultLng = parseFloat(locationLngInput.value || '-2.316');

    const map = L.map(mapElement).setView([defaultLat, defaultLng], 11);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    let marker = null;

    function setSelectedLocation(lat, lng, name, address) {
        locationLatInput.value = String(lat);
        locationLngInput.value = String(lng);

        if (name && !locationNameInput.value) {
            locationNameInput.value = name;
        }

        if (address) {
            locationAddressInput.value = address;
        }

        if (marker) {
            marker.setLatLng([lat, lng]);
        } else {
            marker = L.marker([lat, lng]).addTo(map);
        }

        marker.bindPopup(name || 'Selected location').openPopup();
        map.setView([lat, lng], 15);

        selectedText.textContent = address || name || 'Location selected from the map';
        selectedCoords.textContent = 'Latitude: ' + lat + ', longitude: ' + lng;
        selectedSummary.hidden = false;
    }

    if (!Number.isNaN(defaultLat) && !Number.isNaN(defaultLng) && locationLatInput.value && locationLngInput.value) {
        setSelectedLocation(defaultLat, defaultLng, locationNameInput.value, locationAddressInput.value);
    }

    map.on('click', function (event) {
        const lat = Number(event.latlng.lat.toFixed(7));
        const lng = Number(event.latlng.lng.toFixed(7));

        setSelectedLocation(
            lat,
            lng,
            locationNameInput.value || 'Selected map location',
            locationAddressInput.value || 'Selected manually on the map'
        );
    });

    async function searchLocation() {
        const query = searchInput.value.trim();

        if (!query) {
            searchInput.focus();
            return;
        }

        searchButton.disabled = true;
        searchButton.textContent = 'Searching...';
        resultsElement.hidden = true;
        resultsElement.innerHTML = '';

        try {
            const url = new URL('https://nominatim.openstreetmap.org/search');
            url.searchParams.set('format', 'json');
            url.searchParams.set('addressdetails', '1');
            url.searchParams.set('limit', '5');
            url.searchParams.set('countrycodes', 'gb');
            url.searchParams.set('q', query);

            const response = await fetch(url.toString(), {
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('Location search failed');
            }

            const results = await response.json();

            if (!Array.isArray(results) || results.length === 0) {
                resultsElement.hidden = false;
                resultsElement.innerHTML = '<div class="p-3">No matching locations found. Try a postcode, town or venue name.</div>';
                return;
            }

            results.forEach(function (result) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'dc-location-result';
                button.textContent = result.display_name || 'Unnamed location';

                button.addEventListener('click', function () {
                    const lat = Number(parseFloat(result.lat).toFixed(7));
                    const lng = Number(parseFloat(result.lon).toFixed(7));
                    const displayName = result.name || result.display_name || query;

                    setSelectedLocation(lat, lng, displayName, result.display_name || displayName);
                    resultsElement.hidden = true;
                });

                resultsElement.appendChild(button);
            });

            resultsElement.hidden = false;
        } catch (error) {
            resultsElement.hidden = false;
            resultsElement.innerHTML = '<div class="p-3">The location search is unavailable. You can still click the map to choose a location.</div>';
        } finally {
            searchButton.disabled = false;
            searchButton.textContent = 'Search map';
        }
    }

    searchButton.addEventListener('click', searchLocation);

    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchLocation();
        }
    });

    setTimeout(function () {
        map.invalidateSize();
    }, 250);
})();
</script>

<?php require __DIR__ . '/layout-footer.php'; ?>