<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_auth();

$pdo = db();

$currentGroup = auth_group();
$currentAdmin = auth_admin();
$isAdminOrReviewer = is_reviewer_or_admin();

$flash = '';
$error = '';
$similarDraftWarning = null;
$pendingSaveAction = 'submit';

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('require_csrf')) {
    function require_csrf(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $postedToken = (string)($_POST['csrf_token'] ?? '');
        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');

        if ($postedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
            http_response_code(403);
            exit('Invalid form token. Please refresh the page and try again.');
        }
    }
}

/*
|--------------------------------------------------------------------------
| Small helpers
|--------------------------------------------------------------------------
*/
function post_string(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function route_add_event(): string
{
    return defined('ROUTE_ADD_EVENT') ? (string)ROUTE_ADD_EVENT : 'add-event.php';
}

function normalise_contact_section(string $section): string
{
    $allowed = [
        'Squirrels',
        'Beavers',
        'Cubs',
        'Scouts',
        'Explorers',
        'Network',
        'Group',
        'District',
        'Other',
    ];

    return in_array($section, $allowed, true) ? $section : '';
}

function normalise_contact_role(string $role): string
{
    $allowed = [
        'Team Member',
        'Team Leader',
        'Group Lead Volunteer',
        'Other',
    ];

    return in_array($role, $allowed, true) ? $role : '';
}

/*
|--------------------------------------------------------------------------
| Event / group resolution
|--------------------------------------------------------------------------
*/
$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$groupId = 0;
$editingEvent = null;

if ($eventId > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM events
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $eventId]);
    $candidateEvent = $stmt->fetch();

    if (!$candidateEvent) {
        redirect(ROUTE_403);
    }

    if ($currentGroup && (int)$candidateEvent['group_id'] !== (int)$currentGroup['group_id']) {
        redirect(ROUTE_403);
    }

    if (!$currentGroup && !$isAdminOrReviewer) {
        redirect(ROUTE_403);
    }

    $editingEvent = $candidateEvent;
    $groupId = (int)$candidateEvent['group_id'];
}

if ($groupId <= 0) {
    if ($currentGroup) {
        $groupId = (int)$currentGroup['group_id'];
    } elseif ($isAdminOrReviewer) {
        $groupId = (int)($_GET['group_id'] ?? $_POST['group_id'] ?? 0);
    }
}

if ($groupId <= 0 && $isAdminOrReviewer) {
    $stmt = $pdo->query("SELECT id FROM groups WHERE is_active = 1 ORDER BY group_name ASC LIMIT 1");
    $firstGroupId = $stmt->fetchColumn();
    $groupId = $firstGroupId ? (int)$firstGroupId : 0;
}

if ($groupId <= 0) {
    redirect(ROUTE_403);
}

/*
|--------------------------------------------------------------------------
| Groups for admin/reviewer dropdown
|--------------------------------------------------------------------------
*/
$allGroups = [];

