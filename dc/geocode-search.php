<?php

declare(strict_types=1);

/**
 * Same-origin geocoding proxy.
 *
 * The event location picker (add-event.php / manage-event.php) used to call
 * https://nominatim.openstreetmap.org directly from the browser. That is blocked
 * by our Content-Security-Policy (connect-src 'self') and also breaches
 * Nominatim's usage policy, which requires an identifying User-Agent and
 * discourages direct browser calls.
 *
 * This endpoint runs the lookup server-side and returns the raw Nominatim JSON,
 * so the front-end only ever talks to our own origin.
 */

require_once __DIR__ . '/auth.php';

// Any signed-in user who can reach the event forms may use the search.
dc_require_access();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$query = trim((string) ($_GET['q'] ?? ''));

if ($query === '') {
    echo json_encode([]);
    exit;
}

// Keep the query to a sane length to avoid abuse.
if (function_exists('mb_substr')) {
    $query = mb_substr($query, 0, 200);
} else {
    $query = substr($query, 0, 200);
}

$contactEmail = defined('M365_PROVISIONING_SUPPORT_EMAIL')
    ? M365_PROVISIONING_SUPPORT_EMAIL
    : 'support@irvalscouts.org.uk';

$params = http_build_query([
    'format' => 'json',
    'addressdetails' => '1',
    'limit' => '5',
    'countrycodes' => 'gb',
    // Nominatim's usage policy asks heavy / server-side users to identify
    // themselves with an email so they are rate-limited gently rather than
    // blocked outright.
    'email' => $contactEmail,
    'q' => $query,
]);

$endpoint = 'https://nominatim.openstreetmap.org/search?' . $params;

// Nominatim's usage policy requires a valid, identifying User-Agent referencing
// the application and a contact address.
$userAgent = sprintf(
    '%s/1.0 (%s; %s)',
    'IrwellValleyDistrictCalendar',
    APP_URL,
    $contactEmail
);

$body = null;
$httpCode = 0;
$transportError = '';

if (function_exists('curl_init')) {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: en-GB,en;q=0.9',
            'Referer: ' . APP_URL,
        ],
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($body === false) {
        $transportError = curl_error($ch);
        $body = null;
    }

    curl_close($ch);
} else {
    // Fallback when cURL is unavailable.
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true, // so we can read the body on 4xx/5xx too
            'header' => implode("\r\n", [
                'Accept: application/json',
                'Accept-Language: en-GB,en;q=0.9',
                'User-Agent: ' . $userAgent,
                'Referer: ' . APP_URL,
            ]),
        ],
    ]);

    $body = @file_get_contents($endpoint, false, $context);

    if ($body === false) {
        $transportError = 'file_get_contents failed';
        $body = null;
    } else {
        $httpCode = 200;

        // Determine the status line from the stream wrapper's response headers.
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);

        if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', (string) $responseHeaders[0], $m)) {
            $httpCode = (int) $m[1];
        }
    }
}

if ($body === null || $httpCode < 200 || $httpCode >= 300) {
    // Record the real reason server-side so this is diagnosable, without
    // leaking upstream detail to the browser.
    error_log(sprintf(
        'geocode-search: upstream failure (http=%d, err=%s, q=%s)',
        $httpCode,
        $transportError !== '' ? $transportError : 'none',
        $query
    ));

    // 429 = Nominatim rate limiting. Tell the client specifically so it can
    // suggest waiting a moment rather than reporting a generic outage.
    if ($httpCode === 429 || $httpCode === 403) {
        http_response_code(429);
        echo json_encode(['error' => 'rate_limited']);
        exit;
    }

    http_response_code(502);
    echo json_encode(['error' => 'geocode_unavailable']);
    exit;
}

// Validate it is JSON before passing it through.
$decoded = json_decode($body, true);

if (!is_array($decoded)) {
    error_log('geocode-search: upstream returned non-JSON body for q=' . $query);
    http_response_code(502);
    echo json_encode(['error' => 'geocode_unavailable']);
    exit;
}

echo json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
