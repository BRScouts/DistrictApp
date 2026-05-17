<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_auth();

$pdo = db();

$riskAssessmentId = (int)($_GET['id'] ?? 0);

if ($riskAssessmentId <= 0) {
    http_response_code(404);
    exit('Risk assessment not found.');
}

$isAdminOrReviewer = is_reviewer_or_admin();
$currentGroup = auth_group();
$currentGroupId = $currentGroup['group_id'] ?? null;

$sql = "
    SELECT
        ra.id,
        ra.group_id,
        ra.visibility,
        ra.file_path,
        ra.original_filename,
        ra.mime_type,
        ra.file_extension
    FROM risk_assessments ra
    WHERE ra.id = :id
      AND ra.is_active = 1
      AND ra.admin_review_status = 'available'
";
$params = ['id' => $riskAssessmentId];

if (!$isAdminOrReviewer) {
    if (!$currentGroupId) {
        http_response_code(403);
        exit('Forbidden.');
    }

    $sql .= "
      AND (
            ra.group_id = :group_id
            OR ra.visibility = 'district'
          )
    ";
    $params['group_id'] = (int)$currentGroupId;
}

$sql .= " LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ra = $stmt->fetch();

if (!$ra) {
    http_response_code(404);
    exit('Not found.');
}

$filePath = (string)$ra['file_path'];

if (!is_file($filePath) || !file_exists($filePath)) {
    http_response_code(404);
    exit('File missing.');
}

$ext = strtolower((string)$ra['file_extension']);
if ($ext !== 'pdf') {
    http_response_code(415);
    exit('Inline preview is only available for PDF files.');
}

$mimeType = (string)$ra['mime_type'];
if ($mimeType === '') {
    $mimeType = 'application/pdf';
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)filesize($filePath));
header('Content-Disposition: inline; filename="' . basename((string)$ra['original_filename']) . '"');
header('Cache-Control: private, max-age=3600');

while (ob_get_level()) {
    ob_end_clean();
}

readfile($filePath);
exit;