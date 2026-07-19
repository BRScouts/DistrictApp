<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/system-admin-helpers.php';

if (is_file(__DIR__ . '/app/group-manager-helpers.php')) {
    require_once __DIR__ . '/app/group-manager-helpers.php';
}

$user = sa_require_system_admin();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

$saNavCurrent = 'permissions';

$pageTitle = 'System Admin — Permissions | ' . $appName;
$heroTitle = 'System Admin';
$heroText = 'Permissions reference and role documentation.';
$breadcrumb = '<a href="/index.php">Home</a> / <a href="/system-admin.php">System Admin</a> / Permissions';

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

    .perm-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .9rem;
        margin: 1rem 0 2rem;
    }
    .perm-table th, .perm-table td {
        border: 1px solid #d8d8d8;
        padding: .6rem .75rem;
        text-align: left;
        vertical-align: top;
    }
    .perm-table th {
        background: #1d1d1b;
        color: #fff;
        font-weight: 700;
    }
    .perm-table tr:nth-child(even) td {
        background: #f7f5fb;
    }
    .perm-section {
        margin-top: 2.5rem;
    }
    .perm-section h2 {
        color: #1d1d1b;
        font-size: 1.3rem;
        border-bottom: 3px solid #1d1d1b;
        padding-bottom: .4rem;
    }
    .perm-badge {
        display: inline-block;
        padding: .15rem .4rem;
        font-size: .75rem;
        font-weight: 700;
        border-radius: 3px;
    }
    .perm-badge-admin { background: #ffe0f0; color: #7b1052; }
    .perm-badge-glv { background: #e7f1ff; color: #004085; }
    .perm-badge-member { background: #d1e7dd; color: #0f5132; }
    .perm-badge-link { background: #fff3cd; color: #664d03; }
    .perm-note {
        background: #f7f5fb;
        border-left: 4px solid #1d1d1b;
        padding: .75rem 1rem;
        margin: 1rem 0;
        font-size: .9rem;
    }
</style>

<nav class="sa-service-bar" aria-label="System Admin navigation">
    <div class="sa-service-bar-inner">
        <a class="sa-nav-link" href="/system-admin.php" <?= $saNavCurrent === 'audit-log' ? 'aria-current="page"' : '' ?>>Audit Log</a>
        <a class="sa-nav-link" href="/system-admin-cron.php" <?= $saNavCurrent === 'cron' ? 'aria-current="page"' : '' ?>>Cron Jobs</a>
        <a class="sa-nav-link" href="/system-admin-gdpr.php" <?= $saNavCurrent === 'gdpr' ? 'aria-current="page"' : '' ?>>GDPR Reports</a>
        <a class="sa-nav-link" href="/system-admin-permissions.php" <?= $saNavCurrent === 'permissions' ? 'aria-current="page"' : '' ?>>Permissions</a>
        <a class="sa-nav-link" href="/system-admin-kb.php" <?= $saNavCurrent === 'kb' ? 'aria-current="page"' : '' ?>>Knowledge Base</a>
    </div>
</nav>

<main class="lt-main">

    <section class="perm-section" style="margin-top:0;">
        <h2>Access Levels</h2>
        <p>Each person has an access level determined by their highest role across all group memberships.</p>

        <table class="perm-table">
            <thead>
                <tr>
                    <th>Level</th>
                    <th>Who</th>
                    <th>What they can do</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><span class="perm-badge perm-badge-admin">System Admin</span></td>
                    <td>Technical administrators</td>
                    <td>Full access to everything including system settings, audit log, and all groups.</td>
                </tr>
                <tr>
                    <td><span class="perm-badge perm-badge-admin">District Admin</span></td>
                    <td>District leadership (DLV, DC team)</td>
                    <td>
                        Manage all groups, all people, set primary roles, access the District Admin panel and System Admin panel, view all calendar events, approve/reject events, manage district settings.
                    </td>
                </tr>
                <tr>
                    <td><span class="perm-badge perm-badge-glv">District Reviewer</span></td>
                    <td>Appointed event reviewers</td>
                    <td>View and review/approve calendar events across all groups. Cannot manage people or groups.</td>
                </tr>
                <tr>
                    <td><span class="perm-badge perm-badge-glv">Group Admin / GLV</span></td>
                    <td>Group Lead Volunteers</td>
                    <td>
                        Manage people in their group(s), add/remove members, change roles within their groups, submit and manage events for their group(s), view all roles for any person they manage.
                    </td>
                </tr>
                <tr>
                    <td><span class="perm-badge perm-badge-member">Member</span></td>
                    <td>All other volunteers</td>
                    <td>View the dashboard, directory, calendar. Submit events for their group. View their own profile and roles.</td>
                </tr>
                <tr>
                    <td><span class="perm-badge perm-badge-link">Group Link</span></td>
                    <td>Calendar-only access via link</td>
                    <td>View the District Calendar only. No sign-in, no dashboard, no directory.</td>
                </tr>
            </tbody>
        </table>
    </section>

    <section class="perm-section">
        <h2>Group Membership Roles</h2>
        <p>Each person can belong to one or more groups. Within each group, they have a specific role.</p>

        <?php if (function_exists('gm_membership_role_options')): ?>
            <h3 style="font-size:1rem;margin-top:1.5rem;">Standard Group Roles</h3>
            <table class="perm-table">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Access Level Granted</th>
                        <th>M365 Job Title</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (gm_membership_role_options(null) as $key => $label): ?>
                        <tr>
                            <td><?= e($label) ?></td>
                            <td><?= $key === 'group_lead_volunteer' ? '<span class="perm-badge perm-badge-glv">Group Admin</span>' : '<span class="perm-badge perm-badge-member">Member</span>' ?></td>
                            <td><?= e($label) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (function_exists('gm_district_role_options')): ?>
                <h3 style="font-size:1rem;margin-top:1.5rem;">District Team Roles (Group ID 3 only)</h3>
                <table class="perm-table">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th>M365 Job Title</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (gm_district_role_options() as $key => $label): ?>
                            <tr>
                                <td><?= e($label) ?></td>
                                <td><?= e($label) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="perm-section">
        <h2>Primary Role</h2>
        <div class="perm-note">
            Only District Admins can set a person's primary role.
        </div>
        <p>When a person belongs to multiple groups, one membership can be marked as <strong>primary</strong>. The primary role determines:</p>
        <ul>
            <li><strong>Microsoft 365 Job Title</strong> &mdash; synced from the primary role label</li>
            <li><strong>Microsoft 365 Department</strong> &mdash; synced from the primary role's group name</li>
            <li><strong>Microsoft 365 Manager</strong> &mdash; set to the GLV of the primary group (GLVs themselves are managed by the District Team GLV)</li>
        </ul>
        <p>If no primary is set, the system picks the highest-ranking role automatically (GLV > Team Leader > Team Member > Trustee etc).</p>
    </section>

    <section class="perm-section">
        <h2>Microsoft 365 Sync (Cron)</h2>
        <p>The <code>sync_m365_profiles.php</code> cron runs daily and updates each person's M365 profile:</p>
        <table class="perm-table">
            <thead>
                <tr>
                    <th>M365 Property</th>
                    <th>Source</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Department</td>
                    <td>Group name of their primary membership</td>
                </tr>
                <tr>
                    <td>Job Title</td>
                    <td>Role label of their primary membership</td>
                </tr>
                <tr>
                    <td>Manager</td>
                    <td>
                        Regular volunteers &rarr; their group's GLV<br>
                        GLVs &rarr; the District Team (group 3) GLV
                    </td>
                </tr>
            </tbody>
        </table>
    </section>

    <section class="perm-section">
        <h2>Leavers Process</h2>
        <p>The <code>leavers_notification.php</code> cron runs daily and:</p>
        <ol>
            <li>Finds people marked as <strong>inactive</strong> who have a Microsoft 365 account</li>
            <li>Sends an email to <strong>support@irvalscouts.org.uk</strong> requesting their M365 account be disabled</li>
            <li>Records the notification to avoid duplicates</li>
        </ol>
        <div class="perm-note">
            Making someone inactive in the Group Manager triggers the leaver notification on the next cron run.
        </div>
    </section>

    <section class="perm-section">
        <h2>Who Can Do What &mdash; Quick Reference</h2>
        <table class="perm-table">
            <thead>
                <tr>
                    <th>Action</th>
                    <th>System / District Admin</th>
                    <th>GLV</th>
                    <th>Member</th>
                    <th>Group Link</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>View Dashboard</td>
                    <td>Yes</td><td>Yes</td><td>Yes</td><td>No</td>
                </tr>
                <tr>
                    <td>View Directory</td>
                    <td>Yes</td><td>Yes</td><td>Yes</td><td>No</td>
                </tr>
                <tr>
                    <td>View Calendar</td>
                    <td>Yes</td><td>Yes</td><td>Yes</td><td>Yes</td>
                </tr>
                <tr>
                    <td>Submit events</td>
                    <td>Yes (any group)</td><td>Yes (their groups)</td><td>Yes (their group)</td><td>No</td>
                </tr>
                <tr>
                    <td>Approve/reject events</td>
                    <td>Yes</td><td>No</td><td>No</td><td>No</td>
                </tr>
                <tr>
                    <td>Add people to a group</td>
                    <td>Yes (any group)</td><td>Yes (their groups)</td><td>No</td><td>No</td>
                </tr>
                <tr>
                    <td>Edit roles in own group</td>
                    <td>Yes</td><td>Yes</td><td>No</td><td>No</td>
                </tr>
                <tr>
                    <td>Edit roles in other groups</td>
                    <td>Yes</td><td>No</td><td>No</td><td>No</td>
                </tr>
                <tr>
                    <td>View all roles for a person</td>
                    <td>Yes</td><td>Yes</td><td>No</td><td>No</td>
                </tr>
                <tr>
                    <td>Set primary role</td>
                    <td>Yes</td><td>No</td><td>No</td><td>No</td>
                </tr>
                <tr>
                    <td>Make someone inactive</td>
                    <td>Yes (any group)</td><td>Yes (their groups)</td><td>No</td><td>No</td>
                </tr>
                <tr>
                    <td>Manage district settings</td>
                    <td>Yes</td><td>No</td><td>No</td><td>No</td>
                </tr>
                <tr>
                    <td>Create/manage groups</td>
                    <td>Yes</td><td>No</td><td>No</td><td>No</td>
                </tr>
                <tr>
                    <td>View Audit Log</td>
                    <td>Yes</td><td>No</td><td>No</td><td>No</td>
                </tr>
                <tr>
                    <td>Send bulk communications</td>
                    <td>Yes</td><td>No</td><td>No</td><td>No</td>
                </tr>
            </tbody>
        </table>
    </section>

    <section class="perm-section">
        <h2>Audit Log Event Categories</h2>
        <p>All actions logged by the system are grouped into categories with severity levels:</p>
        <table class="perm-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Category</th>
                    <th>Label</th>
                    <th>Severity</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (audit_event_types() as $code => $meta): ?>
                    <tr>
                        <td><code><?= e($code) ?></code></td>
                        <td><?= e(ucfirst($meta[0])) ?></td>
                        <td><?= e($meta[1]) ?></td>
                        <td>
                            <?php
                                $sevClass = match ($meta[2]) {
                                    'critical' => 'perm-badge-admin',
                                    'warning' => 'perm-badge-link',
                                    default => 'perm-badge-member',
                                };
                            ?>
                            <span class="perm-badge <?= $sevClass ?>"><?= e($meta[2]) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="perm-section">
        <h2>Duplicate Person Detection</h2>
        <p>When adding a new person via the Group Manager, the system checks if someone with the same name or email already exists in another group. If a match is found:</p>
        <ul>
            <li><strong>"Add role to this person"</strong> &mdash; links the existing person to the new group with the chosen role (no duplicate created)</li>
            <li><strong>"This is a different person"</strong> &mdash; tick the checkbox and submit to create a separate person record</li>
        </ul>
    </section>

</main>

<?php include __DIR__ . '/footer.php'; ?>
