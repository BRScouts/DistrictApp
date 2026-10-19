<?php

declare(strict_types=1);

/**
 * Global search autocomplete endpoint (JSON, GET, same-origin).
 *
 * Powers the header search dropdown. Returns up to 3 results per
 * category (Module, Calendar, Risk Assessment, Person) so the user
 * sees a quick preview, with a link to the full results page.
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/search-helpers.php';

require_login();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$query = trim((string) ($_GET['q'] ?? ''));

if (function_exists('mb_substr')) {
    $query = mb_substr($query, 0, 200);
} else {
    $query = substr($query, 0, 200);
}

if ($query === '') {
    echo json_encode(['query' => '', 'results' => [], 'counts' => []], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$search = search_run($query, [], 3);

echo json_encode([
    'query'   => $query,
    'results' => $search['results'],
    'counts'  => $search['counts'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
