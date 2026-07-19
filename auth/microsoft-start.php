<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$provider = microsoft_provider();

$authorizationUrl = $provider->getAuthorizationUrl([
    'scope' => 'openid profile email User.Read',
]);

$_SESSION['oauth2state'] = $provider->getState();

// Cannot use redirect() here — it only allows relative paths (open-redirect protection).
// The Microsoft authorization URL is an external absolute URL.
header('Location: ' . $authorizationUrl);
exit;