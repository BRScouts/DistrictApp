<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/system-admin-helpers.php';

$user = sa_require_system_admin();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filters = sa_build_filters_from_request();
    sa_export_audit_csv($filters);
}

function sa_build_filters_from_request(): array
{
    return array_filter([
        'category'         => trim((string) ($_GET['category'] ?? '')),
        'action'           => trim((string) ($_GET['action'] ?? '')),
        'actor_person_id'  => (int) ($_GET['actor_person_id'] ?? 0) ?: null,
        'target_person_id' => (int) ($_GET['target_person_id'] ?? 0) ?: null,
        'group_id'         => (int) ($_GET['group_id'] ?? 0) ?: null,
        'entity_type'      => trim((string) ($_GET['entity_type'] ?? '')),
        'entity_id'        => (int) ($_GET['entity_id'] ?? 0) ?: null,
        'date_from'        => trim((string) ($_GET['date_from'] ?? '')),
        'date_to'          => trim((string) ($_GET['date_to'] ?? '')),
        'severity'         => trim((string) ($_GET['severity'] ?? '')),
        'search'           => trim((string) ($_GET['search'] ?? '')),
    ]);
}

$filters = sa_build_filters_from_request();
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = sa_fetch_audit_log($filters, $page, 50);
$stats = sa_audit_stats();
$groups = sa_fetch_all_groups();

// Get unique categories for filter dropdown
$categories = [];
foreach (audit_event_types() as $code => $meta) {
    $categories[$meta[0]] = ucfirst($meta[0]);
}
ksort($categories);

$saNavCurrent = 'audit-log';

$pageTitle = 'System Admin — Audit Log | ' . $appName;
$heroTitle = 'System Admin';
$heroText = 'Security audit log and system administration.';
$breadcrumb = '<a href="/index.php">Home</a> / System Admin';

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

    .sa-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .sa-stat {
        background: #fff;
        border: 2px solid #e5e5e5;
        padding: 1rem;
    }

    .sa-stat strong {
        display: block;
        font-size: 1.8rem;
        line-height: 1;
        color: #4d0b93;
    }

    .sa-stat.sa-stat-warning strong {
        color: #d4351c;
    }

    .sa-filters {
        background: #f7f5fb;
        border: 2px solid #e5e5e5;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }

    .sa-filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: .75rem;
        align-items: end;
    }

    .sa-filter-actions {
        display: flex;
        gap: .5rem;
        align-items: end;
        margin-top: .75rem;
    }

    .sa-table-wrap {
        overflow-x: auto;
    }

    .sa-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .88rem;
    }

    .sa-table th,
    .sa-table td {
        border-bottom: 1px solid #e5e5e5;
        padding: .65rem .75rem;
        vertical-align: top;
        text-align: left;
    }

    .sa-table th {
        background: #f7f5fb;
        font-weight: 900;
        white-space: nowrap;
        color: #4d0b93;
    }

    .sa-table tr:hover td {
        background: #faf8fd;
    }

    .sa-severity {
        display: inline-block;
        padding: .15rem .4rem;
        font-size: .72rem;
        font-weight: 900;
        text-transform: uppercase;
    }

    .sa-severity-info {
        background: #d1e7dd;
        color: #0f5132;
    }

    .sa-severity-warning {
        background: #fff3cd;
        color: #664d03;
    }

    .sa-severity-critical {
        background: #f8d7da;
        color: #842029;
    }

    .sa-muted {
        color: #666;
        font-size: .82rem;
    }

    .sa-details-toggle {
        font-size: .78rem;
        color: #4d0b93;
        cursor: pointer;
        font-weight: 700;
        border: 0;
        background: 0;
        padding: .15rem .4rem;
        text-decoration: none;
        border: 1px solid #4d0b93;
    }

    .sa-details-toggle:hover {
        background: #4d0b93;
        color: #fff;
    }

    .sa-details-row {
        display: none;
    }

    .sa-details-row.is-open {
        display: table-row;
    }

    .sa-details-row td {
        padding: 0 !important;
        border-bottom: 2px solid #4d0b93 !important;
    }

    .sa-details-panel {
        background: #f9f7fc;
        border-top: 1px solid #e5e5e5;
        padding: .85rem 1rem;
    }

    .sa-details-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: .5rem .75rem;
    }

    .sa-detail-item {
        display: flex;
        flex-direction: column;
        gap: .1rem;
    }

    .sa-detail-key {
        font-size: .72rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .02em;
        color: #4d0b93;
    }

    .sa-detail-value {
        font-size: .85rem;
        color: #1d1d1b;
        word-break: break-word;
    }

    .sa-detail-value-long {
        grid-column: 1 / -1;
    }

    .sa-row-clickable {
        cursor: pointer;
    }

    .sa-row-clickable:hover td {
        background: #f3eef9;
    }

    .sa-has-details td:first-child {
        border-left: 3px solid transparent;
    }

    .sa-has-details:hover td:first-child {
        border-left-color: #4d0b93;
    }

    .sa-pagination {
        display: flex;
        gap: .5rem;
        align-items: center;
        justify-content: center;
        margin-top: 1.5rem;
        flex-wrap: wrap;
    }

    .sa-pagination a,
    .sa-pagination span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 36px;
        height: 36px;
        padding: 0 .6rem;
        font-weight: 900;
        font-size: .88rem;
        text-decoration: none;
        border: 2px solid #e5e5e5;
        color: #4d0b93;
    }

    .sa-pagination a:hover {
        background: #f7f5fb;
        border-color: #4d0b93;
    }

    .sa-pagination .sa-page-current {
        background: #4d0b93;
        color: #fff;
        border-color: #4d0b93;
    }

    .sa-empty {
        padding: 2rem;
        text-align: center;
        background: #f7f5fb;
        border: 2px solid #e5e5e5;
    }
