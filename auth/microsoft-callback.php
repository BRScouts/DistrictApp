<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (!isset($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth2state'] ?? null)) {
    unset($_SESSION['oauth2state']);
    http_response_code(400);
    exit('Invalid OAuth state.');
}

if (!isset($_GET['code'])) {
    http_response_code(400);
    exit('Missing Microsoft authorization code.');
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

    redirect('/index.php');
} catch (Throwable $e) {
    http_response_code(500);

    echo '<h1>Microsoft sign-in failed</h1>';
    echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
}