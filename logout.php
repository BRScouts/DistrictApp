<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Audit: log the logout before destroying the session
$logoutUser = current_user();
if ($logoutUser) {
    audit_log(
        AUDIT_AUTH_LOGOUT,
        'person',
        (int) $logoutUser['id'],
        (int) $logoutUser['id'],
        ['email' => $logoutUser['email'] ?? '']
    );
}

logout_user();


redirect('/login.php');