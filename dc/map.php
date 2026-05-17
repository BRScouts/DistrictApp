<?php

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
$ctx = dc_require_access();
$allowedGroups = $ctx['group_ids'] ?: [0];
$groupPlaceholders = [];
$params = ['reviewer' => $ctx['is_reviewer'] ? 1 : 0];
foreach ($allowedGroups as $index => $gid) {
    $key = 'allowed_group_' . $index;
    $groupPlaceholders[] = ':' . $key;
    $params[$key] = (int) $gid;
}
$sql = "
    SELECT ce.*, g.group_name
    FROM calendar_events ce JOIN groups g ON g.id = ce.group_id
    WHERE ce.status <> 'cancelled'
      AND ce.ends_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND (:reviewer = 1 OR ce.group_id IN (" . implode(',', $groupPlaceholders) . "))
    ORDER BY ce.starts_at ASC LIMIT 150
";
$stmt=db()->prepare($sql); $stmt->execute($params); $events=$stmt->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Map'; $heroTitle='Calendar map'; $heroText='A simple list-first map page for mobile, with location details where provided.'; $active='map'; require __DIR__.'/layout.php';
?>
<div class="lt-panel-grey mb-4"><p class="mb-0">Events with latitude and longitude can be plotted later using Leaflet or another map provider. For now this page provides a mobile-friendly location list.</p></div>
<div class="dc-list"><?php foreach($events as $event): ?><article class="dc-list-item"><div class="dc-list-body"><h3><a href="/dc/manage-event.php?id=<?= (int)$event['id'] ?>"><?= e($event['title']) ?></a></h3><p><strong><?= e($event['group_name']) ?></strong> · <?= e(date('j M Y H:i', strtotime($event['starts_at']))) ?></p><p><?= e($event['location_name'] ?: 'Location not named') ?><?= $event['location_address'] ? ' — '.e($event['location_address']) : '' ?></p><?php if($event['location_lat'] && $event['location_lng']): ?><p class="mb-0">Coordinates: <?= e($event['location_lat']) ?>, <?= e($event['location_lng']) ?></p><?php endif; ?></div></article><?php endforeach; ?></div>
<?php require __DIR__.'/layout-footer.php'; ?>
