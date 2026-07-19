<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/system-admin-helpers.php';

$user = sa_require_system_admin();

$personId = (int) ($_GET['person_id'] ?? 0);

if ($personId < 1) {
    http_response_code(400);
    echo '<h1>Invalid request</h1><p>Please provide a valid Person ID.</p>';
    exit;
}

$person = sa_fetch_person_full($personId);

if (!$person) {
    http_response_code(404);
    echo '<h1>Person not found</h1><p>No person record found with ID ' . $personId . '.</p>';
    exit;
}

$memberships = sa_fetch_person_memberships($personId);
$accounts = sa_fetch_person_accounts($personId);
$auditHistory = sa_fetch_person_audit_history($personId);

$generatedAt = date('d M Y H:i:s');
$generatedBy = $user['full_name'] ?? $user['email'] ?? 'System Admin';

// Log that this report was generated (this is itself an auditable action)
audit_log('admin.gdpr_report_generated', 'person', $personId, $personId, [
    'generated_by' => $generatedBy,
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>GDPR Audit Report — <?= e($person['full_name'] ?? 'Person #' . $personId) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="icon" type="image/png" href="/assets/img/favicon.png">

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #1d1d1b;
            background: #fff;
            padding: 2rem;
            max-width: 1000px;
            margin: 0 auto;
        }

        @media print {
            body { padding: .5cm; font-size: 9pt; }
            .no-print { display: none !important; }
            .page-break { page-break-before: always; }
            h2 { page-break-after: avoid; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; }
        }

        .report-header {
            border-bottom: 3px solid #1d1d1b;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }

        .report-header h1 {
            font-size: 1.6rem;
            margin-bottom: .25rem;
        }

        .report-meta {
            font-size: .85rem;
            color: #555;
        }

        .report-meta span {
            display: inline-block;
            margin-right: 1.5rem;
        }

        .print-btn {
            display: inline-block;
            margin-bottom: 1.5rem;
            padding: .6rem 1.2rem;
            background: #4d0b93;
            color: #fff;
            border: 0;
            font-weight: 700;
            font-size: .9rem;
            cursor: pointer;
        }

        .print-btn:hover { background: #3a0870; }

        h2 {
            font-size: 1.15rem;
            margin: 2rem 0 .75rem;
            padding-bottom: .35rem;
            border-bottom: 2px solid #e5e5e5;
            color: #1d1d1b;
        }

        h2:first-of-type { margin-top: 0; }

        .data-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: .5rem .75rem;
            margin-bottom: 1.5rem;
        }

        .data-item {
            padding: .4rem 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .data-label {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .02em;
            color: #666;
        }

        .data-value {
            font-size: .9rem;
            color: #1d1d1b;
            word-break: break-word;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
            font-size: .82rem;
        }

        th, td {
            border: 1px solid #d8d8d8;
            padding: .45rem .6rem;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f7f5fb;
            font-weight: 700;
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .01em;
            color: #4d0b93;
        }

        .badge {
            display: inline-block;
            padding: .1rem .3rem;
            font-size: .7rem;
            font-weight: 700;
        }

        .badge-active { background: #d1e7dd; color: #0f5132; }
        .badge-inactive { background: #f8d7da; color: #842029; }
        .badge-info { background: #e7f1ff; color: #004085; }
        .badge-warning { background: #fff3cd; color: #664d03; }

        .section-note {
            font-size: .82rem;
            color: #555;
            font-style: italic;
            margin-bottom: .75rem;
        }

        .footer-note {
            margin-top: 3rem;
            padding-top: 1rem;
            border-top: 2px solid #e5e5e5;
            font-size: .78rem;
            color: #666;
        }

        .audit-count {
            font-size: .85rem;
            color: #555;
            margin-bottom: .5rem;
        }
    </style>
</head>
<body>

<button class="print-btn no-print" onclick="window.print();">Print / Save as PDF</button>

<div class="report-header">
    <h1>GDPR Audit Report</h1>
    <p class="report-meta">
        <span><strong>Subject:</strong> <?= e($person['full_name'] ?? '') ?> (ID: <?= $personId ?>)</span>
        <span><strong>Generated:</strong> <?= e($generatedAt) ?></span>
        <span><strong>By:</strong> <?= e($generatedBy) ?></span>
    </p>
</div>

<h2>1. Personal Data Held</h2>
<p class="section-note">All personal data currently stored in the system for this individual.</p>

<div class="data-grid">
    <?php
    $personalFields = [
        'id' => 'Person ID',
        'full_name' => 'Full Name',
        'preferred_name' => 'Preferred Name',
        'primary_email' => 'Primary Email',
        'phone' => 'Phone',
        'district_email' => 'District Email',
        'status' => 'Account Status',
        'microsoft_user_principal_name' => 'M365 Username',
        'm365_user_principal_name' => 'M365 Username',
        'microsoft_user_id' => 'M365 User ID',
        'm365_user_id' => 'M365 User ID',
        'created_at' => 'Record Created',
        'updated_at' => 'Last Updated',
        'leaver_notified_at' => 'Leaver Notified',
    ];

    foreach ($personalFields as $field => $label):
        if (!array_key_exists($field, $person) || $person[$field] === null || $person[$field] === '') continue;
    ?>
        <div class="data-item">
            <div class="data-label"><?= e($label) ?></div>
            <div class="data-value"><?= e((string) $person[$field]) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<h2>2. Group Memberships</h2>
<p class="section-note">All group associations, both current and historical.</p>

<?php if ($memberships): ?>
    <table>
        <thead>
            <tr>
                <th>Group</th>
                <th>Role</th>
                <th>Access Level</th>
                <th>Status</th>
                <th>Primary</th>
                <th>Approved</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($memberships as $m): ?>
                <tr>
                    <td><?= e($m['group_name'] ?? '') ?></td>
                    <td><?= e(ucwords(str_replace('_', ' ', (string) ($m['membership_role'] ?? 'member')))) ?></td>
                    <td><?= e(ucwords(str_replace('_', ' ', (string) ($m['access_level'] ?? 'member')))) ?></td>
                    <td>
                        <span class="badge <?= ($m['status'] ?? '') === 'active' ? 'badge-active' : 'badge-inactive' ?>">
                            <?= e(ucfirst((string) ($m['status'] ?? 'unknown'))) ?>
                        </span>
                    </td>
                    <td><?= !empty($m['is_primary']) ? 'Yes' : 'No' ?></td>
                    <td><?= e((string) ($m['approved_at'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>No group memberships found.</p>
<?php endif; ?>

<h2>3. Linked Accounts</h2>
<p class="section-note">External authentication accounts linked to this person.</p>

<?php if ($accounts): ?>
    <table>
        <thead>
            <tr>
                <th>Provider</th>
                <th>Provider Subject</th>
                <th>Email</th>
                <th>Last Login</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($accounts as $a): ?>
                <tr>
                    <td><?= e(ucfirst((string) ($a['provider'] ?? ''))) ?></td>
                    <td><?= e((string) ($a['provider_subject'] ?? '')) ?></td>
                    <td><?= e((string) ($a['email'] ?? '')) ?></td>
                    <td><?= e((string) ($a['last_login_at'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>No linked accounts found.</p>
<?php endif; ?>

<h2 class="page-break">4. Full Activity Audit Trail</h2>
<p class="section-note">Complete history of all actions performed by or upon this individual.</p>
<p class="audit-count"><strong><?= number_format(count($auditHistory)) ?></strong> audit log entries found.</p>

<?php if ($auditHistory): ?>
    <table>
        <thead>
            <tr>
                <th>Date & Time</th>
                <th>Event</th>
                <th>Role</th>
                <th>Group</th>
                <th>IP Address</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($auditHistory as $row): ?>
                <?php
                    $eventCode = (string) ($row['action'] ?? '');
                    $isActor = ((int) ($row['actor_person_id'] ?? 0)) === $personId;
                    $isTarget = ((int) ($row['target_person_id'] ?? 0)) === $personId;
                    $role = $isActor && $isTarget ? 'Both' : ($isActor ? 'Actor' : 'Target');
                    $details = json_decode((string) ($row['details_json'] ?? ''), true);
                    $detailSummary = '';
                    if (is_array($details)) {
                        $parts = [];
                        foreach ($details as $k => $v) {
                            if ($v === null || $v === '' || $k === 'status') continue;
                            $parts[] = ucwords(str_replace('_', ' ', $k)) . ': ' . (is_scalar($v) ? $v : json_encode($v));
                        }
                        $detailSummary = implode('; ', array_slice($parts, 0, 4));
                    }
                ?>
                <tr>
                    <td><?= e(date('d M Y H:i', strtotime((string) $row['created_at']))) ?></td>
                    <td><?= e(audit_event_label($eventCode)) ?></td>
                    <td>
                        <span class="badge <?= $isActor ? 'badge-info' : 'badge-warning' ?>">
                            <?= e($role) ?>
                        </span>
                    </td>
                    <td><?= e((string) ($row['group_name'] ?? '—')) ?></td>
                    <td><?= e((string) ($row['ip_address'] ?? '')) ?></td>
                    <td><?= e($detailSummary) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>No audit trail entries found for this person.</p>
<?php endif; ?>

<div class="footer-note">
    <p><strong>Irwell Valley Scout District — GDPR Data Subject Access Report</strong></p>
    <p>
        This report was generated automatically from the Leader Tool audit system.
        It contains all personal data held and a complete record of system interactions
        involving the named individual. Generated on <?= e($generatedAt) ?> by <?= e($generatedBy) ?>.
    </p>
    <p>
        For data subject access requests, contact the District Data Controller.
        Under GDPR Article 15, individuals have the right to obtain confirmation
        as to whether or not personal data concerning them is being processed,
        and access to that personal data.
    </p>
</div>

</body>
</html>
