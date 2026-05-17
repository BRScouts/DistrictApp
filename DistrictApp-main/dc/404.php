<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$context = auth_context();
$isAuthenticated = $context !== null;

render_page_start('Page not found');
render_header('');
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6">

            <div class="card shadow-sm">
                <div class="card-body text-center p-5">

                    <h1 class="display-4 mb-3">404</h1>

                    <h2 class="h4 mb-3">
                        Page not found
                    </h2>

                    <?php if (!$isAuthenticated): ?>

                        <p class="text-muted mb-4">
                            The link you followed may be incomplete or expired.
                            Please check the link provided in your email or contact your District Lead Volunteer (DLV).
                        </p>

                        <a href="<?= e(ROUTE_LOGIN) ?>"
                           class="btn btn-primary">
                            DLV login
                        </a>

                    <?php else: ?>

                        <p class="text-muted mb-4">
                            The page you requested could not be found.
                            It may have been moved or removed.
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