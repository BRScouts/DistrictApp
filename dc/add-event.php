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

$sectionCountFields = [
    'squirrels_count' => 'Squirrels',
    'beavers_count' => 'Beavers',
    'cubs_count' => 'Cubs',
    'scouts_count' => 'Scouts',
    'explorers_count' => 'Explorers',
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

function parse_count_value(string $field, string $label, array &$errors): ?int
{
    $value = trim((string) ($_POST[$field] ?? ''));

    if ($value === '') {
        return null;
    }

    if (!ctype_digit($value)) {
        $errors[] = $label . ' must be a whole number.';
        return null;
    }

    return (int) $value;
}

if ($isSsoUser) {
    $currentPerson = dc_fetch_current_person_for_event((int) $ctx['person_id'], $groupId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $saveAction = (string) ($_POST['save_action'] ?? 'submit');
    $isDraft = $saveAction === 'draft';

    $groupId = dc_selected_group_id((int) ($_POST['group_id'] ?? $groupId));
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

    $squirrelsCount = parse_count_value('squirrels_count', 'Squirrels', $errors);
    $beaversCount = parse_count_value('beavers_count', 'Beavers', $errors);
    $cubsCount = parse_count_value('cubs_count', 'Cubs', $errors);
    $scoutsCount = parse_count_value('scouts_count', 'Scouts', $errors);
    $explorersCount = parse_count_value('explorers_count', 'Explorers', $errors);

    $youngPeopleTotal = 0;
    $hasYoungPeopleCount = false;

    foreach ([$squirrelsCount, $beaversCount, $cubsCount, $scoutsCount, $explorersCount] as $count) {
        if ($count !== null) {
            $youngPeopleTotal += $count;
            $hasYoungPeopleCount = true;
        }
    }

    if (!$isDraft && !$hasYoungPeopleCount) {
        $errors[] = 'Enter the number of young people attending for at least one section.';
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
                    squirrels_count,
                    beavers_count,
                    cubs_count,
                    scouts_count,
                    explorers_count,
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
                    :squirrels_count,
                    :beavers_count,
                    :cubs_count,
                    :scouts_count,
                    :explorers_count,
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
                'young_people_count' => $hasYoungPeopleCount ? $youngPeopleTotal : null,
                'squirrels_count' => $squirrelsCount,
                'beavers_count' => $beaversCount,
                'cubs_count' => $cubsCount,
                'scouts_count' => $scoutsCount,
                'explorers_count' => $explorersCount,
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

            foreach ((array) ($_POST['existing_risk_assessment_ids'] ?? []) as $riskId) {
                $riskId = (int) $riskId;

                if ($riskId <= 0) {
                    continue;
                }

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
                      AND group_id = :group_id
                      AND status = 'active'
                      AND admin_review_status = 'available'
                      AND uploaded_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                    LIMIT 1
                ");

                $stmt->execute([
                    'event_id' => $eventId,
                    'risk_id' => $riskId,
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
        ra.id,
        ra.group_id,
        g.group_name,
        ra.title,
        ra.description,
        ra.visibility,
        ra.uploaded_by_person_id,
        ra.uploaded_by_name,
        ra.uploaded_by_email,
        ra.uploaded_at,
        DATEDIFF(CURDATE(), DATE(ra.uploaded_at)) AS age_days
    FROM risk_assessments ra
    JOIN groups g
      ON g.id = ra.group_id
    WHERE ra.status = 'active'
      AND ra.admin_review_status = 'available'
    ORDER BY ra.uploaded_at DESC
    LIMIT 500
");

$stmt->execute();
$allRiskAssessments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$riskAssessmentCards = [];
$ninetyDaysAgo = new DateTimeImmutable('-90 days');

foreach ($allRiskAssessments as $risk) {
    $riskId = (int) $risk['id'];
    $riskGroupId = (int) $risk['group_id'];
    $visibility = (string) $risk['visibility'];

    $uploadedAt = null;

    try {
        $uploadedAt = new DateTimeImmutable((string) $risk['uploaded_at']);
    } catch (Throwable $e) {
        $uploadedAt = null;
    }

    $isCurrentGroup = $riskGroupId === $groupId;
    $isRecent = $uploadedAt instanceof DateTimeImmutable && $uploadedAt >= $ninetyDaysAgo;

    if ($isCurrentGroup && $isRecent) {
        $canSelect = true;
        $reason = 'This belongs to your Group and is less than 90 days old. You can attach it directly.';
    } elseif ($isCurrentGroup && !$isRecent) {
        $canSelect = false;
        $reason = 'This belongs to your Group but is over 90 days old. Download it, review it and re-upload an updated version.';
    } elseif ($visibility === 'district') {
        $canSelect = false;
        $reason = 'This is a District-shared risk assessment from another Group. Download it, review it and upload your checked version.';
    } else {
        $canSelect = false;
        $reason = 'This belongs to another Group. Download it, review it and upload your own version if suitable.';
    }

    $riskAssessmentCards[] = [
        'id' => $riskId,
        'group_id' => $riskGroupId,
        'title' => (string) $risk['title'],
        'description' => (string) ($risk['description'] ?? ''),
        'visibility' => $visibility,
        'group_name' => (string) $risk['group_name'],
        'uploaded_by_name' => (string) ($risk['uploaded_by_name'] ?? ''),
        'uploaded_at' => (string) $risk['uploaded_at'],
        'age_days' => isset($risk['age_days']) ? (int) $risk['age_days'] : null,
        'can_select' => $canSelect,
        'reason' => $reason,
        'download_url' => '/dc/download-risk-assessment.php?id=' . $riskId,
        'search_text' => strtolower(trim(
            (string) $risk['title'] . ' ' .
            (string) ($risk['description'] ?? '') . ' ' .
            (string) $risk['group_name'] . ' ' .
            (string) ($risk['uploaded_by_name'] ?? '') . ' ' .
            $visibility
        )),
    ];
}

$selectedExistingRiskIds = array_map(
    'intval',
    (array) ($_POST['existing_risk_assessment_ids'] ?? [])
);

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
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
        background: var(--dc-canvas, #f8fafc);
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
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
        background: #fff;
    }

    .dc-location-result {
        display: block;
        width: 100%;
        padding: 0.75rem;
        border: 0;
        border-bottom: 1px solid var(--dc-border, #e2e8f0);
        background: #fff;
        text-align: left;
        cursor: pointer;
    }

    .dc-location-result:hover,
    .dc-location-result:focus {
        background: var(--dc-canvas, #f8fafc);
        outline: 3px solid #ffdd00;
        outline-offset: -3px;
    }

    .dc-selected-location {
        margin-top: 0.75rem;
        padding: 0.75rem;
        border-left: 3px solid #00a794;
        border-radius: 0;
        background: #f0fdf9;
    }

    .dc-section-count-grid {
        display: grid;
        gap: 0.75rem;
    }

    .dc-section-count-row {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.5rem;
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
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
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
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
        border-bottom: 1px solid var(--dc-border, #e2e8f0);
        padding: 0.7rem;
        vertical-align: top;
    }

    .dc-risk-table th {
        background: var(--dc-canvas, #f8fafc);
        text-align: left;
        font-weight: 700;
        font-size: 0.82rem;
        color: var(--dc-muted, #64748b);
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .dc-risk-file-name {
        font-weight: 700;
        display: block;
        max-width: 220px;
        overflow-wrap: anywhere;
    }

    .dc-risk-empty {
        padding: 1rem;
        background: var(--dc-canvas, #f8fafc);
        border: 1px dashed var(--dc-border, #e2e8f0);
        border-radius: 0;
    }

    .dc-remove-risk-file {
        border: 1px solid #dc2626;
        border-radius: 0;
        background: #fff;
        color: #dc2626;
        font-weight: 700;
        font-size: 0.85rem;
        padding: 0.3rem 0.6rem;
    }

    .dc-remove-risk-file:hover,
    .dc-remove-risk-file:focus {
        background: #dc2626;
        color: #fff;
    }

    .dc-staged-file-input {
        position: absolute;
        left: -9999px;
        width: 1px;
        height: 1px;
        opacity: 0;
    }

    .dc-selected-risk-list {
        display: grid;
        gap: 0.5rem;
        margin-top: 1rem;
    }

    .dc-selected-risk-item {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: flex-start;
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
        background: #fff;
        padding: 0.75rem;
    }

    .dc-modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 2000;
        background: rgba(0, 0, 0, 0.4);
        display: none;
        padding: 0.75rem;
        overflow-y: auto;
    }

    .dc-modal-backdrop[aria-hidden="false"] {
        display: block;
    }

    .dc-modal {
        background: #fff;
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        max-width: 1120px;
        margin: 1rem auto;
    }

    .dc-modal-header,
    .dc-modal-footer {
        padding: 1rem;
        border-bottom: 1px solid var(--dc-border, #e2e8f0);
    }

    .dc-modal-footer {
        border-top: 1px solid var(--dc-border, #e2e8f0);
        border-bottom: 0;
    }

    .dc-modal-body {
        padding: 1rem;
    }

    .dc-risk-search-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    @media (min-width: 768px) {
        .dc-risk-search-grid {
            grid-template-columns: 1fr 220px;
        }
    }

    .dc-risk-card-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.5rem;
    }

    .dc-risk-card {
        border: 1px solid var(--dc-border, #e2e8f0);
        border-radius: 0;
        padding: 0.75rem;
        background: #fff;
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.5rem;
    }

    @media (min-width: 768px) {
        .dc-risk-card {
            grid-template-columns: 1fr auto;
            align-items: start;
        }
    }

    .dc-risk-card h3 {
        font-size: 0.95rem;
        margin: 0 0 0.25rem;
        font-weight: 700;
    }

    .dc-risk-meta {
        font-size: 0.82rem;
        color: var(--dc-muted, #64748b);
    }

    .dc-risk-reason {
        font-size: 0.9rem;
        margin-top: 0.35rem;
    }

    .dc-risk-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
        justify-content: flex-start;
    }

    .dc-risk-card.is-selected {
        border-color: #00a794;
        box-shadow: inset 0 0 0 2px #00a794;
    }

    .dc-risk-warning {
        border-left: 3px solid #d4a300;
        border-radius: 0;
        background: #fefce8;
        padding: 0.75rem;
        margin: 0.75rem 0;
    }

    @media (max-width: 767.98px) {
        .dc-location-map {
            height: 300px;
        }

        .dc-modal {
            margin: 0;
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
    <?= csrf_field() ?>
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
                        'day_activity' => 'Day event',
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
            Day events default to two hours. Camps, sleepovers, expeditions and nights away default to two days.
        </p>

        <fieldset class="form-group">
            <legend>Young people attending</legend>

            <p class="form-text">
                Enter numbers by section. Leave sections blank if they are not attending.
            </p>

            <div class="dc-section-count-grid">
                <?php foreach ($sectionCountFields as $field => $label): ?>
                    <div class="dc-section-count-row">
                        <strong><?= e($label) ?></strong>
                        <div>
                            <label for="<?= e($field) ?>">Number attending</label>
                            <input
                                type="number"
                                min="0"
                                id="<?= e($field) ?>"
                                name="<?= e($field) ?>"
                                class="form-control"
                                value="<?= e(old_value($field)) ?>"
                            >
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </fieldset>

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

        <div class="dc-risk-warning">
            <strong>Review every risk assessment before use.</strong>
            <p class="mb-0">
                You can attach risk assessments from your own Group if they are less than 90 days old. District-shared risk assessments from other Groups must be downloaded, reviewed and re-uploaded before use.
            </p>
        </div>

        <button type="button" class="btn btn-primary lt-btn" id="open_risk_modal">
            Select a previous risk assessment
        </button>

        <div id="selected_risk_list" class="dc-selected-risk-list"></div>
        <div id="selected_risk_inputs"></div>

        <fieldset class="form-group mt-4">
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

<div
    id="risk_modal"
    class="dc-modal-backdrop"
    aria-hidden="true"
    role="dialog"
    aria-modal="true"
    aria-labelledby="risk_modal_title"
>
    <div class="dc-modal">
        <div class="dc-modal-header">
            <h2 id="risk_modal_title" class="mb-2">Select a previous risk assessment</h2>
            <p class="mb-0">
                Search the risk assessment library. Only current Group risk assessments less than 90 days old can be attached directly.
            </p>
        </div>

        <div class="dc-modal-body">
            <div class="dc-risk-search-grid">
                <div class="form-group mb-0">
                    <label for="risk_modal_search">Search risk assessments</label>
                    <input
                        type="search"
                        id="risk_modal_search"
                        class="form-control"
                        placeholder="Search by title, Group, uploader or description"
                    >
                </div>

                <div class="form-group mb-0">
                    <label for="risk_modal_filter">Filter</label>
                    <select id="risk_modal_filter" class="form-control">
                        <option value="all">All risk assessments</option>
                        <option value="selectable">Can select now</option>
                        <option value="district">District-shared</option>
                        <option value="current_group">This Group</option>
                        <option value="other_group">Other Groups</option>
                        <option value="older">Older than 90 days</option>
                    </select>
                </div>
            </div>

            <div id="risk_card_grid" class="dc-risk-card-grid"></div>
            <div id="risk_no_results" class="dc-risk-empty" hidden>No matching risk assessments found.</div>
        </div>

        <div class="dc-modal-footer">
            <button type="button" class="btn lt-btn lt-btn-secondary" id="close_risk_modal">
                Close
            </button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
const riskAssessments = <?= json_encode(
    $riskAssessmentCards,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
) ?>;

const initiallySelectedRiskIds = <?= json_encode(
    array_values($selectedExistingRiskIds),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
) ?>;
</script>

<script>
(function () {
    const modal = document.getElementById('risk_modal');
    const openButton = document.getElementById('open_risk_modal');
    const closeButton = document.getElementById('close_risk_modal');
    const searchInput = document.getElementById('risk_modal_search');
    const filterInput = document.getElementById('risk_modal_filter');
    const grid = document.getElementById('risk_card_grid');
    const noResults = document.getElementById('risk_no_results');
    const selectedList = document.getElementById('selected_risk_list');
    const selectedInputs = document.getElementById('selected_risk_inputs');

    if (!modal || !openButton || !closeButton || !searchInput || !filterInput || !grid || !selectedList || !selectedInputs) {
        return;
    }

    const currentGroupId = <?= (int) $groupId ?>;

    const selected = new Set((initiallySelectedRiskIds || []).map(function (id) {
        return Number(id);
    }));

    function escapeText(value) {
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

    function isOlderThan90(risk) {
        return Number(risk.age_days || 0) > 90;
    }

    function matchesFilter(risk, filter) {
        if (filter === 'selectable') {
            return Boolean(risk.can_select);
        }

        if (filter === 'district') {
            return risk.visibility === 'district';
        }

        if (filter === 'current_group') {
            return Number(risk.group_id || 0) === currentGroupId;
        }

        if (filter === 'other_group') {
            return Number(risk.group_id || 0) !== currentGroupId;
        }

        if (filter === 'older') {
            return isOlderThan90(risk);
        }

        return true;
    }

    function renderSelected() {
        selectedList.innerHTML = '';
        selectedInputs.innerHTML = '';

        Array.from(selected).forEach(function (id) {
            const risk = riskAssessments.find(function (item) {
                return Number(item.id) === Number(id);
            });

            if (!risk) {
                return;
            }

            const item = document.createElement('div');
            item.className = 'dc-selected-risk-item';

            const text = document.createElement('div');
            text.innerHTML =
                '<strong>' + escapeText(risk.title) + '</strong><br>' +
                '<span class="form-text">' + escapeText(risk.group_name) + ' · uploaded ' + escapeText(risk.uploaded_at) + '</span>';

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'dc-remove-risk-file';
            remove.textContent = 'Remove';
            remove.addEventListener('click', function () {
                selected.delete(Number(id));
                renderSelected();
                renderCards();
            });

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'existing_risk_assessment_ids[]';
            input.value = String(id);

            item.appendChild(text);
            item.appendChild(remove);

            selectedList.appendChild(item);
            selectedInputs.appendChild(input);
        });
    }

    function renderCards() {
        const query = searchInput.value.trim().toLowerCase();
        const filter = filterInput.value;
        grid.innerHTML = '';

        const filtered = riskAssessments.filter(function (risk) {
            const textMatch = !query || String(risk.search_text || '').includes(query);
            const filterMatch = matchesFilter(risk, filter);

            return textMatch && filterMatch;
        });

        noResults.hidden = filtered.length > 0;

        filtered.forEach(function (risk) {
            const card = document.createElement('article');
            card.className = 'dc-risk-card' + (selected.has(Number(risk.id)) ? ' is-selected' : '');

            const ageText = risk.age_days === null ? 'Age unknown' : risk.age_days + ' days old';
            const visibilityText = risk.visibility === 'district' ? 'District-shared' : 'Group-only';
            const selectText = selected.has(Number(risk.id)) ? 'Selected' : 'Select';

            const title = document.createElement('div');
            title.innerHTML =
                '<h3>' + escapeText(risk.title) + '</h3>' +
                '<div class="dc-risk-meta">' +
                    escapeText(visibilityText) + ' · ' +
                    escapeText(risk.group_name) + ' · ' +
                    escapeText(ageText) +
                    (risk.uploaded_by_name ? '<br>Uploaded by ' + escapeText(risk.uploaded_by_name) : '') +
                '</div>' +
                '<div class="dc-risk-reason">' + escapeText(risk.reason) + '</div>';

            const actions = document.createElement('div');
            actions.className = 'dc-risk-actions';

            if (risk.can_select) {
                const selectButton = document.createElement('button');
                selectButton.type = 'button';
                selectButton.className = 'btn btn-primary lt-btn';
                selectButton.textContent = selectText;
                selectButton.setAttribute('data-select-risk', String(risk.id));
                actions.appendChild(selectButton);
            }

            const download = document.createElement('a');
            download.className = 'btn lt-btn lt-btn-secondary';
            download.href = risk.download_url;
            download.target = '_blank';
            download.rel = 'noopener';
            download.textContent = 'Download';
            actions.appendChild(download);

            card.appendChild(title);
            card.appendChild(actions);
            grid.appendChild(card);
        });
    }

    grid.addEventListener('click', function (event) {
        const button = event.target.closest('[data-select-risk]');

        if (!button) {
            return;
        }

        const id = Number(button.getAttribute('data-select-risk'));

        if (selected.has(id)) {
            selected.delete(id);
        } else {
            selected.add(id);
        }

        renderSelected();
        renderCards();
    });

    function openModal() {
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        searchInput.focus();
        renderCards();
    }

    function closeModal() {
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        openButton.focus();
    }

    openButton.addEventListener('click', openModal);
    closeButton.addEventListener('click', closeModal);

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.getAttribute('aria-hidden') === 'false') {
            closeModal();
        }
    });

    searchInput.addEventListener('input', renderCards);
    filterInput.addEventListener('change', renderCards);

    renderSelected();
    renderCards();
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
            const titleInput = document.createElement('input');
            titleInput.className = 'form-control';
            titleInput.name = 'risk_titles[]';
            titleInput.required = true;
            titleInput.value = item.title;
            titleInput.placeholder = 'Example: Campfire Risk Assessment';

            titleInput.addEventListener('input', function () {
                item.title = titleInput.value;
            });

            titleTd.appendChild(titleInput);

            const visibilityTd = document.createElement('td');
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

            visibilityTd.appendChild(visibilitySelect);

            const descriptionTd = document.createElement('td');
            const descriptionInput = document.createElement('textarea');
            descriptionInput.className = 'form-control';
            descriptionInput.name = 'risk_descriptions[]';
            descriptionInput.rows = 2;
            descriptionInput.value = item.description;
            descriptionInput.placeholder = 'Optional';

            descriptionInput.addEventListener('input', function () {
                item.description = descriptionInput.value;
            });

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

    let endWasManuallyChanged = false;
    let settingEndAutomatically = false;

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

    function defaultEndDate(force) {
        if (!startInput || !endInput || !startInput.value) {
            return;
        }

        if (!force && endWasManuallyChanged) {
            return;
        }

        const startDate = new Date(startInput.value);

        if (Number.isNaN(startDate.getTime())) {
            return;
        }

        const endDate = new Date(startDate.getTime());

        if (looksLikeMultiDayEvent()) {
            endDate.setDate(endDate.getDate() + 2);
        } else {
            endDate.setHours(endDate.getHours() + 2);
        }

        settingEndAutomatically = true;
        endInput.value = toDateTimeLocal(endDate);
        endInput.min = startInput.value;
        settingEndAutomatically = false;
    }

    if (endInput) {
        endInput.addEventListener('change', function () {
            if (!settingEndAutomatically) {
                endWasManuallyChanged = true;
            }
        });
    }

    if (startInput) {
        startInput.addEventListener('change', function () {
            endWasManuallyChanged = false;
            defaultEndDate(true);
        });
    }

    if (eventTypeInput) {
        eventTypeInput.addEventListener('change', function () {
            defaultEndDate(false);
        });
    }

    if (titleInput) {
        titleInput.addEventListener('blur', function () {
            defaultEndDate(false);
        });
    }

    if (descriptionInput) {
        descriptionInput.addEventListener('blur', function () {
            defaultEndDate(false);
        });
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