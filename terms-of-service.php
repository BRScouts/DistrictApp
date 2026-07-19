<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
$pageTitle = 'Terms of Service | ' . $appName;
$heroTitle = 'Terms of Service';
$heroText = 'The terms that govern your use of the District Leader Tool.';
$breadcrumb = '<a href="/index.php">Home</a> &rsaquo; Terms of Service';

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
    .lt-terms {
        max-width: 820px;
        margin: 0 auto;
        padding: 2rem 1rem 3rem;
    }

    .lt-terms h1 {
        font-size: 2rem;
        font-weight: 900;
        letter-spacing: -.03em;
        margin: 0 0 .5rem;
        color: var(--iv-black, #1d1d1b);
    }

    .lt-terms .lt-terms-updated {
        font-size: .9rem;
        font-weight: 700;
        color: var(--iv-grey-700, #555);
        margin: 0 0 2rem;
    }

    .lt-terms h2 {
        font-size: 1.35rem;
        font-weight: 900;
        margin: 2rem 0 .75rem;
        color: var(--iv-purple, #4d2177);
    }

    .lt-terms h3 {
        font-size: 1.1rem;
        font-weight: 900;
        margin: 1.5rem 0 .5rem;
        color: var(--iv-black, #1d1d1b);
    }

    .lt-terms p,
    .lt-terms li {
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.6;
        color: var(--iv-black, #1d1d1b);
    }

    .lt-terms ul {
        margin: .5rem 0 1rem 1.25rem;
        padding: 0;
    }

    .lt-terms li {
        margin-bottom: .4rem;
    }

    .lt-terms a {
        color: var(--iv-blue, #1d70b8);
        font-weight: 900;
    }

    .lt-terms .lt-terms-back {
        display: inline-block;
        margin-top: 2rem;
        font-weight: 900;
    }

    @media (max-width: 575.98px) {
        .lt-terms {
            padding: 1.5rem .75rem 2rem;
        }

        .lt-terms h1 {
            font-size: 1.65rem;
        }
    }
</style>

<main class="lt-terms">
    <?php if (!$isLoggedIn): ?>
        <h1>Terms of Service</h1>
        <p class="lt-terms-updated">Effective from: 19 July 2025</p>
    <?php else: ?>
        <p class="lt-terms-updated">Effective from: 19 July 2025</p>
    <?php endif; ?>

    <h2>1. About this agreement</h2>
    <p>
        These Terms of Service (&ldquo;Terms&rdquo;) govern your use of the District Leader Tool
        (&ldquo;the Platform&rdquo;), operated at <strong>app.irvalscouts.org.uk</strong>.
    </p>
    <p>
        By signing in or using the Platform, you agree to be bound by these Terms. If you do not agree,
        you must not use the Platform.
    </p>

    <h2>2. Platform ownership</h2>
    <p>
        The Platform is owned, developed and maintained by <strong>CK Enterprises Group Ltd</strong>
        (Company No. 11632973 / registered in England and Wales). CK Enterprises retains all intellectual
        property rights in the Platform&rsquo;s source code, design, branding (where applicable) and
        technical infrastructure.
    </p>

    <h2>3. Data ownership and control</h2>
    <p>
        All personal data and organisational data entered into or processed by the Platform is owned and
        controlled by <strong>Irwell Valley Scout District</strong> (part of The Scout Association,
        Charity No. 306101).
    </p>
    <p>
        CK Enterprises Group Ltd acts as a <strong>data processor</strong> on behalf of Irwell Valley Scout
        District and processes data only under the District&rsquo;s instruction. The relationship is governed
        by a Data Processing Agreement between the two parties.
    </p>
    <p>
        For details on how your personal data is collected, used and protected, see our
        <a href="/privacy-notice.php">Privacy Notice</a>.
    </p>

    <h2>4. Who can use the Platform</h2>
    <p>The Platform is provided for use by:</p>
    <ul>
        <li>Active adult volunteers within Irwell Valley Scout District</li>
        <li>District and Group administrators</li>
        <li>Other individuals granted access by a District or Group administrator</li>
    </ul>
    <p>
        Access is via Microsoft 365 sign-in only. You are responsible for keeping your Microsoft account
        credentials secure. You must not share your login or allow others to access the Platform through
        your account.
    </p>

    <h2>5. Acceptable use</h2>
    <p>When using the Platform, you must not:</p>
    <ul>
        <li>Use the Platform for any purpose unrelated to your Scouting role</li>
        <li>Upload content that is unlawful, harmful, threatening, abusive, defamatory or otherwise objectionable</li>
        <li>Upload files containing malware or malicious code</li>
        <li>Attempt to access data or accounts belonging to other users without authorisation</li>
        <li>Use the bulk communications tool (Comms Tool) to send unsolicited or inappropriate messages</li>
        <li>Scrape, harvest or bulk-export personal data from the Platform</li>
        <li>Attempt to circumvent access controls, security features or audit logging</li>
        <li>Use the Platform in any way that could damage, disable or impair its operation</li>
    </ul>

    <h2>6. Content you submit</h2>
    <p>
        You retain ownership of content you create and submit (e.g. event details, risk assessments,
        communications). By submitting content, you grant Irwell Valley Scout District a non-exclusive
        licence to use, store and share that content as necessary to operate the Platform and fulfil
        District purposes.
    </p>
    <p>
        You are responsible for ensuring any content you upload complies with applicable laws and
        The Scout Association&rsquo;s policies.
    </p>

    <h2>7. Microsoft 365 accounts</h2>
    <p>
        Where the Platform provisions a Microsoft 365 account for you, that account remains the property
        of Irwell Valley Scout District. It is provided solely for Scouting purposes. The District may
        suspend or remove the account at any time, including when you leave your volunteer role.
    </p>

    <h2>8. Availability and support</h2>
    <p>
        The Platform is provided on an &ldquo;as is&rdquo; and &ldquo;as available&rdquo; basis.
        CK Enterprises Group Ltd and Irwell Valley Scout District do not guarantee uninterrupted or
        error-free service. Planned maintenance or updates may cause temporary downtime.
    </p>
    <p>
        For support, contact <a href="mailto:support@irvalscouts.org.uk">support@irvalscouts.org.uk</a>.
    </p>

    <h2>9. Limitation of liability</h2>
    <p>
        To the fullest extent permitted by law:
    </p>
    <ul>
        <li>
            CK Enterprises Group Ltd shall not be liable for any indirect, incidental or consequential
            loss arising from your use of the Platform.
        </li>
        <li>
            Irwell Valley Scout District shall not be liable for any loss arising from decisions made
            based on information displayed in the Platform.
        </li>
        <li>
            Neither party shall be liable for any loss caused by circumstances beyond their reasonable
            control (including but not limited to third-party service outages, internet connectivity
            failures or cyber attacks).
        </li>
    </ul>
    <p>
        Nothing in these Terms excludes or limits liability for death or personal injury caused by
        negligence, fraud, or any other liability that cannot be excluded by law.
    </p>

    <h2>10. Suspension and termination</h2>
    <p>
        Irwell Valley Scout District or CK Enterprises Group Ltd may suspend or terminate your access
        to the Platform at any time if:
    </p>
    <ul>
        <li>You breach these Terms</li>
        <li>You are no longer an active volunteer in the District</li>
        <li>It is necessary for security, safeguarding or legal reasons</li>
    </ul>
    <p>
        On termination, your access will be revoked and your data handled in accordance with our
        <a href="/privacy-notice.php">Privacy Notice</a> retention policy.
    </p>

    <h2>11. Changes to these Terms</h2>
    <p>
        We may update these Terms from time to time. The &ldquo;Effective from&rdquo; date at the top
        will reflect any changes. Continued use of the Platform after changes constitutes acceptance
        of the updated Terms. Material changes will be communicated via the Platform or email.
    </p>

    <h2>12. Governing law</h2>
    <p>
        These Terms are governed by the laws of England and Wales. Any disputes shall be subject to
        the exclusive jurisdiction of the courts of England and Wales.
    </p>

    <h2>13. Contact</h2>
    <ul>
        <li><strong>Platform provider:</strong> CK Enterprises Group Ltd &mdash; <a href="https://www.ckenterprises.co.uk" target="_blank" rel="noopener noreferrer">ckenterprises.co.uk</a></li>
        <li><strong>Data controller:</strong> Irwell Valley Scout District &mdash; <a href="mailto:support@irvalscouts.org.uk">support@irvalscouts.org.uk</a></li>
    </ul>

    <?php if ($isLoggedIn): ?>
        <a class="lt-terms-back" href="/index.php">&larr; Back to Dashboard</a>
    <?php else: ?>
        <a class="lt-terms-back" href="/login.php">&larr; Back to Sign in</a>
    <?php endif; ?>
</main>

<?php if (!$isLoggedIn): ?>
</body>
</html>
<?php else: ?>
    <?php include __DIR__ . '/footer.php'; ?>
<?php endif; ?>
