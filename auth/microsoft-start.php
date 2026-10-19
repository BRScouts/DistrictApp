<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$provider = microsoft_provider();

$authorizationUrl = $provider->getAuthorizationUrl([
    'scope' => 'openid profile email User.Read',
]);

$_SESSION['oauth2state'] = $provider->getState();

/*
 * Remember where to send the user after a successful sign-in. The OAuth
 * round-trip to Microsoft loses our query string, so stash a validated,
 * same-site relative path in the session. Rules mirror redirect()'s
 * open-redirect protection.
 */
unset($_SESSION['post_login_redirect']);

$requestedReturn = trim((string) ($_GET['return'] ?? ''));

if (
    $requestedReturn !== ''
    && $requestedReturn[0] === '/'
    && !str_starts_with($requestedReturn, '//')
    && !str_contains($requestedReturn, '://')
    && !preg_match('/[\x00-\x1f\x7f]/', $requestedReturn)
    && !str_starts_with($requestedReturn, '/login.php')
    && !str_starts_with($requestedReturn, '/dc/login.php')
) {
    $_SESSION['post_login_redirect'] = $requestedReturn;
}

// Cannot use redirect() here — it only allows relative paths (open-redirect protection).
// The Microsoft authorization URL is an external absolute URL.
header('Location: ' . $authorizationUrl);
exit;