<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$provider = microsoft_provider();

$authorizationUrl = $provider->getAuthorizationUrl([
    'scope' => 'openid profile email User.Read',
]);

$_SESSION['oauth2state'] = $provider->getState();

redirect($authorizationUrl);