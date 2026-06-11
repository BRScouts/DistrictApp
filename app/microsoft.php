<?php

declare(strict_types=1);

use League\OAuth2\Client\Provider\GenericProvider;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

function microsoft_authority_tenant(): string
{
    $authorityTenant = trim((string) app_config('MS_AUTHORITY_TENANT', ''));

    if ($authorityTenant !== '') {
        return $authorityTenant;
    }

    return trim((string) app_config('MS_TENANT_ID', 'organizations'));
}

function microsoft_provider(): GenericProvider
{
    $authorityTenant = microsoft_authority_tenant();

    return new GenericProvider([
        'clientId' => app_config('MS_CLIENT_ID'),
        'clientSecret' => app_config('MS_CLIENT_SECRET'),
        'redirectUri' => app_config('MS_REDIRECT_URI'),
        'urlAuthorize' => "https://login.microsoftonline.com/{$authorityTenant}/oauth2/v2.0/authorize",
        'urlAccessToken' => "https://login.microsoftonline.com/{$authorityTenant}/oauth2/v2.0/token",
        'urlResourceOwnerDetails' => '',
        'scopes' => ['openid', 'profile', 'email', 'User.Read'],
    ]);
}

function microsoft_csv_config(string $key): array
{
    $value = (string) app_config($key, '');

    return array_values(array_filter(array_map(
        static fn(string $item): string => strtolower(trim($item)),
        explode(',', $value)
    )));
}

function microsoft_email_domain(string $email): string
{
    $email = strtolower(trim($email));

    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }

    return substr($email, strrpos($email, '@') + 1);
}

function microsoft_claim_email(array $claims): string
{
    return strtolower(trim((string) (
        $claims['preferred_username']
        ?? $claims['email']
        ?? $claims['upn']
        ?? ''
    )));
}

function microsoft_assert_allowed_tenant_or_domain(array $claims): void
{
    $allowedTenants = microsoft_csv_config('MS_ALLOWED_TENANTS');
    $allowedDomains = microsoft_csv_config('MS_ALLOWED_DOMAINS');

    if (!$allowedTenants && !$allowedDomains) {
        return;
    }

    $tenantId = strtolower(trim((string) ($claims['tid'] ?? '')));
    $email = microsoft_claim_email($claims);
    $domain = microsoft_email_domain($email);

    $tenantAllowed = $tenantId !== '' && in_array($tenantId, $allowedTenants, true);
    $domainAllowed = $domain !== '' && in_array($domain, $allowedDomains, true);

    if (!$tenantAllowed && !$domainAllowed) {
        throw new RuntimeException('This Microsoft tenant or email domain is not approved for this app.');
    }
}

function microsoft_assert_valid_issuer(array $claims): void
{
    $tenantId = strtolower(trim((string) ($claims['tid'] ?? '')));
    $issuer = strtolower(rtrim((string) ($claims['iss'] ?? ''), '/'));

    if ($tenantId === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId)) {
        throw new RuntimeException('Microsoft token did not contain a valid tenant ID.');
    }

    $expectedIssuer = 'https://login.microsoftonline.com/' . $tenantId . '/v2.0';

    if ($issuer !== $expectedIssuer) {
        throw new RuntimeException('Invalid Microsoft token issuer.');
    }
}

function decode_microsoft_id_token(string $idToken): array
{
    $authorityTenant = microsoft_authority_tenant();
    $clientId = app_config('MS_CLIENT_ID');

    $jwksJson = file_get_contents("https://login.microsoftonline.com/{$authorityTenant}/discovery/v2.0/keys");

    if ($jwksJson === false) {
        throw new RuntimeException('Unable to fetch Microsoft signing keys.');
    }

    $jwks = json_decode($jwksJson, true);

    if (!is_array($jwks)) {
        throw new RuntimeException('Invalid Microsoft signing key response.');
    }

    $decoded = JWT::decode($idToken, JWK::parseKeySet($jwks, 'RS256'));
    $claims = json_decode(json_encode($decoded), true);

    if (!is_array($claims)) {
        throw new RuntimeException('Invalid Microsoft token claims.');
    }

    if (($claims['aud'] ?? null) !== $clientId) {
        throw new RuntimeException('Invalid Microsoft token audience.');
    }

    microsoft_assert_valid_issuer($claims);
    microsoft_assert_allowed_tenant_or_domain($claims);

    return $claims;
}