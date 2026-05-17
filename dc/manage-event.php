<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
$ctx = dc_require_access();
$eventId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$event = dc_get_event($eventId);
if (!$event || !dc_user_can_access_group((int) $event['group_id'])) { require __DIR__ . '/404.php'; exit; }

$errors = [];
$sections = dc_fetch_sections((int) $event['group_id']);
$people = dc_fetch_group_people((int) $event['group_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leaderPersonId = (int) ($_POST['leader_person_id'] ?? 0);
    $selectedLeader = null;
    foreach ($people as $person) { if ((int) $person['id'] === $leaderPersonId) { $selectedLeader = $person; break; } }
    $title = trim((string) ($_POST['title'] ?? ''));
    $startsAt = trim((string) ($_POST['starts_at'] ?? ''));
    $endsAt = trim((string) ($_POST['ends_at'] ?? ''));
    if (!$selectedLeader) { $errors[] = 'Choose the leader responsible for this event.'; }
    if ($title === '') { $errors[] = 'Enter an event title.'; }
    if ($startsAt === '' || $endsAt === '') { $errors[] = 'Enter the start and end date/time.'; }
    if ($startsAt && $endsAt && strtotime($endsAt) <= strtotime($startsAt)) { $errors[] = 'The end date/time must be after the start date/time.'; }

    if (!$errors) {
        $pdo = db(); $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("\n                UPDATE calendar_events SET\n                    title = :title, description = :description, event_type = :event_type, location_name = :location_name,\n                    location_address = :location_address, location_lat = :location_lat, location_lng = :location_lng,\n                    starts_at = :starts_at, ends_at = :ends_at, young_people_count = :young_people_count, adult_count = :adult_count,\n                    leader_name = :leader_name, leader_email = :leader_email, leader_phone = :leader_phone, leader_role = :leader_role,\n                    emergency_contact_name = :emergency_contact_name, emergency_contact_phone = :emergency_contact_phone,\n                    status = 'submitted', reviewer_notes = NULL, reviewed_by_person_id = NULL, reviewed_at = NULL\n                WHERE id = :id\n            ");
            $stmt->execute([
                'title' => $title,
                'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
                'event_type' => (string) ($_POST['event_type'] ?? 'other'),
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
                'id' => $eventId,
            ]);
            $pdo->prepare('DELETE FROM calendar_event_sections WHERE calendar_event_id = :id')->execute(['id' => $eventId]);
            foreach ((array) ($_POST['section_ids'] ?? []) as $sectionId) {
                $pdo->prepare('INSERT IGNORE INTO calendar_event_sections (calendar_event_id, group_section_id) VALUES (:event_id, :section_id)')->execute(['event_id' => $eventId, 'section_id' => (int) $sectionId]);
            }
            if (!empty($_FILES['risk_file']['name'])) {
                $riskId = dc_store_risk_assessment_upload($_FILES['risk_file'], (int)$event['group_id'], trim((string)($_POST['risk_title'] ?? '')) ?: $title . ' risk assessment', trim((string)($_POST['risk_description'] ?? '')) ?: null, $selectedLeader['full_name'], $selectedLeader['primary_email'], $ctx['person_id'], $ctx['actor_type'] === 'person' ? 'sso' : 'group_link', (string)($_POST['risk_visibility'] ?? 'district'));
                $pdo->prepare("INSERT IGNORE INTO event_risk_assessments (calendar_event_id, risk_assessment_id, source_type) VALUES (:event_id, :risk_id, 'reviewed_reupload')")->execute(['event_id' => $eventId, 'risk_id' => $riskId]);
            }
            $pdo->commit();
            dc_log('calendar_event.updated_resubmitted', 'calendar_event', $eventId, [], (int)$event['group_id']);
            dc_queue_event_notifications($eventId, 'submitted');
            redirect('/dc/manage-event.php?id=' . $eventId . '&updated=1');
        } catch (Throwable $e) { $pdo->rollBack(); $errors[] = 'The event could not be updated. ' . $e->getMessage(); }
    }
    $event = dc_get_event($eventId);
}

$stmt = db()->prepare('SELECT group_section_id FROM calendar_event_sections WHERE calendar_event_id = :id');
$stmt->execute(['id' => $eventId]);
$selectedSections = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
$stmt = db()->prepare("\n    SELECT ra.* FROM event_risk_assessments era JOIN risk_assessments ra ON ra.id = era.risk_assessment_id\n    WHERE era.calendar_event_id = :id ORDER BY ra.uploaded_at DESC\n");
$stmt->execute(['id' => $eventId]);
$linkedRisks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage event';
$heroTitle = $event['title'];
$heroText = 'Viewing or editing this event will return it to the review stage.';
$active = 'home';
require __DIR__ . '/layout.php';
?>
<?php if (isset($_GET['created'])): ?><div class="dc-success">Event submitted for review. Notification emails have been added to the queue.</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="dc-success">Event updated and returned to review. Notification emails have been added to the queue.</div><?php endif; ?>
<?php if ($errors): ?><div class="dc-error-summary"><h2>Check the form</h2><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="lt-panel-grey mb-4"><strong>Status:</strong> <span class="lt-badge dc-status dc-status-<?= e($event['status']) ?>"><?= e(str_replace('_', ' ', $event['status'])) ?></span> <span class="ml-2"><strong>Group:</strong> <?= e($event['group_name']) ?></span></div>

