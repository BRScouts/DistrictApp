<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

unset($_SESSION['admin_auth']);

if (!empty($_SESSION['group_auth'])) {
    redirect(ROUTE_CALENDAR);
}

redirect(ROUTE_LOGIN);