<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
$pageTitle = 'Privacy Notice | ' . $appName;
$heroTitle = 'Privacy Notice';
$heroText = 'How we collect, use and protect your personal data.';
$breadcrumb = '<a href="/index.php">Home</a> &rsaquo; Privacy Notice';

$isLoggedIn = is_logged_in();

if ($isLoggedIn) {
    include __DIR__ . '/header.php';
}
?>

<?php if (!$isLoggedIn): ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="icon" type="image/png" href="/assets/img/favicon.png">

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css"
        integrity="sha384-5Kguc7IDQdynmm22yUyn9psYyP8LQhAWCCKJT/RrZJAWqdUAw5eADwc25JoYsXH6"
        crossorigin="anonymous"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,700&display=swap"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="/assets/css/leader-tool.css">
</head>
<body>
    <header class="lt-login-header" style="background: var(--iv-purple, #4d2177); padding: 1rem;">
        <div style="max-width: 960px; margin: 0 auto; display: flex; align-items: center; gap: 1rem;">
            <img
                src="/assets/img/white-ir-logo.png"
                alt=""
                style="height: 52px; width: auto;"
                onerror="this.style.display='none';"
            >
            <div style="color: #fff; font-weight: 900; font-size: 1.1rem; line-height: 1.15;">
                <?= e($appName) ?>
                <span style="display: block; font-size: .88rem; font-weight: 700; opacity: .85; margin-top: .1rem;">Irwell Valley Scout District</span>
            </div>
        </div>
    </header>
<?php endif; ?>

