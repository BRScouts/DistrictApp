<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_auth();

$pdo = db();

$currentGroup = auth_group();
$isAdminOrReviewer = is_reviewer_or_admin();
$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);

if ($eventId <= 0) {
    redirect(ROUTE_403);
}

$flash = '';
$error = '';

/*
|--------------------------------------------------------------------------
| Resolve group
|--------------------------------------------------------------------------
*/
$groupId = 0;

if ($currentGroup) {
    $groupId = (int)$currentGroup['group_id'];
} elseif ($isAdminOrReviewer) {
    $groupId = (int)($_GET['group_id'] ?? $_POST['group_id'] ?? 0);

    if ($groupId <= 0 && $eventId > 0) {
        $stmt = $pdo->prepare("SELECT group_id FROM events WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $eventId]);
        $existingEventGroupId = $stmt->fetchColumn();
        if ($existingEventGroupId) {
            $groupId = (int)$existingEventGroupId;
        }
    }
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
    SELECT id, full_name, email
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
    ORDER BY ra.updated_at DESC, ra.title ASC
");
$stmt->execute(['group_id' => $groupId]);
$availableRiskAssessments = $stmt->fetchAll();

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

function save_group_contact(PDO $pdo, int $groupId, string $name, string $email): ?int
{
    if ($name === '' || $email === '') {
        return null;
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
            SET last_used_at = NOW(),
                updated_at = NOW(),
                is_active = 1
            WHERE id = :id
        ");
        $update->execute(['id' => (int)$existingId]);
        return (int)$existingId;
    }

    $insert = $pdo->prepare("
        INSERT INTO group_contacts (
            group_id,
            full_name,
            email,
            is_active,
            last_used_at,
            created_at,
            updated_at
        ) VALUES (
            :group_id,
            :full_name,
            :email,
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
    ]);

    return (int)$pdo->lastInsertId();
}


function write_audit_log(PDO $pdo, string $entityType, int $entityId, string $action, array $details = []): void
{
    $admin = auth_admin();
    $group = auth_group();

    $actorType = $admin ? 'admin' : 'group_link';
    $adminUserId = $admin ? (int)$admin['admin_user_id'] : null;
    $groupId = $group ? (int)$group['group_id'] : null;

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
            :entity_type,
            :entity_id,
            :action,
            :details,
            NOW()
        )
    ");

    $stmt->execute([
        'actor_type' => $actorType,
        'admin_user_id' => $adminUserId,
        'group_id' => $groupId,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'action' => $action,
        'details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
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
    $uploadDir = __DIR__ . '/uploads/risk_assessments/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $originalName = $file['name'];
    $tmpName = $file['tmp_name'];
    $fileSize = (int)$file['size'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, ['pdf', 'doc', 'docx'], true)) {
        throw new RuntimeException('Only PDF, DOC and DOCX files are allowed.');
    }

    if ($fileSize > 10 * 1024 * 1024) {
        throw new RuntimeException('A risk assessment file exceeded 10MB.');
    }

    $storedFilename = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $uploadDir . $storedFilename;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Unable to save uploaded file.');
    }

    $mimeType = mime_content_type($destination) ?: 'application/octet-stream';
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
| Edit existing event
|--------------------------------------------------------------------------
*/
$editingEvent = null;

$form = [
    'contact_name' => '',
    'contact_email' => '',
    'event_title' => '',
    'event_description' => '',
    'event_location' => '',
    'starts_at_date' => '',
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

if ($eventId > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM events
        WHERE id = :id
          AND group_id = :group_id
        LIMIT 1
    ");
    $stmt->execute([
        'id' => $eventId,
        'group_id' => $groupId,
    ]);
    $editingEvent = $stmt->fetch();

    if ($editingEvent) {
        $form = [
            'contact_name' => (string)$editingEvent['contact_name'],
            'contact_email' => (string)$editingEvent['contact_email'],
            'event_title' => (string)$editingEvent['event_title'],
            'event_description' => (string)$editingEvent['event_description'],
            'event_location' => (string)$editingEvent['event_location'],
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
    }
}


if (!$editingEvent) {
    redirect(ROUTE_403);
}

$attachedRiskAssessments = [];
$attachedRiskAssessmentIds = [];
$eventAuditRows = [];
$eventReviewRows = [];

if ($editingEvent) {
    $stmt = $pdo->prepare("
        SELECT
            ra.id,
            ra.title,
            ra.original_filename,
            ra.updated_at,
            g.group_name
        FROM event_risk_assessments era
        INNER JOIN risk_assessments ra ON ra.id = era.risk_assessment_id
        INNER JOIN groups g ON g.id = ra.group_id
        WHERE era.event_id = :event_id
        ORDER BY ra.updated_at DESC, ra.title ASC
    ");
    $stmt->execute(['event_id' => (int)$editingEvent['id']]);
    $attachedRiskAssessments = $stmt->fetchAll();
    $attachedRiskAssessmentIds = array_map(fn($ra) => (int)$ra['id'], $attachedRiskAssessments);
}

/*
|--------------------------------------------------------------------------
| Handle save
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['contact_name'] = trim((string)($_POST['contact_name'] ?? ''));
    $form['contact_email'] = trim((string)($_POST['contact_email'] ?? ''));
    $form['event_title'] = trim((string)($_POST['event_title'] ?? ''));
    $form['event_description'] = trim((string)($_POST['event_description'] ?? ''));
    $form['event_location'] = trim((string)($_POST['event_location'] ?? ''));
    $form['starts_at_date'] = trim((string)($_POST['starts_at_date'] ?? ''));
    $form['starts_at_time'] = trim((string)($_POST['starts_at_time'] ?? ''));
    $form['ends_at_date'] = trim((string)($_POST['ends_at_date'] ?? ''));
    $form['ends_at_time'] = trim((string)($_POST['ends_at_time'] ?? ''));
    $form['squirrels_count'] = trim((string)($_POST['squirrels_count'] ?? '0'));
    $form['beavers_count'] = trim((string)($_POST['beavers_count'] ?? '0'));
    $form['cubs_count'] = trim((string)($_POST['cubs_count'] ?? '0'));
    $form['scouts_count'] = trim((string)($_POST['scouts_count'] ?? '0'));
    $form['explorers_count'] = trim((string)($_POST['explorers_count'] ?? '0'));
    $form['network_count'] = trim((string)($_POST['network_count'] ?? '0'));
    $form['adults_count'] = trim((string)($_POST['adults_count'] ?? '0'));

    $selectedExistingIds = array_values(array_unique(array_map('intval', $_POST['selected_existing_ras'] ?? [])));
    $uploadVisibilities = $_POST['upload_visibility'] ?? [];

    if ($form['contact_name'] === '') {
        $error = 'Contact name is required.';
    } elseif ($form['contact_email'] === '' || !filter_var($form['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'A valid contact email is required.';
    } elseif ($form['event_title'] === '') {
        $error = 'Event title is required.';
    } elseif ($form['event_location'] === '') {
        $error = 'Event location is required.';
    } elseif ($form['starts_at_date'] === '' || $form['starts_at_time'] === '' || $form['ends_at_date'] === '' || $form['ends_at_time'] === '') {
        $error = 'Start and end date/time are required.';
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
                $form['contact_name'],
                $form['contact_email']
            );

            if ($editingEvent) {
                $stmt = $pdo->prepare("
                    UPDATE events
                    SET
                        contact_id = :contact_id,
                        contact_name = :contact_name,
                        contact_email = :contact_email,
                        event_title = :event_title,
                        event_description = :event_description,
                        event_location = :event_location,
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
                        risk_assessment_completed = 1,
                        status = 'submitted',
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    'contact_id' => $contactId,
                    'contact_name' => $form['contact_name'],
                    'contact_email' => $form['contact_email'],
                    'event_title' => $form['event_title'],
                    'event_description' => $form['event_description'] !== '' ? $form['event_description'] : null,
                    'event_location' => $form['event_location'],
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
                    'id' => (int)$editingEvent['id'],
                ]);

                $savedEventId = (int)$editingEvent['id'];

                $stmt = $pdo->prepare("DELETE FROM event_risk_assessments WHERE event_id = :event_id");
                $stmt->execute(['event_id' => $savedEventId]);
            }


            write_audit_log(
                $pdo,
                'event',
                $savedEventId,
                'updated_resubmitted',
                [
                    'title' => $form['event_title'],
                    'group_id' => $groupId,
                    'status' => 'submitted',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ]
            );

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

                    if ((int)$_FILES['new_risk_assessments']['error'][$i] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('One of the uploaded risk assessments failed to upload.');
                    }

                    $singleFile = [
                        'name' => $_FILES['new_risk_assessments']['name'][$i],
                        'type' => $_FILES['new_risk_assessments']['type'][$i],
                        'tmp_name' => $_FILES['new_risk_assessments']['tmp_name'][$i],
                        'error' => $_FILES['new_risk_assessments']['error'][$i],
                        'size' => $_FILES['new_risk_assessments']['size'][$i],
                    ];

                    $visibility = $uploadVisibilities[$i] ?? 'district';

                    $newRaId = create_uploaded_risk_assessment_multi(
                        $pdo,
                        $groupId,
                        $form['contact_name'],
                        $form['contact_email'],
                        $form['event_title'] . ' Risk Assessment',
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

                    write_audit_log(
                        $pdo,
                        'risk_assessment',
                        $newRaId,
                        'uploaded_for_event',
                        [
                            'event_id' => $savedEventId,
                            'title' => $form['event_title'] . ' Risk Assessment',
                            'filename' => $singleFile['name'],
                            'visibility' => $visibility,
                            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        ]
                    );
                }
            }

            $pdo->commit();

            $subject = 'Away From Hut submission: ' . $form['event_title'];
            $eventLink = BASE_URL . '/manage-event.php?event_id=' . $savedEventId;

            queue_email(
                $form['contact_email'],
                $subject,
                nl2br(e(
                    "Hello {$form['contact_name']},\n\n" .
                    "Your Away From Hut event has been updated and resubmitted for review.\n\n" .
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
                        "An Away From Hut event has been updated for {$group['group_name']}.\n\n" .
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
                    "An Away From Hut event has been updated.\n\n" .
                    "Event: {$form['event_title']}\n" .
                    "Group: {$group['group_name']}\n" .
                    "Review: " . BASE_URL . "/reviewer/events.php\n"
                ))
            );

            redirect(BASE_URL . '/manage-event.php?event_id=' . $savedEventId . '&saved=1' . ($isAdminOrReviewer ? '&group_id=' . $groupId : ''));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Unable to save event: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $flash = 'Event updated successfully.';
}

if ($editingEvent) {
    $riskAssessmentIds = $attachedRiskAssessmentIds;

    $auditClauses = ["(al.entity_type = 'event' AND al.entity_id = :event_id)"];
    $auditParams = ['event_id' => (int)$editingEvent['id']];

    if (!empty($riskAssessmentIds)) {
        $raPlaceholders = [];
        foreach ($riskAssessmentIds as $idx => $raId) {
            $param = 'ra_id_' . $idx;
            $raPlaceholders[] = ':' . $param;
            $auditParams[$param] = $raId;
        }
        $auditClauses[] = "(al.entity_type = 'risk_assessment' AND al.entity_id IN (" . implode(',', $raPlaceholders) . "))";
    }

    $stmt = $pdo->prepare("
        SELECT
            al.*,
            au.full_name AS admin_name,
            g.group_name AS actor_group_name
        FROM audit_log al
        LEFT JOIN admin_users au ON au.id = al.admin_user_id
        LEFT JOIN groups g ON g.id = al.group_id
        WHERE " . implode(' OR ', $auditClauses) . "
        ORDER BY al.created_at DESC, al.id DESC
        LIMIT 100
    ");
    $stmt->execute($auditParams);
    $eventAuditRows = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT
            er.*,
            au.full_name AS admin_name
        FROM event_reviews er
        INNER JOIN admin_users au ON au.id = er.admin_user_id
        WHERE er.event_id = :event_id
        ORDER BY er.created_at DESC, er.id DESC
    ");
    $stmt->execute(['event_id' => (int)$editingEvent['id']]);
    $eventReviewRows = $stmt->fetchAll();
}

render_page_start('Manage Event');
render_header('calendar');
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-xl-10 col-xxl-8">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="mb-1">Manage Event</h1>
                    <p class="text-muted mb-0">View, update and audit an Away From Hut notification.</p>
                </div>
            </div>

            <?php if ($flash !== ''): ?>
                <div class="alert alert-success"><?= e($flash) ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($editingEvent && !empty($editingEvent['admin_comments'])): ?>
                <div class="alert alert-warning">
                    <strong>Reviewer comments:</strong><br>
                    <?= nl2br(e((string)$editingEvent['admin_comments'])) ?>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">

                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h2 class="h4 mb-3">Event details</h2>

                        <?php if (false): ?>
                            <div class="form-group">
                                <label for="group_id">Group</label>
                                <select class="form-control" id="group_id" name="group_id" onchange="this.form.submit()">
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
                            </div>
                        <?php endif; ?>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="contact_name">Contact name</label>
                                <input list="contact_name_list" class="form-control" id="contact_name" name="contact_name" value="<?= e($form['contact_name']) ?>" required>
                                <datalist id="contact_name_list">
                                    <?php foreach ($contacts as $contact): ?>
                                        <option value="<?= e($contact['full_name']) ?>" data-email="<?= e($contact['email']) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="contact_email">Contact email</label>
                                <input type="email" class="form-control" id="contact_email" name="contact_email" value="<?= e($form['contact_email']) ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="event_title">Event title</label>
                            <input type="text" class="form-control" id="event_title" name="event_title" value="<?= e($form['event_title']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="event_description">Description</label>
                            <textarea class="form-control" id="event_description" name="event_description" rows="4"><?= e($form['event_description']) ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="event_location">Location</label>
                            <input type="text" class="form-control" id="event_location" name="event_location" value="<?= e($form['event_location']) ?>" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-3">
                                <label for="starts_at_date">Start date</label>
                                <input type="date" class="form-control" id="starts_at_date" name="starts_at_date" value="<?= e($form['starts_at_date']) ?>" required>
                            </div>
                            <div class="form-group col-md-3">
                                <label for="starts_at_time">Start time</label>
                                <input type="time" class="form-control" id="starts_at_time" name="starts_at_time" value="<?= e($form['starts_at_time']) ?>" required>
                            </div>
                            <div class="form-group col-md-3">
                                <label for="ends_at_date">End date</label>
                                <input type="date" class="form-control" id="ends_at_date" name="ends_at_date" value="<?= e($form['ends_at_date']) ?>" required>
                            </div>
                            <div class="form-group col-md-3">
                                <label for="ends_at_time">End time</label>
                                <input type="time" class="form-control" id="ends_at_time" name="ends_at_time" value="<?= e($form['ends_at_time']) ?>" required>
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
                                <input type="number" min="0" class="form-control" id="squirrels_count" name="squirrels_count" value="<?= e($form['squirrels_count']) ?>">
                            </div>
                            <div class="form-group col-md-3">
                                <label for="beavers_count">Beavers</label>
                                <input type="number" min="0" class="form-control" id="beavers_count" name="beavers_count" value="<?= e($form['beavers_count']) ?>">
                            </div>
                            <div class="form-group col-md-3">
                                <label for="cubs_count">Cubs</label>
                                <input type="number" min="0" class="form-control" id="cubs_count" name="cubs_count" value="<?= e($form['cubs_count']) ?>">
                            </div>
                            <div class="form-group col-md-3">
                                <label for="scouts_count">Scouts</label>
                                <input type="number" min="0" class="form-control" id="scouts_count" name="scouts_count" value="<?= e($form['scouts_count']) ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-3">
                                <label for="explorers_count">Explorers</label>
                                <input type="number" min="0" class="form-control" id="explorers_count" name="explorers_count" value="<?= e($form['explorers_count']) ?>">
                            </div>
                            <div class="form-group col-md-3">
                                <label for="network_count">Network</label>
                                <input type="number" min="0" class="form-control" id="network_count" name="network_count" value="<?= e($form['network_count']) ?>">
                            </div>
                            <div class="form-group col-md-3">
                                <label for="adults_count">Adults</label>
                                <input type="number" min="0" class="form-control" id="adults_count" name="adults_count" value="<?= e($form['adults_count']) ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h4 mb-0">Risk assessments</h2>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#existingRaModal">
                                Choose existing
                            </button>
                        </div>

                        <div class="alert alert-info">
                            Your own group’s risk assessments from the last 90 days can be attached directly.
                            Other groups’ shared assessments can be downloaded and reviewed, but not attached directly.
                        </div>

                        <div id="dropZone" class="border rounded p-4 text-center mb-3" style="background:#fafafa; border-style:dashed !important;">
                            <p class="mb-2"><strong>Drag and drop risk assessments here</strong></p>
                            <p class="text-muted mb-3">or choose files below</p>
                            <input type="file" id="new_risk_assessments" name="new_risk_assessments[]" multiple accept=".pdf,.doc,.docx" class="form-control-file">
                        </div>

                        <?php if (!empty($attachedRiskAssessments)): ?>
                            <div class="mb-4">
                                <h3 class="h6">Currently attached</h3>
                                <?php foreach ($attachedRiskAssessments as $ra): ?>
                                    <div class="border rounded p-2 mb-2 d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?= e($ra['title']) ?></strong><br>
                                            <small class="text-muted">
                                                <?= e($ra['group_name']) ?> &middot;
                                                <?= e($ra['original_filename']) ?> &middot;
                                                updated <?= e(date('d M Y', strtotime((string)$ra['updated_at']))) ?>
                                            </small>
                                            <input type="hidden" name="selected_existing_ras[]" value="<?= (int)$ra['id'] ?>">
                                        </div>
                                        <a href="<?= e(BASE_URL . '/download-risk-assessment.php?id=' . (int)$ra['id']) ?>"
                                           class="btn btn-sm btn-outline-secondary" target="_blank">
                                            Download
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div id="fileList"></div>

                        <div id="selectedExistingList" class="mt-4"></div>
                    </div>
                </div>

                <div class="d-flex justify-content-end mb-5">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <?= $editingEvent ? 'Update and resubmit event' : 'Save changes' ?>
                    </button>
                </div>
            </form>

            <?php if ($editingEvent): ?>
                <div class="card shadow-sm mb-5">
                    <div class="card-body p-4">
                        <h2 class="h4 mb-3">Audit log</h2>

                        <?php if (empty($eventAuditRows) && empty($eventReviewRows)): ?>
                            <p class="text-muted mb-0">No audit activity has been recorded for this event yet.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead>
                                        <tr>
                                            <th>When</th>
                                            <th>Actor</th>
                                            <th>Type</th>
                                            <th>Action</th>
                                            <th>Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($eventAuditRows as $row): ?>
                                            <?php
                                            $details = json_decode((string)($row['details'] ?? ''), true);
                                            if (!is_array($details)) {
                                                $details = [];
                                            }
                                            $actor = $row['actor_type'] === 'admin'
                                                ? ($row['admin_name'] ?: 'Admin user')
                                                : ($row['actor_group_name'] ?: 'Group link');
                                            ?>
                                            <tr>
                                                <td><?= e(date('d M Y H:i', strtotime((string)$row['created_at']))) ?></td>
                                                <td><?= e((string)$actor) ?></td>
                                                <td><?= e(ucwords(str_replace('_', ' ', (string)$row['entity_type']))) ?></td>
                                                <td><?= e(ucwords(str_replace('_', ' ', (string)$row['action']))) ?></td>
                                                <td>
                                                    <?php if (!empty($details)): ?>
                                                        <?php foreach ($details as $key => $value): ?>
                                                            <?php if ($value === null || $value === '') continue; ?>
                                                            <div><strong><?= e(ucwords(str_replace('_', ' ', (string)$key))) ?>:</strong> <?= e(is_scalar($value) ? (string)$value : json_encode($value)) ?></div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">&mdash;</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>

                                        <?php foreach ($eventReviewRows as $row): ?>
                                            <tr>
                                                <td><?= e(date('d M Y H:i', strtotime((string)$row['created_at']))) ?></td>
                                                <td><?= e((string)$row['admin_name']) ?></td>
                                                <td>Review</td>
                                                <td><?= e(ucwords(str_replace('_', ' ', (string)$row['action']))) ?></td>
                                                <td><?= !empty($row['comments']) ? nl2br(e((string)$row['comments'])) : '<span class="text-muted">&mdash;</span>' ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

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
                    <input type="text" class="form-control" id="raSearch" placeholder="Search by title, group or activity">
                </div>

                <div class="table-responsive">
                    <table class="table table-striped" id="raTable">
                        <thead>
                            <tr>
                                <th>Title</th>
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
                            ?>
                            <tr data-search="<?= e(strtolower($ra['title'] . ' ' . $ra['group_name'] . ' ' . ($ra['activity_type'] ?? ''))) ?>">
                                <td>
                                    <strong><?= e($ra['title']) ?></strong>
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
                                <td>
                                    <?php if ($canAttach): ?>
                                        <button type="button"
                                                class="btn btn-sm btn-primary js-add-existing-ra"
                                                data-id="<?= (int)$ra['id'] ?>"
                                                data-title="<?= e($ra['title']) ?>">
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
    const contacts = <?= json_encode(array_map(fn($c) => [
        'full_name' => $c['full_name'],
        'email' => $c['email']
    ], $contacts), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const contactNameInput = document.getElementById('contact_name');
    const contactEmailInput = document.getElementById('contact_email');

    if (contactNameInput && contactEmailInput) {
        contactNameInput.addEventListener('change', function () {
            const name = contactNameInput.value.trim().toLowerCase();
            const found = contacts.find(c => c.full_name.trim().toLowerCase() === name);
            if (found) {
                contactEmailInput.value = found.email;
            }
        });
    }

    const startDate = document.getElementById('starts_at_date');
    const startTime = document.getElementById('starts_at_time');
    const endDate = document.getElementById('ends_at_date');
    const endTime = document.getElementById('ends_at_time');

    function syncEndConstraints() {
        if (startDate.value) {
            endDate.min = startDate.value;
        }

        if (startDate.value && endDate.value && startDate.value === endDate.value && startTime.value) {
            endTime.min = startTime.value;
        } else {
            endTime.removeAttribute('min');
        }
    }

    [startDate, startTime, endDate].forEach(el => {
        if (el) el.addEventListener('change', syncEndConstraints);
    });
    syncEndConstraints();

    const fileInput = document.getElementById('new_risk_assessments');
    const fileList = document.getElementById('fileList');
    const dropZone = document.getElementById('dropZone');

    function renderFiles() {
        fileList.innerHTML = '';
        if (!fileInput.files.length) return;

        Array.from(fileInput.files).forEach((file, index) => {
            const row = document.createElement('div');
            row.className = 'border rounded p-3 mb-2';

            row.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${file.name}</strong><br>
                        <small class="text-muted">${Math.round(file.size / 1024)} KB</small>
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

    if (fileInput) {
        fileInput.addEventListener('change', renderFiles);
    }

    if (dropZone && fileInput) {
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

    const selectedExistingList = document.getElementById('selectedExistingList');
    const addedRaIds = new Set();

    document.querySelectorAll('.js-add-existing-ra').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.dataset.id;
            const title = this.dataset.title;

            if (addedRaIds.has(id)) return;
            addedRaIds.add(id);

            const row = document.createElement('div');
            row.className = 'border rounded p-2 mb-2 d-flex justify-content-between align-items-center';
            row.dataset.raId = id;
            row.innerHTML = `
                <div>
                    <strong>${title}</strong>
                    <input type="hidden" name="selected_existing_ras[]" value="${id}">
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger">Remove</button>
            `;

            row.querySelector('button').addEventListener('click', function () {
                addedRaIds.delete(id);
                row.remove();
            });

            selectedExistingList.appendChild(row);
        });
    });

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