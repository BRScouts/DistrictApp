<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/system-admin-helpers.php';

$user = sa_require_system_admin();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
$pdo = db();

// ─── Dashboard Stats Queries ────────────────────────────────────────────────

function sa_dash_safe_count(string $sql, array $params = []): int
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function sa_dash_safe_rows(string $sql, array $params = []): array
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function sa_dash_table_exists(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
        $stmt->execute(['t' => $table]);
        return $cache[$table] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function sa_dash_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
        $stmt->execute(['t' => $table, 'c' => $column]);
        return $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

// ─── People & Volunteers ────────────────────────────────────────────────────

$totalPeople = sa_dash_safe_count("SELECT COUNT(*) FROM people WHERE status = 'active'");
$totalInactive = sa_dash_safe_count("SELECT COUNT(*) FROM people WHERE status = 'inactive'");
$totalGroups = sa_dash_safe_count("SELECT COUNT(*) FROM groups WHERE is_active = 1");

// ─── Logins Today ───────────────────────────────────────────────────────────

$loginsToday = sa_dash_safe_count("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login_success' AND DATE(created_at) = CURDATE()");
$failedLoginsToday = sa_dash_safe_count("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login_failed' AND DATE(created_at) = CURDATE()");
$uniqueLoginsToday = sa_dash_safe_count("SELECT COUNT(DISTINCT actor_person_id) FROM audit_log WHERE action = 'auth.login_success' AND DATE(created_at) = CURDATE()");

// Logins this week and last 30 days for trend
$loginsThisWeek = sa_dash_safe_count("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login_success' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$loginsLast30 = sa_dash_safe_count("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login_success' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");

// ─── Email Stats (30 days) ──────────────────────────────────────────────────

$emailsSent30d = 0;
$emailsFailed30d = 0;
$emailsPending = 0;

if (sa_dash_table_exists('email_queue')) {
    $hasSentAt = sa_dash_column_exists('email_queue', 'sent_at');
    $hasStatus = sa_dash_column_exists('email_queue', 'status');

    if ($hasStatus) {
        if ($hasSentAt) {
            $emailsSent30d = sa_dash_safe_count("SELECT COUNT(*) FROM email_queue WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        } else {
            $emailsSent30d = sa_dash_safe_count("SELECT COUNT(*) FROM email_queue WHERE status = 'sent' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        }
        $emailsFailed30d = sa_dash_safe_count("SELECT COUNT(*) FROM email_queue WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $emailsPending = sa_dash_safe_count("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'");
    }
}

// ─── M365 Provisioning (30 days) ────────────────────────────────────────────

$accountsProvisioned30d = 0;
$accountsFailed30d = 0;
$accountsPending = 0;

if (sa_dash_table_exists('m365_account_requests')) {
    $accountsProvisioned30d = sa_dash_safe_count("SELECT COUNT(*) FROM m365_account_requests WHERE provision_status IN ('provisioned', 'already_exists') AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $accountsFailed30d = sa_dash_safe_count("SELECT COUNT(*) FROM m365_account_requests WHERE provision_status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $accountsPending = sa_dash_safe_count("SELECT COUNT(*) FROM m365_account_requests WHERE provision_status IN ('pending', 'processing')");
}

// ─── Audit Events ───────────────────────────────────────────────────────────

$auditEventsToday = sa_dash_safe_count("SELECT COUNT(*) FROM audit_log WHERE DATE(created_at) = CURDATE()");
$auditEvents30d = sa_dash_safe_count("SELECT COUNT(*) FROM audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");

// Critical events this week
$criticalCodes = [];
foreach (audit_event_types() as $code => $meta) {
    if ($meta[2] === 'critical') {
        $criticalCodes[] = $code;
    }
}
$criticalThisWeek = 0;
if ($criticalCodes) {
    $placeholders = implode(',', array_fill(0, count($criticalCodes), '?'));
    $criticalThisWeek = sa_dash_safe_count(
        "SELECT COUNT(*) FROM audit_log WHERE action IN ({$placeholders}) AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
        $criticalCodes
    );
}

// ─── Most Active Users (last 7 days) ────────────────────────────────────────

$mostActiveUsers = sa_dash_safe_rows("
    SELECT
        p.id,
        p.full_name,
        p.primary_email,
        COUNT(*) AS action_count,
        MAX(al.created_at) AS last_active
    FROM audit_log al
    JOIN people p ON p.id = al.actor_person_id
    WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      AND al.actor_person_id IS NOT NULL
    GROUP BY p.id, p.full_name, p.primary_email
    ORDER BY action_count DESC
    LIMIT 10
");

// ─── Recent Logins Today ────────────────────────────────────────────────────

$recentLogins = sa_dash_safe_rows("
    SELECT
        p.id AS person_id,
        p.full_name,
        al.created_at,
        al.ip_address,
        al.user_agent
    FROM audit_log al
    JOIN people p ON p.id = al.actor_person_id
    WHERE al.action = 'auth.login_success'
      AND DATE(al.created_at) = CURDATE()
    ORDER BY al.created_at DESC
    LIMIT 15
");

// ─── Cron Health ────────────────────────────────────────────────────────────

$cronJobs = [
    ['code' => 'cron.send_email_queue_run', 'label' => 'Send Email Queue', 'expected' => 'Every 5 min'],
    ['code' => 'cron.provision_m365_run', 'label' => 'Provision M365', 'expected' => 'Every 5 min'],
    ['code' => 'cron.sync_m365_profiles_run', 'label' => 'Sync Profiles', 'expected' => 'Daily 05:15'],
    ['code' => 'cron.leavers_run', 'label' => 'Leavers Notification', 'expected' => 'Daily 06:30'],
    ['code' => 'cron.reminders_cleanse_run', 'label' => 'Reminders & Cleanse', 'expected' => 'Daily 06:10'],
];

$cronHealth = [];
foreach ($cronJobs as $job) {
    $row = sa_dash_safe_rows("
        SELECT created_at, details_json
        FROM audit_log
        WHERE action = :action
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ", ['action' => $job['code']]);

    $lastRun = $row[0] ?? null;
    $details = $lastRun ? (json_decode((string) ($lastRun['details_json'] ?? ''), true) ?: []) : [];
    $status = $details['status'] ?? null;

    $cronHealth[] = [
        'label' => $job['label'],
        'expected' => $job['expected'],
        'last_run' => $lastRun ? $lastRun['created_at'] : null,
        'status' => $status,
        'details' => $details,
    ];
}

// ─── Recent Critical / Warning Events ───────────────────────────────────────

$warningCodes = [];
foreach (audit_event_types() as $code => $meta) {
    if (in_array($meta[2], ['critical', 'warning'], true)) {
        $warningCodes[] = $code;
    }
}

$recentAlerts = [];
if ($warningCodes) {
    $placeholders = implode(',', array_fill(0, count($warningCodes), '?'));
    $recentAlerts = sa_dash_safe_rows(
        "SELECT al.*, p.full_name AS actor_name
         FROM audit_log al
         LEFT JOIN people p ON p.id = al.actor_person_id
         WHERE al.action IN ({$placeholders})
         ORDER BY al.created_at DESC, al.id DESC
         LIMIT 10",
        $warningCodes
    );
}

// ─── District Calendar Stats ────────────────────────────────────────────────

$calendarPendingReview = 0;
$calendarDrafts = 0;
if (sa_dash_table_exists('calendar_events')) {
    $calendarPendingReview = sa_dash_safe_count("SELECT COUNT(*) FROM calendar_events WHERE status IN ('submitted', 'under_review')");
    $calendarDrafts = sa_dash_safe_count("SELECT COUNT(*) FROM calendar_events WHERE status = 'draft' AND starts_at >= NOW()");
}

// ─── Page Setup ─────────────────────────────────────────────────────────────

$saNavCurrent = 'dashboard';

$pageTitle = 'System Admin — Dashboard | ' . $appName;
$heroTitle = 'System Admin';
$heroText = 'System health, activity overview, and operational status.';
$breadcrumb = '<a href="/index.php">Home</a> / System Admin';

include __DIR__ . '/header.php';
?>

<style>
    .sa-service-bar { background: #1d1d1b; border-bottom: 4px solid #ffdd00; }
    .sa-service-bar-inner { max-width: 1180px; margin: 0 auto; padding: 0 1rem; display: flex; align-items: stretch; gap: 0; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .sa-nav-link { display: inline-flex; align-items: center; padding: .85rem 1.1rem; color: rgba(255,255,255,.88); font-weight: 900; font-size: .92rem; text-decoration: none; white-space: nowrap; border-bottom: 4px solid transparent; margin-bottom: -4px; transition: background .1s, border-color .1s; }
    .sa-nav-link:hover { color: #fff; background: rgba(255,255,255,.08); text-decoration: none; }
    .sa-nav-link:focus { outline: 3px solid #ffdd00; outline-offset: -3px; color: #fff; }
    .sa-nav-link[aria-current="page"] { color: #fff; border-bottom-color: #ffdd00; background: rgba(255,255,255,.06); }

    .dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
    .dash-stat { background: #fff; border: 2px solid #e5e5e5; padding: 1.1rem; position: relative; }
    .dash-stat:hover { border-color: #4d0b93; }
    .dash-stat strong { display: block; font-size: 2rem; line-height: 1.1; color: #4d0b93; font-weight: 900; }
    .dash-stat span { font-size: .82rem; color: #555; display: block; margin-top: .2rem; }
    .dash-stat.dash-stat-warning strong { color: #d4351c; }
    .dash-stat.dash-stat-ok strong { color: #00703c; }
    .dash-stat-sub { font-size: .72rem; color: #888; margin-top: .25rem; }

    .dash-section { margin-bottom: 2rem; }
    .dash-section h2 { font-size: 1.15rem; font-weight: 900; color: #1d1d1b; margin: 0 0 .75rem; padding-bottom: .5rem; border-bottom: 2px solid #e5e5e5; }

    .dash-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
    @media (max-width: 768px) { .dash-two-col { grid-template-columns: 1fr; } }

    .dash-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    .dash-table th, .dash-table td { border-bottom: 1px solid #e5e5e5; padding: .55rem .6rem; text-align: left; vertical-align: top; }
    .dash-table th { background: #f7f5fb; font-weight: 900; color: #4d0b93; white-space: nowrap; }
    .dash-table tr:hover td { background: #faf8fd; }

    .dash-badge { display: inline-block; padding: .12rem .4rem; font-size: .7rem; font-weight: 900; text-transform: uppercase; }
    .dash-badge-ok { background: #d1e7dd; color: #0f5132; }
    .dash-badge-warn { background: #fff3cd; color: #664d03; }
    .dash-badge-error { background: #f8d7da; color: #842029; }
    .dash-badge-pending { background: #e7f1ff; color: #004085; }
    .dash-badge-stale { background: #f5f5f5; color: #666; }

    .dash-cron-grid { display: grid; gap: .5rem; }
    .dash-cron-item { display: flex; align-items: center; gap: .75rem; padding: .6rem .75rem; background: #fff; border: 1px solid #e5e5e5; }
    .dash-cron-item:hover { border-color: #4d0b93; }
    .dash-cron-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    .dash-cron-dot-ok { background: #00703c; }
    .dash-cron-dot-warn { background: #f47738; }
    .dash-cron-dot-error { background: #d4351c; }
    .dash-cron-dot-stale { background: #b1b4b6; }
    .dash-cron-info { flex: 1; }
    .dash-cron-label { font-weight: 900; font-size: .88rem; }
    .dash-cron-meta { font-size: .75rem; color: #666; }

    .dash-person-link { color: #4d0b93; font-weight: 700; text-decoration: none; }
    .dash-person-link:hover { text-decoration: underline; }
    .dash-muted { color: #666; font-size: .8rem; }
    .dash-alert-row td { font-size: .82rem; }
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

    <!-- ─── Top Stats Grid ───────────────────────────────────────────── -->
    <div class="dash-grid">
        <div class="dash-stat">
            <strong><?= number_format($totalPeople) ?></strong>
            <span>Active volunteers</span>
            <div class="dash-stat-sub"><?= number_format($totalInactive) ?> inactive &middot; <?= number_format($totalGroups) ?> groups</div>
        </div>
        <div class="dash-stat">
            <strong><?= number_format($loginsToday) ?></strong>
            <span>Logins today</span>
            <div class="dash-stat-sub"><?= number_format($uniqueLoginsToday) ?> unique users</div>
        </div>
        <div class="dash-stat <?= $failedLoginsToday > 0 ? 'dash-stat-warning' : '' ?>">
            <strong><?= number_format($failedLoginsToday) ?></strong>
            <span>Failed logins today</span>
        </div>
        <div class="dash-stat dash-stat-ok">
            <strong><?= number_format($emailsSent30d) ?></strong>
            <span>Emails sent (30d)</span>
            <div class="dash-stat-sub"><?= number_format($emailsPending) ?> pending now</div>
        </div>
        <div class="dash-stat <?= $emailsFailed30d > 0 ? 'dash-stat-warning' : '' ?>">
            <strong><?= number_format($emailsFailed30d) ?></strong>
            <span>Emails failed (30d)</span>
        </div>
        <div class="dash-stat dash-stat-ok">
            <strong><?= number_format($accountsProvisioned30d) ?></strong>
            <span>M365 accounts (30d)</span>
            <div class="dash-stat-sub"><?= number_format($accountsPending) ?> pending</div>
        </div>
        <div class="dash-stat <?= $criticalThisWeek > 0 ? 'dash-stat-warning' : '' ?>">
            <strong><?= number_format($criticalThisWeek) ?></strong>
            <span>Critical events (7d)</span>
        </div>
        <div class="dash-stat">
            <strong><?= number_format($auditEventsToday) ?></strong>
            <span>Audit events today</span>
            <div class="dash-stat-sub"><?= number_format($auditEvents30d) ?> in 30 days</div>
        </div>
    </div>

    <!-- ─── Cron Health + Calendar ───────────────────────────────────── -->
    <div class="dash-two-col">
        <div class="dash-section">
            <h2>Cron Job Health</h2>
            <div class="dash-cron-grid">
                <?php foreach ($cronHealth as $cron):
                    $dotClass = 'dash-cron-dot-stale';
                    if ($cron['last_run']) {
                        $age = time() - strtotime($cron['last_run']);
                        if ($cron['status'] === 'failed') {
                            $dotClass = 'dash-cron-dot-error';
                        } elseif ($cron['status'] === 'completed_with_errors') {
                            $dotClass = 'dash-cron-dot-warn';
                        } elseif ($age < 86400) {
                            $dotClass = 'dash-cron-dot-ok';
                        } elseif ($age < 172800) {
                            $dotClass = 'dash-cron-dot-warn';
                        } else {
                            $dotClass = 'dash-cron-dot-error';
                        }
                    }
                ?>
                <div class="dash-cron-item">
                    <div class="dash-cron-dot <?= $dotClass ?>"></div>
                    <div class="dash-cron-info">
                        <div class="dash-cron-label"><?= e($cron['label']) ?></div>
                        <div class="dash-cron-meta">
                            <?php if ($cron['last_run']): ?>
                                Last: <?= e(date('d M H:i', strtotime($cron['last_run']))) ?>
                                <?php if ($cron['status']): ?>
                                    &middot; <span class="dash-badge <?= $cron['status'] === 'success' ? 'dash-badge-ok' : ($cron['status'] === 'failed' ? 'dash-badge-error' : 'dash-badge-warn') ?>"><?= e($cron['status']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                Never run
                            <?php endif; ?>
                            &middot; <?= e($cron['expected']) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="dash-section">
            <h2>District Calendar</h2>
            <div class="dash-grid" style="grid-template-columns: 1fr 1fr; margin-bottom: 1rem;">
                <div class="dash-stat <?= $calendarPendingReview > 0 ? 'dash-stat-warning' : '' ?>">
                    <strong><?= number_format($calendarPendingReview) ?></strong>
                    <span>Awaiting review</span>
                </div>
                <div class="dash-stat">
                    <strong><?= number_format($calendarDrafts) ?></strong>
                    <span>Upcoming drafts</span>
                </div>
            </div>

            <h2 style="margin-top: 1.5rem;">Quick Links</h2>
            <ul style="font-size: .9rem; line-height: 2;">
                <li><a href="/system-admin.php" class="dash-person-link">Full Audit Log</a></li>
                <li><a href="/system-admin-cron.php" class="dash-person-link">Run Cron Jobs</a></li>
                <li><a href="/system-admin-person.php" class="dash-person-link">Person Lookup</a></li>
                <li><a href="/system-admin-gdpr.php" class="dash-person-link">GDPR Reports</a></li>
                <li><a href="/dc/reviewer/" class="dash-person-link">Calendar Reviewer Panel</a></li>
            </ul>
        </div>
    </div>

    <!-- ─── Two-col: Active Users + Today's Logins ───────────────────── -->
    <div class="dash-two-col">
        <div class="dash-section">
            <h2>Most Active Users (7 days)</h2>
            <?php if ($mostActiveUsers): ?>
            <table class="dash-table">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th>Actions</th>
                        <th>Last Active</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($mostActiveUsers as $mu): ?>
                    <tr>
                        <td>
                            <a class="dash-person-link" href="/system-admin-person.php?person_id=<?= (int) $mu['id'] ?>"><?= e($mu['full_name']) ?></a>
                            <br><span class="dash-muted"><?= e($mu['primary_email']) ?></span>
                        </td>
                        <td><strong><?= number_format((int) $mu['action_count']) ?></strong></td>
                        <td class="dash-muted"><?= e(date('d M H:i', strtotime($mu['last_active']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p class="dash-muted">No activity recorded.</p>
            <?php endif; ?>
        </div>

        <div class="dash-section">
            <h2>Today's Logins</h2>
            <?php if ($recentLogins): ?>
            <table class="dash-table">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th>Time</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentLogins as $rl): ?>
                    <tr>
                        <td><a class="dash-person-link" href="/system-admin-person.php?person_id=<?= (int) $rl['person_id'] ?>"><?= e($rl['full_name']) ?></a></td>
                        <td class="dash-muted"><?= e(date('H:i:s', strtotime($rl['created_at']))) ?></td>
                        <td class="dash-muted"><?= e($rl['ip_address'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p class="dash-muted">No logins recorded today.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ─── Recent Alerts ────────────────────────────────────────────── -->
    <?php if ($recentAlerts): ?>
    <div class="dash-section">
        <h2>Recent Warnings &amp; Critical Events</h2>
        <table class="dash-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Event</th>
                    <th>Severity</th>
                    <th>Actor</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentAlerts as $alert):
                $eventCode = (string) ($alert['action'] ?? '');
                $severity = audit_event_severity($eventCode);
                $badgeClass = $severity === 'critical' ? 'dash-badge-error' : 'dash-badge-warn';
            ?>
                <tr class="dash-alert-row">
                    <td class="dash-muted"><?= e(date('d M H:i', strtotime($alert['created_at']))) ?></td>
                    <td><strong><?= e(audit_event_label($eventCode)) ?></strong></td>
                    <td><span class="dash-badge <?= $badgeClass ?>"><?= e($severity) ?></span></td>
                    <td>
                        <?php if (!empty($alert['actor_name'])): ?>
                            <a class="dash-person-link" href="/system-admin-person.php?person_id=<?= (int) $alert['actor_person_id'] ?>"><?= e($alert['actor_name']) ?></a>
                        <?php else: ?>
                            <span class="dash-muted"><?= e(ucfirst((string) ($alert['actor_type'] ?? 'system'))) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="dash-muted"><?= e($alert['ip_address'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</main>

<?php include __DIR__ . '/footer.php'; ?>
