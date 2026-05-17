<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
$ctx = dc_require_access();

$requestedGroupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : (isset($_POST['group_id']) ? (int) $_POST['group_id'] : null);
$groupId = dc_selected_group_id($requestedGroupId);
$groups = dc_accessible_groups();
$showGroupPicker = count($groups) > 1;
$sections = dc_fetch_sections($groupId);
$people = dc_fetch_group_people($groupId);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupId = dc_selected_group_id((int) ($_POST['group_id'] ?? $groupId));
    $sections = dc_fetch_sections($groupId);
    $people = dc_fetch_group_people($groupId);

    $leaderPersonId = (int) ($_POST['leader_person_id'] ?? 0);
    $selectedLeader = null;
    foreach ($people as $person) {
        if ((int) $person['id'] === $leaderPersonId) {
            $selectedLeader = $person;
            break;
        }
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $eventType = (string) ($_POST['event_type'] ?? 'other');
    $startsAt = trim((string) ($_POST['starts_at'] ?? ''));
    $endsAt = trim((string) ($_POST['ends_at'] ?? ''));

    if (!$selectedLeader) { $errors[] = 'Choose the leader responsible for this event. If they are missing, ask a Group Lead Volunteer to add them.'; }
    if ($title === '') { $errors[] = 'Enter an event title.'; }
    if ($startsAt === '' || $endsAt === '') { $errors[] = 'Enter the start and end date/time.'; }
    if ($startsAt !== '' && $endsAt !== '' && strtotime($endsAt) <= strtotime($startsAt)) { $errors[] = 'The end date/time must be after the start date/time.'; }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("\n                INSERT INTO calendar_events (\n                    group_id, title, description, event_type, location_name, location_address, location_lat, location_lng,\n                    starts_at, ends_at, young_people_count, adult_count, leader_name, leader_email, leader_phone, leader_role,\n                    emergency_contact_name, emergency_contact_phone, submitted_by_person_id, submitted_via, status\n                ) VALUES (\n                    :group_id, :title, :description, :event_type, :location_name, :location_address, :location_lat, :location_lng,\n                    :starts_at, :ends_at, :young_people_count, :adult_count, :leader_name, :leader_email, :leader_phone, :leader_role,\n                    :emergency_contact_name, :emergency_contact_phone, :submitted_by_person_id, :submitted_via, 'submitted'\n                )\n            ");
            $stmt->execute([
                'group_id' => $groupId,
                'title' => $title,
                'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
                'event_type' => $eventType,
                'location_name' => trim((string) ($_POST['location_name'] ?? '')) ?: null,
                'location_address' => trim((string) ($_POST['location_address'] ?? '')) ?: null,
                'location_lat' => ($_POST['location_lat'] ?? '') !== '' ? (float) $_POST['location_lat'] : null,
                'location_lng' => ($_POST['location_lng'] ?? '') !== '' ? (float) $_POST['location_lng'] : null,
                'starts_at' => date('Y-m-d H:i:s', strtotime($startsAt)),
                'ends_at' => date('Y-m-d H:i:s', strtotime($endsAt)),
                'young_people_count' => ($_POST['young_people_count'] ?? '') !== '' ? (int) $_POST['young_people_count'] : null,
                'adult_count' => ($_POST['adult_count'] ?? '') !== '' ? (int) $_POST['adult_count'] : null,
                'leader_name' => $selectedLeader['full_name'],
                'leader_email' => $selectedLeader['primary_email'],
                'leader_phone' => $selectedLeader['phone'],
                'leader_role' => str_replace('_', ' ', (string) $selectedLeader['membership_role']),
                'emergency_contact_name' => trim((string) ($_POST['emergency_contact_name'] ?? '')) ?: null,
                'emergency_contact_phone' => trim((string) ($_POST['emergency_contact_phone'] ?? '')) ?: null,
                'submitted_by_person_id' => $ctx['person_id'],
                'submitted_via' => $ctx['actor_type'] === 'person' ? 'sso' : 'group_link',
            ]);
            $eventId = (int) $pdo->lastInsertId();

            foreach ((array) ($_POST['section_ids'] ?? []) as $sectionId) {
                $stmt = $pdo->prepare('INSERT IGNORE INTO calendar_event_sections (calendar_event_id, group_section_id) VALUES (:event_id, :section_id)');
                $stmt->execute(['event_id' => $eventId, 'section_id' => (int) $sectionId]);
            }

            foreach ((array) ($_POST['existing_risk_assessment_ids'] ?? []) as $riskId) {
                $stmt = $pdo->prepare("\n                    INSERT IGNORE INTO event_risk_assessments (calendar_event_id, risk_assessment_id, source_type)\n                    SELECT :event_id, id, 'selected_existing'\n                    FROM risk_assessments\n                    WHERE id = :risk_id\n                      AND status = 'active'\n                      AND (group_id = :group_id OR visibility = 'district')\n                ");
                $stmt->execute(['event_id' => $eventId, 'risk_id' => (int) $riskId, 'group_id' => $groupId]);
            }

            if (!empty($_FILES['risk_file']['name'])) {
                $riskId = dc_store_risk_assessment_upload(
                    $_FILES['risk_file'],
                    $groupId,
                    trim((string) ($_POST['risk_title'] ?? '')) ?: $title . ' risk assessment',
                    trim((string) ($_POST['risk_description'] ?? '')) ?: null,
                    $selectedLeader['full_name'],
                    $selectedLeader['primary_email'],
                    $ctx['person_id'],
                    $ctx['actor_type'] === 'person' ? 'sso' : 'group_link',
                    (string) ($_POST['risk_visibility'] ?? 'district')
                );
                $stmt = $pdo->prepare("INSERT INTO event_risk_assessments (calendar_event_id, risk_assessment_id, source_type) VALUES (:event_id, :risk_id, 'uploaded')");
                $stmt->execute(['event_id' => $eventId, 'risk_id' => $riskId]);
            }

            $pdo->commit();
            dc_log('calendar_event.created', 'calendar_event', $eventId, ['status' => 'submitted'], $groupId);
            dc_queue_event_notifications($eventId, 'submitted');
            redirect('/dc/manage-event.php?id=' . $eventId . '&created=1');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'The event could not be saved. ' . $e->getMessage();
        }
    }
}

