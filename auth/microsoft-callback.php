<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

/*
|--------------------------------------------------------------------------
| Microsoft returned an error
|--------------------------------------------------------------------------
|
| If Microsoft rejects the login request, it sends ?error=... instead of ?code=...
|
*/

if (isset($_GET['error'])) {
    http_response_code(400);

    echo '<h1>Microsoft sign-in error</h1>';
    echo '<p><strong>Error:</strong> ' . e($_GET['error']) . '</p>';

    if (isset($_GET['error_description'])) {
        echo '<p><strong>Description:</strong> ' . e($_GET['error_description']) . '</p>';
    }

    echo '<p><a href="/login.php">Back to login</a></p>';
    exit;
}

/*
|--------------------------------------------------------------------------
| Validate state
|--------------------------------------------------------------------------
*/

if (!isset($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth2state'] ?? null)) {
    unset($_SESSION['oauth2state']);

    http_response_code(400);

    echo '<h1>Invalid OAuth state</h1>';
    echo '<p>The Microsoft sign-in session could not be verified.</p>';
    echo '<p><a href="/login.php">Back to login</a></p>';
    exit;
}

/*
|--------------------------------------------------------------------------
| Validate authorization code
|--------------------------------------------------------------------------
*/

if (!isset($_GET['code']) || $_GET['code'] === '') {
    http_response_code(400);

    echo '<h1>Missing Microsoft authorization code</h1>';
    echo '<p>Microsoft did not return an authorization code to the callback URL.</p>';

    echo '<h2>Debug information</h2>';
    echo '<pre>';
    echo e(print_r($_GET, true));
    echo '</pre>';

    echo '<p><a href="/login.php">Back to login</a></p>';
    exit;
}

try {
    $provider = microsoft_provider();

    $accessToken = $provider->getAccessToken('authorization_code', [
        'code' => $_GET['code'],
    ]);

    $values = $accessToken->getValues();

    if (!isset($values['id_token'])) {
        throw new RuntimeException('Microsoft did not return an ID token.');
    }

    $claims = decode_microsoft_id_token($values['id_token']);

    $user = find_or_create_microsoft_user($claims);

    login_user($user);

    unset($_SESSION['oauth2state']);

    redirect('/index.php');
} catch (Throwable $e) {
    http_response_code(500);

    echo '<h1>Microsoft sign-in failed</h1>';
    echo '<p>' . e($e->getMessage()) . '</p>';
    echo '<p><a href="/login.php">Back to login</a></p>';
}