<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

require_login();

$accessToken = $_SESSION['microsoft_access_token'] ?? null;

if (!$accessToken) {
    http_response_code(404);
    exit;
}

$ch = curl_init('https://graph.microsoft.com/v1.0/me/photo/$value');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
    ],
]);

$image = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

curl_close($ch);

if ($status !== 200 || !$image) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . ($contentType ?: 'image/jpeg'));
header('Cache-Control: private, max-age=300');

echo $image;