if ($isAdminOrReviewer) {
    $stmt = $pdo->query("
        SELECT id, group_name
        FROM groups
        WHERE is_active = 1
        ORDER BY group_name ASC
    ");
    $allGroups = $stmt->fetchAll();
}

/*
|--------------------------------------------------------------------------
| Load group
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT id, group_name, lead_volunteer_name, lead_volunteer_email, notify_lead_on_event_created
    FROM groups
    WHERE id = :id AND is_active = 1
    LIMIT 1
");
$stmt->execute(['id' => $groupId]);
$group = $stmt->fetch();

if (!$group) {
    redirect(ROUTE_403);
}

/*
|--------------------------------------------------------------------------
| Load contacts
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT id, full_name, email, section, role
    FROM group_contacts
    WHERE group_id = :group_id
      AND is_active = 1
    ORDER BY full_name ASC, email ASC
");
$stmt->execute(['group_id' => $groupId]);
$contacts = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Existing risk assessments visible to this group
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        ra.id,
        ra.group_id,
        ra.title,
        ra.description,
        ra.activity_type,
        ra.location_summary,
        ra.visibility,
        ra.original_filename,
        ra.file_extension,
        ra.updated_at,
        ra.uploaded_at,
        g.group_name
    FROM risk_assessments ra
    INNER JOIN groups g ON g.id = ra.group_id
    WHERE ra.is_active = 1
      AND ra.admin_review_status = 'available'
      AND (
            ra.group_id = :group_id
            OR ra.visibility = 'district'
          )
    ORDER BY ra.updated_at DESC, ra.original_filename ASC, ra.title ASC
");
$stmt->execute(['group_id' => $groupId]);
$availableRiskAssessments = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Display / workflow helpers
|--------------------------------------------------------------------------
*/
function ra_recent_enough(array $ra): bool
{
    $cutoff = strtotime('-90 days');

    return strtotime((string)$ra['uploaded_at']) >= $cutoff
        || strtotime((string)$ra['updated_at']) >= $cutoff;
}

function ra_can_preview_inline(array $ra): bool
{
    return strtolower((string)($ra['file_extension'] ?? '')) === 'pdf';
}

function ra_display_name(array $ra): string
{
    $filename = trim((string)($ra['original_filename'] ?? ''));

    if ($filename !== '') {
        return $filename;
    }

    $title = trim((string)($ra['title'] ?? ''));

    return $title !== '' ? $title : 'Risk assessment';
}

function event_status_label(string $status): string
{
    return match ($status) {
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'under_review' => 'Submitted',
        'approved' => 'Approved',
        'changes_requested' => 'Declined',
        'rejected' => 'Declined',
        'cancelled' => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function workflow_step_class(string $currentStatus, string $step): string
{
    $rank = match ($currentStatus) {
        'draft' => 1,
        'submitted', 'under_review' => 2,
        'approved', 'changes_requested', 'rejected', 'cancelled' => 3,
        default => 1,
    };

    $stepRank = match ($step) {
        'draft' => 1,
        'submitted' => 2,
        'approved' => 3,
        'declined' => 3,
        default => 1,
    };

    if ($step === 'approved' && $currentStatus === 'approved') {
        return 'active success';
    }

    if ($step === 'declined' && in_array($currentStatus, ['changes_requested', 'rejected', 'cancelled'], true)) {
        return 'active danger';
    }

    if ($step === 'submitted' && in_array($currentStatus, ['submitted', 'under_review'], true)) {
        return 'active';
    }

    if ($step === 'draft' && $currentStatus === 'draft') {
        return 'active';
    }

    return $rank > $stepRank ? 'complete' : '';
}

/*
|--------------------------------------------------------------------------
| Audit
|--------------------------------------------------------------------------
*/
function audit_event(PDO $pdo, int $eventId, string $action, array $details = []): void
{
    $admin = auth_admin();
    $group = auth_group();

    $stmt = $pdo->prepare("
        INSERT INTO audit_log (
            actor_type,
            admin_user_id,
            group_id,
            entity_type,
            entity_id,
            action,
            details,
            created_at
        ) VALUES (
            :actor_type,
            :admin_user_id,
            :group_id,
            'event',
            :entity_id,
            :action,
            :details,
            NOW()
        )
    ");

    $stmt->execute([
        'actor_type' => $admin ? 'admin' : 'group_link',
        'admin_user_id' => $admin ? (int)$admin['admin_user_id'] : null,
        'group_id' => $group ? (int)$group['group_id'] : null,
        'entity_id' => $eventId,
        'action' => $action,
        'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

/*
|--------------------------------------------------------------------------
| Contact persistence
|--------------------------------------------------------------------------
*/
function save_group_contact(
    PDO $pdo,
    int $groupId,
    ?int $contactId,
    string $name,
    string $email,
    string $section,
    string $role
): ?int {
    if ($name === '' || $email === '') {
        return null;
    }

    $section = normalise_contact_section($section);
    $role = normalise_contact_role($role);

    /*
     * If the user selected an existing contact, update that contact.
     * If they chose "not here", contact_id will be empty and a new contact is created/reused.
     */
    if ($contactId && $contactId > 0) {
        $check = $pdo->prepare("
            SELECT id
            FROM group_contacts
            WHERE id = :id
              AND group_id = :group_id
            LIMIT 1
        ");
        $check->execute([
            'id' => $contactId,
            'group_id' => $groupId,
        ]);

        if ($check->fetchColumn()) {
            $update = $pdo->prepare("
                UPDATE group_contacts
                SET full_name = :full_name,
                    email = :email,
                    section = :section,
                    role = :role,
                    last_used_at = NOW(),
                    updated_at = NOW(),
                    is_active = 1
                WHERE id = :id
                  AND group_id = :group_id
            ");
            $update->execute([
                'full_name' => $name,
                'email' => $email,
                'section' => $section !== '' ? $section : null,
                'role' => $role !== '' ? $role : null,
                'id' => $contactId,
                'group_id' => $groupId,
            ]);

            return $contactId;
        }
    }

    $check = $pdo->prepare("
        SELECT id
        FROM group_contacts
        WHERE group_id = :group_id
          AND full_name = :full_name
          AND email = :email
        LIMIT 1
    ");
    $check->execute([
        'group_id' => $groupId,
        'full_name' => $name,
        'email' => $email,
    ]);

    $existingId = $check->fetchColumn();

    if ($existingId) {
        $update = $pdo->prepare("
            UPDATE group_contacts
            SET section = :section,
                role = :role,
                last_used_at = NOW(),
                updated_at = NOW(),
                is_active = 1
            WHERE id = :id
              AND group_id = :group_id
        ");
        $update->execute([
            'section' => $section !== '' ? $section : null,
            'role' => $role !== '' ? $role : null,
            'id' => (int)$existingId,
            'group_id' => $groupId,
        ]);

        return (int)$existingId;
    }

    $insert = $pdo->prepare("
        INSERT INTO group_contacts (
            group_id,
            full_name,
            email,
            section,
            role,
            is_active,
            last_used_at,
            created_at,
            updated_at
        ) VALUES (
            :group_id,
            :full_name,
            :email,
            :section,
            :role,
            1,
            NOW(),
            NOW(),
            NOW()
        )
    ");
    $insert->execute([
        'group_id' => $groupId,
        'full_name' => $name,
        'email' => $email,
        'section' => $section !== '' ? $section : null,
        'role' => $role !== '' ? $role : null,
    ]);

    return (int)$pdo->lastInsertId();
}

/*
|--------------------------------------------------------------------------
| Duplicate draft detection
|--------------------------------------------------------------------------
*/
function find_similar_draft_event(
    PDO $pdo,
    int $groupId,
    string $title,
    string $startsAt,
    string $endsAt,
    ?int $excludeEventId = null
): ?array {
    if ($title === '' || $startsAt === '' || $endsAt === '') {
        return null;
    }

    $sql = "
        SELECT id, event_title, starts_at, ends_at, contact_name, updated_at
        FROM events
        WHERE group_id = :group_id
          AND status = 'draft'
          AND starts_at < :ends_at
          AND ends_at > :starts_at
    ";

    $params = [
        'group_id' => $groupId,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
    ];

    if ($excludeEventId !== null && $excludeEventId > 0) {
        $sql .= " AND id <> :exclude_event_id";
        $params['exclude_event_id'] = $excludeEventId;
    }

    $sql .= " ORDER BY updated_at DESC LIMIT 20";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $candidates = $stmt->fetchAll();
    $titleLower = mb_strtolower($title);

    foreach ($candidates as $candidate) {
        $candidateTitle = (string)$candidate['event_title'];
        $candidateLower = mb_strtolower($candidateTitle);

        similar_text($titleLower, $candidateLower, $percent);

        $containsMatch = str_contains($titleLower, $candidateLower)
            || str_contains($candidateLower, $titleLower);

        if ($percent >= 62 || $containsMatch) {
            return $candidate;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Upload helpers
|--------------------------------------------------------------------------
*/
function upload_error_message(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large.',
        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A server extension blocked the uploaded file.',
        default => 'The uploaded file could not be processed.',
    };
}

function normalise_uploaded_filename(string $filename): string
{
    $filename = basename($filename);
    $filename = preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?? 'risk-assessment';
    $filename = trim($filename, " .\t\n\r\0\x0B");

    return $filename !== '' ? $filename : 'risk-assessment';
}

function upload_directory(): string
{
    if (defined('PRIVATE_UPLOAD_ROOT') && is_string(PRIVATE_UPLOAD_ROOT) && PRIVATE_UPLOAD_ROOT !== '') {
        return rtrim(PRIVATE_UPLOAD_ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'risk_assessments' . DIRECTORY_SEPARATOR;
    }

    return __DIR__ . '/uploads/risk_assessments/';
}

function ensure_upload_directory(string $uploadDir): void
{
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create upload directory.');
    }

    if (!is_writable($uploadDir)) {
        throw new RuntimeException('Upload directory is not writable.');
    }

    $denyFile = $uploadDir . '.htaccess';

    if (!is_file($denyFile)) {
        @file_put_contents($denyFile, "Require all denied\n");
    }
}

function detect_uploaded_file_type(string $path, string $originalName): array
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, ['pdf', 'doc', 'docx'], true)) {
        throw new RuntimeException('Only PDF, DOC and DOCX files are allowed.');
    }

    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Uploaded file could not be inspected.');
    }

    $handle = fopen($path, 'rb');

    if (!$handle) {
        throw new RuntimeException('Uploaded file could not be inspected.');
    }

    $header = fread($handle, 8) ?: '';
    fclose($handle);

    $isPdf = str_starts_with($header, '%PDF-');
    $isZipBasedOffice = str_starts_with($header, "PK\x03\x04")
        || str_starts_with($header, "PK\x05\x06")
        || str_starts_with($header, "PK\x07\x08");
    $isLegacyOffice = str_starts_with($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");

    if ($extension === 'pdf') {
        if (!$isPdf) {
            throw new RuntimeException('The uploaded PDF does not appear to be a valid PDF file.');
        }

        return ['extension' => 'pdf', 'mime_type' => 'application/pdf'];
    }

    if ($extension === 'docx') {
        if (!$isZipBasedOffice) {
            throw new RuntimeException('The uploaded DOCX does not appear to be a valid Office document.');
        }

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();

            if ($zip->open($path) !== true) {
                throw new RuntimeException('The uploaded DOCX could not be opened for validation.');
            }

            $hasContentTypes = $zip->locateName('[Content_Types].xml') !== false;
            $hasDocumentXml = $zip->locateName('word/document.xml') !== false;
            $zip->close();

            if (!$hasContentTypes || !$hasDocumentXml) {
                throw new RuntimeException('The uploaded DOCX is missing expected Word document metadata.');
            }
        }

        return [
            'extension' => 'docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
    }

    if ($extension === 'doc') {
        if (!$isLegacyOffice) {
            throw new RuntimeException('The uploaded DOC does not appear to be a valid legacy Word document.');
        }

        return ['extension' => 'doc', 'mime_type' => 'application/msword'];
    }

    throw new RuntimeException('Unsupported file type.');
}

function create_uploaded_risk_assessment_multi(
    PDO $pdo,
    int $groupId,
    string $uploadedByName,
    string $uploadedByEmail,
    string $title,
    string $description,
    string $locationSummary,
    string $visibility,
    array $file
): int {
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message($uploadError));
    }

    $tmpName = (string)($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('The uploaded file was not received safely.');
    }

    $fileSize = (int)($file['size'] ?? 0);

    if ($fileSize <= 0) {
        throw new RuntimeException('The uploaded file was empty.');
    }

    if ($fileSize > 10 * 1024 * 1024) {
        throw new RuntimeException('A risk assessment file exceeded 10MB.');
    }

    $originalName = normalise_uploaded_filename((string)($file['name'] ?? 'risk-assessment'));
    $detected = detect_uploaded_file_type($tmpName, $originalName);

    $extension = $detected['extension'];
    $mimeType = $detected['mime_type'];

    $uploadDir = upload_directory();
    ensure_upload_directory($uploadDir);

    $storedFilename = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $uploadDir . $storedFilename;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Unable to save uploaded file.');
    }

    @chmod($destination, 0640);

    $sha256 = hash_file('sha256', $destination) ?: null;

    $stmt = $pdo->prepare("
        INSERT INTO risk_assessments (
            group_id,
            uploaded_by_name,
            uploaded_by_email,
            title,
            description,
            activity_type,
            location_summary,
            visibility,
            file_path,
            stored_filename,
            original_filename,
            file_extension,
            mime_type,
            file_size_bytes,
            file_sha256,
            uploaded_at,
            updated_at,
            is_active,
            admin_review_status,
            created_at
        ) VALUES (
            :group_id,
            :uploaded_by_name,
            :uploaded_by_email,
            :title,
            :description,
            NULL,
            :location_summary,
            :visibility,
            :file_path,
            :stored_filename,
            :original_filename,
            :file_extension,
            :mime_type,
            :file_size_bytes,
            :file_sha256,
            NOW(),
            NOW(),
            1,
            'available',
            NOW()
        )
    ");

    $stmt->execute([
        'group_id' => $groupId,
        'uploaded_by_name' => $uploadedByName,
        'uploaded_by_email' => $uploadedByEmail,
        'title' => $title,
        'description' => $description !== '' ? $description : null,
        'location_summary' => $locationSummary !== '' ? $locationSummary : null,
        'visibility' => in_array($visibility, ['group', 'district'], true) ? $visibility : 'district',
        'file_path' => $destination,
        'stored_filename' => $storedFilename,
        'original_filename' => $originalName,
        'file_extension' => $extension,
        'mime_type' => $mimeType,
        'file_size_bytes' => $fileSize,
        'file_sha256' => $sha256,
    ]);

    return (int)$pdo->lastInsertId();
}

/*
|--------------------------------------------------------------------------
| Defaults / existing event data
|--------------------------------------------------------------------------
*/
$prefillStartDate = trim((string)($_GET['starts_at_date'] ?? ''));

$form = [
    'contact_id' => '',
    'contact_name' => '',
    'contact_email' => '',
    'contact_section' => '',
    'contact_role' => '',
    'event_title' => '',
    'event_description' => '',
    'event_location' => '',
    'event_location_lat' => '',
    'event_location_lng' => '',
    'starts_at_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefillStartDate) ? $prefillStartDate : '',
    'starts_at_time' => '',
    'ends_at_date' => '',
    'ends_at_time' => '',
    'squirrels_count' => '',
    'beavers_count' => '',
    'cubs_count' => '',
    'scouts_count' => '',
    'explorers_count' => '',
    'network_count' => '',
    'adults_count' => '',
];

$currentRiskAssessments = [];
$auditEntries = [];

if ($editingEvent) {
    $form = [
        'contact_id' => (string)($editingEvent['contact_id'] ?? ''),
        'contact_name' => (string)$editingEvent['contact_name'],
        'contact_email' => (string)$editingEvent['contact_email'],
        'contact_section' => (string)($editingEvent['contact_section'] ?? ''),
        'contact_role' => (string)($editingEvent['contact_role'] ?? ''),
        'event_title' => (string)$editingEvent['event_title'],
        'event_description' => (string)$editingEvent['event_description'],
        'event_location' => (string)$editingEvent['event_location'],
        'event_location_lat' => (string)($editingEvent['event_location_lat'] ?? ''),
        'event_location_lng' => (string)($editingEvent['event_location_lng'] ?? ''),
        'starts_at_date' => date('Y-m-d', strtotime((string)$editingEvent['starts_at'])),
        'starts_at_time' => date('H:i', strtotime((string)$editingEvent['starts_at'])),
        'ends_at_date' => date('Y-m-d', strtotime((string)$editingEvent['ends_at'])),
        'ends_at_time' => date('H:i', strtotime((string)$editingEvent['ends_at'])),
        'squirrels_count' => (string)($editingEvent['squirrels_count'] ?? ''),
        'beavers_count' => (string)($editingEvent['beavers_count'] ?? ''),
        'cubs_count' => (string)($editingEvent['cubs_count'] ?? ''),
        'scouts_count' => (string)($editingEvent['scouts_count'] ?? ''),
        'explorers_count' => (string)($editingEvent['explorers_count'] ?? ''),
        'network_count' => (string)($editingEvent['network_count'] ?? ''),
        'adults_count' => (string)$editingEvent['adults_count'],
    ];

    if ($form['contact_section'] === '' || $form['contact_role'] === '') {
        foreach ($contacts as $contact) {
            if ((int)$contact['id'] === (int)$form['contact_id']) {
                $form['contact_section'] = $form['contact_section'] !== '' ? $form['contact_section'] : (string)($contact['section'] ?? '');
                $form['contact_role'] = $form['contact_role'] !== '' ? $form['contact_role'] : (string)($contact['role'] ?? '');
                break;
            }
        }
    }

    $stmt = $pdo->prepare("
        SELECT
            era.id AS link_id,
            era.source_type,
            era.created_at AS linked_at,
            ra.id,
            ra.group_id,
            ra.title,
            ra.original_filename,
            ra.file_extension,
            ra.updated_at,
            ra.uploaded_at,
            g.group_name
        FROM event_risk_assessments era
        INNER JOIN risk_assessments ra ON ra.id = era.risk_assessment_id
        INNER JOIN groups g ON g.id = ra.group_id
        WHERE era.event_id = :event_id
          AND ra.is_active = 1
        ORDER BY era.created_at DESC, ra.original_filename ASC
    ");
    $stmt->execute(['event_id' => (int)$editingEvent['id']]);
    $currentRiskAssessments = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT
            al.*,
            au.full_name AS admin_name,
            g.group_name AS actor_group_name
        FROM audit_log al
        LEFT JOIN admin_users au ON au.id = al.admin_user_id
        LEFT JOIN groups g ON g.id = al.group_id
        WHERE al.entity_type = 'event'
          AND al.entity_id = :event_id
        ORDER BY al.created_at DESC
        LIMIT 50
    ");
    $stmt->execute(['event_id' => (int)$editingEvent['id']]);
    $auditEntries = $stmt->fetchAll();
}

$currentStatus = $editingEvent ? (string)$editingEvent['status'] : 'draft';
$isCancelled = $editingEvent && $currentStatus === 'cancelled';

/*
|--------------------------------------------------------------------------
| POST handling
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $pendingSaveAction = post_string('save_action', 'submit');
    $confirmDuplicate = post_string('confirm_create_duplicate') === '1';

    if (!in_array($pendingSaveAction, ['draft', 'submit', 'cancel'], true)) {
        $pendingSaveAction = 'submit';
    }

    if ($isCancelled) {
        $error = 'This event has been cancelled and can no longer be edited.';
    }

    /*
    |--------------------------------------------------------------------------
    | Cancel event
    |--------------------------------------------------------------------------
    */
    if ($error === '' && $pendingSaveAction === 'cancel') {
        if (!$editingEvent) {
            $error = 'Only saved events can be cancelled.';
        } else {
            $reason = post_string('cancellation_reason');

            $pdo->beginTransaction();

            try {
                $stmt = $pdo->prepare("
                    UPDATE events
                    SET status = 'cancelled',
                        cancelled_at = NOW(),
                        cancelled_by_contact_id = contact_id,
                        cancellation_reason = :reason,
                        updated_at = NOW()
                    WHERE id = :id
                      AND group_id = :group_id
                      AND status <> 'cancelled'
                ");
                $stmt->execute([
                    'reason' => $reason !== '' ? $reason : null,
                    'id' => (int)$editingEvent['id'],
                    'group_id' => $groupId,
                ]);

                audit_event($pdo, (int)$editingEvent['id'], 'cancelled_by_group', [
                    'reason' => $reason,
                ]);

                $pdo->commit();

                redirect(route_add_event() . '?event_id=' . (int)$editingEvent['id'] . '&cancelled=1' . ($isAdminOrReviewer ? '&group_id=' . $groupId : ''));
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = (defined('APP_DEBUG') && APP_DEBUG)
                    ? 'Unable to cancel event: ' . $e->getMessage()
                    : 'Unable to cancel event. Please try again.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Draft / submit event
    |--------------------------------------------------------------------------
    */
    if ($error === '' && in_array($pendingSaveAction, ['draft', 'submit'], true)) {
        $isDraftSave = $pendingSaveAction === 'draft';

        $form['contact_id'] = post_string('contact_id');
        $form['contact_name'] = post_string('contact_name');
        $form['contact_email'] = post_string('contact_email');
        $form['contact_section'] = normalise_contact_section(post_string('contact_section'));
        $form['contact_role'] = normalise_contact_role(post_string('contact_role'));
        $form['event_title'] = post_string('event_title');
        $form['event_description'] = post_string('event_description');
        $form['event_location'] = post_string('event_location');
        $form['event_location_lat'] = post_string('event_location_lat');
        $form['event_location_lng'] = post_string('event_location_lng');
        $form['starts_at_date'] = post_string('starts_at_date');
        $form['starts_at_time'] = post_string('starts_at_time');
        $form['ends_at_date'] = post_string('ends_at_date');
        $form['ends_at_time'] = post_string('ends_at_time');
        $form['squirrels_count'] = post_string('squirrels_count', '0');
        $form['beavers_count'] = post_string('beavers_count', '0');
        $form['cubs_count'] = post_string('cubs_count', '0');
        $form['scouts_count'] = post_string('scouts_count', '0');
        $form['explorers_count'] = post_string('explorers_count', '0');
        $form['network_count'] = post_string('network_count', '0');
        $form['adults_count'] = post_string('adults_count', '0');

        $selectedExistingIds = array_values(array_unique(array_map('intval', $_POST['selected_existing_ras'] ?? [])));
        $uploadVisibilities = $_POST['upload_visibility'] ?? [];

        if ($form['event_title'] === '') {
            $error = 'Event title is required.';
        } elseif ($form['contact_name'] === '') {
            $error = 'Lead contact name is required.';
        } elseif ($form['contact_email'] === '' || !filter_var($form['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'A valid lead contact email is required.';
        } elseif ($form['contact_section'] === '') {
            $error = 'Lead contact section is required.';
        } elseif ($form['contact_role'] === '') {
            $error = 'Lead contact role is required.';
        } elseif ($form['starts_at_date'] === '' || $form['starts_at_time'] === '' || $form['ends_at_date'] === '' || $form['ends_at_time'] === '') {
            $error = 'Start and end date/time are required.';
        } elseif (!$isDraftSave && $form['event_location'] === '') {
            $error = 'Event location is required before submitting for approval.';
        }

        $locationLat = null;
        $locationLng = null;

        if ($form['event_location_lat'] !== '' || $form['event_location_lng'] !== '') {
            if (!is_numeric($form['event_location_lat']) || !is_numeric($form['event_location_lng'])) {
                $error = 'Location coordinates are invalid. Please search the location again or clear the pin.';
            } else {
                $locationLat = (float)$form['event_location_lat'];
                $locationLng = (float)$form['event_location_lng'];

                if ($locationLat < -90 || $locationLat > 90 || $locationLng < -180 || $locationLng > 180) {
                    $error = 'Location coordinates are outside the valid range.';
                }
            }
        }

        $startsAt = null;
        $endsAt = null;

        if ($error === '') {
            $startsAt = $form['starts_at_date'] . ' ' . $form['starts_at_time'] . ':00';
            $endsAt = $form['ends_at_date'] . ' ' . $form['ends_at_time'] . ':00';

            if (strtotime($endsAt) <= strtotime($startsAt)) {
                $error = 'End date and time must be after the start date and time.';
            }
        }

        if ($error === '' && !$editingEvent && !$confirmDuplicate && $startsAt && $endsAt) {
            $similarDraftWarning = find_similar_draft_event($pdo, $groupId, $form['event_title'], $startsAt, $endsAt, null);

            if ($similarDraftWarning) {
                $error = 'A similar draft already exists for this group and date/time. Please confirm whether you want to create another event.';
            }
        }

        $counts = [
            'squirrels_count' => max(0, (int)$form['squirrels_count']),
            'beavers_count' => max(0, (int)$form['beavers_count']),
            'cubs_count' => max(0, (int)$form['cubs_count']),
            'scouts_count' => max(0, (int)$form['scouts_count']),
            'explorers_count' => max(0, (int)$form['explorers_count']),
            'network_count' => max(0, (int)$form['network_count']),
        ];

        $youngPeopleTotal = array_sum($counts);
        $adultsCount = max(0, (int)$form['adults_count']);

        $validatedExistingRaIds = [];

        if ($error === '' && !empty($selectedExistingIds)) {
            foreach ($selectedExistingIds as $raId) {
                $stmt = $pdo->prepare("
                    SELECT id, group_id, uploaded_at, updated_at
                    FROM risk_assessments
                    WHERE id = :id
                      AND is_active = 1
                      AND admin_review_status = 'available'
                      AND (
                            group_id = :group_id
                            OR visibility = 'district'
                          )
                    LIMIT 1
                ");
                $stmt->execute([
                    'id' => $raId,
                    'group_id' => $groupId,
                ]);
                $ra = $stmt->fetch();

                if (!$ra) {
                    $error = 'One of the selected risk assessments is not available.';
                    break;
                }

                $isOwnGroup = (int)$ra['group_id'] === $groupId;
                $isRecent = strtotime((string)$ra['uploaded_at']) >= strtotime('-90 days')
                    || strtotime((string)$ra['updated_at']) >= strtotime('-90 days');

                if (!$isOwnGroup || !$isRecent) {
                    $error = 'Only your own group’s recent risk assessments can be attached directly.';
                    break;
                }

                $validatedExistingRaIds[] = (int)$ra['id'];
            }
        }

        if ($error === '') {
            $pdo->beginTransaction();

            try {
                $contactId = save_group_contact(
                    $pdo,
                    $groupId,
                    $form['contact_id'] !== '' ? (int)$form['contact_id'] : null,
                    $form['contact_name'],
                    $form['contact_email'],
                    $form['contact_section'],
                    $form['contact_role']
                );

                $targetStatus = $isDraftSave ? 'draft' : 'submitted';

                $hasNewUpload = false;

                if (!empty($_FILES['new_risk_assessments']['name']) && is_array($_FILES['new_risk_assessments']['name'])) {
                    foreach ($_FILES['new_risk_assessments']['error'] as $uploadErrorCode) {
                        if ((int)$uploadErrorCode !== UPLOAD_ERR_NO_FILE) {
                            $hasNewUpload = true;
                            break;
                        }
                    }
                }

                $riskAssessmentCompleted = (!empty($validatedExistingRaIds) || $hasNewUpload) ? 1 : 0;

                if ($editingEvent) {
                    $stmt = $pdo->prepare("
                        UPDATE events
                        SET
                            contact_id = :contact_id,
                            contact_name = :contact_name,
                            contact_email = :contact_email,
                            contact_section = :contact_section,
                            contact_role = :contact_role,
                            event_title = :event_title,
                            event_description = :event_description,
                            event_location = :event_location,
                            event_location_lat = :event_location_lat,
                            event_location_lng = :event_location_lng,
                            starts_at = :starts_at,
                            ends_at = :ends_at,
                            squirrels_count = :squirrels_count,
                            beavers_count = :beavers_count,
                            cubs_count = :cubs_count,
                            scouts_count = :scouts_count,
                            explorers_count = :explorers_count,
                            network_count = :network_count,
                            young_people_count = :young_people_count,
                            adults_count = :adults_count,
                            risk_assessment_completed = :risk_assessment_completed,
                            status = :status,
                            submitted_at = CASE WHEN :submitted_status = 'submitted' THEN NOW() ELSE submitted_at END,
                            reviewed_by_admin_id = CASE WHEN :review_reset_status = 'submitted' THEN NULL ELSE reviewed_by_admin_id END,
                            reviewed_at = CASE WHEN :review_reset_status2 = 'submitted' THEN NULL ELSE reviewed_at END,
                            updated_at = NOW()
                        WHERE id = :id
                          AND group_id = :group_id
                          AND status <> 'cancelled'
                    ");

                    $stmt->execute([
                        'contact_id' => $contactId,
                        'contact_name' => $form['contact_name'],
                        'contact_email' => $form['contact_email'],
                        'contact_section' => $form['contact_section'],
                        'contact_role' => $form['contact_role'],
                        'event_title' => $form['event_title'],
                        'event_description' => $form['event_description'] !== '' ? $form['event_description'] : null,
                        'event_location' => $form['event_location'] !== '' ? $form['event_location'] : null,
                        'event_location_lat' => $locationLat,
                        'event_location_lng' => $locationLng,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'squirrels_count' => $counts['squirrels_count'],
                        'beavers_count' => $counts['beavers_count'],
                        'cubs_count' => $counts['cubs_count'],
                        'scouts_count' => $counts['scouts_count'],
                        'explorers_count' => $counts['explorers_count'],
                        'network_count' => $counts['network_count'],
                        'young_people_count' => $youngPeopleTotal,
                        'adults_count' => $adultsCount,
                        'risk_assessment_completed' => $riskAssessmentCompleted,
                        'status' => $targetStatus,
                        'submitted_status' => $targetStatus,
                        'review_reset_status' => $targetStatus,
                        'review_reset_status2' => $targetStatus,
                        'id' => (int)$editingEvent['id'],
                        'group_id' => $groupId,
                    ]);

                    $savedEventId = (int)$editingEvent['id'];

                    $stmt = $pdo->prepare("DELETE FROM event_risk_assessments WHERE event_id = :event_id");
                    $stmt->execute(['event_id' => $savedEventId]);

                    audit_event($pdo, $savedEventId, $isDraftSave ? 'draft_updated' : 'updated', [
                        'title' => $form['event_title'],
                        'status' => $targetStatus,
                        'location' => $form['event_location'],
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO events (
                            group_id,
                            contact_id,
                            contact_name,
                            contact_email,
                            contact_section,
                            contact_role,
                            event_title,
                            event_description,
                            event_location,
                            event_location_lat,
                            event_location_lng,
                            starts_at,
                            ends_at,
                            squirrels_count,
                            beavers_count,
                            cubs_count,
                            scouts_count,
                            explorers_count,
                            network_count,
                            young_people_count,
                            adults_count,
                            risk_assessment_completed,
                            status,
                            submitted_at,
                            updated_at
                        ) VALUES (
                            :group_id,
                            :contact_id,
                            :contact_name,
                            :contact_email,
                            :contact_section,
                            :contact_role,
                            :event_title,
                            :event_description,
                            :event_location,
                            :event_location_lat,
                            :event_location_lng,
                            :starts_at,
                            :ends_at,
                            :squirrels_count,
                            :beavers_count,
                            :cubs_count,
                            :scouts_count,
                            :explorers_count,
                            :network_count,
                            :young_people_count,
                            :adults_count,
                            :risk_assessment_completed,
                            :status,
                            CASE WHEN :submitted_status = 'submitted' THEN NOW() ELSE NULL END,
                            NOW()
                        )
                    ");

                    $stmt->execute([
                        'group_id' => $groupId,
                        'contact_id' => $contactId,
                        'contact_name' => $form['contact_name'],
                        'contact_email' => $form['contact_email'],
                        'contact_section' => $form['contact_section'],
                        'contact_role' => $form['contact_role'],
                        'event_title' => $form['event_title'],
                        'event_description' => $form['event_description'] !== '' ? $form['event_description'] : null,
                        'event_location' => $form['event_location'] !== '' ? $form['event_location'] : null,
                        'event_location_lat' => $locationLat,
                        'event_location_lng' => $locationLng,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'squirrels_count' => $counts['squirrels_count'],
                        'beavers_count' => $counts['beavers_count'],
                        'cubs_count' => $counts['cubs_count'],
                        'scouts_count' => $counts['scouts_count'],
                        'explorers_count' => $counts['explorers_count'],
                        'network_count' => $counts['network_count'],
                        'young_people_count' => $youngPeopleTotal,
                        'adults_count' => $adultsCount,
                        'risk_assessment_completed' => $riskAssessmentCompleted,
                        'status' => $targetStatus,
                        'submitted_status' => $targetStatus,
                    ]);

                    $savedEventId = (int)$pdo->lastInsertId();

                    audit_event($pdo, $savedEventId, $isDraftSave ? 'draft_created' : 'created', [
                        'title' => $form['event_title'],
                        'status' => $targetStatus,
                        'location' => $form['event_location'],
                    ]);
                }

                foreach ($validatedExistingRaIds as $raId) {
                    $stmt = $pdo->prepare("
                        INSERT INTO event_risk_assessments (
                            event_id,
                            risk_assessment_id,
                            source_type,
                            created_at
                        ) VALUES (
                            :event_id,
                            :ra_id,
                            'selected_existing',
                            NOW()
                        )
                    ");
                    $stmt->execute([
                        'event_id' => $savedEventId,
                        'ra_id' => $raId,
                    ]);
                }

                if (!empty($_FILES['new_risk_assessments']['name']) && is_array($_FILES['new_risk_assessments']['name'])) {
                    $fileCount = count($_FILES['new_risk_assessments']['name']);

                    for ($i = 0; $i < $fileCount; $i++) {
                        if ((int)$_FILES['new_risk_assessments']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                            continue;
                        }

                        $singleFile = [
                            'name' => $_FILES['new_risk_assessments']['name'][$i],
                            'type' => $_FILES['new_risk_assessments']['type'][$i],
                            'tmp_name' => $_FILES['new_risk_assessments']['tmp_name'][$i],
                            'error' => $_FILES['new_risk_assessments']['error'][$i],
                            'size' => $_FILES['new_risk_assessments']['size'][$i],
                        ];

                        $visibility = $uploadVisibilities[$i] ?? 'district';
                        $documentTitle = pathinfo(normalise_uploaded_filename((string)$singleFile['name']), PATHINFO_FILENAME);

                        $newRaId = create_uploaded_risk_assessment_multi(
                            $pdo,
                            $groupId,
                            $form['contact_name'],
                            $form['contact_email'],
                            $documentTitle,
                            $form['event_description'],
                            $form['event_location'],
                            $visibility,
                            $singleFile
                        );

                        $stmt = $pdo->prepare("
                            INSERT INTO event_risk_assessments (
                                event_id,
                                risk_assessment_id,
                                source_type,
                                created_at
                            ) VALUES (
                                :event_id,
                                :ra_id,
                                'uploaded',
                                NOW()
                            )
                        ");
                        $stmt->execute([
                            'event_id' => $savedEventId,
                            'ra_id' => $newRaId,
                        ]);

                        audit_event($pdo, $savedEventId, 'risk_assessment_uploaded', [
                            'risk_assessment_id' => $newRaId,
                            'filename' => $singleFile['name'],
                        ]);
                    }
                }

                $pdo->commit();

                if (!$isDraftSave) {
                    $subject = 'Away From Hut submission: ' . $form['event_title'];
                    $eventLink = APP_URL . BASE_URL . '/add-event.php?event_id=' . $savedEventId;

                    queue_email(
                        $form['contact_email'],
                        $subject,
                        nl2br(e(
                            "Hello {$form['contact_name']},\n\n" .
                            "Your Away From Hut event has been submitted for review.\n\n" .
                            "Event: {$form['event_title']}\n" .
                            "Group: {$group['group_name']}\n" .
                            "View submission: {$eventLink}\n"
                        ))
                    );

                    if (!empty($group['lead_volunteer_email']) && (int)$group['notify_lead_on_event_created'] === 1) {
                        queue_email(
                            (string)$group['lead_volunteer_email'],
                            $subject,
                            nl2br(e(
                                "A new Away From Hut event has been submitted for {$group['group_name']}.\n\n" .
                                "Event: {$form['event_title']}\n" .
                                "Contact: {$form['contact_name']} ({$form['contact_email']})\n" .
                                "View submission: {$eventLink}\n"
                            ))
                        );
                    }

                    queue_email(
                        'reviewer@example.org',
                        $subject,
                        nl2br(e(
                            "An Away From Hut event has been submitted or updated.\n\n" .
                            "Event: {$form['event_title']}\n" .
                            "Group: {$group['group_name']}\n" .
                            "Review: " . APP_URL . BASE_URL . "/reviewer/events.php\n"
                        ))
                    );
                }

                $savedFlag = $isDraftSave ? 'draft_saved' : 'saved';

                redirect(route_add_event() . '?event_id=' . $savedEventId . '&' . $savedFlag . '=1' . ($isAdminOrReviewer ? '&group_id=' . $groupId : ''));
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = (defined('APP_DEBUG') && APP_DEBUG)
                    ? 'Unable to save event: ' . $e->getMessage()
                    : 'Unable to save event. Please check the form and uploaded files, then try again.';
            }$error = (defined('APP_DEBUG') && APP_DEBUG)
    ? 'Unable to save event: ' . $e->getMessage()
    : 'Unable to save event. Please check the form and uploaded files, then try again.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Flash messages
|--------------------------------------------------------------------------
*/
if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $flash = $editingEvent ? 'Event updated and submitted for review.' : 'Event submitted for review.';
}

if (isset($_GET['draft_saved']) && $_GET['draft_saved'] === '1') {
    $flash = 'Draft saved. It has not been sent for approval.';
}

if (isset($_GET['cancelled']) && $_GET['cancelled'] === '1') {
    $flash = 'Event cancelled. It can no longer be edited.';
}

$pageTitle = $editingEvent ? 'Manage Event' : 'Add Event';
$currentStatus = $editingEvent ? (string)$editingEvent['status'] : 'draft';
$isCancelled = $editingEvent && $currentStatus === 'cancelled';
$canSaveDraft = !$isCancelled && (!$editingEvent || $currentStatus === 'draft');
$formDisabled = $isCancelled ? 'disabled' : '';

render_page_start($pageTitle);
render_header('add-event');
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    .workflow {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        margin-bottom: 1.5rem;
    }

    .workflow-step {
        flex: 1 1 160px;
        border: 1px solid #dee2e6;
        border-radius: .5rem;
        padding: .75rem 1rem;
        background: #fff;
    }

    .workflow-step.complete {
        border-color: #28a745;
        background: #f3fbf5;
    }

    .workflow-step.active {
        border-color: #007bff;
        background: #f4f9ff;
        box-shadow: 0 0 0 .15rem rgba(0, 123, 255, .12);
    }

    .workflow-step.active.success {
        border-color: #28a745;
        background: #f3fbf5;
        box-shadow: 0 0 0 .15rem rgba(40, 167, 69, .12);
    }

    .workflow-step.active.danger {
        border-color: #dc3545;
        background: #fff5f5;
        box-shadow: 0 0 0 .15rem rgba(220, 53, 69, .12);
    }

    .workflow-step small {
        display: block;
        color: #6c757d;
    }

    .audit-box {
        max-height: 650px;
        overflow-y: auto;
    }

    .audit-item {
        border-left: 3px solid #dee2e6;
        padding-left: .75rem;
        margin-bottom: 1rem;
    }

    .risk-assessment-row {
        border: 1px solid #dee2e6;
        border-radius: .5rem;
        padding: .75rem;
        margin-bottom: .75rem;
        background: #fff;
    }

    .location-result-item {
        cursor: pointer;
    }

    .location-result-distance {
        font-size: .8rem;
    }

    .contact-panel {
        border: 1px solid #dee2e6;
        border-radius: .5rem;
        background: #f8f9fa;
        padding: 1rem;
    }

    .leaflet-container {
        font-family: inherit;
    }

    @media (max-width: 767.98px) {
        .workflow {
            display: block;
        }

        .workflow-step {
            margin-bottom: .75rem;
        }

        .btn-mobile-block {
            display: block;
            width: 100%;
            margin-bottom: .5rem;
        }
    }
</style>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="<?= $editingEvent ? 'col-xl-8' : 'col-xl-10 col-xxl-8' ?>">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="mb-1"><?= e($pageTitle) ?></h1>
                    <p class="text-muted mb-0">
                        <?= $editingEvent ? 'Update this Away From Hut notification.' : 'Create a new Away From Hut notification.' ?>
                    </p>
                </div>
            </div>

            <div class="workflow">
                <div class="workflow-step <?= e(workflow_step_class($currentStatus, 'draft')) ?>">
                    <strong>Draft</strong>
                    <small>Saved but not submitted</small>
                </div>

                <div class="workflow-step <?= e(workflow_step_class($currentStatus, 'submitted')) ?>">
                    <strong>Submitted</strong>
                    <small>Awaiting review</small>
                </div>

                <?php if (in_array($currentStatus, ['changes_requested', 'rejected', 'cancelled'], true)): ?>
                    <div class="workflow-step <?= e(workflow_step_class($currentStatus, 'declined')) ?>">
                        <strong><?= $currentStatus === 'cancelled' ? 'Cancelled' : 'Declined' ?></strong>
                        <small><?= $currentStatus === 'cancelled' ? 'No further edits' : 'Changes needed' ?></small>
                    </div>
                <?php else: ?>
                    <div class="workflow-step <?= e(workflow_step_class($currentStatus, 'approved')) ?>">
                        <strong>Approved</strong>
                        <small>Ready to proceed</small>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($editingEvent): ?>
                <div class="alert alert-secondary">
                    Current status: <strong><?= e(event_status_label($currentStatus)) ?></strong>
                </div>
            <?php endif; ?>

            <?php if ($isCancelled): ?>
                <div class="alert alert-danger">
                    This event has been cancelled. Details are now read-only and cannot be edited.
                    <?php if (!empty($editingEvent['cancellation_reason'])): ?>
                        <br><strong>Reason:</strong> <?= nl2br(e((string)$editingEvent['cancellation_reason'])) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($flash !== ''): ?>
                <div class="alert alert-success"><?= e($flash) ?></div>
            <?php endif; ?>

            <?php if ($error !== '' && !$similarDraftWarning): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($similarDraftWarning): ?>
                <div class="alert alert-warning">
                    <h2 class="h5">Similar draft already exists</h2>
                    <p class="mb-2">
                        A similar draft already exists for this group and date/time.
                    </p>
                    <ul class="mb-3">
                        <li><strong><?= e((string)$similarDraftWarning['event_title']) ?></strong></li>
                        <li>
                            <?= e(date('d M Y H:i', strtotime((string)$similarDraftWarning['starts_at']))) ?>
                            to
                            <?= e(date('d M Y H:i', strtotime((string)$similarDraftWarning['ends_at']))) ?>
                        </li>
                        <li>Lead: <?= e((string)$similarDraftWarning['contact_name']) ?></li>
                    </ul>
                    <p class="mb-0">
                        Open the existing draft, or confirm below that you want to create another event anyway.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($editingEvent && !empty($editingEvent['admin_comments'])): ?>
                <div class="alert alert-warning">
                    <strong>Reviewer comments:</strong><br>
                    <?= nl2br(e((string)$editingEvent['admin_comments'])) ?>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" id="eventForm">
                <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="save_action" id="save_action" value="<?= e($pendingSaveAction) ?>">
                <input type="hidden" name="confirm_create_duplicate" id="confirm_create_duplicate" value="0">
                <input type="hidden" name="contact_id" id="contact_id" value="<?= e($form['contact_id']) ?>">

                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h2 class="h4 mb-3">Event details</h2>

                        <?php if ($isAdminOrReviewer && !$editingEvent && !$currentGroup): ?>
                            <div class="form-group">
                                <label for="group_id">Group</label>
                                <select class="form-control" id="group_id" name="group_id" onchange="this.form.submit()" <?= $formDisabled ?>>
                                    <?php foreach ($allGroups as $g): ?>
                                        <option value="<?= (int)$g['id'] ?>" <?= (int)$g['id'] === $groupId ? 'selected' : '' ?>>
                                            <?= e($g['group_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <div class="mb-3">
                                <strong>Group:</strong> <?= e((string)$group['group_name']) ?>
                                <?php if ($isAdminOrReviewer && $editingEvent): ?>
                                    <input type="hidden" name="group_id" value="<?= (int)$groupId ?>">
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="contact-panel mb-4">
                            <h3 class="h5 mb-3">Lead contact</h3>

                            <div class="form-group">
                                <label for="contact_picker">Select lead contact</label>
                                <select class="form-control" id="contact_picker" <?= $formDisabled ?>>
                                    <option value="">Select yourself / the event lead...</option>
                                    <?php foreach ($contacts as $contact): ?>
                                        <option
                                            value="<?= (int)$contact['id'] ?>"
                                            data-name="<?= e((string)$contact['full_name']) ?>"
                                            data-email="<?= e((string)$contact['email']) ?>"
                                            data-section="<?= e((string)($contact['section'] ?? '')) ?>"
                                            data-role="<?= e((string)($contact['role'] ?? '')) ?>"
                                            <?= (int)$form['contact_id'] === (int)$contact['id'] ? 'selected' : '' ?>
                                        >
                                            <?= e((string)$contact['full_name']) ?>
                                            <?= !empty($contact['section']) ? ' — ' . e((string)$contact['section']) : '' ?>
                                            <?= !empty($contact['role']) ? ' / ' . e((string)$contact['role']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="__new__">My name / role is not here...</option>
                                </select>
                                <small class="form-text text-muted">
                                    If leaders are no longer with the group, they can be removed on the GLV page.
                                </small>
                            </div>

                            <div class="alert alert-info small mb-3">
                                Selecting a contact prefills the details below. You can edit them before saving.
                                If the details change, they will be saved back to the contact list for this group.
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="contact_name">Lead name</label>
                                    <input type="text" class="form-control" id="contact_name" name="contact_name" value="<?= e($form['contact_name']) ?>" required <?= $formDisabled ?>>
                                </div>

                                <div class="form-group col-md-6">
                                    <label for="contact_email">Lead email</label>
                                    <input type="email" class="form-control" id="contact_email" name="contact_email" value="<?= e($form['contact_email']) ?>" required <?= $formDisabled ?>>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="contact_section">Section</label>
                                    <select class="form-control" id="contact_section" name="contact_section" required <?= $formDisabled ?>>
                                        <option value="">Select section...</option>
                                        <?php foreach (['Squirrels', 'Beavers', 'Cubs', 'Scouts', 'Explorers', 'Network', 'Group', 'District', 'Other'] as $section): ?>
                                            <option value="<?= e($section) ?>" <?= $form['contact_section'] === $section ? 'selected' : '' ?>>
                                                <?= e($section) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group col-md-6">
                                    <label for="contact_role">Role</label>
                                    <select class="form-control" id="contact_role" name="contact_role" required <?= $formDisabled ?>>
                                        <option value="">Select role...</option>
                                        <?php foreach (['Team Member', 'Team Leader', 'Group Lead Volunteer', 'Other'] as $role): ?>
                                            <option value="<?= e($role) ?>" <?= $form['contact_role'] === $role ? 'selected' : '' ?>>
                                                <?= e($role) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="form-text text-muted">
                                        If your role is not listed, choose “Other”.
                                    </small>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="event_title">Event title</label>
                            <input type="text" class="form-control" id="event_title" name="event_title" value="<?= e($form['event_title']) ?>" required <?= $formDisabled ?>>
                            <small class="form-text text-muted">Required for both drafts and submissions.</small>
                        </div>

                        <div class="form-group">
                            <label for="event_description">Description</label>
                            <textarea class="form-control" id="event_description" name="event_description" rows="4" <?= $formDisabled ?>><?= e($form['event_description']) ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="event_location">Location / starting point</label>
                            <div class="input-group">
                                <input
                                    type="text"
                                    class="form-control"
                                    id="event_location"
                                    name="event_location"
                                    value="<?= e($form['event_location']) ?>"
                                    placeholder="Search address, place, postcode or starting point"
                                    <?= $formDisabled ?>
                                >
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-outline-primary" id="locationSearchBtn" <?= $formDisabled ?>>
                                        Search
                                    </button>
                                </div>
                            </div>

                            <small class="form-text text-muted">
                                Required before submitting for approval. Drafts can be saved before the location is complete.
                            </small>

                            <input type="hidden" id="event_location_lat" name="event_location_lat" value="<?= e($form['event_location_lat']) ?>">
                            <input type="hidden" id="event_location_lng" name="event_location_lng" value="<?= e($form['event_location_lng']) ?>">

                            <div id="locationSearchResults" class="list-group mt-2"></div>

                            <div id="eventLocationMap"
                                 class="border rounded mt-3"
                                 style="height: 320px; background:#f8f9fa;">
                            </div>

                            <small class="form-text text-muted">
                                Search for a location, then drag the pin to adjust the exact point.
                            </small>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-3">
                                <label for="starts_at_date">Start date</label>
                                <input type="date" class="form-control" id="starts_at_date" name="starts_at_date" value="<?= e($form['starts_at_date']) ?>" required <?= $formDisabled ?>>
                            </div>

                            <div class="form-group col-md-3">
                                <label for="starts_at_time">Start time</label>
                                <input type="time" class="form-control" id="starts_at_time" name="starts_at_time" value="<?= e($form['starts_at_time']) ?>" required <?= $formDisabled ?>>
                            </div>

                            <div class="form-group col-md-3">
                                <label for="ends_at_date">End date</label>
                                <input type="date" class="form-control" id="ends_at_date" name="ends_at_date" value="<?= e($form['ends_at_date']) ?>" required <?= $formDisabled ?>>
                            </div>

                            <div class="form-group col-md-3">
                                <label for="ends_at_time">End time</label>
                                <input type="time" class="form-control" id="ends_at_time" name="ends_at_time" value="<?= e($form['ends_at_time']) ?>" required <?= $formDisabled ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h2 class="h4 mb-3">Numbers attending</h2>

                        <div class="form-row">
                            <div class="form-group col-md-3">
                                <label for="squirrels_count">Squirrels</label>
                                <input type="number" min="0" class="form-control" id="squirrels_count" name="squirrels_count" value="<?= e($form['squirrels_count']) ?>" <?= $formDisabled ?>>
                            </div>

                            <div class="form-group col-md-3">
                                <label for="beavers_count">Beavers</label>
                                <input type="number" min="0" class="form-control" id="beavers_count" name="beavers_count" value="<?= e($form['beavers_count']) ?>" <?= $formDisabled ?>>
                            </div>

                            <div class="form-group col-md-3">
                                <label for="cubs_count">Cubs</label>
                                <input type="number" min="0" class="form-control" id="cubs_count" name="cubs_count" value="<?= e($form['cubs_count']) ?>" <?= $formDisabled ?>>
                            </div>

                            <div class="form-group col-md-3">
                                <label for="scouts_count">Scouts</label>
                                <input type="number" min="0" class="form-control" id="scouts_count" name="scouts_count" value="<?= e($form['scouts_count']) ?>" <?= $formDisabled ?>>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-3">
                                <label for="explorers_count">Explorers</label>
                                <input type="number" min="0" class="form-control" id="explorers_count" name="explorers_count" value="<?= e($form['explorers_count']) ?>" <?= $formDisabled ?>>
                            </div>

                            <div class="form-group col-md-3">
                                <label for="network_count">Network</label>
                                <input type="number" min="0" class="form-control" id="network_count" name="network_count" value="<?= e($form['network_count']) ?>" <?= $formDisabled ?>>
                            </div>

                            <div class="form-group col-md-3">
                                <label for="adults_count">Adults</label>
                                <input type="number" min="0" class="form-control" id="adults_count" name="adults_count" value="<?= e($form['adults_count']) ?>" <?= $formDisabled ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h4 mb-0">Risk assessments</h2>

                            <?php if (!$isCancelled): ?>
                                <button type="button" class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#existingRaModal">
                                    Choose existing
                                </button>
                            <?php endif; ?>
                        </div>

                        <div class="alert alert-info">
                            Your own group’s risk assessments from the last 90 days can be attached directly.
                            Other groups’ shared assessments can be downloaded and reviewed, but not attached directly.
                            Drafts can be saved before the risk assessment is complete.
                        </div>

                        <?php if ($editingEvent && !empty($currentRiskAssessments)): ?>
                            <h3 class="h6 mb-3">Currently attached</h3>

                            <div id="currentRiskAssessmentList" class="mb-4">
                                <?php foreach ($currentRiskAssessments as $ra): ?>
                                    <div class="risk-assessment-row d-flex justify-content-between align-items-center" data-ra-id="<?= (int)$ra['id'] ?>">
                                        <div>
                                            <strong><?= e(ra_display_name($ra)) ?></strong><br>
                                            <small class="text-muted">
                                                <?= e((string)$ra['group_name']) ?> ·
                                                Updated <?= e(date('d M Y', strtotime((string)$ra['updated_at']))) ?>
                                            </small>
                                            <input type="hidden" name="selected_existing_ras[]" value="<?= (int)$ra['id'] ?>">
                                        </div>

                                        <div class="text-nowrap">
                                            <a href="<?= e(BASE_URL . '/' . (ra_can_preview_inline($ra) ? 'preview-risk-assessment.php' : 'download-risk-assessment.php') . '?id=' . (int)$ra['id']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                View
                                            </a>

                                            <?php if (!$isCancelled): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger js-remove-attached-ra">
                                                    Remove
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!$isCancelled): ?>
                            <div id="dropZone" class="border rounded p-4 text-center mb-3" style="background:#fafafa; border-style:dashed !important;">
                                <p class="mb-2"><strong>Drag and drop risk assessments here</strong></p>
                                <p class="text-muted mb-3">or choose files below</p>
                                <input type="file" id="new_risk_assessments" name="new_risk_assessments[]" multiple accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" class="form-control-file">
                                <small class="form-text text-muted">Accepted: PDF, DOC and DOCX only. Files are checked by content, not just filename.</small>
                            </div>

                            <div id="fileList"></div>
                            <div id="selectedExistingList" class="mt-4"></div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!$isCancelled): ?>
                    <div class="d-flex flex-wrap justify-content-end mb-5">
                        <?php if ($editingEvent): ?>
                            <button type="button" class="btn btn-outline-danger btn-lg btn-mobile-block mr-md-auto" data-toggle="modal" data-target="#cancelEventModal">
                                Cancel event
                            </button>
                        <?php endif; ?>

                        <?php if ($similarDraftWarning): ?>
                            <a class="btn btn-outline-primary btn-lg btn-mobile-block mr-md-2" href="<?= e(route_add_event() . '?event_id=' . (int)$similarDraftWarning['id']) ?>">
                                Open existing draft
                            </a>

                            <button type="submit"
                                    class="btn btn-danger btn-lg btn-mobile-block mr-md-2"
                                    formnovalidate
                                    onclick="document.getElementById('confirm_create_duplicate').value='1'; document.getElementById('save_action').value='<?= e($pendingSaveAction) ?>';">
                                Create another event
                            </button>
                        <?php endif; ?>

                        <?php if ($canSaveDraft): ?>
                            <button type="submit" class="btn btn-outline-secondary btn-lg btn-mobile-block mr-md-2" formnovalidate onclick="document.getElementById('save_action').value='draft';">
                                Save draft
                            </button>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary btn-lg btn-mobile-block" onclick="document.getElementById('save_action').value='submit';">
                            <?= $editingEvent ? 'Submit changes for review' : 'Submit for approval' ?>
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($editingEvent): ?>
            <div class="col-xl-3">
                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h2 class="h5 mb-3">Audit log</h2>

                        <?php if (empty($auditEntries)): ?>
                            <p class="text-muted mb-0">No audit entries yet.</p>
                        <?php else: ?>
                            <div class="audit-box">
                                <?php foreach ($auditEntries as $entry): ?>
                                    <?php
                                    $actor = 'System';

                                    if ((string)$entry['actor_type'] === 'admin' && !empty($entry['admin_name'])) {
                                        $actor = (string)$entry['admin_name'];
                                    } elseif ((string)$entry['actor_type'] === 'group_link' && !empty($entry['actor_group_name'])) {
                                        $actor = (string)$entry['actor_group_name'];
                                    }

                                    $details = [];
                                    if (!empty($entry['details'])) {
                                        $decoded = json_decode((string)$entry['details'], true);
                                        if (is_array($decoded)) {
                                            $details = $decoded;
                                        }
                                    }
                                    ?>

                                    <div class="audit-item">
                                        <strong><?= e(ucfirst(str_replace('_', ' ', (string)$entry['action']))) ?></strong><br>
                                        <small class="text-muted">
                                            <?= e($actor) ?> · <?= e(date('d M Y H:i', strtotime((string)$entry['created_at']))) ?>
                                        </small>

                                        <?php if (!empty($details)): ?>
                                            <div class="small mt-1 text-muted">
                                                <?php foreach ($details as $key => $value): ?>
                                                    <?php if (is_scalar($value)): ?>
                                                        <div>
                                                            <?= e(ucfirst(str_replace('_', ' ', (string)$key))) ?>:
                                                            <?= e((string)$value) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($editingEvent && !$isCancelled): ?>
    <div class="modal fade" id="cancelEventModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <form method="post" class="modal-content">
                <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="save_action" value="cancel">

                <div class="modal-header">
                    <h5 class="modal-title">Cancel event</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    <p>
                        Cancelling this event will stop further editing on this page.
                        The event will remain visible for audit/history purposes.
                    </p>

                    <div class="form-group">
                        <label for="cancellation_reason">Reason for cancellation</label>
                        <textarea class="form-control" id="cancellation_reason" name="cancellation_reason" rows="4"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Keep event</button>
                    <button type="submit" class="btn btn-danger">Cancel event</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="modal fade" id="existingRaModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Choose existing risk assessments</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body">
                <div class="form-group">
                    <input type="text" class="form-control" id="raSearch" placeholder="Search by document name, group or activity">
                </div>

                <div class="table-responsive">
                    <table class="table table-striped" id="raTable">
                        <thead>
                            <tr>
                                <th>Document</th>
                                <th>Group</th>
                                <th>Last updated</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($availableRiskAssessments as $ra): ?>
                            <?php
                            $isOwnGroup = (int)$ra['group_id'] === $groupId;
                            $isRecent = ra_recent_enough($ra);
                            $canAttach = $isOwnGroup && $isRecent;
                            $displayName = ra_display_name($ra);
                            ?>
                            <tr data-search="<?= e(strtolower($displayName . ' ' . $ra['group_name'] . ' ' . ($ra['activity_type'] ?? ''))) ?>">
                                <td>
                                    <strong><?= e($displayName) ?></strong>
                                    <?php if (!empty($ra['activity_type'])): ?>
                                        <br><small class="text-muted"><?= e($ra['activity_type']) ?></small>
                                    <?php endif; ?>
                                </td>

                                <td><?= e($ra['group_name']) ?></td>

                                <td><?= e(date('d M Y', strtotime((string)$ra['updated_at']))) ?></td>

                                <td>
                                    <?php if ($canAttach): ?>
                                        <span class="badge badge-success">Can attach</span>
                                    <?php elseif ($isOwnGroup): ?>
                                        <span class="badge badge-warning">Review and re-upload</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Download only</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-nowrap">
                                    <a href="<?= e(BASE_URL . '/' . (ra_can_preview_inline($ra) ? 'preview-risk-assessment.php' : 'download-risk-assessment.php') . '?id=' . (int)$ra['id']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                        View
                                    </a>

                                    <?php if ($canAttach): ?>
                                        <button type="button"
                                                class="btn btn-sm btn-primary js-add-existing-ra"
                                                data-id="<?= (int)$ra['id'] ?>"
                                                data-title="<?= e($displayName) ?>">
                                            Add
                                        </button>
                                    <?php else: ?>
                                        <a href="<?= e(BASE_URL . '/download-risk-assessment.php?id=' . (int)$ra['id']) ?>"
                                           target="_blank"
                                           class="btn btn-sm btn-outline-secondary">
                                            Download
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const isEditingEvent = <?= $editingEvent ? 'true' : 'false' ?>;
    const isCancelled = <?= $isCancelled ? 'true' : 'false' ?>;

    const contacts = <?= json_encode(array_map(fn($c) => [
        'id' => (int)$c['id'],
        'full_name' => $c['full_name'],
        'email' => $c['email'],
        'section' => $c['section'] ?? '',
        'role' => $c['role'] ?? '',
    ], $contacts), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /*
    |--------------------------------------------------------------------------
    | Contact prefill
    |--------------------------------------------------------------------------
    */
    const contactPicker = document.getElementById('contact_picker');
    const contactIdInput = document.getElementById('contact_id');
    const contactNameInput = document.getElementById('contact_name');
    const contactEmailInput = document.getElementById('contact_email');
    const contactSectionInput = document.getElementById('contact_section');
    const contactRoleInput = document.getElementById('contact_role');

    function setContactDetails(id, name, email, section, role) {
        if (!contactIdInput || !contactNameInput || !contactEmailInput || !contactSectionInput || !contactRoleInput) {
            return;
        }

        contactIdInput.value = id || '';
        contactNameInput.value = name || '';
        contactEmailInput.value = email || '';
        contactSectionInput.value = section || '';
        contactRoleInput.value = role || '';
    }

    if (contactPicker && !isCancelled) {
        contactPicker.addEventListener('change', function () {
            if (contactPicker.value === '__new__') {
                setContactDetails('', '', '', '', '');
                contactNameInput.focus();
                return;
            }

            const selected = contactPicker.options[contactPicker.selectedIndex];

            if (!selected || !selected.value) {
                setContactDetails('', '', '', '', '');
                return;
            }

            setContactDetails(
                selected.value,
                selected.dataset.name || '',
                selected.dataset.email || '',
                selected.dataset.section || '',
                selected.dataset.role || ''
            );
        });
    }

    /*
     * If the user manually edits name or email after selecting a contact,
     * keep the contact_id so the selected contact updates.
     * If they selected "not here", contact_id remains empty and a new contact is saved.
     */

    /*
    |--------------------------------------------------------------------------
    | Date helpers
    |--------------------------------------------------------------------------
    */
    const eventTitleInput = document.getElementById('event_title');
    const startDate = document.getElementById('starts_at_date');
    const startTime = document.getElementById('starts_at_time');
    const endDate = document.getElementById('ends_at_date');
    const endTime = document.getElementById('ends_at_time');

    let endManuallyTouched = isEditingEvent && Boolean(endDate && endDate.value && endTime && endTime.value);

    function pad(num) {
        return String(num).padStart(2, '0');
    }

    function setDateField(input, date) {
        if (!input) return;
        input.value = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    }

    function setTimeField(input, date) {
        if (!input) return;
        input.value = `${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

    function titleSuggestsLongEvent() {
        const title = (eventTitleInput ? eventTitleInput.value : '').toLowerCase();
        return /\b(camp|expedition|sleepover)\b/.test(title);
    }

    function getStartDateTime() {
        if (!startDate || !startDate.value) return null;

        const time = startTime.value || '09:00';
        const date = new Date(`${startDate.value}T${time}`);

        return Number.isNaN(date.getTime()) ? null : date;
    }

    function syncEndConstraints() {
        if (!startDate || !endDate || !endTime) return;

        if (startDate.value) {
            endDate.min = startDate.value;
        }

        if (startDate.value && endDate.value && startDate.value === endDate.value && startTime.value) {
            endTime.min = startTime.value;
        } else {
            endTime.removeAttribute('min');
        }
    }

    function applySmartEndDate(force = false) {
        if (!force && endManuallyTouched) {
            return;
        }

        const start = getStartDateTime();

        if (!start) {
            return;
        }

        const end = new Date(start.getTime());
        const minutesToAdd = titleSuggestsLongEvent() ? 48 * 60 : 90;

        end.setMinutes(end.getMinutes() + minutesToAdd);

        setDateField(endDate, end);
        setTimeField(endTime, end);
        syncEndConstraints();
    }

    function initialiseDefaultDates() {
        if (isCancelled || !startDate || !startTime || !endDate || !endTime) {
            return;
        }

        if (!startDate.value) {
            const now = new Date();
            now.setMinutes(0, 0, 0);
            now.setHours(now.getHours() + 1);

            setDateField(startDate, now);
            setTimeField(startTime, now);
        } else if (!startTime.value) {
            startTime.value = '09:00';
        }

        if (!endDate.value || !endTime.value) {
            applySmartEndDate(true);
        }
    }

    [endDate, endTime].forEach(el => {
        if (el && !isCancelled) {
            el.addEventListener('input', () => { endManuallyTouched = true; });
            el.addEventListener('change', () => { endManuallyTouched = true; });
        }
    });

    [startDate, startTime].forEach(el => {
        if (el && !isCancelled) {
            el.addEventListener('change', function () {
                syncEndConstraints();
                applySmartEndDate(false);
            });
        }
    });

    if (eventTitleInput && !isCancelled) {
        eventTitleInput.addEventListener('input', function () {
            applySmartEndDate(false);
        });
    }

    initialiseDefaultDates();
    syncEndConstraints();

    /*
    |--------------------------------------------------------------------------
    | Map / Manchester-focused location search
    |--------------------------------------------------------------------------
    */
    const locationInput = document.getElementById('event_location');
    const locationLatInput = document.getElementById('event_location_lat');
    const locationLngInput = document.getElementById('event_location_lng');
    const locationSearchBtn = document.getElementById('locationSearchBtn');
    const locationResults = document.getElementById('locationSearchResults');
    const mapElement = document.getElementById('eventLocationMap');

    const MANCHESTER_CENTRE = { lat: 53.4808, lng: -2.2426 };
    const GREATER_MANCHESTER_VIEWBOX = '-2.75,53.75,-1.85,53.25';

    let map = null;
    let marker = null;

    function hasSavedCoordinates() {
        return locationLatInput.value !== '' && locationLngInput.value !== ''
            && !Number.isNaN(parseFloat(locationLatInput.value))
            && !Number.isNaN(parseFloat(locationLngInput.value));
    }

    function updateCoordinateInputs(lat, lng) {
        if (isCancelled) return;

        locationLatInput.value = Number(lat).toFixed(7);
        locationLngInput.value = Number(lng).toFixed(7);
    }

    function placeMarker(lat, lng, zoom = 15) {
        if (!map) return;

        const point = [lat, lng];

        if (!marker) {
            marker = L.marker(point, { draggable: !isCancelled }).addTo(map);

            marker.on('dragend', function () {
                const pos = marker.getLatLng();
                updateCoordinateInputs(pos.lat, pos.lng);
            });
        } else {
            marker.setLatLng(point);
        }

        updateCoordinateInputs(lat, lng);
        map.setView(point, zoom);
    }

    if (mapElement && typeof L !== 'undefined') {
        const defaultLat = hasSavedCoordinates() ? parseFloat(locationLatInput.value) : MANCHESTER_CENTRE.lat;
        const defaultLng = hasSavedCoordinates() ? parseFloat(locationLngInput.value) : MANCHESTER_CENTRE.lng;
        const defaultZoom = hasSavedCoordinates() ? 15 : 10;

        map = L.map('eventLocationMap').setView([defaultLat, defaultLng], defaultZoom);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        if (hasSavedCoordinates()) {
            placeMarker(defaultLat, defaultLng, 15);
        }

        setTimeout(() => {
            map.invalidateSize();
        }, 250);

        if (!isCancelled) {
            map.on('click', function (e) {
                placeMarker(e.latlng.lat, e.latlng.lng, map.getZoom());
            });
        }
    }

    function distanceFromManchesterKm(lat, lng) {
        const earthRadiusKm = 6371;
        const toRad = deg => deg * Math.PI / 180;
        const dLat = toRad(lat - MANCHESTER_CENTRE.lat);
        const dLng = toRad(lng - MANCHESTER_CENTRE.lng);

        const a =
            Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(toRad(MANCHESTER_CENTRE.lat)) * Math.cos(toRad(lat)) *
            Math.sin(dLng / 2) * Math.sin(dLng / 2);

        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

        return earthRadiusKm * c;
    }

    function normaliseLocationQuery(query) {
        const trimmed = query.trim();

        if (/\b(manchester|greater manchester|salford|stockport|bolton|bury|oldham|rochdale|tameside|trafford|wigan)\b/i.test(trimmed)) {
            return trimmed;
        }

        if (/^[a-z]{1,2}\d[a-z\d]?\s*\d[a-z]{2}$/i.test(trimmed)) {
            return `${trimmed}, Greater Manchester, UK`;
        }

        return `${trimmed}, Manchester, UK`;
    }

    async function fetchNominatim(params) {
        const url = 'https://nominatim.openstreetmap.org/search?' + new URLSearchParams(params).toString();

        const response = await fetch(url, {
            headers: {
                'Accept': 'application/json'
            }
        });

        if (!response.ok) {
            throw new Error('Location search failed.');
        }

        return await response.json();
    }

    function dedupeAndRankLocationResults(results) {
        const seen = new Set();

        return results
            .filter(result => result && result.lat && result.lon && result.display_name)
            .map(result => {
                const lat = parseFloat(result.lat);
                const lon = parseFloat(result.lon);
                const distanceKm = distanceFromManchesterKm(lat, lon);
                const importance = Number(result.importance || 0);
                const placeRank = Number(result.place_rank || 99);

                return {
                    ...result,
                    latNum: lat,
                    lonNum: lon,
                    distanceKm,
                    score: distanceKm - (importance * 20) + (placeRank * 0.1)
                };
            })
            .filter(result => {
                const key = `${result.latNum.toFixed(5)},${result.lonNum.toFixed(5)}`;

                if (seen.has(key)) {
                    return false;
                }

                seen.add(key);
                return true;
            })
            .sort((a, b) => a.score - b.score)
            .slice(0, 8);
    }

    async function searchLocation() {
        if (isCancelled || !locationInput || !locationResults || !locationSearchBtn) return;

        const query = locationInput.value.trim();

        locationResults.innerHTML = '';

        if (query.length < 3) {
            locationResults.innerHTML = '<div class="list-group-item text-muted">Enter at least 3 characters to search.</div>';
            return;
        }

        locationSearchBtn.disabled = true;
        locationSearchBtn.textContent = 'Searching...';

        try {
            const normalisedQuery = normaliseLocationQuery(query);

            const boundedResults = await fetchNominatim({
                format: 'jsonv2',
                addressdetails: '1',
                limit: '10',
                countrycodes: 'gb',
                bounded: '1',
                viewbox: GREATER_MANCHESTER_VIEWBOX,
                q: normalisedQuery
            });

            let results = Array.isArray(boundedResults) ? boundedResults : [];

            if (results.length === 0) {
                const fallbackResults = await fetchNominatim({
                    format: 'jsonv2',
                    addressdetails: '1',
                    limit: '10',
                    countrycodes: 'gb',
                    q: normalisedQuery
                });

                results = Array.isArray(fallbackResults) ? fallbackResults : [];
            }

            results = dedupeAndRankLocationResults(results);

            if (results.length === 0) {
                locationResults.innerHTML = '<div class="list-group-item text-muted">No matching locations found. Try adding a postcode, street, town, or “Manchester”.</div>';
                return;
            }

            results.forEach(result => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action location-result-item';

                const distanceText = result.distanceKm <= 80
                    ? `${result.distanceKm.toFixed(1)} km from Manchester centre`
                    : 'Outside the Manchester area';

                item.innerHTML = `
                    <strong>${escapeHtml(result.display_name)}</strong><br>
                    <small class="text-muted">${result.latNum.toFixed(5)}, ${result.lonNum.toFixed(5)}</small>
                    <span class="badge badge-light location-result-distance ml-2">${escapeHtml(distanceText)}</span>
                `;

                item.addEventListener('click', function () {
                    locationInput.value = result.display_name;
                    placeMarker(result.latNum, result.lonNum, 16);
                    locationResults.innerHTML = '';
                });

                locationResults.appendChild(item);
            });
        } catch (err) {
            locationResults.innerHTML = '<div class="list-group-item text-danger">Unable to search locations. Please try again.</div>';
        } finally {
            locationSearchBtn.disabled = false;
            locationSearchBtn.textContent = 'Search';
        }
    }

    if (locationSearchBtn && !isCancelled) {
        locationSearchBtn.addEventListener('click', searchLocation);
    }

    if (locationInput && !isCancelled) {
        locationInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchLocation();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | File upload list
    |--------------------------------------------------------------------------
    */
    const fileInput = document.getElementById('new_risk_assessments');
    const fileList = document.getElementById('fileList');
    const dropZone = document.getElementById('dropZone');
    const allowedExtensions = ['pdf', 'doc', 'docx'];
    const maxUploadBytes = 10 * 1024 * 1024;

    function getFileExtension(filename) {
        const parts = String(filename).split('.');
        return parts.length > 1 ? parts.pop().toLowerCase() : '';
    }

    function renderFiles() {
        if (!fileList || !fileInput) return;

        fileList.innerHTML = '';

        if (!fileInput.files.length) {
            return;
        }

        Array.from(fileInput.files).forEach((file, index) => {
            const extension = getFileExtension(file.name);
            const isAllowedExtension = allowedExtensions.includes(extension);
            const isAllowedSize = file.size <= maxUploadBytes;

            const row = document.createElement('div');
            row.className = 'border rounded p-3 mb-2';

            const warning = !isAllowedExtension
                ? '<div class="text-danger small mt-1">This file type will be rejected. Upload PDF, DOC or DOCX only.</div>'
                : (!isAllowedSize ? '<div class="text-danger small mt-1">This file is larger than 10MB and will be rejected.</div>' : '');

            row.innerHTML = `
                <div class="d-md-flex justify-content-between align-items-center">
                    <div class="mb-2 mb-md-0">
                        <strong>${escapeHtml(file.name)}</strong><br>
                        <small class="text-muted">${Math.round(file.size / 1024)} KB</small>
                        ${warning}
                    </div>
                    <div style="min-width:220px;">
                        <label class="small mb-1">Sharing</label>
                        <select class="form-control form-control-sm" name="upload_visibility[${index}]">
                            <option value="district" selected>Share with district</option>
                            <option value="group">Only my group</option>
                        </select>
                    </div>
                </div>
            `;

            fileList.appendChild(row);
        });
    }

    if (fileInput && !isCancelled) {
        fileInput.addEventListener('change', renderFiles);
    }

    if (dropZone && fileInput && !isCancelled) {
        ['dragenter', 'dragover'].forEach(evt => {
            dropZone.addEventListener(evt, function (e) {
                e.preventDefault();
                dropZone.classList.add('border-primary');
            });
        });

        ['dragleave', 'drop'].forEach(evt => {
            dropZone.addEventListener(evt, function (e) {
                e.preventDefault();
                dropZone.classList.remove('border-primary');
            });
        });

        dropZone.addEventListener('drop', function (e) {
            if (e.dataTransfer.files && e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                renderFiles();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Existing RA add/remove
    |--------------------------------------------------------------------------
    */
    const selectedExistingList = document.getElementById('selectedExistingList');
    const addedRaIds = new Set();

    document.querySelectorAll('input[name="selected_existing_ras[]"]').forEach(input => {
        addedRaIds.add(input.value);
    });

    if (!isCancelled) {
        document.querySelectorAll('.js-remove-attached-ra').forEach(btn => {
            btn.addEventListener('click', function () {
                const row = this.closest('[data-ra-id]');
                if (!row) return;

                const id = row.dataset.raId;
                addedRaIds.delete(id);
                row.remove();
            });
        });

        document.querySelectorAll('.js-add-existing-ra').forEach(btn => {
            btn.addEventListener('click', function () {
                const id = this.dataset.id;
                const title = this.dataset.title;

                if (addedRaIds.has(id)) return;

                addedRaIds.add(id);

                const row = document.createElement('div');
                row.className = 'risk-assessment-row d-flex justify-content-between align-items-center';
                row.dataset.raId = id;
                row.innerHTML = `
                    <div>
                        <strong>${escapeHtml(title)}</strong>
                        <input type="hidden" name="selected_existing_ras[]" value="${escapeHtml(id)}">
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger">Remove</button>
                `;

                row.querySelector('button').addEventListener('click', function () {
                    addedRaIds.delete(id);
                    row.remove();
                });

                if (selectedExistingList) {
                    selectedExistingList.appendChild(row);
                }
            });
        });
    }

    /*
    |--------------------------------------------------------------------------
    | RA table search
    |--------------------------------------------------------------------------
    */
    const raSearch = document.getElementById('raSearch');

    if (raSearch) {
        raSearch.addEventListener('input', function () {
            const q = raSearch.value.trim().toLowerCase();

            document.querySelectorAll('#raTable tbody tr').forEach(row => {
                const haystack = row.dataset.search || '';
                row.style.display = haystack.includes(q) ? '' : 'none';
            });
        });
    }
});
</script>

<?php render_page_end(); ?>