<style>
    .lt-privacy {
        max-width: 820px;
        margin: 0 auto;
        padding: 2rem 1rem 3rem;
    }

    .lt-privacy h1 {
        font-size: 2rem;
        font-weight: 900;
        letter-spacing: -.03em;
        margin: 0 0 .5rem;
        color: var(--iv-black, #1d1d1b);
    }

    .lt-privacy .lt-privacy-updated {
        font-size: .9rem;
        font-weight: 700;
        color: var(--iv-grey-700, #555);
        margin: 0 0 2rem;
    }

    .lt-privacy h2 {
        font-size: 1.35rem;
        font-weight: 900;
        margin: 2rem 0 .75rem;
        color: var(--iv-purple, #4d2177);
    }

    .lt-privacy h3 {
        font-size: 1.1rem;
        font-weight: 900;
        margin: 1.5rem 0 .5rem;
        color: var(--iv-black, #1d1d1b);
    }

    .lt-privacy p,
    .lt-privacy li {
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.6;
        color: var(--iv-black, #1d1d1b);
    }

    .lt-privacy ul {
        margin: .5rem 0 1rem 1.25rem;
        padding: 0;
    }

    .lt-privacy li {
        margin-bottom: .4rem;
    }

    .lt-privacy a {
        color: var(--iv-blue, #1d70b8);
        font-weight: 900;
    }

    .lt-privacy table {
        width: 100%;
        border-collapse: collapse;
        margin: 1rem 0;
        font-size: .95rem;
    }

    .lt-privacy th,
    .lt-privacy td {
        border: 1px solid var(--iv-grey-300, #d8d8d8);
        padding: .75rem;
        text-align: left;
        font-weight: 700;
    }

    .lt-privacy th {
        background: var(--iv-grey-100, #f4f4f4);
        font-weight: 900;
    }

    .lt-privacy .lt-privacy-back {
        display: inline-block;
        margin-top: 2rem;
        font-weight: 900;
    }

    @media (max-width: 575.98px) {
        .lt-privacy {
            padding: 1.5rem .75rem 2rem;
        }

        .lt-privacy h1 {
            font-size: 1.65rem;
        }

        .lt-privacy table {
            font-size: .85rem;
        }

        .lt-privacy th,
        .lt-privacy td {
            padding: .5rem;
        }
    }
</style>

<main class="lt-privacy">
    <?php if (!$isLoggedIn): ?>
        <h1>Privacy Notice</h1>
        <p class="lt-privacy-updated">Last updated: 19 July 2025</p>
    <?php else: ?>
        <p class="lt-privacy-updated">Last updated: 19 July 2025</p>
    <?php endif; ?>

    <h2>1. Who we are</h2>
    <p>
        This application (&ldquo;District Dashboard&rdquo; or &ldquo;Leader Tool&rdquo;) is operated by
        <strong>Irwell Valley Scout District</strong>, part of The Scout Association (Charity No. 306101).
        The technical platform is built and maintained by <strong>CK Enterprises Group Ltd</strong> on behalf of the District.
    </p>
    <p>
        For the purposes of data protection law (UK GDPR and the Data Protection Act 2018),
        the Data Controller is <strong>Irwell Valley Scout District</strong>.
    </p>

    <h2>2. What data we collect</h2>
    <p>We collect and process the following personal data when you use this application:</p>

    <table>
        <thead>
            <tr>
                <th>Category</th>
                <th>Examples</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Identity</td>
                <td>Full name, preferred name, initials</td>
            </tr>
            <tr>
                <td>Contact</td>
                <td>Email address (District Microsoft 365 account)</td>
            </tr>
            <tr>
                <td>Membership</td>
                <td>Scout Group, section, role, membership number</td>
            </tr>
            <tr>
                <td>Account &amp; access</td>
                <td>Access level, login timestamps, session data</td>
            </tr>
            <tr>
                <td>Profile photo</td>
                <td>Photo retrieved from your Microsoft 365 account (if available)</td>
            </tr>
            <tr>
                <td>Activity</td>
                <td>Events submitted, risk assessments uploaded, audit log entries</td>
            </tr>
            <tr>
                <td>Technical</td>
                <td>IP address, browser type (collected in server logs)</td>
            </tr>
        </tbody>
    </table>

    <h2>3. How we collect your data</h2>
    <ul>
        <li><strong>Microsoft sign-in:</strong> When you authenticate via Microsoft 365, we receive your name, email address and profile photo from Microsoft Entra ID / Graph API.</li>
        <li><strong>Group link access:</strong> When you use a Group calendar link, we record the Group context and timestamp.</li>
        <li><strong>Direct input:</strong> Information you enter into the application (e.g. event details, risk assessments).</li>
        <li><strong>Provisioning:</strong> If a Group Lead Volunteer or District Admin requests a Microsoft 365 account for you, we use your name and membership details to create that account.</li>
    </ul>

    <h2>4. Why we process your data (lawful basis)</h2>
    <table>
        <thead>
            <tr>
                <th>Purpose</th>
                <th>Lawful basis</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Provide access to District tools and your account</td>
                <td>Legitimate interest</td>
            </tr>
            <tr>
                <td>Manage Scout volunteer records and Group membership</td>
                <td>Legitimate interest</td>
            </tr>
            <tr>
                <td>Send operational emails (reminders, notifications)</td>
                <td>Legitimate interest</td>
            </tr>
            <tr>
                <td>Provision and manage Microsoft 365 accounts</td>
                <td>Legitimate interest / Consent (where applicable)</td>
            </tr>
            <tr>
                <td>Safeguarding and audit trail</td>
                <td>Legal obligation / Legitimate interest</td>
            </tr>
            <tr>
                <td>Technical security and system administration</td>
                <td>Legitimate interest</td>
            </tr>
        </tbody>
    </table>

    <h2>5. Who we share your data with</h2>
    <ul>
        <li><strong>Microsoft:</strong> Your account is hosted on Microsoft 365. Microsoft processes data under its own terms as a data processor.</li>
        <li><strong>CK Enterprises Group Ltd:</strong> Provides technical development and hosting support. They act as a data processor under instruction from the District.</li>
        <li><strong>The Scout Association:</strong> Membership data may be shared as required by Scout Association policies.</li>
        <li><strong>Other District volunteers:</strong> Your name, role and Group are visible to other authenticated users in the District Directory.</li>
    </ul>
    <p>We do not sell your data or share it with third parties for marketing purposes.</p>

    <h2>6. Where your data is stored</h2>
    <p>
        Your data is stored on secure servers within the United Kingdom and within
        Microsoft&rsquo;s European data centres. We do not transfer your personal data outside
        the UK/EEA unless covered by appropriate safeguards (e.g. Microsoft&rsquo;s standard contractual clauses).
    </p>

    <h2>7. How long we keep your data</h2>
    <ul>
        <li><strong>Active accounts:</strong> Retained while you are an active volunteer in the District.</li>
        <li><strong>Leavers:</strong> After you leave the District, your data is retained for up to 12 months to support any transition, then deleted or anonymised.</li>
        <li><strong>Audit logs:</strong> Retained for up to 24 months for safeguarding and accountability purposes.</li>
        <li><strong>Calendar events and risk assessments:</strong> Automatically removed <?= e((string) DC_EVENT_DELETE_AFTER_DAYS) ?> days after the event date.</li>
    </ul>

    <h2>8. Your rights</h2>
    <p>Under UK GDPR you have the right to:</p>
    <ul>
        <li><strong>Access</strong> &ndash; Request a copy of the personal data we hold about you.</li>
        <li><strong>Rectification</strong> &ndash; Ask us to correct inaccurate data.</li>
        <li><strong>Erasure</strong> &ndash; Ask us to delete your data (subject to lawful retention requirements).</li>
        <li><strong>Restriction</strong> &ndash; Ask us to limit how we process your data.</li>
        <li><strong>Portability</strong> &ndash; Request your data in a machine-readable format.</li>
        <li><strong>Object</strong> &ndash; Object to processing based on legitimate interest.</li>
    </ul>
    <p>
        To exercise any of these rights, contact us at
        <a href="mailto:support@irvalscouts.org.uk">support@irvalscouts.org.uk</a>.
    </p>

    <h2>9. Cookies and sessions</h2>
    <p>
        This application uses a session cookie to keep you signed in. This is strictly necessary for the
        application to function and does not require consent. We do not use analytics cookies, advertising
        cookies or any third-party tracking.
    </p>

    <h2>10. Security</h2>
    <p>
        We take appropriate technical and organisational measures to protect your personal data, including:
    </p>
    <ul>
        <li>HTTPS encryption for all connections</li>
        <li>Secure, HTTP-only session cookies</li>
        <li>Microsoft Entra ID authentication (no passwords stored locally)</li>
        <li>Role-based access controls</li>
        <li>Regular review of access permissions</li>
    </ul>

    <h2>11. Changes to this notice</h2>
    <p>
        We may update this Privacy Notice from time to time. The &ldquo;Last updated&rdquo; date at the top of
        this page will reflect any changes. Continued use of the application after changes constitutes
        acceptance of the updated notice.
    </p>

    <h2>12. Contact and complaints</h2>
    <p>
        If you have questions about this Privacy Notice or how your data is handled, contact:
    </p>
    <ul>
        <li><strong>Irwell Valley Scout District:</strong> <a href="mailto:support@irvalscouts.org.uk">support@irvalscouts.org.uk</a></li>
    </ul>
    <p>
        If you are not satisfied with our response, you have the right to complain to the
        <a href="https://ico.org.uk/make-a-complaint/" target="_blank" rel="noopener noreferrer">Information Commissioner&rsquo;s Office (ICO)</a>.
    </p>

    <?php if ($isLoggedIn): ?>
        <a class="lt-privacy-back" href="/index.php">&larr; Back to Dashboard</a>
    <?php else: ?>
        <a class="lt-privacy-back" href="/login.php">&larr; Back to Sign in</a>
    <?php endif; ?>
</main>

<?php if ($isLoggedIn): ?>
    <?php include __DIR__ . '/footer.php'; ?>
<?php else: ?>
    <footer style="max-width: 820px; margin: 0 auto; padding: 0 1rem 2rem; text-align: center; font-size: .88rem; font-weight: 800; color: var(--iv-grey-700, #555);">
        <p>&copy; <?= e(date('Y')) ?> Irwell Valley Scout District. Built by <a href="https://www.ckenterprises.co.uk" target="_blank" rel="noopener noreferrer" style="color: inherit; font-weight: 900;">CK Enterprises UK</a></p>
    </footer>
</body>
</html>
<?php endif; ?>