$stmt = db()->prepare("\n    SELECT id, title, visibility, uploaded_by_name, uploaded_at\n    FROM risk_assessments\n    WHERE status = 'active'\n      AND admin_review_status = 'available'\n      AND (group_id = :group_id OR visibility = 'district')\n    ORDER BY visibility DESC, uploaded_at DESC\n    LIMIT 50\n");
$stmt->execute(['group_id' => $groupId]);
$riskAssessments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Add event';
$heroTitle = 'Add an event';
$heroText = 'Submit an away-from-hut notification or activity for review.';
$active = 'add';
require __DIR__ . '/layout.php';
?>
<?php if ($errors): ?><div class="dc-error-summary"><h2>Check the form</h2><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="dc-form">
    <?php if ($showGroupPicker): ?>
    <section class="lt-panel"><h2 class="lt-section-title">Group</h2><label for="group_id">Choose Group</label><select id="group_id" name="group_id" class="form-control" onchange="window.location='/dc/add-event.php?group_id='+this.value"><?= dc_group_options_html($groupId) ?></select></section>
    <?php else: ?><input type="hidden" name="group_id" value="<?= (int) $groupId ?>"><?php endif; ?>

    <section class="lt-panel">
        <h2 class="lt-section-title">Who is leading this?</h2>
        <?php if (!$people): ?>
            <p class="dc-warning">No leaders have been added to this Group yet. Ask a Group Lead Volunteer to add leaders before submitting events.</p>
        <?php else: ?>
            <label for="leader_person_id">Leader responsible</label>
            <select id="leader_person_id" name="leader_person_id" class="form-control" required>
                <option value="">Select a leader</option>
                <?php foreach ($people as $person): ?>
                    <option value="<?= (int) $person['id'] ?>"><?= e($person['full_name']) ?><?= $person['primary_email'] ? ' — ' . e($person['primary_email']) : '' ?></option>
                <?php endforeach; ?>
            </select>
            <p class="form-text">If the person is not listed, ask a Group Lead Volunteer to add them. Group-link users cannot add new people.</p>
        <?php endif; ?>
    </section>

    <section class="lt-panel">
        <h2 class="lt-section-title">Event details</h2>
        <div class="form-group"><label for="title">Event title</label><input id="title" name="title" class="form-control" required></div>
        <div class="form-group"><label for="event_type">Event type</label><select id="event_type" name="event_type" class="form-control"><option value="meeting_away_from_hut">Meeting away from hut</option><option value="day_activity">Day activity</option><option value="nights_away">Nights away</option><option value="camp">Camp</option><option value="hike">Hike</option><option value="water_activity">Water activity</option><option value="other">Other</option></select></div>
        <div class="form-group"><label for="description">Description</label><textarea id="description" name="description" class="form-control" rows="4"></textarea></div>
        <div class="row"><div class="col-md-6 form-group"><label for="starts_at">Starts</label><input type="datetime-local" id="starts_at" name="starts_at" class="form-control" required></div><div class="col-md-6 form-group"><label for="ends_at">Ends</label><input type="datetime-local" id="ends_at" name="ends_at" class="form-control" required></div></div>
        <div class="row"><div class="col-md-6 form-group"><label for="young_people_count">Young people attending</label><input type="number" min="0" id="young_people_count" name="young_people_count" class="form-control"></div><div class="col-md-6 form-group"><label for="adult_count">Adults attending</label><input type="number" min="0" id="adult_count" name="adult_count" class="form-control"></div></div>
        <?php if ($sections): ?><fieldset class="form-group"><legend>Sections involved</legend><div class="lt-check-list"><?php foreach ($sections as $section): ?><label class="lt-check"><input type="checkbox" name="section_ids[]" value="<?= (int) $section['id'] ?>"> <?= e($section['section_name']) ?></label><?php endforeach; ?></div></fieldset><?php endif; ?>
    </section>

    <section class="lt-panel">
        <h2 class="lt-section-title">Location</h2>
        <div class="form-group"><label for="location_name">Location name</label><input id="location_name" name="location_name" class="form-control"></div>
        <div class="form-group"><label for="location_address">Address or meeting point</label><textarea id="location_address" name="location_address" class="form-control" rows="3"></textarea></div>
        <div class="row"><div class="col-md-6 form-group"><label for="location_lat">Latitude, optional</label><input id="location_lat" name="location_lat" class="form-control"></div><div class="col-md-6 form-group"><label for="location_lng">Longitude, optional</label><input id="location_lng" name="location_lng" class="form-control"></div></div>
    </section>

    <section class="lt-panel">
        <h2 class="lt-section-title">Emergency contact</h2>
        <div class="row"><div class="col-md-6 form-group"><label for="emergency_contact_name">Name</label><input id="emergency_contact_name" name="emergency_contact_name" class="form-control"></div><div class="col-md-6 form-group"><label for="emergency_contact_phone">Phone</label><input id="emergency_contact_phone" name="emergency_contact_phone" class="form-control"></div></div>
    </section>

    <section class="lt-panel">
        <h2 class="lt-section-title">Risk assessment</h2>
        <?php if ($riskAssessments): ?><fieldset class="form-group"><legend>Select existing risk assessments</legend><div class="lt-check-list"><?php foreach ($riskAssessments as $risk): ?><label class="lt-check"><input type="checkbox" name="existing_risk_assessment_ids[]" value="<?= (int) $risk['id'] ?>"> <?= e($risk['title']) ?> <span class="lt-badge ml-1"><?= e($risk['visibility']) ?></span></label><?php endforeach; ?></div></fieldset><?php endif; ?>
        <div class="form-group"><label for="risk_file">Upload a new risk assessment</label><input type="file" id="risk_file" name="risk_file" class="form-control" accept=".pdf,.doc,.docx"></div>
        <div class="form-group"><label for="risk_title">Risk assessment title</label><input id="risk_title" name="risk_title" class="form-control"></div>
        <div class="form-group"><label for="risk_description">Risk assessment description</label><textarea id="risk_description" name="risk_description" class="form-control" rows="3"></textarea></div>
        <fieldset class="form-group"><legend>Sharing</legend><label class="lt-check"><input type="radio" name="risk_visibility" value="district" checked> Share with the District by default</label><label class="lt-check"><input type="radio" name="risk_visibility" value="group"> Keep to this Group only</label></fieldset>
    </section>

    <div class="dc-sticky-actions"><button class="btn btn-primary lt-btn" type="submit" <?= !$people ? 'disabled' : '' ?>>Submit for review</button><a class="btn lt-btn lt-btn-secondary" href="/dc/">Cancel</a></div>
</form>
<?php require __DIR__ . '/layout-footer.php'; ?>
