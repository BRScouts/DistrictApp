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

/**
 * Load RA with access rules
 */
$sql = "
    SELECT
        ra.id,
        ra.group_id,
        ra.title,
        ra.visibility,
        ra.file_path,
        ra.original_filename,
        ra.mime_type,
        ra.file_extension,
        ra.is_active,
        ra.admin_review_status
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
    exit('Risk assessment not found or access denied.');
}

$filePath = (string)$ra['file_path'];

if ($filePath === '' || !is_file($filePath) || !file_exists($filePath)) {
    http_response_code(404);
    exit('File not found on server.');
}

/**
 * Whitelist content types for safety
 */
$allowedMimeTypes = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/octet-stream',
];

$mimeType = (string)$ra['mime_type'];
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    $mimeType = 'application/octet-stream';
}

$downloadName = (string)$ra['original_filename'];
if ($downloadName === '') {
    $downloadName = 'risk-assessment-' . (int)$ra['id'] . '.' . strtolower((string)$ra['file_extension']);
}

/**
 * Prevent header injection in filename
 */
$downloadName = str_replace(["\r", "\n"], '', $downloadName);

$fileSize = filesize($filePath);
if ($fileSize === false) {
    http_response_code(500);
    exit('Unable to read file size.');
}

/**
 * Optional audit logging
 */
try {
    $actorType = $isAdminOrReviewer ? 'admin' : 'group_link';
    $adminUserId = auth_admin()['admin_user_id'] ?? null;
    $groupId = $currentGroupId ?: null;

    $log = $pdo->prepare("
        INSERT INTO audit_log (
            actor_type,
            admin_user_id,
            group_id,
            entity_type,
            entity_id,
            action,
            details,
            created_at
        ) VALUES (
            :actor_type,
            :admin_user_id,
            :group_id,
            'risk_assessment',
            :entity_id,
            'download',
            :details,
            NOW()
        )
    ");
    $log->execute([
        'actor_type' => $actorType,
        'admin_user_id' => $adminUserId,
        'group_id' => $groupId,
        'entity_id' => (int)$ra['id'],
        'details' => json_encode([
            'title' => $ra['title'],
            'filename' => $downloadName,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
} catch (Throwable $e) {
    // Do not block download if audit logging fails
}

/**
 * Stream file
 */
header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . (string)$fileSize);
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, must-revalidate');
header('Pragma: private');
header('Expires: 0');

while (ob_get_level()) {
    ob_end_clean();
}

$handle = fopen($filePath, 'rb');

if ($handle === false) {
    http_response_code(500);
    exit('Unable to open file.');
}

while (!feof($handle)) {
    echo fread($handle, 8192);
    flush();
}

fclose($handle);
exit;