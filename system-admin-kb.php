<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/system-admin-helpers.php';

$user = sa_require_system_admin();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

$saNavCurrent = 'kb';

$pageTitle = 'System Admin — Knowledge Base | ' . $appName;
$heroTitle = 'System Admin';
$heroText = 'Knowledge base and technical reference for the Leader Tool.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/system-admin.php">System Admin</a> / Knowledge Base';

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

    .kb-section {
        margin-bottom: 2.5rem;
    }

    .kb-section h2 {
        font-size: 1.4rem;
        font-weight: 900;
        color: #4d0b93;
        margin: 0 0 .75rem;
        padding-bottom: .5rem;
        border-bottom: 3px solid #4d0b93;
    }

    .kb-section h3 {
        font-size: 1.1rem;
        font-weight: 900;
        color: #1d1d1b;
        margin: 1.25rem 0 .5rem;
    }

    .kb-section p,
    .kb-section li {
        font-size: .94rem;
        line-height: 1.6;
        color: #1d1d1b;
    }

    .kb-section ul {
        padding-left: 1.25rem;
        margin: .5rem 0;
    }

    .kb-section li {
        margin-bottom: .35rem;
    }

    .kb-card-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1rem;
        margin: 1rem 0;
    }

    .kb-card {
        background: #fff;
        border: 2px solid #e5e5e5;
        padding: 1.25rem;
    }

    .kb-card:hover {
        border-color: #4d0b93;
    }

    .kb-card h4 {
        font-size: 1rem;
        font-weight: 900;
        color: #4d0b93;
        margin: 0 0 .4rem;
    }

    .kb-card p {
        margin: 0;
        font-size: .88rem;
        color: #333;
    }

    .kb-badge {
        display: inline-block;
        padding: .15rem .5rem;
        font-size: .72rem;
        font-weight: 900;
        text-transform: uppercase;
        margin-right: .4rem;
        margin-bottom: .25rem;
    }

    .kb-badge-schedule {
        background: #d1e7dd;
        color: #0f5132;
    }

    .kb-badge-role {
        background: #e2d9f3;
        color: #4d0b93;
    }

    .kb-badge-tech {
        background: #fff3cd;
        color: #664d03;
    }

    .kb-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .88rem;
        margin: .75rem 0;
    }

    .kb-table th,
    .kb-table td {
        border: 1px solid #e5e5e5;
        padding: .6rem .75rem;
        text-align: left;
        vertical-align: top;
    }

    .kb-table th {
        background: #f7f5fb;
        font-weight: 900;
        color: #4d0b93;
        white-space: nowrap;
    }

    .kb-table tr:hover td {
        background: #faf8fd;
    }

    .kb-code {
        font-family: Consolas, Monaco, monospace;
        font-size: .82rem;
        background: #f3f2f1;
        padding: .15rem .4rem;
        border: 1px solid #e5e5e5;
    }

    .kb-toc {
        background: #f7f5fb;
        border: 2px solid #e5e5e5;
        padding: 1rem 1.5rem;
        margin-bottom: 2rem;
    }

    .kb-toc h3 {
        margin: 0 0 .5rem;
        font-size: .95rem;
        font-weight: 900;
        color: #4d0b93;
    }

    .kb-toc ol {
        margin: 0;
        padding-left: 1.25rem;
    }

    .kb-toc li {
        margin-bottom: .25rem;
        font-size: .88rem;
    }

    .kb-toc a {
        color: #4d0b93;
        font-weight: 700;
        text-decoration: none;
    }

    .kb-toc a:hover {
        text-decoration: underline;
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

    <div class="kb-toc">
        <h3>Contents</h3>
        <ol>
            <li><a href="#overview">Application Overview</a></li>
            <li><a href="#modules">Modules &amp; Features</a></li>
            <li><a href="#cron-jobs">Cron Jobs</a></li>
            <li><a href="#access-levels">Access Levels &amp; Roles</a></li>
            <li><a href="#microsoft">Microsoft 365 Integration</a></li>
            <li><a href="#email">Email System</a></li>
            <li><a href="#district-calendar">District Calendar</a></li>
            <li><a href="#architecture">Technical Architecture</a></li>
        </ol>
    </div>

    <!-- ─── 1. Application Overview ─────────────────────────────────────── -->
    <div class="kb-section" id="overview">
        <h2>1. Application Overview</h2>
        <p>
            The <strong>Irwell Valley Leader Tool</strong> is a web application for managing volunteers, groups, and communications
            across Irwell Valley Scout District. It provides a centralised platform for:
        </p>
        <ul>
            <li>Tracking all active volunteers and their group memberships</li>
            <li>Managing Microsoft 365 account provisioning and lifecycle</li>
            <li>Running a District Calendar with event submissions, reviews, and risk assessments</li>
            <li>Sending targeted communications to leaders via an email queue</li>
            <li>Providing an audit trail of all significant actions for security and accountability</li>
            <li>Generating GDPR data reports and supporting data subject requests</li>
            <li>Raising and tracking technical support requests</li>
        </ul>
        <p>
            Authentication is via Microsoft SSO (Entra ID). The app bridges to the main Scouts website (WordPress)
            for the people/groups database, and communicates with Microsoft Graph API for M365 account management.
        </p>
    </div>

    <!-- ─── 2. Modules & Features ───────────────────────────────────────── -->
    <div class="kb-section" id="modules">
        <h2>2. Modules &amp; Features</h2>

        <div class="kb-card-grid">
            <div class="kb-card">
                <h4>Dashboard / Home</h4>
                <span class="kb-badge kb-badge-role">All users</span>
                <p>Landing page after login. Shows the user's group memberships, role, and quick links to their areas.</p>
            </div>

            <div class="kb-card">
                <h4>Group Manager</h4>
                <span class="kb-badge kb-badge-role">Group Admin+</span>
                <p>Add, edit, and manage people within a Scout Group. Includes managing roles, contact details, M365 account requests, and marking leavers as inactive.</p>
            </div>

            <div class="kb-card">
                <h4>District Admin</h4>
                <span class="kb-badge kb-badge-role">District Admin+</span>
                <p>Cross-group management. View/edit any group's members, manage access levels, and oversee the directory.</p>
            </div>

            <div class="kb-card">
                <h4>Directory</h4>
                <span class="kb-badge kb-badge-role">All logged-in users</span>
                <p>Searchable directory of all active volunteers across the district, showing name, role, group, and profile photo.</p>
            </div>

            <div class="kb-card">
                <h4>District Calendar</h4>
                <span class="kb-badge kb-badge-role">All users (view) / Group Admin+ (submit)</span>
                <p>Event submission and review workflow. Events go through draft &rarr; submitted &rarr; reviewed &rarr; approved states. Includes risk assessment uploads.</p>
            </div>

            <div class="kb-card">
                <h4>Comms Tool</h4>
                <span class="kb-badge kb-badge-role">District Admin+</span>
                <p>Build targeted recipient lists by group, role, or section and queue District-wide emails for sending via Microsoft Graph.</p>
            </div>

            <div class="kb-card">
                <h4>Technical Support</h4>
                <span class="kb-badge kb-badge-role">All users</span>
                <p>Raise a support request for issues with the website, District App, email, or OneDrive. Queues an email to the support mailbox.</p>
            </div>

            <div class="kb-card">
                <h4>System Admin</h4>
                <span class="kb-badge kb-badge-role">System Admin only</span>
                <p>Audit log, cron job management, GDPR reports, permissions reference, and this knowledge base.</p>
            </div>

            <div class="kb-card">
                <h4>Profile</h4>
                <span class="kb-badge kb-badge-role">All users</span>
                <p>View and update personal details. Profile photo synced from Microsoft 365.</p>
            </div>

            <div class="kb-card">
                <h4>Onboarding</h4>
                <span class="kb-badge kb-badge-role">New users</span>
                <p>Guides new users who sign in for the first time to select their group, confirming their membership before accessing the app.</p>
            </div>
        </div>
    </div>

    <!-- ─── 3. Cron Jobs ────────────────────────────────────────────────── -->
    <div class="kb-section" id="cron-jobs">
        <h2>3. Cron Jobs</h2>
        <p>Scheduled tasks run via cPanel cron (CLI) or can be triggered manually from the <a href="/system-admin-cron.php">Cron Jobs</a> page. Each job includes a <code class="kb-code">cron-guard.php</code> that allows browser execution by system admins for testing.</p>

        <table class="kb-table">
            <thead>
                <tr>
                    <th>Job</th>
                    <th>File</th>
                    <th>Schedule</th>
                    <th>What it does</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Send Email Queue</strong></td>
                    <td><code class="kb-code">cron/send-email-queue.php</code></td>
                    <td><span class="kb-badge kb-badge-schedule">Every 5 min</span></td>
                    <td>
                        Picks up pending emails from the <code class="kb-code">email_queue</code> table and sends them via Microsoft Graph API
                        (<code class="kb-code">sendMail</code> endpoint). Marks each as sent or failed. Retries up to 5 attempts.
                        All outbound email from the app goes through this queue.
                    </td>
                </tr>
                <tr>
                    <td><strong>Provision M365 Accounts</strong></td>
                    <td><code class="kb-code">cron/provision_m365_accounts.php</code></td>
                    <td><span class="kb-badge kb-badge-schedule">Every 5 min</span></td>
                    <td>
                        Processes pending rows in <code class="kb-code">m365_account_requests</code>. For each request:
                        <ul>
                            <li>Checks if the UPN already exists in Microsoft 365 (Graph API)</li>
                            <li>If not, creates the user with a temporary password and assigns a licence</li>
                            <li>Sends an onboarding email to the volunteer with sign-in details</li>
                            <li>Notifies the person who requested the account</li>
                            <li>Links the Graph user ID back to the <code class="kb-code">people</code> and <code class="kb-code">user_accounts</code> tables</li>
                        </ul>
                        On failure, emails support and marks the request for retry.
                    </td>
                </tr>
                <tr>
                    <td><strong>Sync M365 Profiles</strong></td>
                    <td><code class="kb-code">cron/sync_m365_profiles.php</code></td>
                    <td><span class="kb-badge kb-badge-schedule">Daily 05:15</span></td>
                    <td>
                        For every active volunteer with a linked M365 account, syncs their Microsoft 365 profile:
                        <ul>
                            <li><strong>department</strong> &rarr; their Scout Group name</li>
                            <li><strong>jobTitle</strong> &rarr; their membership role (e.g. "Cub Section Team Leader")</li>
                            <li><strong>manager</strong> &rarr; their Group Lead Volunteer (or the District Lead Volunteer for GLVs)</li>
                        </ul>
                        Only PATCHes when values have actually changed. Rate-limited to respect Graph API throttling.
                    </td>
                </tr>
                <tr>
                    <td><strong>Leavers Notification</strong></td>
                    <td><code class="kb-code">cron/leavers_notification.php</code></td>
                    <td><span class="kb-badge kb-badge-schedule">Daily 06:30</span></td>
                    <td>
                        Detects people marked as <code class="kb-code">status = 'inactive'</code> who still have a linked Microsoft 365 account.
                        Queues an email to the support mailbox requesting their M365 account be disabled.
                        Tracks notifications via a <code class="kb-code">leaver_notified_at</code> column to avoid duplicates.
                    </td>
                </tr>
                <tr>
                    <td><strong>Reminders &amp; Cleanse</strong></td>
                    <td><code class="kb-code">cron/Reminders-and-clense.php</code></td>
                    <td><span class="kb-badge kb-badge-schedule">Daily 06:10</span></td>
                    <td>
                        District Calendar maintenance:
                        <ul>
                            <li><strong>Day-before draft reminder</strong> &mdash; emails the event leader if their event is tomorrow but still in draft</li>
                            <li><strong>8-day draft reminder</strong> &mdash; emails the event leader if start date is &lt;8 days away and still in draft</li>
                            <li><strong>Reviewer reminder</strong> &mdash; emails district reviewers when submitted events are &lt;7 days away and not yet approved</li>
                            <li><strong>Old event deletion</strong> &mdash; permanently deletes draft/cancelled/rejected events older than 365 days (and their risk assessment links)</li>
                        </ul>
                    </td>
                </tr>
            </tbody>
        </table>

        <h3>Cron Guard</h3>
        <p>
            All cron files include <code class="kb-code">cron/cron-guard.php</code>. When run from CLI it passes through with no checks.
            When accessed via a browser, it requires the user to be logged in as a <strong>system_admin</strong> or <strong>district_admin</strong>,
            allowing safe manual execution from the System Admin &rarr; Cron Jobs page.
        </p>

        <h3>Server Cron Commands</h3>
        <p>On the production server, cron jobs are configured in cPanel with commands like:</p>
        <table class="kb-table">
            <thead>
                <tr>
                    <th>Schedule</th>
                    <th>Command</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>*/5 * * * *</td>
                    <td><code class="kb-code">/usr/local/bin/php /home/brscouts/app.irvalscouts.org.uk/cron/send-email-queue.php</code></td>
                </tr>
                <tr>
                    <td>*/5 * * * *</td>
                    <td><code class="kb-code">/usr/local/bin/php /home/brscouts/app.irvalscouts.org.uk/cron/provision_m365_accounts.php</code></td>
                </tr>
                <tr>
                    <td>15 5 * * *</td>
                    <td><code class="kb-code">/usr/local/bin/php /home/brscouts/app.irvalscouts.org.uk/cron/sync_m365_profiles.php</code></td>
                </tr>
                <tr>
                    <td>30 6 * * *</td>
                    <td><code class="kb-code">/usr/local/bin/php /home/brscouts/app.irvalscouts.org.uk/cron/leavers_notification.php</code></td>
                </tr>
                <tr>
                    <td>10 6 * * *</td>
                    <td><code class="kb-code">/usr/local/bin/php /home/brscouts/app.irvalscouts.org.uk/cron/Reminders-and-clense.php</code></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- ─── 4. Access Levels ────────────────────────────────────────────── -->
    <div class="kb-section" id="access-levels">
        <h2>4. Access Levels &amp; Roles</h2>

        <h3>Access Levels (app-wide permissions)</h3>
        <table class="kb-table">
            <thead>
                <tr>
                    <th>Level</th>
                    <th>Can access</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>system_admin</strong></td>
                    <td>Everything. System Admin panel, cron execution, GDPR reports, all groups.</td>
                </tr>
                <tr>
                    <td><strong>district_admin</strong></td>
                    <td>District Admin, Comms Tool, all groups, cron execution. Cannot access System Admin audit log.</td>
                </tr>
                <tr>
                    <td><strong>district_reviewer</strong></td>
                    <td>District Calendar review panel. Can approve/reject submitted events.</td>
                </tr>
                <tr>
                    <td><strong>group_admin</strong></td>
                    <td>Group Manager for their own group(s). Can add/edit people, request M365 accounts, manage access within their group.</td>
                </tr>
                <tr>
                    <td><strong>member</strong></td>
                    <td>Dashboard, Directory, Profile, District Calendar (view), Technical Support.</td>
                </tr>
            </tbody>
        </table>

        <h3>Membership Roles (within a group)</h3>
        <p>Each person holds a <strong>membership_role</strong> within their group, describing their Scouting position. Examples:</p>
        <ul>
            <li>Group Lead Volunteer, Group Leadership Team Member</li>
            <li>Section Team Leaders &amp; Members (Squirrel, Beaver, Cub, Scout, Explorer)</li>
            <li>Group Chair, Group Treasurer, Group Trustee</li>
            <li>District Lead Volunteer, District Youth Lead, District Leadership Team Member</li>
            <li>District section team roles, District programme/support teams, District trustees</li>
        </ul>
        <p>These roles determine the <strong>jobTitle</strong> field synced to Microsoft 365 profiles.</p>
    </div>

    <!-- ─── 5. Microsoft 365 Integration ────────────────────────────────── -->
    <div class="kb-section" id="microsoft">
        <h2>5. Microsoft 365 Integration</h2>

        <h3>Authentication (SSO)</h3>
        <p>
            Users sign in via Microsoft Entra ID (formerly Azure AD) using OAuth 2.0 / OpenID Connect.
            The callback at <code class="kb-code">/auth/microsoft-callback.php</code> matches the returned
            <code class="kb-code">oid</code> (object ID) to a row in <code class="kb-code">user_accounts</code>
            (provider = 'microsoft') to identify the local person record.
        </p>

        <h3>Account Provisioning</h3>
        <p>When a Group Admin requests an M365 account via the Group Manager, a row is inserted into <code class="kb-code">m365_account_requests</code>. The provisioning cron then:</p>
        <ol>
            <li>Obtains a client_credentials Graph token using the app registration</li>
            <li>Checks if the UPN already exists (<code class="kb-code">GET /users/{upn}</code>)</li>
            <li>Creates the user if needed (<code class="kb-code">POST /users</code>) with a temporary password</li>
            <li>Assigns a licence via the configured SKU ID</li>
            <li>Sends onboarding email with credentials to the volunteer's personal email</li>
        </ol>

        <h3>Profile Sync</h3>
        <p>The daily sync cron keeps M365 profiles aligned with the Leader Tool data:</p>
        <ul>
            <li><strong>department</strong> = group name</li>
            <li><strong>jobTitle</strong> = membership role label</li>
            <li><strong>manager</strong> = the group's GLV (or the DLV for GLVs themselves)</li>
        </ul>

        <h3>Leaver Lifecycle</h3>
        <p>When a person is set to inactive, the leavers cron detects them and emails support to disable the M365 account. This is a notification-only process &mdash; the account is not automatically disabled via Graph to allow for a manual handover check.</p>

        <h3>Required Azure App Permissions</h3>
        <table class="kb-table">
            <thead>
                <tr>
                    <th>Permission</th>
                    <th>Type</th>
                    <th>Used for</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>User.ReadWrite.All</td>
                    <td>Application</td>
                    <td>Creating users, updating profiles, setting managers</td>
                </tr>
                <tr>
                    <td>Mail.Send</td>
                    <td>Application</td>
                    <td>Sending emails via Graph sendMail</td>
                </tr>
                <tr>
                    <td>Directory.Read.All</td>
                    <td>Application</td>
                    <td>Reading user profiles and licence info</td>
                </tr>
                <tr>
                    <td>openid, profile, email</td>
                    <td>Delegated</td>
                    <td>SSO sign-in</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- ─── 6. Email System ─────────────────────────────────────────────── -->
    <div class="kb-section" id="email">
        <h2>6. Email System</h2>
        <p>All outbound email uses a queue-based pattern rather than sending inline:</p>
        <ol>
            <li>Any part of the app inserts a row into <code class="kb-code">email_queue</code> with status <code class="kb-code">pending</code></li>
            <li>The <strong>send-email-queue</strong> cron picks up pending rows every 5 minutes</li>
            <li>Each email is sent via the Microsoft Graph <code class="kb-code">sendMail</code> API (not SMTP)</li>
            <li>On success, the row is marked <code class="kb-code">sent</code>. On failure, <code class="kb-code">attempt_count</code> is incremented and retried next run (up to 5 attempts)</li>
        </ol>

        <h3>Email Sources</h3>
        <ul>
            <li><strong>Provisioning</strong> &mdash; onboarding emails, requester notifications, support failure alerts</li>
            <li><strong>Leavers</strong> &mdash; account disable requests to support</li>
            <li><strong>District Calendar</strong> &mdash; draft reminders, reviewer reminders</li>
            <li><strong>Comms Tool</strong> &mdash; bulk communications queued by district admins</li>
            <li><strong>Technical Support</strong> &mdash; support requests raised by users</li>
        </ul>

        <h3>Key Config</h3>
        <table class="kb-table">
            <thead>
                <tr>
                    <th>Constant</th>
                    <th>Purpose</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code class="kb-code">MS_GRAPH_MAIL_FROM</code></td>
                    <td>The mailbox used to send (must be licensed in the tenant)</td>
                </tr>
                <tr>
                    <td><code class="kb-code">MS_GRAPH_MAIL_FROM_NAME</code></td>
                    <td>Display name on outbound emails</td>
                </tr>
                <tr>
                    <td><code class="kb-code">MS_GRAPH_MAIL_REPLY_TO</code></td>
                    <td>Reply-To address (optional)</td>
                </tr>
                <tr>
                    <td><code class="kb-code">EMAIL_QUEUE_BATCH_SIZE</code></td>
                    <td>Max emails per cron run (default 25)</td>
                </tr>
                <tr>
                    <td><code class="kb-code">EMAIL_QUEUE_MAX_ATTEMPTS</code></td>
                    <td>Retry limit before marking permanently failed (default 5)</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- ─── 7. District Calendar ────────────────────────────────────────── -->
    <div class="kb-section" id="district-calendar">
        <h2>7. District Calendar</h2>

        <h3>Event Lifecycle</h3>
        <table class="kb-table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Meaning</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>draft</strong></td>
                    <td>Created but not submitted. Only the creator/group admin can see it. Triggers reminders if the start date approaches.</td>
                </tr>
                <tr>
                    <td><strong>submitted</strong></td>
                    <td>Sent for district review. Appears in the reviewer queue.</td>
                </tr>
                <tr>
                    <td><strong>under_review</strong></td>
                    <td>A reviewer has started looking at it.</td>
                </tr>
                <tr>
                    <td><strong>changes_requested</strong></td>
                    <td>Reviewer has asked for edits before approval.</td>
                </tr>
                <tr>
                    <td><strong>approved</strong></td>
                    <td>Good to go. Visible on the public calendar.</td>
                </tr>
                <tr>
                    <td><strong>rejected</strong></td>
                    <td>Not approved. Will be deleted after 365 days by the cleanse cron.</td>
                </tr>
                <tr>
                    <td><strong>cancelled</strong></td>
                    <td>Previously approved but now cancelled. Deleted after 365 days.</td>
                </tr>
            </tbody>
        </table>

        <h3>Risk Assessments</h3>
        <p>Events can have linked risk assessment documents uploaded as PDFs. These are stored in <code class="kb-code">dc/uploads/risk_assessments/</code> and protected by <code class="kb-code">.htaccess</code>. Download/preview is gated through <code class="kb-code">dc/download-risk-assessment.php</code>.</p>

        <h3>Reviewer Panel</h3>
        <p>District reviewers access <code class="kb-code">/dc/reviewer/</code> to see submitted events and approve, reject, or request changes. Notifications are sent automatically when the event start date approaches and no review has been completed.</p>
    </div>

    <!-- ─── 8. Technical Architecture ───────────────────────────────────── -->
    <div class="kb-section" id="architecture">
        <h2>8. Technical Architecture</h2>

        <h3>Stack</h3>
        <table class="kb-table">
            <thead>
                <tr>
                    <th>Layer</th>
                    <th>Technology</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Language</td>
                    <td>PHP 8.1+ (strict types throughout)</td>
                </tr>
                <tr>
                    <td>Database</td>
                    <td>MySQL (via PDO)</td>
                </tr>
                <tr>
                    <td>HTTP Client</td>
                    <td>Guzzle (for Microsoft Graph API calls)</td>
                </tr>
                <tr>
                    <td>Auth</td>
                    <td>Microsoft Entra ID OAuth 2.0 (OpenID Connect)</td>
                </tr>
                <tr>
                    <td>Hosting</td>
                    <td>cPanel shared hosting (brscouts account)</td>
                </tr>
                <tr>
                    <td>Local dev</td>
                    <td>XAMPP (Apache + PHP + MySQL)</td>
                </tr>
                <tr>
                    <td>Dependencies</td>
                    <td>Composer (guzzlehttp/guzzle)</td>
                </tr>
            </tbody>
        </table>

        <h3>Key Directories</h3>
        <table class="kb-table">
            <thead>
                <tr>
                    <th>Path</th>
                    <th>Purpose</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code class="kb-code">app/</code></td>
                    <td>Core library files &mdash; bootstrap, auth, DB bridge, CSRF, audit, helpers</td>
                </tr>
                <tr>
                    <td><code class="kb-code">auth/</code></td>
                    <td>Microsoft OAuth callback, profile photo proxy</td>
                </tr>
                <tr>
                    <td><code class="kb-code">cron/</code></td>
                    <td>Scheduled tasks (email send, provisioning, sync, leavers, reminders)</td>
                </tr>
                <tr>
                    <td><code class="kb-code">dc/</code></td>
                    <td>District Calendar module (events, reviewer panel, admin settings, risk assessments)</td>
                </tr>
                <tr>
                    <td><code class="kb-code">database/migrations/</code></td>
                    <td>SQL migration files for schema changes</td>
                </tr>
                <tr>
                    <td><code class="kb-code">assets/</code></td>
                    <td>CSS and images</td>
                </tr>
                <tr>
                    <td><code class="kb-code">vendor/</code></td>
                    <td>Composer dependencies (gitignored)</td>
                </tr>
            </tbody>
        </table>

        <h3>Database Bridge</h3>
        <p>
            The app uses <code class="kb-code">app/db-bridge.php</code> to connect to the database.
            The people and groups data originates from the main Scouts website (WordPress) database,
            accessed via bridge queries. The Leader Tool adds its own tables
            (<code class="kb-code">audit_log</code>, <code class="kb-code">email_queue</code>,
            <code class="kb-code">m365_account_requests</code>, <code class="kb-code">user_accounts</code>,
            <code class="kb-code">calendar_events</code>, <code class="kb-code">group_memberships</code>,
            <code class="kb-code">app_settings</code>, etc.) alongside the WordPress tables.
        </p>

        <h3>Security</h3>
        <ul>
            <li>All forms use CSRF tokens (<code class="kb-code">app/csrf.php</code>)</li>
            <li>Security headers set via <code class="kb-code">app/security-headers.php</code></li>
            <li>Input escaped with <code class="kb-code">e()</code> (htmlspecialchars wrapper)</li>
            <li>Database queries use prepared statements (PDO) throughout</li>
            <li>Audit log records all significant actions with actor, target, IP, and details</li>
        </ul>
    </div>

</main>

<?php include __DIR__ . '/footer.php'; ?>