<form method="post" enctype="multipart/form-data" class="dc-form">
<input type="hidden" name="id" value="<?= (int)$eventId ?>">
<section class="lt-panel"><h2 class="lt-section-title">Leader</h2><label for="leader_person_id">Leader responsible</label><select id="leader_person_id" name="leader_person_id" class="form-control" required><option value="">Select a leader</option><?php foreach ($people as $person): ?><option value="<?= (int)$person['id'] ?>" <?= strtolower((string)$person['primary_email']) === strtolower((string)$event['leader_email']) ? 'selected' : '' ?>><?= e($person['full_name']) ?><?= $person['primary_email'] ? ' — ' . e($person['primary_email']) : '' ?></option><?php endforeach; ?></select></section>
<section class="lt-panel"><h2 class="lt-section-title">Event details</h2><div class="form-group"><label for="title">Event title</label><input id="title" name="title" class="form-control" value="<?= e($event['title']) ?>" required></div><div class="form-group"><label for="event_type">Event type</label><select id="event_type" name="event_type" class="form-control"><?php foreach (['meeting_away_from_hut','day_activity','nights_away','camp','hike','water_activity','other'] as $type): ?><option value="<?= e($type) ?>" <?= $event['event_type']===$type?'selected':'' ?>><?= e(str_replace('_',' ',$type)) ?></option><?php endforeach; ?></select></div><div class="form-group"><label for="description">Description</label><textarea id="description" name="description" class="form-control" rows="4"><?= e($event['description']) ?></textarea></div><div class="row"><div class="col-md-6 form-group"><label for="starts_at">Starts</label><input type="datetime-local" id="starts_at" name="starts_at" class="form-control" value="<?= e(date('Y-m-d\TH:i', strtotime($event['starts_at']))) ?>" required></div><div class="col-md-6 form-group"><label for="ends_at">Ends</label><input type="datetime-local" id="ends_at" name="ends_at" class="form-control" value="<?= e(date('Y-m-d\TH:i', strtotime($event['ends_at']))) ?>" required></div></div><div class="row"><div class="col-md-6 form-group"><label for="young_people_count">Young people</label><input type="number" min="0" id="young_people_count" name="young_people_count" class="form-control" value="<?= e($event['young_people_count']) ?>"></div><div class="col-md-6 form-group"><label for="adult_count">Adults</label><input type="number" min="0" id="adult_count" name="adult_count" class="form-control" value="<?= e($event['adult_count']) ?>"></div></div><?php if ($sections): ?><fieldset class="form-group"><legend>Sections involved</legend><div class="lt-check-list"><?php foreach ($sections as $section): ?><label class="lt-check"><input type="checkbox" name="section_ids[]" value="<?= (int)$section['id'] ?>" <?= in_array((int)$section['id'],$selectedSections,true)?'checked':'' ?>> <?= e($section['section_name']) ?></label><?php endforeach; ?></div></fieldset><?php endif; ?></section>
<section class="lt-panel"><h2 class="lt-section-title">Location</h2><div class="form-group"><label for="location_name">Location name</label><input id="location_name" name="location_name" class="form-control" value="<?= e($event['location_name']) ?>"></div><div class="form-group"><label for="location_address">Address</label><textarea id="location_address" name="location_address" class="form-control" rows="3"><?= e($event['location_address']) ?></textarea></div><div class="row"><div class="col-md-6 form-group"><label for="location_lat">Latitude</label><input id="location_lat" name="location_lat" class="form-control" value="<?= e($event['location_lat']) ?>"></div><div class="col-md-6 form-group"><label for="location_lng">Longitude</label><input id="location_lng" name="location_lng" class="form-control" value="<?= e($event['location_lng']) ?>"></div></div></section>
<section class="lt-panel"><h2 class="lt-section-title">Emergency contact</h2><div class="row"><div class="col-md-6 form-group"><label for="emergency_contact_name">Name</label><input id="emergency_contact_name" name="emergency_contact_name" class="form-control" value="<?= e($event['emergency_contact_name']) ?>"></div><div class="col-md-6 form-group"><label for="emergency_contact_phone">Phone</label><input id="emergency_contact_phone" name="emergency_contact_phone" class="form-control" value="<?= e($event['emergency_contact_phone']) ?>"></div></div></section>
<section class="lt-panel"><h2 class="lt-section-title">Risk assessments</h2><?php if ($linkedRisks): ?><ul class="dc-clean-list"><?php foreach ($linkedRisks as $risk): ?><li><a href="/dc/download-risk-assessment.php?id=<?= (int)$risk['id'] ?>"><?= e($risk['title']) ?></a> <span class="lt-badge"><?= e($risk['visibility']) ?></span></li><?php endforeach; ?></ul><?php else: ?><p>No risk assessments linked yet.</p><?php endif; ?><div class="form-group"><label for="risk_file">Upload another risk assessment</label><input type="file" id="risk_file" name="risk_file" class="form-control" accept=".pdf,.doc,.docx"></div><div class="form-group"><label for="risk_title">Risk assessment title</label><input id="risk_title" name="risk_title" class="form-control"></div><div class="form-group"><label for="risk_description">Description</label><textarea id="risk_description" name="risk_description" class="form-control" rows="3"></textarea></div><fieldset class="form-group"><legend>Sharing</legend><label class="lt-check"><input type="radio" name="risk_visibility" value="district" checked> Share with the District</label><label class="lt-check"><input type="radio" name="risk_visibility" value="group"> Keep to this Group only</label></fieldset></section>
<div class="dc-sticky-actions"><button class="btn btn-primary lt-btn" type="submit">Save changes and return to review</button><a class="btn lt-btn lt-btn-secondary" href="/dc/">Back</a><?php if ($ctx['is_reviewer']): ?><a class="btn lt-btn lt-btn-secondary" href="/dc/reviewer/review-event.php?id=<?= (int)$eventId ?>">Review</a><?php endif; ?></div>
</form>
<?php require __DIR__ . '/layout-footer.php'; ?>
