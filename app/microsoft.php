<?php

declare(strict_types=1);

use League\OAuth2\Client\Provider\GenericProvider;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

function microsoft_provider(): GenericProvider
{
    $tenantId = app_config('MS_TENANT_ID');

    return new GenericProvider([
        'clientId' => app_config('MS_CLIENT_ID'),
        'clientSecret' => app_config('MS_CLIENT_SECRET'),
        'redirectUri' => app_config('MS_REDIRECT_URI'),

        'urlAuthorize' => "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize",
        'urlAccessToken' => "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
        'urlResourceOwnerDetails' => '',
        'scopes' => ['openid', 'profile', 'email', 'User.Read'],
    ]);
}

function decode_microsoft_id_token(string $idToken): array
{
    $tenantId = app_config('MS_TENANT_ID');
    $clientId = app_config('MS_CLIENT_ID');

    $jwksUrl = "https://login.microsoftonline.com/{$tenantId}/discovery/v2.0/keys";
    $jwksJson = file_get_contents($jwksUrl);

    if ($jwksJson === false) {
        throw new RuntimeException('Unable to fetch Microsoft signing keys.');
    }

    $jwks = json_decode($jwksJson, true);

    if (!is_array($jwks)) {
        throw new RuntimeException('Invalid Microsoft signing key response.');
    }

    $keys = JWK::parseKeySet($jwks);
    $decoded = JWT::decode($idToken, $keys);
    $claims = json_decode(json_encode($decoded), true);

    if (($claims['aud'] ?? null) !== $clientId) {
        throw new RuntimeException('Invalid Microsoft token audience.');
    }

    if (!isset($claims['iss']) || !str_contains($claims['iss'], (string) $tenantId)) {
        throw new RuntimeException('Invalid Microsoft token issuer.');
    }

    return $claims;
}