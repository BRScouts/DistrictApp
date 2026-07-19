<?php

declare(strict_types=1);

/**
 * Serve the current user's Microsoft profile photo using client credentials.
 *
 * Previously this used a delegated access token stored in the session, which
 * posed a risk on shared hosting (session files readable by other users).
 * Now uses the app-level client_credentials flow (same as directory-photo.php).
 */

require_once __DIR__ . '/../app/bootstrap.php';

require_login();

$user = current_user();

if (!$user) {
    http_response_code(404);
    exit;
}

$personId = (int) $user['id'];

// ─── Resolve Graph user ID for the current user ────────────────────────────

$pdo = db();

$stmt = $pdo->prepare("
    SELECT ua.provider_subject
    FROM user_accounts ua
    WHERE ua.person_id = :person_id
      AND ua.provider = 'microsoft'
      AND ua.provider_subject IS NOT NULL
      AND ua.provider_subject <> ''
    LIMIT 1
");
$stmt->execute(['person_id' => $personId]);
$graphUserId = $stmt->fetchColumn();

if ($graphUserId === false) {
    // Fallback: m365_account_requests
    try {
        $stmt = $pdo->prepare("
            SELECT mar.graph_user_id
            FROM m365_account_requests mar
            WHERE mar.person_id = :person_id
              AND mar.graph_user_id IS NOT NULL
              AND mar.graph_user_id <> ''
            LIMIT 1
        ");
        $stmt->execute(['person_id' => $personId]);
        $graphUserId = $stmt->fetchColumn();
    } catch (Throwable $e) {
        // Table may not exist
    }
}

if ($graphUserId === false || $graphUserId === '') {
    http_response_code(404);
    exit;
}

// ─── Get app-level access token (client credentials) ────────────────────────

$tenantId = app_config('MS_TENANT_ID', '');
$clientId = app_config('MS_CLIENT_ID', '');
$clientSecret = app_config('MS_CLIENT_SECRET', '');

if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
    http_response_code(503);
    exit;
}

$cacheDir = __DIR__ . '/../storage/profile-photos';

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$tokenCacheFile = $cacheDir . '/.token_cache.json';
$accessToken = null;

// Reuse cached token if still valid
if (is_file($tokenCacheFile)) {
    $tokenData = json_decode(file_get_contents($tokenCacheFile), true);
    if (is_array($tokenData) && !empty($tokenData['access_token']) && ($tokenData['expires_at'] ?? 0) > time() + 60) {
        $accessToken = $tokenData['access_token'];
    }
}

if ($accessToken === null) {
    $ch = curl_init("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]),
    ]);

    $tokenResponse = curl_exec($ch);
    $tokenStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($tokenStatus !== 200 || !$tokenResponse) {
        http_response_code(503);
        exit;
    }

    $tokenPayload = json_decode($tokenResponse, true);

    if (!is_array($tokenPayload) || empty($tokenPayload['access_token'])) {
        http_response_code(503);
        exit;
    }

    $accessToken = $tokenPayload['access_token'];

    file_put_contents($tokenCacheFile, json_encode([
        'access_token' => $accessToken,
        'expires_at' => time() + (int) ($tokenPayload['expires_in'] ?? 3500),
    ]), LOCK_EX);
}

// ─── Fetch photo from Microsoft Graph ───────────────────────────────────────

$ch = curl_init('https://graph.microsoft.com/v1.0/users/' . rawurlencode($graphUserId) . '/photo/$value');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
    ],
]);

$image = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status !== 200 || !$image) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=300');

echo $image;
