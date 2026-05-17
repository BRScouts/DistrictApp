<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$context = auth_context();
$isAuthenticated = $context !== null;

render_page_start('Access denied');
render_header('');
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6">

            <div class="card shadow-sm">
                <div class="card-body text-center p-5">

                    <h1 class="display-4 mb-3">403</h1>

                    <h2 class="h4 mb-3">
                        You don’t have permission to access this page
                    </h2>

                    <?php if (!$isAuthenticated): ?>

                        <p class="text-muted mb-4">
                            If you followed a link from an email, please check that the link is complete and correct.
                            If the issue continues, contact your District Lead Volunteer (DLV).
                        </p>

                        <a href="<?= e(ROUTE_LOGIN) ?>"
                           class="btn btn-primary">
                            DLV login
                        </a>

                    <?php else: ?>

                        <p class="text-muted mb-4">
                            Your account does not have access to this area.
                            If you believe this is incorrect, contact your District Lead Volunteer (DLV).
                        </p>

                        <a href="<?= e(ROUTE_CALENDAR) ?>"
                           class="btn btn-outline-primary">
                            Return to calendar
                        </a>

                    <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</div>

<?php render_page_end(); ?>