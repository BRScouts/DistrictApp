<?php

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
$ctx = dc_require_access();
$groupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : null;
$selectedGroupId = dc_selected_group_id($groupId);
$showGroupPicker = count(dc_accessible_groups()) > 1;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedGroupId = dc_selected_group_id((int) ($_POST['group_id'] ?? $selectedGroupId));
    $people = dc_fetch_group_people($selectedGroupId);
    $uploader = $people[0] ?? null;
    if (!$uploader && $ctx['person_id']) { $uploader = ['full_name'=>$ctx['name'], 'primary_email'=>$ctx['email']]; }
    try {
        dc_store_risk_assessment_upload($_FILES['risk_file'], $selectedGroupId, trim((string)($_POST['title'] ?? '')), trim((string)($_POST['description'] ?? '')) ?: null, $uploader['full_name'] ?? 'Unknown leader', $uploader['primary_email'] ?? 'unknown@example.invalid', $ctx['person_id'], $ctx['actor_type'] === 'person' ? 'sso' : 'group_link', (string)($_POST['visibility'] ?? 'district'));
        redirect('/dc/risk-assessments.php?uploaded=1&group_id=' . $selectedGroupId);
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

$allowedGroups = $ctx['group_ids'] ?: [0];
$sql = "\n    SELECT ra.*, g.group_name\n    FROM risk_assessments ra\n    JOIN groups g ON g.id = ra.group_id\n    WHERE ra.status = 'active'\n      AND ra.admin_review_status = 'available'\n      AND (ra.visibility = 'district' OR ra.group_id IN (" . implode(',', array_fill(0, count($allowedGroups), '?')) . "))\n";
$params = $allowedGroups;
if ($showGroupPicker && $selectedGroupId) { $sql .= " AND (ra.group_id = ? OR ra.visibility = 'district')"; $params[] = $selectedGroupId; }
$sql .= " ORDER BY ra.visibility DESC, ra.uploaded_at DESC LIMIT 150";
$stmt = db()->prepare($sql); $stmt->execute($params); $risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle='Risk assessments'; $heroTitle='Risk assessments'; $heroText='View and share Group and District risk assessments.'; $active='risk'; require __DIR__.'/layout.php';
?>
<?php if (isset($_GET['uploaded'])): ?><div class="dc-success">Risk assessment uploaded.</div><?php endif; ?>
<?php if ($errors): ?><div class="dc-error-summary"><h2>Upload failed</h2><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="row"><div class="col-lg-7 mb-4"><section class="lt-panel"><h2 class="lt-section-title">Available risk assessments</h2><?php if (!$risks): ?><p>No risk assessments available yet.</p><?php else: ?><div class="dc-list"><?php foreach ($risks as $risk): ?><article class="dc-list-item"><div class="dc-list-body"><h3><a href="/dc/download-risk-assessment.php?id=<?= (int)$risk['id'] ?>"><?= e($risk['title']) ?></a></h3><p class="mb-1"><strong><?= e($risk['group_name']) ?></strong> · uploaded by <?= e($risk['uploaded_by_name']) ?> on <?= e(date('j M Y', strtotime($risk['uploaded_at']))) ?></p><span class="lt-badge"><?= e($risk['visibility']) ?></span></div></article><?php endforeach; ?></div><?php endif; ?></section></div><div class="col-lg-5 mb-4"><section class="lt-panel-grey"><h2 class="lt-section-title">Upload a risk assessment</h2><form method="post" enctype="multipart/form-data"><?php if ($showGroupPicker): ?><div class="form-group"><label for="group_id">Group</label><select id="group_id" name="group_id" class="form-control"><?= dc_group_options_html($selectedGroupId) ?></select></div><?php else: ?><input type="hidden" name="group_id" value="<?= (int)$selectedGroupId ?>"><?php endif; ?><div class="form-group"><label for="title">Title</label><input id="title" name="title" class="form-control" required></div><div class="form-group"><label for="description">Description</label><textarea id="description" name="description" class="form-control" rows="3"></textarea></div><div class="form-group"><label for="risk_file">File</label><input type="file" id="risk_file" name="risk_file" class="form-control" accept=".pdf,.doc,.docx" required></div><fieldset class="form-group"><legend>Sharing</legend><label class="lt-check"><input type="radio" name="visibility" value="district" checked> Share with the District</label><label class="lt-check"><input type="radio" name="visibility" value="group"> Keep to this Group only</label></fieldset><button class="btn btn-primary lt-btn" type="submit">Upload</button></form></section></div></div>
<?php require __DIR__.'/layout-footer.php'; ?>
