<?php

declare(strict_types=1);

/**
 * Send HTTP security headers on every response.
 *
 * Include this file early (via bootstrap.php) before any output.
 */

// Prevent clickjacking
header('X-Frame-Options: DENY');

// Stop browsers from MIME-sniffing the content type
header('X-Content-Type-Options: nosniff');

// Limit referrer information leakage
header('Referrer-Policy: strict-origin-when-cross-origin');

// Restrict browser features/permissions
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

// Force HTTPS (only applies if served over HTTPS in production)
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Content Security Policy — allows inline styles/scripts for now since the app uses them,
// but blocks embedding from unknown origins and restricts form targets.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
