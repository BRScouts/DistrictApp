<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (isset($_GET['error'])) {
    http_response_code(400);
    echo '<h1>Microsoft sign-in error</h1>';
    echo '<p>The Microsoft sign-in request was not completed.</p>';
    echo '<p><a href="/login.php">Back to sign in</a></p>';
    exit;
}

if (!isset($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth2state'] ?? null)) {
    unset($_SESSION['oauth2state']);
    http_response_code(400);
    echo '<h1>Sign-in session expired</h1>';
    echo '<p>The Microsoft sign-in session could not be verified.</p>';
    echo '<p><a href="/login.php">Back to sign in</a></p>';
    exit;
}

if (!isset($_GET['code']) || $_GET['code'] === '') {
    http_response_code(400);
    echo '<h1>Missing Microsoft authorisation code</h1>';
    echo '<p>Microsoft did not return the information needed to complete sign in.</p>';
    echo '<p><a href="/login.php">Back to sign in</a></p>';
    exit;
}

try {
    $provider = microsoft_provider();
    $accessToken = $provider->getAccessToken('authorization_code', ['code' => $_GET['code']]);
    $values = $accessToken->getValues();

    if (!isset($values['id_token'])) {
        throw new RuntimeException('Microsoft did not return an ID token.');
    }

    $claims = decode_microsoft_id_token($values['id_token']);
    $user = find_or_create_microsoft_user($claims);

    login_user($user);
    $_SESSION['microsoft_access_token'] = $accessToken->getToken();
    unset($_SESSION['oauth2state']);

    // Audit: successful login
    audit_log(
        AUDIT_AUTH_LOGIN_SUCCESS,
        'person',
        (int) $user['id'],
        (int) $user['id'],
        [
            'email' => $user['primary_email'] ?? $user['email'] ?? '',
            'provider' => 'microsoft',
            'access_level' => $user['highest_access_level'] ?? 'member',
        ]
    );

    redirect(user_needs_group_onboarding() ? '/onboarding.php' : '/index.php');
} catch (Throwable $e) {
    error_log('Microsoft sign-in failed: ' . $e->getMessage());

    // Audit: failed login attempt
    audit_log(
        AUDIT_AUTH_LOGIN_FAILED,
        null,
        null,
        null,
        [
            'error' => substr($e->getMessage(), 0, 255),
            'provider' => 'microsoft',
        ]
    );

    http_response_code(500);
    echo '<h1>Microsoft sign-in failed</h1>';
    echo '<p>We could not complete your sign in. Please try again or contact the District team.</p>';
    echo '<p><a href="/login.php">Back to sign in</a></p>';
}