</style>

<nav class="sa-service-bar" aria-label="System Admin navigation">
    <div class="sa-service-bar-inner">
        <a class="sa-nav-link" href="/system-admin.php" <?= $saNavCurrent === 'audit-log' ? 'aria-current="page"' : '' ?>>Audit Log</a>
        <a class="sa-nav-link" href="/system-admin-cron.php" <?= $saNavCurrent === 'cron' ? 'aria-current="page"' : '' ?>>Cron Jobs</a>
        <a class="sa-nav-link" href="/system-admin-gdpr.php" <?= $saNavCurrent === 'gdpr' ? 'aria-current="page"' : '' ?>>GDPR Reports</a>
        <a class="sa-nav-link" href="/system-admin-permissions.php" <?= $saNavCurrent === 'permissions' ? 'aria-current="page"' : '' ?>>Permissions</a>
    </div>
</nav>

<main class="lt-main">

    <div class="sa-stats">
        <div class="sa-stat">
            <strong><?= number_format($stats['total']) ?></strong>
            <span>Total events</span>
        </div>
        <div class="sa-stat">
            <strong><?= number_format($stats['today']) ?></strong>
            <span>Events today</span>
        </div>
        <div class="sa-stat">
            <strong><?= number_format($stats['logins_today']) ?></strong>
            <span>Logins today</span>
        </div>
        <div class="sa-stat <?= $stats['failed_logins_today'] > 0 ? 'sa-stat-warning' : '' ?>">
            <strong><?= number_format($stats['failed_logins_today']) ?></strong>
            <span>Failed logins today</span>
        </div>
        <div class="sa-stat <?= $stats['critical_today'] > 0 ? 'sa-stat-warning' : '' ?>">
            <strong><?= number_format($stats['critical_today']) ?></strong>
            <span>Critical events today</span>
        </div>
    </div>

    <div class="sa-filters">
        <form method="get" id="sa-filter-form">
            <div class="sa-filter-grid">
                <div class="form-group mb-0">
                    <label for="sa-search">Search</label>
                    <input class="form-control" type="search" id="sa-search" name="search" value="<?= e($filters['search'] ?? '') ?>" placeholder="Name, IP, action...">
                </div>

                <div class="form-group mb-0">
                    <label for="sa-category">Category</label>
                    <select class="form-control" id="sa-category" name="category">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= ($filters['category'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-0">
                    <label for="sa-severity">Severity</label>
                    <select class="form-control" id="sa-severity" name="severity">
                        <option value="">All severities</option>
                        <option value="info" <?= ($filters['severity'] ?? '') === 'info' ? 'selected' : '' ?>>Info</option>
                        <option value="warning" <?= ($filters['severity'] ?? '') === 'warning' ? 'selected' : '' ?>>Warning</option>
                        <option value="critical" <?= ($filters['severity'] ?? '') === 'critical' ? 'selected' : '' ?>>Critical</option>
                    </select>
                </div>

                <div class="form-group mb-0">
                    <label for="sa-group">Group</label>
                    <select class="form-control" id="sa-group" name="group_id">
                        <option value="">All groups</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int) $g['id'] ?>" <?= ((int) ($filters['group_id'] ?? 0)) === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['group_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-0">
                    <label for="sa-date-from">From</label>
                    <input class="form-control" type="date" id="sa-date-from" name="date_from" value="<?= e($filters['date_from'] ?? '') ?>">
                </div>

                <div class="form-group mb-0">
                    <label for="sa-date-to">To</label>
                    <input class="form-control" type="date" id="sa-date-to" name="date_to" value="<?= e($filters['date_to'] ?? '') ?>">
                </div>
            </div>

            <?php if (!empty($filters['actor_person_id'])): ?>
                <input type="hidden" name="actor_person_id" value="<?= (int) $filters['actor_person_id'] ?>">
            <?php endif; ?>
            <?php if (!empty($filters['target_person_id'])): ?>
                <input type="hidden" name="target_person_id" value="<?= (int) $filters['target_person_id'] ?>">
            <?php endif; ?>

            <div class="sa-filter-actions">
                <button class="btn btn-primary lt-btn" type="submit">Filter</button>
                <a class="btn btn-secondary lt-btn" href="/system-admin.php">Clear</a>
                <a class="btn btn-secondary lt-btn" href="/system-admin.php?<?= e(http_build_query(array_merge($filters, ['export' => 'csv']))) ?>">Export CSV</a>
            </div>
        </form>
    </div>

    <?php if (!empty($filters['actor_person_id']) || !empty($filters['target_person_id'])): ?>
        <div class="alert alert-info mb-3">
            <?php if (!empty($filters['actor_person_id'])): ?>
                Showing actions <strong>by</strong> person ID <?= (int) $filters['actor_person_id'] ?>.
                <a href="/system-admin.php?<?= e(http_build_query(array_diff_key($filters, ['actor_person_id' => 1]))) ?>">Remove filter</a>
            <?php endif; ?>
            <?php if (!empty($filters['target_person_id'])): ?>
                Showing actions <strong>involving</strong> person ID <?= (int) $filters['target_person_id'] ?>.
                <a href="/system-admin.php?<?= e(http_build_query(array_diff_key($filters, ['target_person_id' => 1]))) ?>">Remove filter</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p class="sa-muted" style="margin-bottom:.75rem;">
        Showing <?= number_format(count($result['rows'])) ?> of <?= number_format($result['total']) ?> entries
        <?= $result['pages'] > 1 ? '(page ' . $result['page'] . ' of ' . $result['pages'] . ')' : '' ?>
    </p>

    <?php if (!$result['rows']): ?>
        <div class="sa-empty">
            <strong>No audit log entries found.</strong>
            <p class="mb-0 mt-2">Try adjusting your filters or check that the audit logging migration has been run.</p>
        </div>
    <?php else: ?>
        <div class="sa-table-wrap">
            <table class="sa-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Event</th>
                        <th>Severity</th>
                        <th>Actor</th>
                        <th>Target</th>
                        <th>Group</th>
                        <th>IP</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $row): ?>
                    <?php
                        $eventCode = (string) ($row['action'] ?? '');
                        $severity = audit_event_severity($eventCode);
                        $severityClass = 'sa-severity-' . $severity;
                        $label = audit_event_label($eventCode);
                        $details = (string) ($row['details_json'] ?? '');
                        $rowId = (int) ($row['id'] ?? 0);
                        $hasDetails = ($details && $details !== 'null' && $details !== '{}');
                        $parsedDetails = $hasDetails ? json_decode($details, true) : null;
                        if (!is_array($parsedDetails)) { $parsedDetails = null; $hasDetails = false; }
                    ?>
                    <tr class="<?= $hasDetails ? 'sa-row-clickable sa-has-details' : '' ?>" <?= $hasDetails ? 'data-details-row="sa-drow-' . $rowId . '"' : '' ?>>
                        <td>
                            <?= e(date('d M Y', strtotime((string) $row['created_at']))) ?><br>
                            <span class="sa-muted"><?= e(date('H:i:s', strtotime((string) $row['created_at']))) ?></span>
                        </td>
                        <td>
                            <strong><?= e($label) ?></strong><br>
                            <span class="sa-muted"><?= e($eventCode) ?></span>
                        </td>
                        <td><span class="sa-severity <?= e($severityClass) ?>"><?= e($severity) ?></span></td>
                        <td>
                            <?php if (!empty($row['actor_name'])): ?>
                                <a href="/system-admin.php?actor_person_id=<?= (int) $row['actor_person_id'] ?>"><?= e($row['actor_name']) ?></a>
                            <?php else: ?>
                                <span class="sa-muted"><?= e(ucfirst((string) ($row['actor_type'] ?? 'system'))) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['target_name'])): ?>
                                <a href="/system-admin.php?target_person_id=<?= (int) $row['target_person_id'] ?>"><?= e($row['target_name']) ?></a>
                                <br><span class="sa-muted">Person #<?= (int) $row['target_person_id'] ?></span>
                            <?php elseif (!empty($row['entity_type']) && !empty($row['entity_id'])): ?>
                                <?php
                                    $entityLabel = ucwords(str_replace('_', ' ', (string) $row['entity_type']));
                                    $entityLink = sa_entity_link((string) $row['entity_type'], (int) $row['entity_id']);
                                ?>
                                <?php if ($entityLink): ?>
                                    <a href="<?= e($entityLink) ?>"><?= e($entityLabel) ?> #<?= (int) $row['entity_id'] ?></a>
                                <?php else: ?>
                                    <span><?= e($entityLabel) ?> #<?= (int) $row['entity_id'] ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="sa-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($row['group_name']) ? e($row['group_name']) : '<span class="sa-muted">—</span>' ?></td>
                        <td><span class="sa-muted"><?= e((string) ($row['ip_address'] ?? '')) ?></span></td>
                        <td>
                            <?php if ($hasDetails): ?>
                                <button type="button" class="sa-details-toggle" data-target="sa-drow-<?= $rowId ?>">Details</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($hasDetails): ?>
                        <tr class="sa-details-row" id="sa-drow-<?= $rowId ?>">
                            <td colspan="8">
                                <div class="sa-details-panel">
                                    <div class="sa-details-grid">
                                        <?php foreach ($parsedDetails as $dKey => $dVal): ?>
                                            <?php if ($dVal === null || $dVal === '') continue; ?>
                                            <?php
                                                $displayVal = is_scalar($dVal) ? (string) $dVal : json_encode($dVal, JSON_PRETTY_PRINT);
                                                $isLong = strlen($displayVal) > 60;
                                            ?>
                                            <div class="sa-detail-item <?= $isLong ? 'sa-detail-value-long' : '' ?>">
                                                <span class="sa-detail-key"><?= e(ucwords(str_replace('_', ' ', (string) $dKey))) ?></span>
                                                <span class="sa-detail-value"><?= e($displayVal) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($result['pages'] > 1): ?>
            <nav class="sa-pagination" aria-label="Audit log pagination">
                <?php
                    $queryBase = $filters;
                    unset($queryBase['page']);

                    $range = range(
                        max(1, $result['page'] - 3),
                        min($result['pages'], $result['page'] + 3)
                    );
                ?>

                <?php if ($result['page'] > 1): ?>
                    <a href="/system-admin.php?<?= e(http_build_query(array_merge($queryBase, ['page' => $result['page'] - 1]))) ?>">Prev</a>
                <?php endif; ?>

                <?php if ($range[0] > 1): ?>
                    <a href="/system-admin.php?<?= e(http_build_query(array_merge($queryBase, ['page' => 1]))) ?>">1</a>
                    <?php if ($range[0] > 2): ?><span>…</span><?php endif; ?>
                <?php endif; ?>

                <?php foreach ($range as $p): ?>
                    <?php if ($p === $result['page']): ?>
                        <span class="sa-page-current"><?= $p ?></span>
                    <?php else: ?>
                        <a href="/system-admin.php?<?= e(http_build_query(array_merge($queryBase, ['page' => $p]))) ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if (end($range) < $result['pages']): ?>
                    <?php if (end($range) < $result['pages'] - 1): ?><span>…</span><?php endif; ?>
                    <a href="/system-admin.php?<?= e(http_build_query(array_merge($queryBase, ['page' => $result['pages']]))) ?>"><?= $result['pages'] ?></a>
                <?php endif; ?>

                <?php if ($result['page'] < $result['pages']): ?>
                    <a href="/system-admin.php?<?= e(http_build_query(array_merge($queryBase, ['page' => $result['page'] + 1]))) ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

</main>

<script>
(function () {
    // Toggle details row via button click
    document.querySelectorAll('.sa-details-toggle').forEach(function (button) {
        button.addEventListener('click', function (e) {
            e.stopPropagation();
            var targetId = button.getAttribute('data-target');
            var row = document.getElementById(targetId);

            if (row) {
                var isOpen = row.classList.toggle('is-open');
                button.textContent = isOpen ? 'Hide' : 'Details';
            }
        });
    });

    // Toggle details row via clicking anywhere on the main row
    document.querySelectorAll('.sa-row-clickable').forEach(function (row) {
        row.addEventListener('click', function (e) {
            // Don't toggle if clicking a link
            if (e.target.closest('a') || e.target.closest('button')) return;

            var targetId = row.getAttribute('data-details-row');
            var detailsRow = document.getElementById(targetId);
            var button = row.querySelector('.sa-details-toggle');

            if (detailsRow) {
                var isOpen = detailsRow.classList.toggle('is-open');
                if (button) button.textContent = isOpen ? 'Hide' : 'Details';
            }
        });
    });
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>
