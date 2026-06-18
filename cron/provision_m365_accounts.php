<?php

declare(strict_types=1);

/**
 * Cron: provision Microsoft 365 accounts from m365_account_requests.
 *
 * Recommended cPanel schedule:
 *
 * Every 5 minutes:
 * /usr/local/bin/php /home/YOUR_CPANEL_USER/app.irvalscouts.org.uk/cron/provision-m365-users.php >> /home/YOUR_CPANEL_USER/m365-provisioning.log 2>&1
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (is_file(__DIR__ . '/../app/group-manager-helpers.php')) {
    require_once __DIR__ . '/../app/group-manager-helpers.php';
}

$pdo = db();

/**
 * -------------------------------------------------------------------------
 * Output helpers
 * -------------------------------------------------------------------------
 */

function m365_stdout(string $message): void
{
    if (!str_ends_with($message, "\n")) {
        $message .= "\n";
    }

    if (PHP_SAPI === 'cli' && defined('STDOUT')) {
        /** @var resource $stream */
        $stream = constant('STDOUT');
        fwrite($stream, $message);
        return;
    }

    echo $message;
}

function m365_stderr(string $message): void
{
    if (!str_ends_with($message, "\n")) {
        $message .= "\n";
    }

    if (PHP_SAPI === 'cli' && defined('STDERR')) {
        /** @var resource $stream */
        $stream = constant('STDERR');
        fwrite($stream, $message);
        return;
    }

    error_log(trim($message));
}

/**
 * -------------------------------------------------------------------------
 * Config helpers
 * -------------------------------------------------------------------------
 */

function m365_config_value(string $key, ?string $fallbackKey = null, string $default = ''): string
{
    if (defined($key)) {
        $value = (string) constant($key);

        if ($value !== '') {
            return $value;
        }
    }

    if (function_exists('app_config')) {
        $value = (string) app_config($key, '');

        if ($value !== '') {
            return $value;
        }
    }

    if ($fallbackKey !== null && defined($fallbackKey)) {
        $value = (string) constant($fallbackKey);

        if ($value !== '') {
            return $value;
        }
    }

    if ($fallbackKey !== null && function_exists('app_config')) {
        $value = (string) app_config($fallbackKey, '');

        if ($value !== '') {
            return $value;
        }
    }

    return $default;
}

