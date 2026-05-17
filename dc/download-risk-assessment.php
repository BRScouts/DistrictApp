<?php

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
$ctx = dc_require_access();
$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM risk_assessments WHERE id = :id AND status = "active" LIMIT 1');
$stmt->execute(['id'=>$id]);
$risk = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$risk) { require __DIR__ . '/404.php'; exit; }
if ($risk['visibility'] !== 'district' && !dc_user_can_access_group((int)$risk['group_id'])) { require __DIR__ . '/403.php'; exit; }
$path = __DIR__ . '/' . ltrim((string)$risk['file_path'], '/');
if (!is_file($path)) { require __DIR__ . '/404.php'; exit; }
dc_log('risk_assessment.downloaded', 'risk_assessment', (int)$risk['id'], [], (int)$risk['group_id']);
header('Content-Type: ' . ($risk['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
$disposition = isset($_GET['preview']) ? 'inline' : 'attachment';
header('Content-Disposition: ' . $disposition . '; filename="' . basename((string)$risk['original_filename']) . '"');
readfile($path);
exit;
