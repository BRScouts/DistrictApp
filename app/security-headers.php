<?php

declare(strict_types=1);

/**
 * Send HTTP security headers on every response.
 *
 * Include this file early (via bootstrap.php) before any output.
 */

// ─── CSP Nonce ──────────────────────────────────────────────────────────────

/**
 * Generate a per-request nonce for Content-Security-Policy.
 * Every inline <script> tag must include nonce="<?= csp_nonce() ?>".
 */
function csp_nonce(): string
{
    static $nonce = null;

    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }

    return $nonce;
}

/**
 * Output a nonce attribute for use in script tags: <script <?= csp_nonce_attr() ?>>
 */
function csp_nonce_attr(): string
{
    return 'nonce="' . csp_nonce() . '"';
}

// ─── Headers ────────────────────────────────────────────────────────────────

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

// Content Security Policy — nonce-based for scripts, still allows unsafe-inline
// for styles (moving styles to nonces is lower priority and more disruptive).
$nonce = csp_nonce();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
