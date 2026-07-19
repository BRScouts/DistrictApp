<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/system-admin-helpers.php';

$user = sa_require_system_admin();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

$cronJobs = [
    'leavers' => [
        'label' => 'Leavers Notification',
        'description' => 'Detects inactive people with M365 accounts and emails support to disable them.',
        'file' => '/cron/leavers_notification.php',
        'cron_expression' => '30 6 * * *',
        'schedule_human' => 'Daily at 06:30',
        'expected_interval_seconds' => 86400, // 24 hours
        'audit_code' => AUDIT_CRON_LEAVERS_RUN,
    ],
    'provision' => [
        'label' => 'Provision M365 Accounts',
        'description' => 'Creates Microsoft 365 accounts from pending requests and sends onboarding emails.',
        'file' => '/cron/provision_m365_accounts.php',
        'cron_expression' => '*/5 * * * *',
        'schedule_human' => 'Every 5 minutes',
        'expected_interval_seconds' => 300, // 5 minutes
        'audit_code' => AUDIT_CRON_PROVISION_RUN,
    ],
    'reminders' => [
        'label' => 'Reminders & Cleanse',
        'description' => 'Sends draft/review reminders and deletes old cancelled/rejected events.',
        'file' => '/cron/Reminders-and-clense.php',
        'cron_expression' => '10 6 * * *',
        'schedule_human' => 'Daily at 06:10',
        'expected_interval_seconds' => 86400,
        'audit_code' => AUDIT_CRON_REMINDERS_RUN,
    ],
    'sync' => [
        'label' => 'Sync M365 Profiles',
        'description' => 'Syncs department, job title and manager in Microsoft 365 from the Leader Tool.',
        'file' => '/cron/sync_m365_profiles.php',
        'cron_expression' => '15 5 * * *',
        'schedule_human' => 'Daily at 05:15',
        'expected_interval_seconds' => 86400,
        'audit_code' => AUDIT_CRON_SYNC_PROFILES_RUN,
    ],
    'send_email' => [
        'label' => 'Send Email Queue',
        'description' => 'Sends pending emails from the queue via Microsoft Graph API.',
        'file' => '/cron/send-email-queue.php',
        'cron_expression' => '* * * * *',
        'schedule_human' => 'Every minute',
        'expected_interval_seconds' => 60,
        'audit_code' => AUDIT_CRON_SEND_EMAIL_RUN,
    ],
];

// Handle running a cron job
$runOutput = null;
$runJob = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_cron'])) {
    csrf_validate();
    $jobKey = (string) ($_POST['run_cron'] ?? '');

    if (isset($cronJobs[$jobKey])) {
        $runJob = $cronJobs[$jobKey];
        $filePath = __DIR__ . $runJob['file'];

        if (is_file($filePath)) {
            ob_start();
            $cronStartTime = microtime(true);

            try {
                // The cron files will detect they're in browser mode via cron-guard.php
                // and the user is already authenticated at this point.
                include $filePath;
                $runOutput = ob_get_clean();
            } catch (Throwable $e) {
                $runOutput = ob_get_clean() . "\n\nERROR: " . $e->getMessage();
            }

            $cronDuration = round(microtime(true) - $cronStartTime, 2);
            $runOutput = trim($runOutput ?: '(No output)');
            $runOutput .= "\n\n--- Completed in {$cronDuration}s ---";
        } else {
            $runOutput = "ERROR: Cron file not found: {$filePath}";
        }
    }
}

