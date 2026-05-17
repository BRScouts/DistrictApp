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

function old_array_value(string $key, string|int $index, string $default = ''): string
{
    if (!isset($_POST[$key]) || !is_array($_POST[$key])) {
        return $default;
    }

    return isset($_POST[$key][$index]) ? (string) $_POST[$key][$index] : $default;
}

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
    $saveAction = (string) ($_POST['save_action'] ?? 'submit');
    $isDraft = $saveAction === 'draft';

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
            $errors[] = 'We could not find your user profile. Please complete onboarding before saving this event.';
        } elseif (empty($selectedLeader['primary_email'])) {
            $errors[] = 'Your profile needs an email address before you can save this event.';
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

    if (!$isDraft && $startsAt && $startsAt < $now->modify('-1 minute')) {
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
    $sectionCounts = [];

    foreach ((array) ($_POST['section_ids'] ?? []) as $sectionId) {
        $sectionId = (int) $sectionId;

        if (in_array($sectionId, $validSectionIds, true)) {
            $selectedSectionIds[] = $sectionId;
        }
    }

    $selectedSectionIds = array_values(array_unique($selectedSectionIds));

    foreach ((array) ($_POST['section_young_people_count'] ?? []) as $sectionId => $count) {
        $sectionId = (int) $sectionId;
        $count = trim((string) $count);

        if (!in_array($sectionId, $validSectionIds, true)) {
            continue;
        }

        if ($count === '') {
            $sectionCounts[$sectionId] = null;
            continue;
        }

        if (!ctype_digit($count)) {
            $errors[] = 'Young people numbers by section must be whole numbers.';
            break;
        }

        $sectionCounts[$sectionId] = (int) $count;
    }

    $youngPeopleTotal = 0;
    $hasSectionCount = false;

    foreach ($selectedSectionIds as $sectionId) {
        if (isset($sectionCounts[$sectionId]) && $sectionCounts[$sectionId] !== null) {
            $youngPeopleTotal += (int) $sectionCounts[$sectionId];
            $hasSectionCount = true;
        }
    }

    if (!$isDraft && $sections && !$selectedSectionIds) {
        $errors[] = 'Choose at least one section involved in this event.';
    }

    if (!$isDraft && $selectedSectionIds && !$hasSectionCount) {
        $errors[] = 'Enter the number of young people attending for at least one selected section.';
    }

    $riskTitles = isset($_POST['risk_titles']) && is_array($_POST['risk_titles'])
        ? $_POST['risk_titles']
        : [];

    $riskDescriptions = isset($_POST['risk_descriptions']) && is_array($_POST['risk_descriptions'])
        ? $_POST['risk_descriptions']
        : [];

    $riskVisibilities = isset($_POST['risk_visibilities']) && is_array($_POST['risk_visibilities'])
        ? $_POST['risk_visibilities']
        : [];

    if (isset($_FILES['risk_files']) && is_array($_FILES['risk_files']['name'] ?? null)) {
        $fileCount = count($_FILES['risk_files']['name']);

        for ($i = 0; $i < $fileCount; $i++) {
            $fileError = $_FILES['risk_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE;

            if ($fileError === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $riskTitle = trim((string) ($riskTitles[$i] ?? ''));

            if ($riskTitle === '') {
                $errors[] = 'Enter a title for each uploaded risk assessment.';
                break;
            }
        }
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $eventStatus = $isDraft ? 'draft' : 'submitted';

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
                    NULL,
                    NULL,
                    :submitted_by_person_id,
                    :submitted_via,
                    :status
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
                'young_people_count' => $hasSectionCount ? $youngPeopleTotal : null,
                'adult_count' => ($_POST['adult_count'] ?? '') !== '' ? (int) $_POST['adult_count'] : null,
                'leader_name' => (string) ($selectedLeader['full_name'] ?? ''),
                'leader_email' => (string) ($selectedLeader['primary_email'] ?? ''),
                'leader_phone' => $selectedLeader['phone'] ?? null,
                'leader_role' => str_replace('_', ' ', (string) ($selectedLeader['membership_role'] ?? '')),
                'submitted_by_person_id' => $isSsoUser ? (int) $ctx['person_id'] : null,
                'submitted_via' => $isSsoUser ? 'sso' : 'group_link',
                'status' => $eventStatus,
            ]);

            $eventId = (int) $pdo->lastInsertId();

            foreach ($selectedSectionIds as $sectionId) {
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO calendar_event_sections (
                        calendar_event_id,
                        group_section_id,
                        young_people_count
                    ) VALUES (
                        :event_id,
                        :section_id,
                        :young_people_count
                    )
                ");

                $stmt->execute([
                    'event_id' => $eventId,
                    'section_id' => $sectionId,
                    'young_people_count' => $sectionCounts[$sectionId] ?? null,
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

                for ($i = 0; $i < $fileCount; $i++) {
                    $file = uploaded_file_from_multiple($_FILES['risk_files'], $i);

                    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }

                    $riskTitle = trim((string) ($riskTitles[$i] ?? ''));
                    $riskDescription = trim((string) ($riskDescriptions[$i] ?? ''));
                    $riskVisibility = (string) ($riskVisibilities[$i] ?? 'district');

                    if (!in_array($riskVisibility, ['district', 'group'], true)) {
                        $riskVisibility = 'district';
                    }

                    if ($riskTitle === '') {
                        throw new RuntimeException('Each uploaded risk assessment must have a title.');
                    }

                    $riskId = dc_store_risk_assessment_upload(
                        $file,
                        $groupId,
                        $riskTitle,
                        $riskDescription !== '' ? $riskDescription : null,
                        (string) ($selectedLeader['full_name'] ?? ''),
                        (string) ($selectedLeader['primary_email'] ?? ''),
                        $isSsoUser ? (int) $ctx['person_id'] : null,
                        $isSsoUser ? 'sso' : 'group_link',
                        $riskVisibility
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
                $isDraft ? 'calendar_event.draft_created' : 'calendar_event.created',
                'calendar_event',
                $eventId,
                ['status' => $eventStatus],
                $groupId
            );

            if (!$isDraft) {
                dc_queue_event_notifications($eventId, 'submitted');
            }

            redirect('/dc/manage-event.php?id=' . $eventId . ($isDraft ? '&draft=1' : '&created=1'));
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
$heroText = 'Submit an away-from-hut notification or activity for review, or save it as a draft.';
$active = 'add';

require __DIR__ . '/layout.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

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

    .dc-location-map-wrap {
        position: relative;
        width: 100%;
        max-width: 100%;
        overflow: hidden;
        border: 2px solid #000;
        background: #f5f5f5;
        margin-top: 1rem;
        isolation: isolate;
    }

    .dc-location-map {
        display: block;
        width: 100%;
        height: 360px;
        min-height: 320px;
        max-width: 100%;
        position: relative;
        z-index: 0;
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

    .dc-section-count-grid {
        display: grid;
        gap: 0.75rem;
    }

    .dc-section-count-row {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.5rem;
        border: 1px solid #d8d8d8;
        padding: 0.75rem;
        background: #fff;
    }

    @media (min-width: 768px) {
        .dc-section-count-row {
            grid-template-columns: minmax(220px, 1fr) 180px;
            align-items: center;
        }
    }

    .dc-risk-stage-controls {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.75rem;
        align-items: end;
        margin-bottom: 1rem;
    }

    @media (min-width: 768px) {
        .dc-risk-stage-controls {
            grid-template-columns: 1fr auto;
        }
    }

    .dc-risk-table-wrap {
        overflow-x: auto;
        border: 1px solid #d8d8d8;
        background: #fff;
    }

    .dc-risk-table {
        width: 100%;
        min-width: 760px;
        border-collapse: collapse;
        margin: 0;
    }

    .dc-risk-table th,
    .dc-risk-table td {
        border-bottom: 1px solid #d8d8d8;
        padding: 0.75rem;
        vertical-align: top;
    }

    .dc-risk-table th {
        background: #f5f5f5;
        text-align: left;
        font-weight: 800;
    }

    .dc-risk-table input,
    .dc-risk-table textarea,
    .dc-risk-table select {
        min-width: 180px;
    }

    .dc-risk-file-name {
        font-weight: 800;
        display: block;
        max-width: 220px;
        overflow-wrap: anywhere;
    }

    .dc-risk-empty {
        padding: 1rem;
        background: #f5f5f5;
        border: 1px dashed #888;
    }

    .dc-remove-risk-file {
        border: 2px solid #d4351c;
        background: #fff;
        color: #d4351c;
        font-weight: 800;
        padding: 0.35rem 0.65rem;
    }

    .dc-remove-risk-file:hover,
    .dc-remove-risk-file:focus {
        background: #d4351c;
        color: #fff;
    }

    .dc-staged-file-input {
        position: absolute;
        left: -9999px;
        width: 1px;
        height: 1px;
        opacity: 0;
    }

    @media (max-width: 767.98px) {
        .dc-location-map {
            height: 300px;
        }
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
                <p class="mb-1">You are submitting this event as:</p>

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

        <div class="form-group">
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

        <?php if ($sections): ?>
            <fieldset class="form-group">
                <legend>Sections and young people attending</legend>

                <p class="form-text">
                    Select each section involved and enter the number of young people attending from that section.
                </p>

                <div class="dc-section-count-grid">
                    <?php foreach ($sections as $section): ?>
                        <?php
                            $sectionId = (int) $section['id'];
                            $checked = in_array(
                                (string) $sectionId,
                                array_map('strval', (array) ($_POST['section_ids'] ?? [])),
                                true
                            );
                        ?>
                        <div class="dc-section-count-row">
                            <label class="lt-check mb-0">
                                <input
                                    type="checkbox"
                                    name="section_ids[]"
                                    value="<?= $sectionId ?>"
                                    data-section-checkbox
                                    data-section-id="<?= $sectionId ?>"
                                    <?= $checked ? 'checked' : '' ?>
                                >
                                <?= e((string) $section['section_name']) ?>
                            </label>

                            <div>
                                <label for="section_young_people_count_<?= $sectionId ?>">
                                    Young people
                                </label>
                                <input
                                    type="number"
                                    min="0"
                                    id="section_young_people_count_<?= $sectionId ?>"
                                    name="section_young_people_count[<?= $sectionId ?>]"
                                    class="form-control"
                                    data-section-count="<?= $sectionId ?>"
                                    value="<?= e(old_array_value('section_young_people_count', $sectionId)) ?>"
                                    <?= $checked ? '' : 'disabled' ?>
                                >
                            </div>
                        </div>
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

        <div class="dc-location-map-wrap">
            <div id="location_map" class="dc-location-map" aria-label="Selected event location map"></div>
        </div>

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

        <fieldset class="form-group">
            <legend>Upload new risk assessments</legend>

            <p class="form-text">
                Choose one or more files. They will appear in the table below before you save the event.
            </p>

            <div class="dc-risk-stage-controls">
                <div class="form-group mb-md-0">
                    <label for="risk_file_picker">Choose risk assessment files</label>
                    <input
                        type="file"
                        id="risk_file_picker"
                        class="form-control"
                        accept=".pdf,.doc,.docx"
                        multiple
                    >
                </div>

                <button type="button" class="btn lt-btn lt-btn-secondary" id="clear_risk_files">
                    Clear uploaded files
                </button>
            </div>

            <input
                type="file"
                id="risk_files"
                name="risk_files[]"
                class="dc-staged-file-input"
                accept=".pdf,.doc,.docx"
                multiple
                tabindex="-1"
                aria-hidden="true"
            >

            <div id="risk_empty_state" class="dc-risk-empty">
                No new risk assessments selected yet.
            </div>

            <div id="risk_table_wrap" class="dc-risk-table-wrap" hidden>
                <table class="dc-risk-table">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Risk assessment title</th>
                            <th>Sharing</th>
                            <th>Description</th>
                            <th>Remove</th>
                        </tr>
                    </thead>
                    <tbody id="risk_file_rows"></tbody>
                </table>
            </div>
        </fieldset>
    </section>

    <div class="dc-sticky-actions">
        <button
            class="btn lt-btn lt-btn-secondary"
            type="submit"
            name="save_action"
            value="draft"
            <?= (!$isSsoUser && !$people) || ($isSsoUser && !$currentPerson) ? 'disabled' : '' ?>
        >
            Save as draft
        </button>

        <button
            class="btn btn-primary lt-btn"
            type="submit"
            name="save_action"
            value="submit"
            <?= (!$isSsoUser && !$people) || ($isSsoUser && !$currentPerson) ? 'disabled' : '' ?>
        >
            Submit for review
        </button>

        <a class="btn lt-btn lt-btn-secondary" href="/dc/">
            Cancel
        </a>
    </div>
</form>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
(function () {
    const checkboxes = document.querySelectorAll('[data-section-checkbox]');

    checkboxes.forEach(function (checkbox) {
        const sectionId = checkbox.getAttribute('data-section-id');
        const countInput = document.querySelector('[data-section-count="' + sectionId + '"]');

        function sync() {
            if (!countInput) {
                return;
            }

            countInput.disabled = !checkbox.checked;

            if (!checkbox.checked) {
                countInput.value = '';
            }
        }

        checkbox.addEventListener('change', sync);
        sync();
    });
})();
</script>

<script>
(function () {
    const picker = document.getElementById('risk_file_picker');
    const realInput = document.getElementById('risk_files');
    const rows = document.getElementById('risk_file_rows');
    const tableWrap = document.getElementById('risk_table_wrap');
    const emptyState = document.getElementById('risk_empty_state');
    const clearButton = document.getElementById('clear_risk_files');

    if (!picker || !realInput || !rows || !tableWrap || !emptyState || !clearButton) {
        return;
    }

    let stagedFiles = [];

    function formatBytes(bytes) {
        if (!bytes && bytes !== 0) {
            return '';
        }

        if (bytes < 1024) {
            return bytes + ' bytes';
        }

        if (bytes < 1024 * 1024) {
            return Math.round(bytes / 1024) + ' KB';
        }

        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function titleFromFilename(filename) {
        return filename
            .replace(/\.[^/.]+$/, '')
            .replace(/[_-]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .replace(/\b\w/g, function (letter) {
                return letter.toUpperCase();
            });
    }

    function syncRealInput() {
        const transfer = new DataTransfer();

        stagedFiles.forEach(function (item) {
            transfer.items.add(item.file);
        });

        realInput.files = transfer.files;
    }

    function renderRows() {
        rows.innerHTML = '';

        stagedFiles.forEach(function (item, index) {
            const tr = document.createElement('tr');

            const fileTd = document.createElement('td');
            const fileName = document.createElement('span');
            fileName.className = 'dc-risk-file-name';
            fileName.textContent = item.file.name;

            const fileSize = document.createElement('span');
            fileSize.className = 'form-text';
            fileSize.textContent = formatBytes(item.file.size);

            fileTd.appendChild(fileName);
            fileTd.appendChild(fileSize);

            const titleTd = document.createElement('td');
            const titleLabel = document.createElement('label');
            titleLabel.className = 'sr-only';
            titleLabel.textContent = 'Risk assessment title';

            const titleInput = document.createElement('input');
            titleInput.className = 'form-control';
            titleInput.name = 'risk_titles[]';
            titleInput.required = true;
            titleInput.value = item.title;
            titleInput.placeholder = 'Example: Campfire Risk Assessment';

            titleInput.addEventListener('input', function () {
                item.title = titleInput.value;
            });

            titleTd.appendChild(titleLabel);
            titleTd.appendChild(titleInput);

            const visibilityTd = document.createElement('td');
            const visibilityLabel = document.createElement('label');
            visibilityLabel.className = 'sr-only';
            visibilityLabel.textContent = 'Sharing';

            const visibilitySelect = document.createElement('select');
            visibilitySelect.className = 'form-control';
            visibilitySelect.name = 'risk_visibilities[]';

            const districtOption = document.createElement('option');
            districtOption.value = 'district';
            districtOption.textContent = 'Share with District';

            const groupOption = document.createElement('option');
            groupOption.value = 'group';
            groupOption.textContent = 'Keep to this Group';

            visibilitySelect.appendChild(districtOption);
            visibilitySelect.appendChild(groupOption);
            visibilitySelect.value = item.visibility;

            visibilitySelect.addEventListener('change', function () {
                item.visibility = visibilitySelect.value;
            });

            visibilityTd.appendChild(visibilityLabel);
            visibilityTd.appendChild(visibilitySelect);

            const descriptionTd = document.createElement('td');
            const descriptionLabel = document.createElement('label');
            descriptionLabel.className = 'sr-only';
            descriptionLabel.textContent = 'Description';

            const descriptionInput = document.createElement('textarea');
            descriptionInput.className = 'form-control';
            descriptionInput.name = 'risk_descriptions[]';
            descriptionInput.rows = 2;
            descriptionInput.value = item.description;
            descriptionInput.placeholder = 'Optional';

            descriptionInput.addEventListener('input', function () {
                item.description = descriptionInput.value;
            });

            descriptionTd.appendChild(descriptionLabel);
            descriptionTd.appendChild(descriptionInput);

            const removeTd = document.createElement('td');
            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'dc-remove-risk-file';
            removeButton.textContent = 'Remove';

            removeButton.addEventListener('click', function () {
                stagedFiles.splice(index, 1);
                syncRealInput();
                renderRows();
            });

            removeTd.appendChild(removeButton);

            tr.appendChild(fileTd);
            tr.appendChild(titleTd);
            tr.appendChild(visibilityTd);
            tr.appendChild(descriptionTd);
            tr.appendChild(removeTd);

            rows.appendChild(tr);
        });

        const hasFiles = stagedFiles.length > 0;
        tableWrap.hidden = !hasFiles;
        emptyState.hidden = hasFiles;
    }

    picker.addEventListener('change', function () {
        Array.from(picker.files || []).forEach(function (file) {
            stagedFiles.push({
                file: file,
                title: titleFromFilename(file.name),
                visibility: 'district',
                description: ''
            });
        });

        picker.value = '';
        syncRealInput();
        renderRows();
    });

    clearButton.addEventListener('click', function () {
        stagedFiles = [];
        picker.value = '';
        syncRealInput();
        renderRows();
    });

    renderRows();
})();
</script>

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

    const map = L.map(mapElement, {
        scrollWheelZoom: false
    }).setView([defaultLat, defaultLng], 11);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    let marker = null;

    function setSelectedLocation(lat, lng, name, address) {
        locationLatInput.value = String(lat);
        locationLngInput.value = String(lng);

        if (name) {
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

        setTimeout(function () {
            map.invalidateSize();
        }, 100);
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

            setTimeout(function () {
                map.invalidateSize();
            }, 100);
        }
    }

    searchButton.addEventListener('click', searchLocation);

    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchLocation();
        }
    });

    window.addEventListener('resize', function () {
        map.invalidateSize();
    });

    setTimeout(function () {
        map.invalidateSize();
    }, 250);
})();
</script>

<?php require __DIR__ . '/layout-footer.php'; ?>