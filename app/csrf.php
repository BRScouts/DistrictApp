<?php

declare(strict_types=1);

/**
 * CSRF protection helpers.
 *
 * Usage in forms:
 *   <?= csrf_field() ?>
 *
 * Usage in POST handlers (call early, before processing):
 *   csrf_validate();
 */

/**
 * Generate or retrieve the current session CSRF token.
 */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

/**
 * Output a hidden form field containing the CSRF token.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Validate the submitted CSRF token against the session token.
 * Aborts with 403 if the token is missing or invalid.
 *
 * On success the token is rotated so it cannot be replayed. Any
 * subsequent form render will receive the fresh token via csrf_token().
 */
function csrf_validate(): void
{
    $submitted = (string) ($_POST['_csrf_token'] ?? '');
    $expected = $_SESSION['_csrf_token'] ?? '';

    if ($expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        echo '<h1>403 Forbidden</h1><p>Your session has expired or the form submission could not be verified. Please go back and try again.</p>';
        exit;
    }

    // Rotate token after successful validation to prevent replay
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
