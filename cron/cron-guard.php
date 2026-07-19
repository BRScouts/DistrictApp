<?php

declare(strict_types=1);

/**
 * Cron Access Guard
 *
 * Include this at the top of each cron file (after bootstrap.php).
 * - If running from CLI: proceeds without checks.
 * - If accessed via browser: requires logged-in system_admin or district_admin.
 * - Sets $cronRunViaBrowser = true/false for output formatting.
 */

$cronRunViaBrowser = (PHP_SAPI !== 'cli');

if ($cronRunViaBrowser) {
    require_login();

    $cronUser = current_user();
    $cronAccessLevel = (string) ($cronUser['highest_access_level'] ?? $cronUser['role'] ?? 'member');

    if (!in_array($cronAccessLevel, ['system_admin', 'district_admin'], true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Access denied. This cron job can only be run by System Administrators.\n";
        exit;
    }

    // Set content type for browser output
    header('Content-Type: text/plain; charset=utf-8');
}
