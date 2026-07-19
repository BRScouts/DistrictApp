<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/system-admin-helpers.php';

$user = sa_require_system_admin();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

// Search for people
$search = trim((string) ($_GET['search'] ?? ''));
$searchResults = [];

if ($search !== '') {
    try {
        $stmt = db()->prepare("
            SELECT
                p.id,
                p.full_name,
                p.primary_email,
                p.status,
                GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.group_name SEPARATOR ', ') AS groups_list
            FROM people p
            LEFT JOIN group_memberships gm ON gm.person_id = p.id AND gm.status = 'active'
            LEFT JOIN groups g ON g.id = gm.group_id AND g.is_active = 1
            WHERE (
                p.full_name LIKE :search
                OR p.primary_email LIKE :search2
                OR p.id = :exact_id
            )
            GROUP BY p.id, p.full_name, p.primary_email, p.status
            ORDER BY p.full_name ASC
            LIMIT 20
        ");
        $stmt->execute([
            'search' => '%' . $search . '%',
            'search2' => '%' . $search . '%',
            'exact_id' => is_numeric($search) ? (int) $search : 0,
        ]);
        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $searchResults = [];
    }
}

// Fetch recently generated reports from audit log
$recentReports = [];
try {
    $stmt = db()->prepare("
        SELECT
            al.created_at,
            al.target_person_id,
            al.actor_person_id,
            al.details_json,
            tp.full_name AS subject_name,
            tp.primary_email AS subject_email,
            ap.full_name AS generated_by_name
        FROM audit_log al
        LEFT JOIN people tp ON tp.id = al.target_person_id
        LEFT JOIN people ap ON ap.id = al.actor_person_id
        WHERE al.action = 'admin.gdpr_report_generated'
        ORDER BY al.created_at DESC, al.id DESC
        LIMIT 20
    ");
    $stmt->execute();
    $recentReports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Non-critical
}

$saNavCurrent = 'gdpr';

$pageTitle = 'System Admin — GDPR Reports | ' . $appName;
$heroTitle = 'System Admin';
$heroText = 'Generate GDPR data subject access reports.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/system-admin.php">System Admin</a> / GDPR Reports';

include __DIR__ . '/header.php';
?>

<style>
    .sa-service-bar {
        background: #1d1d1b;
        border-bottom: 4px solid #ffdd00;
    }

    .sa-service-bar-inner {
        max-width: 1180px;
        margin: 0 auto;
        padding: 0 1rem;
        display: flex;
        align-items: stretch;
        gap: 0;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .sa-nav-link {
        display: inline-flex;
        align-items: center;
        padding: .85rem 1.1rem;
        color: rgba(255, 255, 255, .88);
        font-weight: 900;
        font-size: .92rem;
        text-decoration: none;
        white-space: nowrap;
        border-bottom: 4px solid transparent;
        margin-bottom: -4px;
        transition: background .1s, border-color .1s;
    }

    .sa-nav-link:hover {
        color: #fff;
        background: rgba(255, 255, 255, .08);
        text-decoration: none;
    }

    .sa-nav-link:focus {
        outline: 3px solid #ffdd00;
        outline-offset: -3px;
        color: #fff;
    }

    .sa-nav-link[aria-current="page"] {
        color: #fff;
        border-bottom-color: #ffdd00;
        background: rgba(255, 255, 255, .06);
    }

    .gdpr-intro {
        background: #fff;
        border: 2px solid #e5e5e5;
        border-left: 5px solid #1d1d1b;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .gdpr-intro h2 {
        margin: 0 0 .35rem;
        font-size: 1.1rem;
        color: #1d1d1b;
    }

    .gdpr-intro p {
        margin: 0;
        font-size: .88rem;
        color: #555;
    }

    .gdpr-search-panel {
        background: #f7f5fb;
        border: 2px solid #e5e5e5;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .gdpr-search-row {
        display: flex;
        gap: .75rem;
        align-items: end;
        flex-wrap: wrap;
    }

    .gdpr-search-row .form-group {
        flex: 1;
        min-width: 220px;
    }

    .gdpr-results {
        margin-top: 1rem;
    }

    .gdpr-person-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: .85rem 1rem;
        border: 1px solid #e5e5e5;
        background: #fff;
        margin-bottom: .5rem;
        flex-wrap: wrap;
    }

    .gdpr-person-card:hover {
        border-color: #4d0b93;
        background: #faf8fd;
    }

    .gdpr-person-info {
        min-width: 0;
    }

    .gdpr-person-name {
        font-weight: 900;
        color: #1d1d1b;
        font-size: .95rem;
    }

    .gdpr-person-meta {
        font-size: .82rem;
        color: #555;
        margin-top: .15rem;
    }

    .gdpr-person-actions {
        display: flex;
        gap: .4rem;
        flex-shrink: 0;
    }

    .gdpr-badge {
        display: inline-block;
        padding: .15rem .4rem;
        font-size: .7rem;
        font-weight: 900;
    }

    .gdpr-badge-active { background: #d1e7dd; color: #0f5132; }
    .gdpr-badge-inactive { background: #f8d7da; color: #842029; }

    .gdpr-section {
        margin-top: 2rem;
    }

    .gdpr-section h3 {
        font-size: 1rem;
        margin: 0 0 .75rem;
        color: #1d1d1b;
        border-bottom: 2px solid #e5e5e5;
        padding-bottom: .35rem;
    }

    .gdpr-recent-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .85rem;
    }

    .gdpr-recent-table th,
    .gdpr-recent-table td {
        border-bottom: 1px solid #e5e5e5;
        padding: .6rem .75rem;
        text-align: left;
        vertical-align: middle;
    }

    .gdpr-recent-table th {
        background: #f7f5fb;
        font-weight: 900;
        color: #4d0b93;
        font-size: .78rem;
        text-transform: uppercase;
    }

    .gdpr-recent-table tr:hover td {
        background: #faf8fd;
    }

    .gdpr-empty {
        padding: 1.5rem;
        text-align: center;
        background: #f7f5fb;
        border: 1px solid #e5e5e5;
        color: #555;
        font-size: .9rem;
    }

    .sa-muted {
        color: #666;
        font-size: .82rem;
    }
</style>

<nav class="sa-service-bar" aria-label="System Admin navigation">
    <div class="sa-service-bar-inner">
        <a class="sa-nav-link" href="/system-admin-dashboard.php" <?= $saNavCurrent === 'dashboard' ? 'aria-current="page"' : '' ?>>Dashboard</a>
        <a class="sa-nav-link" href="/system-admin.php" <?= $saNavCurrent === 'audit-log' ? 'aria-current="page"' : '' ?>>Audit Log</a>
        <a class="sa-nav-link" href="/system-admin-cron.php" <?= $saNavCurrent === 'cron' ? 'aria-current="page"' : '' ?>>Cron Jobs</a>
        <a class="sa-nav-link" href="/system-admin-gdpr.php" <?= $saNavCurrent === 'gdpr' ? 'aria-current="page"' : '' ?>>GDPR</a>
        <a class="sa-nav-link" href="/system-admin-permissions.php" <?= $saNavCurrent === 'permissions' ? 'aria-current="page"' : '' ?>>Permissions</a>
        <a class="sa-nav-link" href="/system-admin-person.php" <?= $saNavCurrent === 'person' ? 'aria-current="page"' : '' ?>>Person Lookup</a>
        <a class="sa-nav-link" href="/system-admin-kb.php" <?= $saNavCurrent === 'kb' ? 'aria-current="page"' : '' ?>>KB</a>
    </div>
</nav>

<main class="lt-main">

    <div class="gdpr-intro">
        <h2>GDPR Data Subject Access Reports</h2>
        <p>
            Generate a full audit report for any person in the system. The report includes all personal data held,
            group memberships, linked accounts, and a complete activity audit trail. Use for Data Subject Access Requests
            (DSAR) under GDPR Article 15, or for internal security investigations.
        </p>
    </div>

    <div class="gdpr-search-panel">
        <form method="get">
            <label for="gdpr-search"><strong>Search for a person</strong></label>
            <p class="sa-muted" style="margin: .25rem 0 .5rem;">Search by name, email address, or person ID.</p>
            <div class="gdpr-search-row">
                <div class="form-group mb-0">
                    <input
                        class="form-control"
                        type="search"
                        id="gdpr-search"
                        name="search"
                        value="<?= e($search) ?>"
                        placeholder="e.g. John Smith, john@example.com, or 42"
                        autofocus
                    >
                </div>
                <button class="btn btn-primary lt-btn" type="submit">Search</button>
                <?php if ($search !== ''): ?>
                    <a class="btn btn-secondary lt-btn" href="/system-admin-gdpr.php">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($search !== ''): ?>
            <div class="gdpr-results">
                <?php if ($searchResults): ?>
                    <p class="sa-muted" style="margin-bottom: .5rem;">
                        <?= count($searchResults) ?> result<?= count($searchResults) === 1 ? '' : 's' ?> found for "<?= e($search) ?>"
                    </p>

                    <?php foreach ($searchResults as $result): ?>
                        <div class="gdpr-person-card">
                            <div class="gdpr-person-info">
                                <div class="gdpr-person-name">
                                    <?= e($result['full_name'] ?? 'Unnamed') ?>
                                    <span class="gdpr-badge <?= ($result['status'] ?? '') === 'active' ? 'gdpr-badge-active' : 'gdpr-badge-inactive' ?>">
                                        <?= e(ucfirst((string) ($result['status'] ?? 'unknown'))) ?>
                                    </span>
                                </div>
                                <div class="gdpr-person-meta">
                                    ID: <?= (int) $result['id'] ?>
                                    <?php if (!empty($result['primary_email'])): ?>
                                        &middot; <?= e($result['primary_email']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($result['groups_list'])): ?>
                                        &middot; <?= e($result['groups_list']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="gdpr-person-actions">
                                <a class="btn btn-primary lt-btn" href="/system-admin-gdpr-report.php?person_id=<?= (int) $result['id'] ?>" target="_blank">
                                    Generate Report
                                </a>
                                <a class="btn btn-secondary lt-btn" href="/system-admin.php?target_person_id=<?= (int) $result['id'] ?>">
                                    View Audit Log
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="gdpr-empty">
                        No people found matching "<?= e($search) ?>". Try a different name, email, or ID.
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="gdpr-section">
        <h3>Recently Generated Reports</h3>

        <?php if ($recentReports): ?>
            <table class="gdpr-recent-table">
                <thead>
                    <tr>
                        <th>Generated</th>
                        <th>Subject</th>
                        <th>Generated By</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentReports as $report): ?>
                        <tr>
                            <td>
                                <?= e(date('d M Y H:i', strtotime((string) $report['created_at']))) ?>
                            </td>
                            <td>
                                <?php if (!empty($report['subject_name'])): ?>
                                    <strong><?= e($report['subject_name']) ?></strong>
                                    <?php if (!empty($report['subject_email'])): ?>
                                        <br><span class="sa-muted"><?= e($report['subject_email']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="sa-muted">Person #<?= (int) $report['target_person_id'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($report['generated_by_name'] ?? 'System') ?>
                            </td>
                            <td>
                                <?php if (!empty($report['target_person_id'])): ?>
                                    <a class="btn btn-secondary lt-btn" href="/system-admin-gdpr-report.php?person_id=<?= (int) $report['target_person_id'] ?>" target="_blank" style="font-size:.78rem;padding:.3rem .6rem;">
                                        Regenerate
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="gdpr-empty">
                No GDPR reports have been generated yet. Use the search above to find a person and generate their first report.
            </div>
        <?php endif; ?>
    </div>

</main>

<?php include __DIR__ . '/footer.php'; ?>