function m365_config_bool(string $key, bool $default = false): bool
{
    $value = m365_config_value($key, null, $default ? 'true' : 'false');
    $value = strtolower(trim($value));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function m365_config_int(string $key, int $default): int
{
    $value = m365_config_value($key, null, (string) $default);

    if (!is_numeric($value)) {
        return $default;
    }

    return max(0, (int) $value);
}

/**
 * -------------------------------------------------------------------------
 * Database helpers
 * -------------------------------------------------------------------------
 */

function m365_table_exists(string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute(['table_name' => $table]);

        return $cache[$table] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function m365_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function m365_table_columns(string $table): array
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute(['table_name' => $table]);

        return $cache[$table] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

function m365_mark_request(int $requestId, array $values): void
{
    $columns = m365_table_columns('m365_account_requests');
    $update = [];

    foreach ($values as $column => $value) {
        if (in_array($column, $columns, true)) {
            $update[$column] = $value;
        }
    }

    if (!$update) {
        return;
    }

    $sets = [];

    foreach (array_keys($update) as $column) {
        $sets[] = '`' . str_replace('`', '``', $column) . '` = :' . $column;
    }

    $update['_id'] = $requestId;

    $stmt = db()->prepare("
        UPDATE m365_account_requests
        SET " . implode(', ', $sets) . "
        WHERE id = :_id
    ");
    $stmt->execute($update);
}

function m365_try_lock_request(int $requestId): bool
{
    $stmt = db()->prepare("
        UPDATE m365_account_requests
        SET
            provision_status = 'processing',
            provision_started_at = NOW(),
            provision_attempts = provision_attempts + 1,
            provision_error = NULL
        WHERE id = :id
          AND provision_status IN ('pending', 'failed')
          AND status IN ('requested', 'approved')
    ");

    $stmt->execute(['id' => $requestId]);

    return $stmt->rowCount() === 1;
}

function m365_fetch_pending_requests(int $limit): array
{
    $stmt = db()->prepare("
        SELECT
            r.*,

            p.full_name,
            p.primary_email,
            p.phone,

            requested_by.full_name AS requested_by_name,
            requested_by.primary_email AS requested_by_email

        FROM m365_account_requests r

        JOIN people p
          ON p.id = r.person_id

        LEFT JOIN people requested_by
          ON requested_by.id = r.requested_by_person_id

        WHERE r.status IN ('requested', 'approved')
          AND r.provision_status IN ('pending', 'failed')
          AND r.requested_upn IS NOT NULL
          AND r.requested_upn <> ''
          AND r.provision_attempts < 5

        ORDER BY
            CASE r.status
                WHEN 'approved' THEN 0
                ELSE 1
            END,
            r.created_at ASC,
            r.id ASC

        LIMIT :limit
    ");

    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * -------------------------------------------------------------------------
 * String/email helpers
 * -------------------------------------------------------------------------
 */

function m365_escape_html(string $value): string
{
    if (function_exists('e')) {
        return e($value);
    }

    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function m365_email_domain(string $email): string
{
    $email = strtolower(trim($email));

    if (!str_contains($email, '@')) {
        return '';
    }

    $parts = explode('@', $email);

    return strtolower(trim((string) end($parts)));
}

function m365_is_allowed_upn(string $email): bool
{
    $domain = strtolower(ltrim(trim(m365_config_value('M365_DEFAULT_DOMAIN', null, 'irvalscouts.org.uk')), '@'));

    return $domain !== '' && m365_email_domain($email) === $domain;
}

function m365_make_mail_nickname(string $upn): string
{
    $local = strtolower(trim(strstr($upn, '@', true) ?: $upn));
    $local = preg_replace('/[^a-z0-9._-]+/', '', $local) ?? '';
    $local = trim($local, '._-');

    return $local !== '' ? substr($local, 0, 64) : 'user' . random_int(1000, 9999);
}

function m365_first_name(string $displayName): string
{
    $displayName = trim($displayName);

    if ($displayName === '') {
        return '';
    }

    $parts = preg_split('/\s+/', $displayName);

    return trim((string) ($parts[0] ?? ''));
}

function m365_generate_temp_password(): string
{
    /*
     * Avoid ambiguous characters and include mixed character classes.
     */
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $symbols = '!#%*-_=+?';

    $chars = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
        $symbols[random_int(0, strlen($symbols) - 1)],
    ];

    $pool = $upper . $lower . $digits . $symbols;

    while (count($chars) < 16) {
        $chars[] = $pool[random_int(0, strlen($pool) - 1)];
    }

    shuffle($chars);

    return implode('', $chars);
}

function m365_app_url(string $path): string
{
    if (function_exists('gm_absolute_url')) {
        return gm_absolute_url($path);
    }

    $base = rtrim(m365_config_value('APP_URL', null, ''), '/');

    if ($base === '') {
        return $path;
    }

    return $base . '/' . ltrim($path, '/');
}

/**
 * -------------------------------------------------------------------------
 * Microsoft Graph helpers
 * -------------------------------------------------------------------------
 */

function m365_json_decode_response(string $body): array
{
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : [];
}

function m365_graph_client(): \GuzzleHttp\Client
{
    return new \GuzzleHttp\Client([
        'timeout' => 30,
        'http_errors' => false,
    ]);
}

function m365_graph_token(\GuzzleHttp\Client $client): string
{
    $tenantId = m365_config_value('M365_GRAPH_TENANT_ID', 'MS_TENANT_ID');
    $clientId = m365_config_value('M365_GRAPH_CLIENT_ID', 'MS_CLIENT_ID');
    $clientSecret = m365_config_value('M365_GRAPH_CLIENT_SECRET', 'MS_CLIENT_SECRET');

    if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
        throw new RuntimeException('Microsoft Graph provisioning credentials are not configured.');
    }

    $response = $client->post('https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token', [
        'form_params' => [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ],
    ]);

    $status = $response->getStatusCode();
    $payload = m365_json_decode_response((string) $response->getBody());

    if ($status < 200 || $status >= 300 || empty($payload['access_token'])) {
        $message = $payload['error_description'] ?? $payload['error'] ?? 'Unable to obtain Microsoft Graph token.';
        throw new RuntimeException((string) $message);
    }

    return (string) $payload['access_token'];
}

function m365_graph_request(
    \GuzzleHttp\Client $client,
    string $method,
    string $url,
    string $token,
    ?array $json = null
): array {
    $options = [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ],
    ];

    if ($json !== null) {
        $options['headers']['Content-Type'] = 'application/json';
        $options['json'] = $json;
    }

    $response = $client->request($method, $url, $options);
    $status = $response->getStatusCode();
    $body = (string) $response->getBody();
    $payload = $body !== '' ? m365_json_decode_response($body) : [];

    return [
        'status' => $status,
        'payload' => $payload,
        'body' => $body,
    ];
}

function m365_graph_get_user_by_upn(\GuzzleHttp\Client $client, string $token, string $upn): ?array
{
    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($upn)
        . '?$select=id,displayName,userPrincipalName,mail,accountEnabled';

    $result = m365_graph_request($client, 'GET', $url, $token);

    if ((int) $result['status'] === 404) {
        return null;
    }

    if ((int) $result['status'] < 200 || (int) $result['status'] >= 300) {
        $message = $result['payload']['error']['message'] ?? 'Unable to check whether the Microsoft 365 account already exists.';
        throw new RuntimeException((string) $message);
    }

    return $result['payload'];
}

function m365_graph_create_user(
    \GuzzleHttp\Client $client,
    string $token,
    string $displayName,
    string $upn,
    string $temporaryPassword
): array {
    $usageLocation = m365_config_value('M365_USAGE_LOCATION', null, 'GB');

    $payload = [
        'accountEnabled' => true,
        'displayName' => $displayName,
        'mailNickname' => m365_make_mail_nickname($upn),
        'userPrincipalName' => $upn,
        'usageLocation' => $usageLocation,
        'passwordProfile' => [
            'forceChangePasswordNextSignIn' => true,
            'password' => $temporaryPassword,
        ],
    ];

    $result = m365_graph_request(
        $client,
        'POST',
        'https://graph.microsoft.com/v1.0/users',
        $token,
        $payload
    );

    if ((int) $result['status'] < 200 || (int) $result['status'] >= 300) {
        $message = $result['payload']['error']['message'] ?? 'Microsoft Graph could not create the user.';
        throw new RuntimeException((string) $message);
    }

    return $result['payload'];
}

function m365_graph_assign_license(\GuzzleHttp\Client $client, string $token, string $userId): void
{
    $skuId = trim(m365_config_value('M365_DEFAULT_SKU_ID', null, ''));

    if ($skuId === '') {
        /*
         * Allow account creation without a licence if the SKU is not configured yet.
         * The support/admin email will still say the account was created.
         */
        return;
    }

    $result = m365_graph_request(
        $client,
        'POST',
        'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userId) . '/assignLicense',
        $token,
        [
            'addLicenses' => [
                [
                    'skuId' => $skuId,
                ],
            ],
            'removeLicenses' => [],
        ]
    );

    if ((int) $result['status'] < 200 || (int) $result['status'] >= 300) {
        $message = $result['payload']['error']['message'] ?? 'Microsoft Graph could not assign the Microsoft 365 licence.';
        throw new RuntimeException((string) $message);
    }
}
m365_stdout('PHP_SAPI = ' . PHP_SAPI);
m365_stdout('Script path = ' . __FILE__);
m365_stdout('M365_PROVISIONING_ENABLED = ' . m365_config_value('M365_PROVISIONING_ENABLED', null, 'NOT SET'));
m365_stdout('M365_PROVISIONING_MODE = ' . m365_config_value('M365_PROVISIONING_MODE', null, 'NOT SET'));
exit;
/**
 * -------------------------------------------------------------------------
 * Email queue helpers
 * -------------------------------------------------------------------------
 */

function m365_insert_email_queue(array $values): bool
{
    if (!m365_table_exists('email_queue')) {
        return false;
    }

    $columns = m365_table_columns('email_queue');

    if (!$columns) {
        return false;
    }

    $insert = [];

    foreach ($values as $column => $value) {
        if (in_array($column, $columns, true)) {
            $insert[$column] = $value;
        }
    }

    if (!$insert || !isset($insert['to_email'], $insert['subject'])) {
        return false;
    }

    if (in_array('status', $columns, true) && !array_key_exists('status', $insert)) {
        $insert['status'] = 'pending';
    }

    if (in_array('created_at', $columns, true) && !array_key_exists('created_at', $insert)) {
        $insert['created_at'] = date('Y-m-d H:i:s');
    }

    $fieldSql = implode(', ', array_map(
        static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
        array_keys($insert)
    ));

    $placeholderSql = implode(', ', array_map(
        static fn(string $column): string => ':' . $column,
        array_keys($insert)
    ));

    $stmt = db()->prepare("
        INSERT INTO email_queue ({$fieldSql})
        VALUES ({$placeholderSql})
    ");

    return $stmt->execute($insert);
}

function m365_queue_email(
    ?int $personId,
    string $toEmail,
    string $toName,
    string $subject,
    string $plainBody,
    string $notificationType,
    ?int $requestId = null
): bool {
    $toEmail = strtolower(trim($toEmail));

    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $htmlBody = nl2br(m365_escape_html($plainBody));

    return m365_insert_email_queue([
        'person_id' => $personId,
        'recipient_person_id' => $personId,
        'to_email' => $toEmail,
        'to_name' => $toName !== '' ? $toName : $toEmail,
        'subject' => $subject,
        'body' => $plainBody,
        'body_text' => $plainBody,
        'body_html' => $htmlBody,
        'notification_type' => $notificationType,
        'related_entity_type' => 'm365_account_request',
        'related_entity_id' => $requestId,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function m365_support_notify(string $subject, string $body, ?int $requestId = null): void
{
    $supportEmail = m365_config_value('M365_PROVISIONING_SUPPORT_EMAIL', null, 'support@irvalscouts.org.uk');

    if ($supportEmail === '' || !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    m365_queue_email(
        null,
        $supportEmail,
        'Irwell Valley Support',
        $subject,
        $body,
        'm365_provisioning_support',
        $requestId
    );
}

/**
 * -------------------------------------------------------------------------
 * Person/user-account update helpers
 * -------------------------------------------------------------------------
 */

function m365_update_person_after_provisioning(int $personId, string $graphUserId, string $upn): void
{
    if (m365_table_exists('people')) {
        $columns = m365_table_columns('people');
        $values = [];

        foreach ([
            'microsoft_user_id' => $graphUserId,
            'microsoft_user_principal_name' => $upn,
            'm365_user_id' => $graphUserId,
            'm365_user_principal_name' => $upn,
            'district_email' => $upn,
            'updated_at' => date('Y-m-d H:i:s'),
        ] as $column => $value) {
            if (in_array($column, $columns, true)) {
                $values[$column] = $value;
            }
        }

        if ($values) {
            $sets = [];

            foreach (array_keys($values) as $column) {
                $sets[] = '`' . str_replace('`', '``', $column) . '` = :' . $column;
            }

            $values['_id'] = $personId;

            $stmt = db()->prepare("
                UPDATE people
                SET " . implode(', ', $sets) . "
                WHERE id = :_id
            ");
            $stmt->execute($values);
        }
    }

    /*
     * If the app has user_accounts, pre-link Microsoft sign-in to this person.
     * This helps prevent duplicate person records on first Microsoft login.
     */
    if (!m365_table_exists('user_accounts')) {
        return;
    }

    $columns = m365_table_columns('user_accounts');

    if (!in_array('person_id', $columns, true) || !in_array('provider', $columns, true)) {
        return;
    }

    $subjectColumn = null;

    foreach (['subject', 'provider_subject', 'provider_user_id', 'external_id'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $subjectColumn = $candidate;
            break;
        }
    }

    if ($subjectColumn === null) {
        return;
    }

    try {
        $safeSubjectColumn = '`' . str_replace('`', '``', $subjectColumn) . '`';

        $stmt = db()->prepare("
            SELECT id
            FROM user_accounts
            WHERE provider = 'microsoft'
              AND {$safeSubjectColumn} = :subject
            LIMIT 1
        ");
        $stmt->execute(['subject' => $graphUserId]);

        if ($stmt->fetchColumn()) {
            return;
        }

        $insert = [
            'person_id' => $personId,
            'provider' => 'microsoft',
            $subjectColumn => $graphUserId,
        ];

        foreach (['email', 'provider_email', 'username'] as $emailColumn) {
            if (in_array($emailColumn, $columns, true)) {
                $insert[$emailColumn] = $upn;
                break;
            }
        }

        if (in_array('created_at', $columns, true)) {
            $insert['created_at'] = date('Y-m-d H:i:s');
        }

        if (in_array('updated_at', $columns, true)) {
            $insert['updated_at'] = date('Y-m-d H:i:s');
        }

        $fieldSql = implode(', ', array_map(
            static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
            array_keys($insert)
        ));

        $placeholderSql = implode(', ', array_map(
            static fn(string $column): string => ':' . $column,
            array_keys($insert)
        ));

        $stmt = db()->prepare("
            INSERT INTO user_accounts ({$fieldSql})
            VALUES ({$placeholderSql})
        ");
        $stmt->execute($insert);
    } catch (Throwable $e) {
        /*
         * Do not fail provisioning if pre-linking is not possible.
         */
    }
}

/**
 * -------------------------------------------------------------------------
 * Email body helpers
 * -------------------------------------------------------------------------
 */

function m365_onboarding_body(string $firstName, string $displayName, string $upn, string $temporaryPassword): string
{
    $ssoUrl = m365_app_url('/auth/microsoft-start.php');
    $dashboardUrl = m365_app_url('/index.php');
    $calendarUrl = m365_app_url('/dc/');

    $helloName = $firstName !== '' ? $firstName : $displayName;

    return "Hello {$helloName},\n\n"
        . "Your Irwell Valley District Microsoft 365 account has been created.\n\n"
        . "Username:\n{$upn}\n\n"
        . "Temporary password:\n{$temporaryPassword}\n\n"
        . "You will be asked to change this password when you first sign in.\n\n"
        . "Sign in with Microsoft:\n{$ssoUrl}\n\n"
        . "Dashboard:\n{$dashboardUrl}\n\n"
        . "District Calendar:\n{$calendarUrl}\n\n"
        . "This account gives you access to District Microsoft 365 services connected to your role, including District email where enabled.\n\n"
        . "Irwell Valley Scout District";
}

function m365_requester_created_body(string $requesterName, string $volunteerName, string $upn): string
{
    $helloName = trim($requesterName) !== '' ? trim($requesterName) : 'there';

    return "Hello {$helloName},\n\n"
        . "The Microsoft 365 account you requested has now been created.\n\n"
        . "Volunteer:\n{$volunteerName}\n\n"
        . "Microsoft 365 username:\n{$upn}\n\n"
        . "The volunteer has been sent their username and temporary password. They will be asked to change the password when they first sign in.\n\n"
        . "Irwell Valley Scout District";
}

function m365_requester_existing_body(string $requesterName, string $volunteerName, string $upn): string
{
    $helloName = trim($requesterName) !== '' ? trim($requesterName) : 'there';

    return "Hello {$helloName},\n\n"
        . "The Microsoft 365 account you requested already exists, so no duplicate account was created.\n\n"
        . "Volunteer:\n{$volunteerName}\n\n"
        . "Existing Microsoft 365 username:\n{$upn}\n\n"
        . "The volunteer has been sent sign-in instructions.\n\n"
        . "Irwell Valley Scout District";
}

function m365_queue_requester_email(array $request, string $subject, string $body, string $notificationType): void
{
    $requesterEmail = strtolower(trim((string) ($request['requested_by_email'] ?? '')));
    $requesterName = trim((string) ($request['requested_by_name'] ?? ''));

    if ($requesterEmail === '' || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    m365_queue_email(
        isset($request['requested_by_person_id']) ? (int) $request['requested_by_person_id'] : null,
        $requesterEmail,
        $requesterName !== '' ? $requesterName : $requesterEmail,
        $subject,
        $body,
        $notificationType,
        (int) $request['id']
    );
}

/**
 * -------------------------------------------------------------------------
 * Main provisioning
 * -------------------------------------------------------------------------
 */

function m365_process_request(array $request, \GuzzleHttp\Client $client, string $token): void
{
    $requestId = (int) $request['id'];
    $personId = (int) $request['person_id'];

    $displayName = trim((string) ($request['full_name'] ?? ''));
    $personalEmail = strtolower(trim((string) ($request['primary_email'] ?? '')));
    $upn = strtolower(trim((string) ($request['requested_upn'] ?? '')));

    if ($displayName === '') {
        $displayName = $upn;
    }

    $firstName = m365_first_name($displayName);

    if ($upn === '' || !filter_var($upn, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Requested Microsoft 365 username is not a valid email address.');
    }

    if (!m365_is_allowed_upn($upn)) {
        throw new RuntimeException('Requested Microsoft 365 username is not on the allowed District domain.');
    }

    if ($personalEmail === '' || !filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The person does not have a valid primary email address for onboarding.');
    }

    $temporaryPassword = m365_generate_temp_password();

    $existingUser = m365_graph_get_user_by_upn($client, $token, $upn);

    if ($existingUser) {
        $graphUserId = (string) ($existingUser['id'] ?? '');
        $graphUpn = strtolower((string) ($existingUser['userPrincipalName'] ?? $upn));

        if ($graphUserId === '') {
            throw new RuntimeException('Microsoft Graph found the user but did not return an ID.');
        }

        m365_update_person_after_provisioning($personId, $graphUserId, $graphUpn);

        m365_queue_email(
            $personId,
            $personalEmail,
            $displayName,
            'Your Irwell Valley District Microsoft 365 account',
            "Hello {$firstName},\n\n"
            . "Your Irwell Valley District Microsoft 365 account is already available.\n\n"
            . "Username:\n{$graphUpn}\n\n"
            . "Please use the Microsoft sign-in button to access the District Dashboard and District Calendar.\n\n"
            . "Sign in with Microsoft:\n" . m365_app_url('/auth/microsoft-start.php') . "\n\n"
            . "Irwell Valley Scout District",
            'm365_account_already_exists',
            $requestId
        );

        m365_queue_requester_email(
            $request,
            'Microsoft 365 account already exists',
            m365_requester_existing_body(
                (string) ($request['requested_by_name'] ?? ''),
                $displayName,
                $graphUpn
            ),
            'm365_account_requester_existing'
        );

        m365_mark_request($requestId, [
            'status' => 'provisioned',
            'provision_status' => 'already_exists',
            'graph_user_id' => $graphUserId,
            'graph_user_principal_name' => $graphUpn,
            'provisioned_at' => date('Y-m-d H:i:s'),
            'onboarding_email_queued_at' => date('Y-m-d H:i:s'),
            'provision_error' => null,
        ]);

        return;
    }

    $createdUser = m365_graph_create_user($client, $token, $displayName, $upn, $temporaryPassword);
    $graphUserId = (string) ($createdUser['id'] ?? '');

    if ($graphUserId === '') {
        throw new RuntimeException('Microsoft Graph created the user but did not return an ID.');
    }

    m365_graph_assign_license($client, $token, $graphUserId);
    m365_update_person_after_provisioning($personId, $graphUserId, $upn);

    m365_queue_email(
        $personId,
        $personalEmail,
        $displayName,
        'Your Irwell Valley District Microsoft 365 account is ready',
        m365_onboarding_body($firstName, $displayName, $upn, $temporaryPassword),
        'm365_account_created',
        $requestId
    );

    m365_queue_requester_email(
        $request,
        'Microsoft 365 account created',
        m365_requester_created_body(
            (string) ($request['requested_by_name'] ?? ''),
            $displayName,
            $upn
        ),
        'm365_account_requester_created'
    );

    m365_mark_request($requestId, [
        'status' => 'provisioned',
        'provision_status' => 'provisioned',
        'graph_user_id' => $graphUserId,
        'graph_user_principal_name' => $upn,
        'provisioned_at' => date('Y-m-d H:i:s'),
        'onboarding_email_queued_at' => date('Y-m-d H:i:s'),
        'temporary_password_sent_at' => date('Y-m-d H:i:s'),
        'provision_error' => null,
    ]);
}

/**
 * -------------------------------------------------------------------------
 * Cron runner
 * -------------------------------------------------------------------------
 */

if (!m365_config_bool('M365_PROVISIONING_ENABLED', false)) {
    m365_stdout('Microsoft 365 provisioning is disabled.');
    exit(0);
}

if (m365_config_value('M365_PROVISIONING_MODE', null, 'cron') !== 'cron') {
    m365_stdout('Microsoft 365 provisioning mode is not cron.');
    exit(0);
}

if (!m365_table_exists('m365_account_requests')) {
    m365_stderr('m365_account_requests table does not exist.');
    exit(1);
}

$limit = m365_config_int('M365_PROVISIONING_BATCH_LIMIT', 5);

if ($limit < 1) {
    m365_stdout('Batch limit is zero. Nothing to do.');
    exit(0);
}

$requests = m365_fetch_pending_requests($limit);

if (!$requests) {
    m365_stdout('No pending Microsoft 365 account requests.');
    exit(0);
}

try {
    $client = m365_graph_client();
    $token = m365_graph_token($client);
} catch (Throwable $e) {
    $message = 'Microsoft 365 provisioning could not start: ' . $e->getMessage();

    m365_stderr($message);

    m365_support_notify(
        'Microsoft 365 provisioning failed to start',
        $message
    );

    exit(1);
}

$processed = 0;
$failed = 0;

foreach ($requests as $request) {
    $requestId = (int) $request['id'];

    if (!m365_try_lock_request($requestId)) {
        continue;
    }

    try {
        m365_process_request($request, $client, $token);
        $processed++;

        m365_stdout("Provisioned request {$requestId}.");
    } catch (Throwable $e) {
        $failed++;
        $message = $e->getMessage() ?: 'Unknown provisioning error.';

        m365_mark_request($requestId, [
            'provision_status' => 'failed',
            'provision_error' => $message,
        ]);

        $personName = trim((string) ($request['full_name'] ?? ''));
        $upn = trim((string) ($request['requested_upn'] ?? ''));

        m365_support_notify(
            'Microsoft 365 account provisioning failed',
            "A Microsoft 365 account request failed.\n\n"
            . "Request ID: {$requestId}\n"
            . "Person: {$personName}\n"
            . "Requested username: {$upn}\n\n"
            . "Error:\n{$message}\n",
            $requestId
        );

        if (m365_column_exists('m365_account_requests', 'support_notified_at')) {
            m365_mark_request($requestId, [
                'support_notified_at' => date('Y-m-d H:i:s'),
            ]);
        }

        m365_stderr("Failed request {$requestId}: {$message}");
    }
}

m365_stdout("Done. Provisioned: {$processed}. Failed: {$failed}.");
exit($failed > 0 ? 1 : 0);
