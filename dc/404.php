<?php

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
http_response_code(404);
$pageTitle = 'Page not found';
$heroTitle = 'Page not found';
$heroText = 'The page or record could not be found.';
require __DIR__ . '/layout.php';
?>
<div class="lt-panel"><p>The page may have moved or the item may no longer be available.</p><a href="/dc/">Back to calendar</a></div>
<?php require __DIR__ . '/layout-footer.php'; ?>
