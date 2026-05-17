<?php

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
http_response_code(403);
$pageTitle = 'Access denied';
$heroTitle = 'You cannot access this page';
$heroText = 'Your current sign-in or Group link does not allow this action.';
require __DIR__ . '/layout.php';
?>
<div class="lt-panel">
    <p>Use a valid Group link or sign in with a Microsoft account that has access to this area.</p>
    <a class="btn btn-primary lt-btn" href="/dc/login.php">Open District Calendar</a>
</div>
<?php require __DIR__ . '/layout-footer.php'; ?>
