<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (isset($_GET['token'])) {
    $ctx = dc_context(true);
    redirect('/dc/');
}

if (current_user()) {
    redirect('/dc/');
}

$pageTitle = 'Open District Calendar';
$heroTitle = 'Open District Calendar';
$heroText = 'Use your Group link or sign in with your District Microsoft account.';
require __DIR__ . '/layout.php';
?>
<div class="row">
    <div class="col-lg-7 mb-4">
        <div class="lt-panel">
            <h2 class="lt-section-title">Sign in with Microsoft</h2>
            <p class="lt-lede">District users and Group Lead Volunteers should sign in to use the full Leader Tool.</p>
            <a class="btn btn-primary lt-btn" href="/auth/microsoft-start.php">Sign in with Microsoft</a>
        </div>
    </div>
    <div class="col-lg-5 mb-4">
        <div class="lt-panel-grey">
            <h2 class="h4 font-weight-bold">Using a Group link?</h2>
            <p>Your Group link should be opened from the link shared with your Group. It will look like:</p>
            <pre class="dc-code">/dc/login.php?token=...</pre>
            <p class="mb-0">A Group link gives access to that Group’s calendar area. It is not a personal account.</p>
        </div>
    </div>
</div>
<?php require __DIR__ . '/layout-footer.php'; ?>