// Fetch last run info from audit log for each cron
$lastRuns = [];
foreach ($cronJobs as $key => $job) {
    try {
        $stmt = db()->prepare("
            SELECT created_at, details_json
            FROM audit_log
            WHERE action = :action
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute(['action' => $job['audit_code']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $lastRuns[$key] = [
                'time' => $row['created_at'],
                'details' => json_decode((string) ($row['details_json'] ?? ''), true) ?: [],
            ];
        }
    } catch (Throwable $e) {
        // Non-critical
    }
}

$saNavCurrent = 'cron';

$pageTitle = 'System Admin — Cron Jobs | ' . $appName;
$heroTitle = 'System Admin';
$heroText = 'Run and monitor scheduled cron jobs.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/system-admin.php">System Admin</a> / Cron Jobs';

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

    .cron-grid {
        display: grid;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .cron-card {
        border: 2px solid #e5e5e5;
        background: #fff;
        padding: 1.25rem;
    }

    .cron-card h3 {
        margin: 0 0 .35rem;
        font-size: 1.1rem;
        color: #1d1d1b;
    }

    .cron-card p {
        margin: 0 0 .75rem;
        color: #555;
        font-size: .9rem;
    }

    .cron-meta {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        align-items: center;
        margin-bottom: .75rem;
        font-size: .82rem;
    }

    .cron-badge {
        display: inline-block;
        padding: .2rem .45rem;
        font-weight: 900;
        font-size: .72rem;
        text-transform: uppercase;
    }

    .cron-badge-schedule {
        background: #e7f1ff;
        color: #004085;
    }

    .cron-badge-success {
        background: #d1e7dd;
        color: #0f5132;
    }

    .cron-badge-error {
        background: #f8d7da;
        color: #842029;
    }

    .cron-badge-never {
        background: #f5f5f5;
        color: #666;
    }

    .cron-badge-overdue {
        background: #f8d7da;
        color: #842029;
        animation: pulse-badge 2s infinite;
    }

    .cron-badge-ontime {
        background: #d1e7dd;
        color: #0f5132;
    }

    @keyframes pulse-badge {
        0%, 100% { opacity: 1; }
        50% { opacity: .7; }
    }

    .cron-schedule-info {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        align-items: center;
        margin-bottom: .5rem;
        font-size: .82rem;
    }

    .cron-expression {
        font-family: Consolas, Monaco, 'Courier New', monospace;
        background: #1d1d1b;
        color: #e0e0e0;
        padding: .15rem .45rem;
        font-size: .75rem;
        font-weight: 700;
    }

    .cron-last-run {
        font-size: .82rem;
        color: #555;
    }

    .cron-last-run strong {
        color: #1d1d1b;
    }

    .cron-details {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        margin-top: .5rem;
    }

    .cron-detail-chip {
        background: #f7f5fb;
        border: 1px solid #e5e5e5;
        padding: .2rem .5rem;
        font-size: .78rem;
        font-weight: 700;
    }

    .cron-actions {
        margin-top: 1rem;
        display: flex;
        gap: .5rem;
    }

    .cron-output {
        margin-top: 1.5rem;
        border: 2px solid #1d1d1b;
        background: #1d1d1b;
        color: #e0e0e0;
        padding: 1rem;
        font-family: Consolas, Monaco, 'Courier New', monospace;
        font-size: .82rem;
        line-height: 1.5;
        white-space: pre-wrap;
        word-break: break-word;
        max-height: 500px;
        overflow-y: auto;
    }

    .cron-output-header {
        margin-top: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }

    .cron-output-header h3 {
        margin: 0;
        font-size: 1rem;
    }

    .cron-warning {
        background: #fff3cd;
        border-left: 4px solid #e6a817;
        padding: .75rem 1rem;
        margin-bottom: 1.5rem;
        font-size: .9rem;
    }
</style>

<nav class="sa-service-bar" aria-label="System Admin navigation">
    <div class="sa-service-bar-inner">
        <a class="sa-nav-link" href="/system-admin-dashboard.php">Dashboard</a>
        <a class="sa-nav-link" href="/system-admin.php">Audit Log</a>
        <a class="sa-nav-link" href="/system-admin-cron.php" aria-current="page">Cron Jobs</a>
        <a class="sa-nav-link" href="/system-admin-gdpr.php">GDPR</a>
        <a class="sa-nav-link" href="/system-admin-permissions.php">Permissions</a>
        <a class="sa-nav-link" href="/system-admin-person.php">Person Lookup</a>
        <a class="sa-nav-link" href="/system-admin-kb.php">KB</a>
    </div>
</nav>

<main class="lt-main">

    <div class="cron-warning">
        <strong>Cron job monitoring and manual execution.</strong>
        These jobs run automatically via cPanel on the schedules shown below. Use this page to check their status, view last run details, or trigger them manually if a scheduled run was missed.
    </div>

    <?php if ($runOutput !== null && $runJob !== null): ?>
        <div class="cron-output-header">
            <h3>Output: <?= e($runJob['label']) ?></h3>
        </div>
        <div class="cron-output"><?= e($runOutput) ?></div>
    <?php endif; ?>

    <div class="cron-grid">
        <?php foreach ($cronJobs as $key => $job): ?>
            <?php
                $lastRun = $lastRuns[$key] ?? null;
                $lastDetails = $lastRun['details'] ?? [];
                $lastStatus = (string) ($lastDetails['status'] ?? '');
                $lastTime = $lastRun ? $lastRun['time'] : null;

                // Calculate if overdue
                $isOverdue = false;
                $overdueBy = '';
                if ($lastTime && isset($job['expected_interval_seconds'])) {
                    $elapsed = time() - strtotime($lastTime);
                    // Consider overdue if more than 2x the expected interval has passed
                    $threshold = (int) $job['expected_interval_seconds'] * 2;
                    if ($elapsed > $threshold) {
                        $isOverdue = true;
                        $overdueBy = cron_time_ago(date('Y-m-d H:i:s', time() - ($elapsed - (int) $job['expected_interval_seconds'])));
                    }
                }
            ?>
            <div class="cron-card" style="<?= $isOverdue ? 'border-left: 4px solid #d4351c;' : '' ?>">
                <h3><?= e($job['label']) ?></h3>
                <p><?= e($job['description']) ?></p>

                <div class="cron-schedule-info">
                    <span class="cron-expression" title="cPanel cron expression"><?= e($job['cron_expression']) ?></span>
                    <span class="cron-badge cron-badge-schedule"><?= e($job['schedule_human']) ?></span>
                    <?php if ($isOverdue): ?>
                        <span class="cron-badge cron-badge-overdue">Overdue</span>
                    <?php elseif ($lastTime): ?>
                        <span class="cron-badge cron-badge-ontime">On schedule</span>
                    <?php endif; ?>
                </div>

                <div class="cron-meta">
                    <?php if ($lastTime): ?>
                        <?php if ($lastStatus === 'success'): ?>
                            <span class="cron-badge cron-badge-success">Last run: OK</span>
                        <?php elseif (str_contains($lastStatus, 'error')): ?>
                            <span class="cron-badge cron-badge-error">Last run: Errors</span>
                        <?php elseif ($lastStatus === 'failed'): ?>
                            <span class="cron-badge cron-badge-error">Last run: Failed</span>
                        <?php else: ?>
                            <span class="cron-badge cron-badge-success">Last run: Completed</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="cron-badge cron-badge-never">Never run (no audit record)</span>
                    <?php endif; ?>
                </div>

                <?php if ($lastTime): ?>
                    <div class="cron-last-run">
                        <strong>Last run:</strong> <?= e(date('d M Y H:i:s', strtotime($lastTime))) ?>
                        (<?= e(cron_time_ago($lastTime)) ?>)
                        <?php if ($isOverdue): ?>
                            <span style="color:#d4351c;font-weight:900;"> &mdash; overdue by <?= e($overdueBy) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($lastDetails): ?>
                        <div class="cron-details">
                            <?php foreach ($lastDetails as $dKey => $dVal): ?>
                                <?php if ($dKey === 'status' || $dVal === null || $dVal === '') continue; ?>
                                <span class="cron-detail-chip"><?= e(ucwords(str_replace('_', ' ', $dKey))) ?>: <?= e(is_scalar($dVal) ? (string) $dVal : json_encode($dVal)) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="cron-actions">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="run_cron" value="<?= e($key) ?>">
                        <button class="btn btn-primary lt-btn" type="submit" onclick="return confirm('Run <?= e($job['label']) ?> now?');">
                            Run now
                        </button>
                    </form>
                    <a class="btn btn-secondary lt-btn" href="/system-admin.php?category=cron&action=<?= e($job['audit_code']) ?>">
                        View history
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</main>

<?php
function cron_time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);

    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return round($diff / 60) . 'm ago';
    if ($diff < 86400) return round($diff / 3600) . 'h ago';
    return round($diff / 86400) . 'd ago';
}
?>

<?php include __DIR__ . '/footer.php'; ?>
