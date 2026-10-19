<?php
$sql = file_get_contents(__DIR__ . '/database/migrations/005_help_articles.sql');

// Match JSON array bodies: single-quoted SQL literals that begin with [ and end with ].
// SQL escapes an apostrophe by doubling it ('') so the literal never contains a lone '.
if (!preg_match_all("/'(\[(?:[^']|'')*\])'/", $sql, $m)) {
    fwrite(STDERR, "No JSON bodies matched\n");
    exit(1);
}

$ok = 0;
$fail = 0;
foreach ($m[1] as $i => $raw) {
    // Un-double the SQL-escaped apostrophes to recover the real JSON text.
    $json = str_replace("''", "'", $raw);
    $decoded = json_decode($json, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $ok++;
    } else {
        $fail++;
        echo "BODY #" . ($i + 1) . " INVALID: " . json_last_error_msg() . "\n";
    }
}

echo "Total bodies: " . count($m[1]) . " | Valid: $ok | Invalid: $fail\n";
exit($fail === 0 ? 0 : 1);
