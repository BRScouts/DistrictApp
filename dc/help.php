<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_auth();

$currentGroup = auth_group();
$currentAdmin = auth_admin();

$pageTitle = 'Help & User Guide';

function help_e(string $value): string
{
    if (function_exists('e')) {
        return e($value);
    }

    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function help_url(string $path): string
{
    if (defined('BASE_URL')) {
        return rtrim((string)BASE_URL, '/') . '/' . ltrim($path, '/');
    }

    return $path;
}

$links = [
    'calendar' => help_url('index.php'),
    'add_event' => defined('ROUTE_ADD_EVENT') ? (string)ROUTE_ADD_EVENT : help_url('add-event.php'),
    'risk_assessments' => help_url('risk-assessments.php'),
    'map' => help_url('map.php'),
];

render_page_start($pageTitle);
render_header('help');
?>

<style>
    :root {
        --help-border: #dee2e6;
        --help-muted: #6c757d;
        --help-soft: #f8f9fa;
        --help-blue-soft: #f4f9ff;
        --help-green-soft: #f3fbf5;
        --help-yellow-soft: #fff9e6;
        --help-red-soft: #fff5f5;
    }

    html {
        scroll-behavior: smooth;
    }

    .help-hero {
        background: linear-gradient(135deg, #f4f9ff 0%, #ffffff 100%);
        border: 1px solid var(--help-border);
        border-radius: 1rem;
        padding: 2rem;
        margin-bottom: 1.5rem;
    }

    .help-search-wrap {
        position: sticky;
        top: 0;
        z-index: 20;
        background: #fff;
        border: 1px solid var(--help-border);
        border-radius: 1rem;
        padding: 1rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 .5rem 1.25rem rgba(0, 0, 0, .04);
    }

    .help-sidebar {
        position: sticky;
        top: 1rem;
        max-height: calc(100vh - 2rem);
        overflow-y: auto;
        border: 1px solid var(--help-border);
        border-radius: 1rem;
        background: #fff;
        padding: 1rem;
    }

    .help-sidebar .nav-link {
        color: #343a40;
        padding: .35rem .5rem;
        border-radius: .35rem;
        font-size: .95rem;
    }

    .help-sidebar .nav-link:hover,
    .help-sidebar .nav-link.active {
        background: var(--help-blue-soft);
        color: #0056b3;
    }

    .help-sidebar .nav-heading {
        font-size: .75rem;
        letter-spacing: .05em;
        text-transform: uppercase;
        color: var(--help-muted);
        font-weight: 700;
        margin: 1rem .5rem .35rem;
    }

    .help-section {
        border: 1px solid var(--help-border);
        border-radius: 1rem;
        background: #fff;
        margin-bottom: 1.5rem;
        overflow: hidden;
    }

    .help-section-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--help-border);
        background: var(--help-soft);
    }

    .help-section-body {
        padding: 1.5rem;
    }

    .help-section h2,
    .help-section h3,
    .help-section h4,
    .faq-item {
        scroll-margin-top: 7rem;
    }

    .help-step {
        display: flex;
        gap: .9rem;
        margin-bottom: 1rem;
    }

    .help-step-number {
        flex: 0 0 2rem;
        width: 2rem;
        height: 2rem;
        border-radius: 50%;
        background: #007bff;
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: .9rem;
    }

    .help-callout {
        border-left: 4px solid #007bff;
        background: var(--help-blue-soft);
        padding: 1rem;
        border-radius: .5rem;
        margin: 1rem 0;
    }

    .help-callout.warning {
        border-left-color: #ffc107;
        background: var(--help-yellow-soft);
    }

    .help-callout.success {
        border-left-color: #28a745;
        background: var(--help-green-soft);
    }

    .help-callout.danger {
        border-left-color: #dc3545;
        background: var(--help-red-soft);
    }

    .help-card-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
    }

    .help-mini-card {
        border: 1px solid var(--help-border);
        border-radius: .75rem;
        padding: 1rem;
        background: #fff;
    }

    .help-mini-card h4 {
        font-size: 1rem;
        margin-bottom: .5rem;
    }

    .faq-item {
        border: 1px solid var(--help-border);
        border-radius: .75rem;
        padding: 1rem;
        margin-bottom: .75rem;
        background: #fff;
    }

    .faq-item h3 {
        font-size: 1.05rem;
        margin-bottom: .5rem;
    }

    .help-badge {
        display: inline-block;
        border-radius: 999px;
        padding: .2rem .55rem;
        font-size: .75rem;
        font-weight: 700;
        background: #eef2f7;
        color: #343a40;
        margin-right: .25rem;
        margin-bottom: .25rem;
    }

    .help-badge.green {
        background: #e9f7ef;
        color: #155724;
    }

    .help-badge.yellow {
        background: #fff3cd;
        color: #856404;
    }

    .help-badge.red {
        background: #f8d7da;
        color: #721c24;
    }

    .help-badge.blue {
        background: #d9ecff;
        color: #004085;
    }

    .help-empty-state {
        display: none;
        border: 1px dashed var(--help-border);
        border-radius: 1rem;
        padding: 2rem;
        text-align: center;
        color: var(--help-muted);
        background: var(--help-soft);
    }

    mark.help-mark {
        background: #fff3cd;
        padding: 0 .1rem;
        border-radius: .15rem;
    }

    .help-anchor {
        color: inherit;
        text-decoration: none;
    }

    .help-anchor:hover {
        color: #0056b3;
        text-decoration: none;
    }

    .help-table th {
        white-space: nowrap;
    }

    .help-page-link-card {
        display: block;
        border: 1px solid var(--help-border);
        border-radius: .75rem;
        padding: 1rem;
        color: inherit;
        background: #fff;
        height: 100%;
    }

    .help-page-link-card:hover {
        text-decoration: none;
        color: inherit;
        border-color: #007bff;
        background: var(--help-blue-soft);
    }

    .help-highlight-box {
        border: 1px solid var(--help-border);
        border-radius: .75rem;
        padding: 1rem;
        background: var(--help-soft);
    }

    @media (max-width: 991.98px) {
        .help-sidebar {
            position: static;
            max-height: none;
            margin-bottom: 1.5rem;
        }

        .help-search-wrap {
            position: static;
        }

        .help-card-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="container-fluid">
    <div class="help-hero searchable-block" data-search-title="Help and user guide overview introduction">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <h1 class="mb-2">Help & User Guide</h1>
                <p class="lead mb-2">
                    This guide explains how to use the Away From Hut tool as a group user.
                </p>
                <p class="text-muted mb-0">
                    It covers the calendar, submitting events, risk assessments, approvals, archiving, reusable documents, and the map.
                </p>
            </div>

            <div class="col-lg-4 mt-3 mt-lg-0">
                <?php if ($currentGroup): ?>
                    <div class="alert alert-info mb-0">
                        <strong>Your group:</strong><br>
                        <?= help_e((string)$currentGroup['group_name']) ?>
                    </div>
                <?php elseif ($currentAdmin): ?>
                    <div class="alert alert-warning mb-0">
                        This page is written for group users. Reviewer and admin-only screens are not covered.
                    </div>
                <?php else: ?>
                    <div class="alert alert-secondary mb-0">
                        This guide is for signed-in group users.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="help-search-wrap">
        <label for="helpSearch" class="font-weight-bold">Search this help page</label>
        <div class="input-group">
            <input
                type="search"
                class="form-control form-control-lg"
                id="helpSearch"
                placeholder="Search FAQs, risk assessments, approvals, map, attaching files..."
                autocomplete="off"
            >
            <div class="input-group-append">
                <button class="btn btn-outline-secondary" type="button" id="clearHelpSearch">
                    Clear
                </button>
            </div>
        </div>
        <small class="form-text text-muted">
            Search works on this page only. Nothing is saved to the database.
        </small>
    </div>

    <div class="row">
        <aside class="col-lg-3 col-xl-2 mb-4">
            <nav class="help-sidebar" aria-label="Help sections">
                <div class="nav-heading mt-0">Pages</div>
                <a class="nav-link" href="#overview">Overview</a>
                <a class="nav-link" href="#calendar-page">Calendar page</a>
                <a class="nav-link" href="#add-event-page">Add / manage event page</a>
                <a class="nav-link" href="#risk-assessments-page">Risk assessments page</a>
                <a class="nav-link" href="#map-page">Map page</a>

                <div class="nav-heading">Key tasks</div>
                <a class="nav-link" href="#submit-event">Submit an event</a>
                <a class="nav-link" href="#approval-workflow">Approvals workflow</a>
                <a class="nav-link" href="#how-risk-assessments-work">How risk assessments work</a>
                <a class="nav-link" href="#reuse-risk-assessments">Reuse and attach rules</a>
                <a class="nav-link" href="#sharing-risk-assessments">Sharing and private RAs</a>
                <a class="nav-link" href="#archiving-risk-assessments">Archiving</a>

                <div class="nav-heading">FAQs</div>
                <a class="nav-link" href="#faq">All FAQs</a>
                <a class="nav-link" href="#faq-events">Event FAQs</a>
                <a class="nav-link" href="#faq-risk-assessments">Risk assessment FAQs</a>
                <a class="nav-link" href="#faq-approval">Approval FAQs</a>
                <a class="nav-link" href="#faq-map">Map FAQs</a>
                <a class="nav-link" href="#faq-troubleshooting">Troubleshooting</a>
            </nav>
        </aside>

        <main class="col-lg-9 col-xl-10">
            <div id="helpEmptyState" class="help-empty-state">
                <h2 class="h5">No matches found</h2>
                <p class="mb-0">
                    Try searching for a broader word such as “risk”, “attach”, “approval”, “map”, “archive”, or “event”.
                </p>
            </div>

            <section id="overview" class="help-section searchable-block" data-search-title="Overview quick links pages calendar add event risk assessments map">
                <div class="help-section-header">
                    <h2 class="h3 mb-1">
                        <a href="#overview" class="help-anchor">Overview</a>
                    </h2>
                    <p class="text-muted mb-0">
                        The tool is designed to help groups notify, track, and manage Away From Hut events.
                    </p>
                </div>

                <div class="help-section-body">
                    <p>
                        As a group user, you normally use four main areas:
                    </p>

                    <div class="row">
                        <div class="col-md-6 col-xl-3 mb-3">
                            <a class="help-page-link-card" href="<?= help_e($links['calendar']) ?>">
                                <h3 class="h5">Calendar</h3>
                                <p class="mb-0 text-muted">
                                    View events and understand what is planned across the district.
                                </p>
                            </a>
                        </div>

                        <div class="col-md-6 col-xl-3 mb-3">
                            <a class="help-page-link-card" href="<?= help_e($links['add_event']) ?>">
                                <h3 class="h5">Add Event</h3>
                                <p class="mb-0 text-muted">
                                    Submit a new Away From Hut notification or update one already submitted.
                                </p>
                            </a>
                        </div>

                        <div class="col-md-6 col-xl-3 mb-3">
                            <a class="help-page-link-card" href="<?= help_e($links['risk_assessments']) ?>">
                                <h3 class="h5">Risk Assessments</h3>
                                <p class="mb-0 text-muted">
                                    View, reuse, upload, share, or archive risk assessment documents.
                                </p>
                            </a>
                        </div>

                        <div class="col-md-6 col-xl-3 mb-3">
                            <a class="help-page-link-card" href="<?= help_e($links['map']) ?>">
                                <h3 class="h5">Map</h3>
                                <p class="mb-0 text-muted">
                                    See event locations and check where activities are taking place.
                                </p>
                            </a>
                        </div>
                    </div>

                    <div class="help-callout">
                        <strong>Important:</strong> Submitting an event does not automatically mean it is approved.
                        The event must go through the review process before it should be treated as approved.
                    </div>
                </div>
            </section>

            <section id="calendar-page" class="help-section searchable-block" data-search-title="Calendar page view events statuses filters submitted approved declined cancelled">
                <div class="help-section-header">
                    <h2 class="h3 mb-1">
                        <a href="#calendar-page" class="help-anchor">Calendar page</a>
                    </h2>
                    <p class="text-muted mb-0">
                        The calendar is the main place to see submitted, approved, declined, or cancelled events.
                    </p>
                </div>

                <div class="help-section-body">
                    <h3 class="h5">What the calendar is for</h3>
                    <p>
                        Use the calendar to check what events are already planned, avoid clashes, and find information about existing events.
                        Depending on how the tool has been configured, you may be able to see events from other groups as well as your own.
                    </p>

                    <h3 class="h5 mt-4">What to do on this page</h3>

                    <div class="help-step">
                        <div class="help-step-number">1</div>
                        <div>
                            <strong>Look for the date of your planned activity.</strong>
                            <p class="mb-0 text-muted">
                                Use the calendar view to check what else is happening nearby or at the same time.
                            </p>
                        </div>
                    </div>

                    <div class="help-step">
                        <div class="help-step-number">2</div>
                        <div>
                            <strong>Open an event to see more detail.</strong>
                            <p class="mb-0 text-muted">
                                Event details may include the title, group, contact details, location, dates, times, numbers attending, status, and reviewer comments.
                            </p>
                        </div>
                    </div>

                    <div class="help-step">
                        <div class="help-step-number">3</div>
                        <div>
                            <strong>Use the status to understand whether the event can proceed.</strong>
                            <p class="mb-0 text-muted">
                                Submitted events are waiting for review. Approved events have been accepted. Declined or changes requested events need action.
                            </p>
                        </div>
                    </div>

                    <h3 class="h5 mt-4">Common statuses</h3>

                    <div class="table-responsive">
                        <table class="table table-bordered help-table">
                            <thead class="thead-light">
                                <tr>
                                    <th>Status</th>
                                    <th>Meaning</th>
                                    <th>What you should do</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><span class="help-badge">Draft</span></td>
                                    <td>The event has not yet been submitted.</td>
                                    <td>Complete the event details and submit when ready.</td>
                                </tr>
                                <tr>
                                    <td><span class="help-badge blue">Submitted</span></td>
                                    <td>The event has been sent for review.</td>
                                    <td>Wait for a reviewer decision. Check for email updates or reviewer comments.</td>
                                </tr>
                                <tr>
                                    <td><span class="help-badge green">Approved</span></td>
                                    <td>The event has been reviewed and approved.</td>
                                    <td>Keep your plans and risk assessment up to date.</td>
                                </tr>
                                <tr>
                                    <td><span class="help-badge yellow">Changes requested</span></td>
                                    <td>A reviewer needs something changed or clarified.</td>
                                    <td>Open the event, read the comments, update it, and resubmit.</td>
                                </tr>
                                <tr>
                                    <td><span class="help-badge red">Declined / rejected</span></td>
                                    <td>The event has not been accepted in its current form.</td>
                                    <td>Read the comments and contact the reviewer if unclear.</td>
                                </tr>
                                <tr>
                                    <td><span class="help-badge red">Cancelled</span></td>
                                    <td>The event is no longer going ahead.</td>
                                    <td>No further action is usually needed unless you need to submit a replacement event.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section id="add-event-page" class="help-section searchable-block" data-search-title="Add event manage event submit contact name email event title description location dates numbers risk assessments">
                <div class="help-section-header">
                    <h2 class="h3 mb-1">
                        <a href="#add-event-page" class="help-anchor">Add / manage event page</a>
                    </h2>
                    <p class="text-muted mb-0">
                        Use this page to submit a new event or update an existing event.
                    </p>
                </div>

                <div class="help-section-body">
                    <h3 id="submit-event" class="h5">How to submit an event</h3>

                    <div class="help-step">
                        <div class="help-step-number">1</div>
                        <div>
                            <strong>Enter the contact details.</strong>
                            <p class="mb-0 text-muted">
                                Add the name and email address of the person responsible for the event submission.
                                If the contact has been used before, their email may be filled automatically.
                            </p>
                        </div>
                    </div>

                    <div class="help-step">
                        <div class="help-step-number">2</div>
                        <div>
                            <strong>Add the event title and description.</strong>
                            <p class="mb-0 text-muted">
                                Use a clear title, such as “Beavers trip to Heaton Park”.
                                The description should explain what the group is doing and any key details a reviewer needs.
                            </p>
                        </div>
                    </div>

                    <div class="help-step">
                        <div class="help-step-number">3</div>
                        <div>
                            <strong>Search for the location or starting point.</strong>
                            <p class="mb-0 text-muted">
                                For walks, hikes, expeditions, or similar activities, use the starting location.
                                Search for a place, address, postcode, or landmark, then choose the correct result.
                            </p>
                        </div>
                    </div>

                    <div class="help-step">
                        <div class="help-step-number">4</div>
                        <div>
                            <strong>Adjust the map pin if needed.</strong>
                            <p class="mb-0 text-muted">
                                You can drag the pin or click the map to set the exact location.
                                This helps reviewers and other users understand where the event is taking place.
                            </p>
                        </div>
                    </div>

                    <div class="help-step">
                        <div class="help-step-number">5</div>
                        <div>
                            <strong>Enter the start and end date and time.</strong>
                            <p class="mb-0 text-muted">
                                The system may suggest an end time automatically, but you should check it is correct.
                                Camps, sleepovers, and expeditions may need longer durations.
                            </p>
                        </div>
                    </div>

                    <div class="help-step">
                        <div class="help-step-number">6</div>
                        <div>
                            <strong>Enter numbers attending.</strong>
                            <p class="mb-0 text-muted">
                                Add the expected number of young people by section and the number of adults attending.
                            </p>
                        </div>
                    </div>

                    <div class="help-step">
                        <div class="help-step-number">7</div>
                        <div>
                            <strong>Attach or upload risk assessments.</strong>
                            <p class="mb-0 text-muted">
                                You can attach a recent risk assessment from your own group, upload a new one, or look at shared examples from other groups.
                            </p>
                        </div>
                    </div>

                    <div class="help-step">
                        <div class="help-step-number">8</div>
                        <div>
                            <strong>Submit the event.</strong>
                            <p class="mb-0 text-muted">
                                Once submitted, the event is sent for review. It is not approved until a reviewer approves it.
                            </p>
                        </div>
                    </div>

                    <div class="help-callout success">
                        <strong>Tip:</strong> If you update an existing event, it may be sent back to review again.
                        This is normal, especially if the location, date, time, numbers, or risk assessment has changed.
                    </div>
                </div>
            </section>

            <section id="approval-workflow" class="help-section searchable-block" data-search-title="Approval workflow submitted under review approved changes requested declined rejected cancelled reviewer comments resubmit">
                <div class="help-section-header">
                    <h2 class="h3 mb-1">
                        <a href="#approval-workflow" class="help-anchor">How approvals work</a>
                    </h2>
                    <p class="text-muted mb-0">
                        After you submit an event, a reviewer checks the details and risk assessment information.
                    </p>
                </div>

                <div class="help-section-body">
                    <h3 class="h5">The normal approval flow</h3>

                    <div class="help-card-grid">
                        <div class="help-mini-card">
                            <h4>1. Draft</h4>
                            <p class="mb-0 text-muted">
                                You are still preparing the event. It has not yet been submitted for review.
                            </p>
                        </div>

                        <div class="help-mini-card">
                            <h4>2. Submitted</h4>
                            <p class="mb-0 text-muted">
                                The event has been sent to the review team. You should wait for a decision or comments.
                            </p>
                        </div>

                        <div class="help-mini-card">
                            <h4>3. Approved</h4>
                            <p class="mb-0 text-muted">
                                The reviewer has approved the event. Keep the event accurate if anything changes.
                            </p>
                        </div>

                        <div class="help-mini-card">
                            <h4>4. Changes requested</h4>
                            <p class="mb-0 text-muted">
                                The reviewer needs more information or changes. Read the comments, edit the event, and resubmit.
                            </p>
                        </div>
                    </div>

                    <div class="help-callout warning">
                        <strong>If your event is declined or changes are requested:</strong>
                        open the event, read the reviewer comments carefully, make the requested changes, and submit again.
                        If you do not understand what is needed, contact the reviewer or your usual district contact.
                    </div>

                    <h3 class="h5 mt-4">What reviewers may check</h3>
                    <ul>
                        <li>Whether the event has a clear contact person.</li>
                        <li>Whether the dates, times, and location are sensible.</li>
                        <li>Whether numbers attending are complete.</li>
                        <li>Whether the risk assessment is relevant and recent.</li>
                        <li>Whether the activity description is clear enough.</li>
                        <li>Whether any reviewer comments from a previous review have been addressed.</li>
                    </ul>
                </div>
            </section>

            <section id="risk-assessments-page" class="help-section searchable-block" data-search-title="Risk assessments page upload view download share private archive reuse documents own group district">
                <div class="help-section-header">
                    <h2 class="h3 mb-1">
                        <a href="#risk-assessments-page" class="help-anchor">Risk assessments page</a>
                    </h2>
                    <p class="text-muted mb-0">
                        This page is where you manage risk assessment documents outside a single event submission.
                    </p>
                </div>

                <div class="help-section-body">
                    <h3 class="h5">What the risk assessments page is for</h3>
                    <p>
                        The risk assessments page lets you keep a library of documents that can be used for future events.
                        You can upload documents for your own group, decide whether to share them with the district, and archive old documents that should no longer be used.
                    </p>

                    <h3 class="h5 mt-4">Typical things you can do</h3>

                    <div class="help-card-grid">
                        <div class="help-mini-card">
                            <h4>Upload a risk assessment</h4>
                            <p class="mb-0 text-muted">
                                Add a PDF, DOC, or DOCX file so it can be reused or attached to an event later.
                            </p>
                        </div>

                        <div class="help-mini-card">
                            <h4>View or download a file</h4>
                            <p class="mb-0 text-muted">
                                Open your own risk assessments or view shared risk assessments from other groups.
                            </p>
                        </div>

                        <div class="help-mini-card">
                            <h4>Share with district</h4>
                            <p class="mb-0 text-muted">
                                Make a risk assessment visible to other groups as a useful reference.
                            </p>
                        </div>

                        <div class="help-mini-card">
                            <h4>Keep private to your group</h4>
                            <p class="mb-0 text-muted">
                                Keep a document available only to your own group.
                            </p>
                        </div>

                        <div class="help-mini-card">
                            <h4>Archive old documents</h4>
                            <p class="mb-0 text-muted">
                                Remove outdated risk assessments from normal use without deleting the record completely.
                            </p>
                        </div>

                        <div class="help-mini-card">
                            <h4>Check previous examples</h4>
                            <p class="mb-0 text-muted">
                                Use shared documents from other groups to help prepare your own risk assessment.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section id="how-risk-assessments-work" class="help-section searchable-block" data-search-title="How risk assessments work upload attach recent selected existing shared district private own group">
                <div class="help-section-header">
                    <h2 class="h3 mb-1">
                        <a href="#how-risk-assessments-work" class="help-anchor">How risk assessments work</a>
                    </h2>
                    <p class="text-muted mb-0">
                        Risk assessments are stored as documents and can be linked to event submissions.
                    </p>
                </div>

                <div class="help-section-body">
                    <h3 class="h5">There are two common ways to use a risk assessment</h3>

                    <div class="table-responsive">
                        <table class="table table-bordered help-table">
                            <thead class="thead-light">
                                <tr>
                                    <th>Method</th>
                                    <th>What it means</th>
                                    <th>When to use it</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Upload a new risk assessment</td>
                                    <td>You add a fresh PDF, DOC, or DOCX file during event submission or from the risk assessments page.</td>
                                    <td>Use this when the event is new, the activity has changed, or an old document is no longer suitable.</td>
                                </tr>
                                <tr>
                                    <td>Attach an existing risk assessment</td>
                                    <td>You choose a recent risk assessment already uploaded by your own group.</td>
                                    <td>Use this when your group has already prepared a suitable and recent document for the same or very similar activity.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="help-callout">
                        <strong>Key rule:</strong> a risk assessment should match the actual activity, place, people, dates, and circumstances.
                        Do not reuse a document just because it has a similar title.
                    </div>

                    <h3 class="h5 mt-4">What counts as a good risk assessment?</h3>
                    <ul>
                        <li>It is relevant to the activity being planned.</li>
                        <li>It reflects the actual location or route.</li>
                        <li>It considers the group attending, including young people and adults.</li>
                        <li>It is recent enough to still be reliable.</li>
                        <li>It has been reviewed and updated if anything significant has changed.</li>
                        <li>It is clear enough for another leader or reviewer to understand.</li>
                    </ul>

                    <h3 class="h5 mt-4">What happens when you upload one during an event submission?</h3>
                    <p>
                        When you upload a risk assessment while submitting an event, the document is saved into the risk assessment library
                        and linked to that event. You can choose whether that uploaded document is shared with the district or kept private to your group.
                    </p>
                </div>
            </section>

            <section id="reuse-risk-assessments" class="help-section searchable-block" data-search-title="Reuse risk assessments attach recent one 90 days own group selected existing download only other groups shared examples">
                <div class="help-section-header">
                    <h2 class="h3 mb-1">
                        <a href="#reuse-risk-assessments" class="help-anchor">When you can and cannot reuse risk assessments</a>
                    </h2>
                    <p class="text-muted mb-0">
                        The tool allows reuse, but it deliberately limits direct attaching to reduce accidental use of unsuitable documents.
                    </p>
                </div>

                <div class="help-section-body">
                    <h3 class="h5">The attach recent one rule</h3>
                    <p>
                        When submitting an event, you can directly attach an existing risk assessment only when it is:
                    </p>

                    <ul>
                        <li>from your own group, and</li>
                        <li>recent enough, usually uploaded or updated within the last 90 days, and</li>
                        <li>available for use, not archived or unavailable.</li>
                    </ul>

                    <div class="help-callout success">
                        <strong>Example:</strong> Your group uploaded a “Heaton Park walk” risk assessment last month.
                        You are running another very similar Heaton Park walk this month.
                        You may be able to attach that recent document directly, provided it still matches the event.
                    </div>

                    <h3 class="h5 mt-4">When you should not reuse directly</h3>
                    <p>
                        Do not reuse a risk assessment directly if any important detail has changed.
                    </p>

                    <ul>
                        <li>The location, route, or venue is different.</li>
                        <li>The activity is different.</li>
                        <li>The age group or numbers are significantly different.</li>
                        <li>The staffing or supervision plan has changed.</li>
                        <li>The weather, season, transport, equipment, or overnight arrangements introduce different risks.</li>
                        <li>The document is old or you are not confident it still applies.</li>
                    </ul>

                    <div class="help-callout warning">
                        <strong>If in doubt:</strong> download or copy the old document, update it properly, then upload the updated version as a new risk assessment.
                    </div>

                    <h3 class="h5 mt-4">Why other groups’ risk assessments are usually download-only</h3>
                    <p>
                        Other groups may share risk assessments to help everyone learn from previous activities.
                        However, a risk assessment written by another group may not match your leaders, young people, route, equipment, timing, or control measures.
                    </p>

                    <p>
                        For that reason, shared risk assessments from other groups are normally available to view or download as references,
                        but not attach directly to your event.
                    </p>

                    <div class="help-callout">
                        <strong>Best practice:</strong> use another group’s shared risk assessment as a starting point,
                        then create and upload your own version for your own event.
                    </div>
                </div>
            </section>

            <section id="sharing-risk-assessments" class="help-section searchable-block" data-search-title="Sharing risk assessments district private only my group visibility upload visibility share with district other groups">
                <div class="help-section-header">
                    <h2 class="h3 mb-1">
                        <a href="#sharing-risk-assessments" class="help-anchor">Sharing and private risk assessments</a>
                    </h2>
                    <p class="text-muted mb-0">
                        You can decide whether uploaded documents are shared with the district or kept private to your group.
                    </p>
                </div>

                <div class="help-section-body">
                    <h3 class="h5">Share with district</h3>
                    <p>
                        Choose this when the risk assessment may be useful to other groups.
                        Other groups can view or download it as an example, but they should still check and adapt it for their own event.
                    </p>

                    <h3 class="h5 mt-4">Only my group</h3>
                    <p>
                        Choose this when the document should only be visible to your group.
                        This is useful for group-specific arrangements, sensitive details, or documents that are not suitable as examples for others.
                    </p>

                    <div class="table-responsive">
                        <table class="table table-bordered help-table">
                            <thead class="thead-light">
                                <tr>
                                    <th>Visibility choice</th>
                                    <th>Who can use it?</th>
                                    <th>Good for</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Share with district</td>
                                    <td>Your group can use it. Other groups may view or download it as a reference.</td>
                                    <td>Common venues, common activity types, useful templates, examples that may help others.</td>
                                </tr>
                                <tr>
                                    <td>Only my group</td>
                                    <td>Your group only.</td>
                                    <td>Group-specific documents, sensitive details, incomplete examples, or documents not suitable for wider reuse.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="help-callout warning">
                        <strong>Before sharing:</strong> check the file does not contain private information that other groups should not see.
                    </div>
                </div>
            </section>

            <section id="archiving-risk-assessments" class="help-section searchable-block" data-search-title="Archiving risk assessments archive old unavailable remove from use delete archived restore old documents">
                <div class="help-section-header">
                    <h2 class="h3 mb-1">
                        <a href="#archiving-risk-assessments" class="help-anchor">Archiving risk assessments</a>
                    </h2>
                    <p class="text-muted mb-0">
                        Archiving helps keep old or outdated documents out of normal use.
                    </p>
                </div>

                <div class="help-section-body">
                    <h3 class="h5">What archiving means</h3>
                    <p>
                        Archiving a risk assessment normally removes it from the active list so it is not accidentally reused.
                        It is not the same as saying the activity was unsafe or wrong.
                        It simply means the document should no longer be used as a current risk assessment.
                    </p>

                    <h3 class="h5 mt-4">When to archive a risk assessment</h3>
                    <ul>
                        <li>The document is out of date.</li>
                        <li>The venue, route, or activity has changed significantly.</li>
                        <li>The document has been replaced by a newer version.</li>
                        <li>The document was uploaded by mistake.</li>
                        <li>The document should no longer be available for reuse.</li>
                    </ul>

                    <h3 class="h5 mt-4">What happens to events that already used it?</h3>
                    <p>
                        Archiving usually affects future reuse. Existing event records may still show that the document was attached at the time.
                        If a future event needs the same activity, upload a fresh or updated version rather than relying on the archived one.
                    </p>

                    <div class="help-callout">
                        <strong>Good habit:</strong> when you upload a newer version of a risk assessment, archive the older version so users pick the right one next time.
                    </div>
                </div>
            </section>

            <section id="map-page" class="help-section searchable-block" data-search-title="Map page event locations pins markers search Manchester starting point route location coordinates">
                <div class="help-section-header">
                    <h2 class="h3 mb-1">
                        <a href="#map-page" class="help-anchor">Map page</a>
                    </h2>
                    <p class="text-muted mb-0">
                        The map helps users understand where events are taking place.
                    </p>
                </div>

                <div class="help-section-body">
                    <h3 class="h5">What the map shows</h3>
                    <p>
                        The map shows event locations based on the coordinates saved when the event was submitted.
                        If an event has a location but no saved coordinates, it may not appear on the map or may need the location to be searched again.
                    </p>

                    <h3 class="h5 mt-4">How to use the map</h3>

                    <div class="help-step">
                        <div class="help-step-number">1</div>
                        <div>
                            <strong>Open the map page.</strong>
                            <p class="mb-0 text-muted">
                                The map will show events that have usable location coordinates.
                            </p>
                        </div>
                    </div>

                    <div class="help-step">
                        <div class="help-step-number">2</div>
                        <div>
                            <strong>Click a marker.</strong>
                            <p class="mb-0 text-muted">
                                A marker may show information such as the event title, group, date, or status.
                            </p>
                        </div>
                    </div>

                    <div class="help-step">
                        <div class="help-step-number">3</div>
                        <div>
                            <strong>Check nearby events.</strong>
                            <p class="mb-0 text-muted">
                                The map can help identify nearby activities or possible clashes.
                            </p>
                        </div>
                    </div>

                    <h3 class="h5 mt-4">Map accuracy</h3>
                    <p>
                        The map is only as accurate as the location chosen when the event was submitted.
                        If the pin is in the wrong place, edit the event, search for the location again, and move the pin to the correct point.
                    </p>

                    <div class="help-callout warning">
                        <strong>For walks, hikes, and expeditions:</strong> use the starting point as the event location unless your local process asks for something different.
                    </div>
                </div>
            </section>

            <section id="faq" class="help-section searchable-block" data-search-title="FAQs frequently asked questions help support common issues">
                <div class="help-section-header">
                    <h2 class="h3 mb-1">
                        <a href="#faq" class="help-anchor">Frequently asked questions</a>
                    </h2>
                    <p class="text-muted mb-0">
                        Common questions from group users.
                    </p>
                </div>

                <div class="help-section-body">
                    <h3 id="faq-events" class="h4 mb-3">Event FAQs</h3>

                    <div class="faq-item searchable-block" id="faq-submit-event" data-search-title="FAQ how do I submit event new away from hut notification">
                        <h3>How do I submit a new event?</h3>
                        <p>
                            Go to the Add Event page, complete the contact details, event information, location, dates, numbers attending,
                            and risk assessment section. Then press the submit button. The event will be sent for review.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-is-submitted-approved" data-search-title="FAQ submitted approved is my event approved after submitting">
                        <h3>Is my event approved as soon as I submit it?</h3>
                        <p>
                            No. Submitting means the event has been sent for review. It is not approved until the status changes to Approved.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-edit-submitted-event" data-search-title="FAQ can I edit submitted event update resubmit">
                        <h3>Can I edit an event after submitting it?</h3>
                        <p>
                            Yes, if the tool allows you to open and manage the event. When you update it, it may be resubmitted for review,
                            especially if important details have changed.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-event-changes" data-search-title="FAQ what changes require resubmit date time location numbers risk assessment">
                        <h3>What changes should I update on an event?</h3>
                        <p>
                            Update the event if the date, time, location, activity, contact person, numbers attending, or risk assessment changes.
                            These details may affect whether the event can be approved.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-contact-person" data-search-title="FAQ contact person email leader responsible event">
                        <h3>Who should be listed as the contact person?</h3>
                        <p>
                            Use the person responsible for the event submission or the person best placed to answer reviewer questions.
                            The email address should be one they actively check.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-event-location" data-search-title="FAQ event location starting point walks hikes expeditions">
                        <h3>What location should I use for a walk, hike, or expedition?</h3>
                        <p>
                            Use the starting point unless your local process asks for something different.
                            Add more route detail in the description or risk assessment where needed.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-event-not-visible" data-search-title="FAQ event not visible calendar missing">
                        <h3>Why can’t I see my event on the calendar?</h3>
                        <p>
                            Check that the event was submitted successfully and that you are looking at the correct date range.
                            If the calendar has filters, make sure your group and event status are included.
                        </p>
                    </div>

                    <h3 id="faq-risk-assessments" class="h4 mt-5 mb-3">Risk assessment FAQs</h3>

                    <div class="faq-item searchable-block" id="faq-what-is-ra" data-search-title="FAQ what is a risk assessment document RA">
                        <h3>What is a risk assessment in this tool?</h3>
                        <p>
                            It is a document, usually PDF, DOC, or DOCX, that records the risks for an activity and the controls used to manage them.
                            The tool stores the document and links it to event submissions.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-attach-recent" data-search-title="FAQ attach recent risk assessment 90 days own group">
                        <h3>Why can I only attach recent risk assessments from my own group?</h3>
                        <p>
                            This helps prevent old or unsuitable documents being attached by mistake.
                            A document from your own group is more likely to match your leaders, young people, and arrangements.
                            A recent document is more likely to still be accurate.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-90-days" data-search-title="FAQ 90 days recent risk assessment updated uploaded">
                        <h3>What does “recent” mean?</h3>
                        <p>
                            In this tool, recent usually means the risk assessment was uploaded or updated within the last 90 days.
                            You should still check it is suitable for the event before attaching it.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-other-group-ra" data-search-title="FAQ other groups risk assessment shared district download only">
                        <h3>Can I use another group’s shared risk assessment?</h3>
                        <p>
                            You can view or download shared risk assessments from other groups as examples.
                            You should not assume they are ready to use for your event.
                            Review the document, adapt it for your group and activity, then upload your own version if needed.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-why-download-only" data-search-title="FAQ why download only cannot attach other group risk assessment">
                        <h3>Why is another group’s risk assessment download-only?</h3>
                        <p>
                            Another group’s document may not match your activity, route, supervision, numbers, equipment, or young people.
                            Download-only access encourages you to review and adapt it rather than attach it without checking.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-private-ra" data-search-title="FAQ private risk assessment only my group visibility">
                        <h3>How do I make a risk assessment private to my group?</h3>
                        <p>
                            When uploading the file, choose the option such as “Only my group”.
                            This keeps the risk assessment visible to your group and prevents other groups from using it as a shared example.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-share-ra" data-search-title="FAQ share risk assessment with district other groups">
                        <h3>When should I share a risk assessment with the district?</h3>
                        <p>
                            Share it when it may be a useful example for other groups, and it does not contain sensitive or group-only information.
                            Other groups should still adapt it for their own circumstances.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-upload-types" data-search-title="FAQ upload file types PDF DOC DOCX risk assessment">
                        <h3>What file types can I upload?</h3>
                        <p>
                            The tool is intended for PDF, DOC, and DOCX risk assessment files.
                            If a file is rejected, check that it is one of those file types and has not been renamed from a different format.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-ra-too-large" data-search-title="FAQ risk assessment file too large upload size limit">
                        <h3>Why was my file rejected as too large?</h3>
                        <p>
                            The tool may have a maximum upload size. Try reducing image sizes in the document, exporting to a smaller PDF,
                            or asking for help if the file still cannot be uploaded.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-archive-ra" data-search-title="FAQ archive risk assessment old document remove from use">
                        <h3>What does archiving a risk assessment do?</h3>
                        <p>
                            Archiving removes an old or unsuitable document from normal active use.
                            It helps prevent the wrong version being reused for future events.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-delete-ra" data-search-title="FAQ delete remove archive risk assessment">
                        <h3>Should I delete or archive an old risk assessment?</h3>
                        <p>
                            In most cases, archive it. Archiving keeps the record while making it clear the document should no longer be used.
                            Deletion may not be available to normal users.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-old-ra-event" data-search-title="FAQ archived risk assessment attached to existing event">
                        <h3>What if a risk assessment attached to an old event is archived later?</h3>
                        <p>
                            Archiving usually affects future use. The old event may still show what was attached at the time.
                            For a new event, use an active and updated document.
                        </p>
                    </div>

                    <h3 id="faq-approval" class="h4 mt-5 mb-3">Approval FAQs</h3>

                    <div class="faq-item searchable-block" id="faq-review-time" data-search-title="FAQ how long review approval takes">
                        <h3>How long does approval take?</h3>
                        <p>
                            This depends on your local review process and reviewer availability.
                            Submit events as early as possible, especially for camps, expeditions, nights away, unusual activities, or larger events.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-changes-requested" data-search-title="FAQ changes requested declined reviewer comments resubmit">
                        <h3>What should I do if changes are requested?</h3>
                        <p>
                            Open the event, read the reviewer comments, update the necessary details, and submit again.
                            Make sure any risk assessment changes are also uploaded or attached.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-declined" data-search-title="FAQ declined rejected event not approved">
                        <h3>What does declined or rejected mean?</h3>
                        <p>
                            It means the event has not been approved in its current form.
                            Read the comments carefully and contact the reviewer if you are unsure what needs to change.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-approved-then-changed" data-search-title="FAQ approved event changed update date location risk assessment">
                        <h3>My event was approved, but something changed. What should I do?</h3>
                        <p>
                            Update the event if anything important changes, such as the location, activity, date, time, numbers, or risk assessment.
                            The event may need to be reviewed again.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-email-notification" data-search-title="FAQ email notification submitted approval contact lead volunteer">
                        <h3>Who receives email notifications?</h3>
                        <p>
                            The event contact usually receives a confirmation. Depending on your group settings, the lead volunteer may also be notified.
                            Reviewers may receive a notification that a submission is waiting.
                        </p>
                    </div>

                    <h3 id="faq-map" class="h4 mt-5 mb-3">Map FAQs</h3>

                    <div class="faq-item searchable-block" id="faq-map-pin-wrong" data-search-title="FAQ map pin wrong incorrect location coordinates">
                        <h3>The map pin is wrong. How do I fix it?</h3>
                        <p>
                            Edit the event, search for the location again, then drag the pin or click the correct point on the map.
                            Save and resubmit the event if needed.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-map-event-missing" data-search-title="FAQ event missing from map no marker">
                        <h3>Why is my event missing from the map?</h3>
                        <p>
                            The event may not have saved coordinates, or it may be outside the current filters or date range.
                            Edit the event and search/select the location again so coordinates are saved.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-location-search" data-search-title="FAQ location search not finding address Manchester postcode">
                        <h3>The location search cannot find my address. What should I try?</h3>
                        <p>
                            Try adding “Manchester”, “Greater Manchester”, the town, or the postcode.
                            For example, use “Heaton Park, Manchester” rather than just “Heaton Park”.
                            You can also place the pin manually on the map if needed.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-starting-point" data-search-title="FAQ route starting point hike walk expedition map location">
                        <h3>For a route-based activity, should I map the whole route?</h3>
                        <p>
                            The event location normally records the starting point.
                            Put route details in the description or risk assessment.
                        </p>
                    </div>

                    <h3 id="faq-troubleshooting" class="h4 mt-5 mb-3">Troubleshooting FAQs</h3>

                    <div class="faq-item searchable-block" id="faq-upload-fails" data-search-title="FAQ upload fails risk assessment file rejected error">
                        <h3>My risk assessment upload failed. What should I check?</h3>
                        <p>
                            Check that the file is a PDF, DOC, or DOCX document, that it is not too large, and that it opens correctly on your device.
                            If it still fails, export it again or save it as a new PDF and try again.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-login-link" data-search-title="FAQ access link token login group link not working">
                        <h3>My group access link does not work. What should I do?</h3>
                        <p>
                            Check that you copied the full link and that it has not expired or been changed.
                            If it still does not work, contact the person who manages access for your group or district.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-page-not-loading" data-search-title="FAQ page not loading browser issue cache">
                        <h3>A page is not loading correctly. What should I try?</h3>
                        <p>
                            Refresh the page, try signing in again, or use a modern browser.
                            If the issue continues, note what you were trying to do and report it to the tool administrator.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-wrong-group" data-search-title="FAQ wrong group showing access group">
                        <h3>The wrong group is showing. What should I do?</h3>
                        <p>
                            You may be using a different group access link or an old browser session.
                            Sign out if available, close the browser tab, and open the correct group link again.
                        </p>
                    </div>

                    <div class="faq-item searchable-block" id="faq-cannot-find-ra" data-search-title="FAQ cannot find risk assessment missing archived unavailable">
                        <h3>I cannot find a risk assessment I expected to see. Why?</h3>
                        <p>
                            It may be archived, private to another group, not shared with the district, outside the recent attach window,
                            or not yet uploaded. Check the risk assessments page and any filters.
                        </p>
                    </div>
                </div>
            </section>

            <section id="good-practice" class="help-section searchable-block" data-search-title="Good practice checklist submit early clear details accurate risk assessment update changes">
                <div class="help-section-header">
                    <h2 class="h3 mb-1">
                        <a href="#good-practice" class="help-anchor">Good practice checklist</a>
                    </h2>
                    <p class="text-muted mb-0">
                        Use this checklist before submitting an event.
                    </p>
                </div>

                <div class="help-section-body">
                    <div class="help-highlight-box">
                        <ul class="mb-0">
                            <li>Submit the event as early as possible.</li>
                            <li>Use a clear title and description.</li>
                            <li>Check the location and map pin are correct.</li>
                            <li>Use the correct start and end date/time.</li>
                            <li>Enter realistic numbers for young people and adults.</li>
                            <li>Attach or upload a risk assessment that genuinely matches the event.</li>
                            <li>Do not directly reuse old documents without checking they still apply.</li>
                            <li>Use shared risk assessments from other groups as examples, not automatic approval documents.</li>
                            <li>Archive old risk assessments when they have been replaced.</li>
                            <li>Read reviewer comments carefully and resubmit when changes are complete.</li>
                        </ul>
                    </div>
                </div>
            </section>
        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('helpSearch');
    const clearButton = document.getElementById('clearHelpSearch');
    const emptyState = document.getElementById('helpEmptyState');

    const searchableBlocks = Array.from(document.querySelectorAll('.searchable-block'));
    const sections = Array.from(document.querySelectorAll('.help-section'));
    const navLinks = Array.from(document.querySelectorAll('.help-sidebar .nav-link'));

    const originalHtml = new Map();

    searchableBlocks.forEach(block => {
        originalHtml.set(block, block.innerHTML);
        block.dataset.searchText = [
            block.dataset.searchTitle || '',
            block.innerText || ''
        ].join(' ').toLowerCase();
    });

    function escapeRegExp(value) {
        return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function stripMarks(block) {
        if (!originalHtml.has(block)) {
            return;
        }

        block.innerHTML = originalHtml.get(block);
    }

    function highlightText(block, query) {
        if (!query) {
            return;
        }

        const terms = query
            .split(/\s+/)
            .map(term => term.trim())
            .filter(term => term.length >= 2);

        if (terms.length === 0) {
            return;
        }

        const pattern = new RegExp('(' + terms.map(escapeRegExp).join('|') + ')', 'gi');

        const walker = document.createTreeWalker(
            block,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: function (node) {
                    if (!node.nodeValue || !node.nodeValue.trim()) {
                        return NodeFilter.FILTER_REJECT;
                    }

                    const parent = node.parentElement;
                    if (!parent) {
                        return NodeFilter.FILTER_REJECT;
                    }

                    if (['SCRIPT', 'STYLE', 'TEXTAREA', 'INPUT', 'BUTTON'].includes(parent.tagName)) {
                        return NodeFilter.FILTER_REJECT;
                    }

                    return NodeFilter.FILTER_ACCEPT;
                }
            }
        );

        const textNodes = [];
        while (walker.nextNode()) {
            textNodes.push(walker.currentNode);
        }

        textNodes.forEach(node => {
            const value = node.nodeValue;
            if (!pattern.test(value)) {
                pattern.lastIndex = 0;
                return;
            }

            pattern.lastIndex = 0;

            const wrapper = document.createElement('span');
            wrapper.innerHTML = value.replace(pattern, '<mark class="help-mark">$1</mark>');
            node.parentNode.replaceChild(wrapper, node);
        });
    }

    function updateSearch() {
        const query = searchInput.value.trim().toLowerCase();
        const terms = query
            .split(/\s+/)
            .map(term => term.trim())
            .filter(Boolean);

        searchableBlocks.forEach(stripMarks);

        if (terms.length === 0) {
            sections.forEach(section => {
                section.style.display = '';
            });

            searchableBlocks.forEach(block => {
                block.style.display = '';
            });

            emptyState.style.display = 'none';
            return;
        }

        let visibleSections = 0;

        sections.forEach(section => {
            const nestedBlocks = Array.from(section.querySelectorAll('.searchable-block'));
            const sectionOwnText = [
                section.dataset.searchTitle || '',
                section.innerText || ''
            ].join(' ').toLowerCase();

            const sectionMatches = terms.every(term => sectionOwnText.includes(term));

            let nestedMatchCount = 0;

            nestedBlocks.forEach(block => {
                const blockText = [
                    block.dataset.searchTitle || '',
                    block.innerText || ''
                ].join(' ').toLowerCase();

                const blockMatches = terms.every(term => blockText.includes(term));
                block.style.display = blockMatches ? '' : 'none';

                if (blockMatches) {
                    nestedMatchCount++;
                    highlightText(block, query);
                }
            });

            const visible = sectionMatches || nestedMatchCount > 0;
            section.style.display = visible ? '' : 'none';

            if (visible) {
                visibleSections++;
                highlightText(section, query);
            }
        });

        emptyState.style.display = visibleSections === 0 ? 'block' : 'none';
    }

    if (searchInput) {
        searchInput.addEventListener('input', updateSearch);
    }

    if (clearButton) {
        clearButton.addEventListener('click', function () {
            searchInput.value = '';
            updateSearch();
            searchInput.focus();
        });
    }

    navLinks.forEach(link => {
        link.addEventListener('click', function () {
            navLinks.forEach(item => item.classList.remove('active'));
            this.classList.add('active');
        });
    });

    function updateActiveNavOnScroll() {
        const fromTop = window.scrollY + 140;
        let currentId = '';

        document.querySelectorAll('.help-section[id], .faq-item[id]').forEach(element => {
            if (element.offsetTop <= fromTop && element.offsetParent !== null) {
                currentId = element.id;
            }
        });

        if (!currentId) {
            return;
        }

        navLinks.forEach(link => {
            const href = link.getAttribute('href') || '';
            link.classList.toggle('active', href === '#' + currentId);
        });
    }

    window.addEventListener('scroll', updateActiveNavOnScroll, { passive: true });
    updateActiveNavOnScroll();
});
</script>

<?php render_page_end(); ?>