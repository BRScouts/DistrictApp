<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/*
 * Signed-in users should not see the Group-link landing page.
 * They already have a personal account context, so send them straight to the calendar.
 */
if (current_user()) {
    redirect('/dc/');
}

/*
 * If a token is present, dc_context() validates it, stores the group-link
 * context in $_SESSION['dc_group_link'], logs the use, and redirects back
 * to this page without the token in the URL.
 */
if (isset($_GET['token'])) {
    dc_context(true);
    redirect('/dc/');
}

/*
 * Allow the user to continue with the Group link after seeing the SSO prompt.
 */
if (isset($_GET['continue']) && $_GET['continue'] === 'group-link') {
    if (!empty($_SESSION['dc_group_link'])) {
        redirect('/dc/');
    }

    redirect('/dc/login.php');
}

$groupLink = $_SESSION['dc_group_link'] ?? null;
$hasGroupLink = is_array($groupLink) && !empty($groupLink['group_id']);

$pageTitle = 'Open District Calendar';
$heroTitle = 'Open District Calendar';

$heroText = $hasGroupLink
    ? 'Continue with your Group calendar link, or sign in with Microsoft if you have a District account.'
    : 'Sign in with your District Microsoft account to open the District Calendar.';

require __DIR__ . '/layout.php';
?>

<style>
    .dc-login-panel {
        display: grid;
        gap: 1.25rem;
        max-width: 880px;
    }

    .dc-login-card {
        background: #ffffff;
        border: 1px solid #d8dde3;
        border-top: 8px solid #7413dc;
        border-radius: 0;
        box-shadow: 0 2px 0 rgba(16, 24, 32, 0.08);
    }

    .dc-login-card-inner {
        padding: clamp(1.25rem, 3vw, 2rem);
    }

    .dc-login-card h2 {
        margin: 0;
        color: #101820;
        font-size: clamp(1.75rem, 4vw, 2.8rem);
        font-weight: 900;
        line-height: 1;
        letter-spacing: -0.04em;
    }

    .dc-login-card h3 {
        margin: 0;
        color: #101820;
        font-size: clamp(1.2rem, 2vw, 1.45rem);
        font-weight: 900;
        line-height: 1.1;
        letter-spacing: -0.02em;
    }

    .dc-login-card p {
        color: #101820;
        font-size: 1rem;
        line-height: 1.55;
    }

    .dc-login-lede {
        max-width: 720px;
        margin: 1rem 0 0;
        font-size: clamp(1.05rem, 2vw, 1.25rem) !important;
        font-weight: 800;
        line-height: 1.42 !important;
    }

    .dc-login-group-summary {
        display: grid;
        gap: 0.2rem;
        margin: 1.25rem 0 0;
        padding: 1rem;
        background: #f7f3fc;
        border-left: 8px solid #7413dc;
    }

    .dc-login-group-summary span {
        color: #4b5563;
        font-size: 0.9rem;
        font-weight: 900;
        line-height: 1.2;
    }

    .dc-login-group-summary strong {
        display: block;
        color: #4d0b93;
        font-size: 1.3rem;
        font-weight: 900;
        line-height: 1.15;
    }

    .dc-login-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 1.5rem;
    }

    .dc-login-actions .btn,
    .dc-login-actions .lt-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 50px;
        padding: 0.75rem 1rem;
        border-radius: 0;
        font-weight: 900;
        line-height: 1.15;
        text-decoration: none;
    }

    .dc-login-actions .dc-login-primary {
        background: #7413dc;
        border: 2px solid #7413dc;
        color: #ffffff;
        box-shadow: 0 4px 0 #000000;
    }

    .dc-login-actions .dc-login-primary:hover {
        background: #4d0b93;
        border-color: #4d0b93;
        color: #ffffff;
    }

    .dc-login-actions .dc-login-secondary {
        background: #ffffff;
        border: 2px solid #101820;
        color: #101820;
        box-shadow: 0 4px 0 #101820;
    }

    .dc-login-actions .dc-login-secondary:hover {
        background: #101820;
        color: #ffffff;
    }

    .dc-login-help {
        margin-top: 1.5rem;
        padding-top: 1.25rem;
        border-top: 1px solid #d8dde3;
    }

    .dc-login-help p {
        margin: 0;
        color: #4b5563;
        font-size: 0.98rem;
        font-weight: 700;
    }

    .dc-login-sso-box {
        margin-top: 1.5rem;
        padding: 1rem;
        background: #f5f6f8;
        border-left: 8px solid #00a794;
    }

    .dc-login-sso-box h3 {
        margin-bottom: 0.5rem;
    }

    .dc-login-sso-box p {
        margin: 0;
        color: #101820;
        font-weight: 700;
    }

    .dc-login-info-card {
        background: #ffffff;
        border: 1px solid #d8dde3;
        border-top: 8px solid #00a794;
        border-radius: 0;
    }

    .dc-login-info-card-inner {
        padding: clamp(1.25rem, 3vw, 1.5rem);
    }

    .dc-login-info-card h2 {
        margin: 0;
        color: #101820;
        font-size: clamp(1.25rem, 2vw, 1.6rem);
        font-weight: 900;
        line-height: 1.1;
        letter-spacing: -0.02em;
    }

    .dc-login-info-card p {
        margin: 0.75rem 0 0;
        color: #101820;
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.5;
    }

    .dc-code {
        display: block;
        margin: 1rem 0;
        padding: 0.85rem;
        background: #2f005c;
        color: #ffffff;
        border-radius: 0;
        overflow-x: auto;
        font-size: 0.95rem;
        font-weight: 800;
        line-height: 1.4;
    }

    .dc-login-warning {
        margin-top: 1rem;
        padding: 1rem;
        background: #fff8d6;
        border-left: 8px solid #ffdd00;
        color: #101820;
        font-weight: 800;
        line-height: 1.45;
    }

    @media (max-width: 767.98px) {
        .dc-login-actions {
            display: grid;
        }

        .dc-login-actions .btn,
        .dc-login-actions .lt-btn {
            width: 100%;
        }
    }
</style>

<div class="dc-login-panel">
    <section class="dc-login-card">
        <div class="dc-login-card-inner">
            <?php if ($hasGroupLink): ?>
                <h2>Continue to your Group calendar</h2>

                <p class="lt-lede dc-login-lede">
                    Your Group link has been recognised. Continue with this link to open the calendar for your Group.
                </p>

                <div class="dc-login-group-summary">
                    <span>Group link recognised for</span>
                    <strong><?= e((string) ($groupLink['group_name'] ?? 'your Group')) ?></strong>
                </div>

                <div class="dc-login-actions">
                    <a class="btn lt-btn dc-login-primary" href="/dc/login.php?continue=group-link">
                        Continue with Group link
                    </a>

                    <a class="btn lt-btn dc-login-secondary" href="/auth/microsoft-start.php">
                        Sign in with Microsoft
                    </a>
                </div>

                <div class="dc-login-sso-box">
                    <h3>Have a District Microsoft account?</h3>
                    <p>
                        Microsoft sign-in is better if you have a District account because it gives you personal access,
                        stronger security and the full District Calendar experience.
                    </p>
                </div>
            <?php else: ?>
                <h2>Sign in with Microsoft</h2>

                <p class="lt-lede dc-login-lede">
                    District users and Group Lead Volunteers should sign in with their District Microsoft 365 account.
                </p>

                <div class="dc-login-actions">
                    <a class="btn lt-btn dc-login-primary" href="/auth/microsoft-start.php">
                        Sign in with Microsoft
                    </a>
                </div>

                <div class="dc-login-help">
                    <p>
                        If you were sent a Group calendar link, open that link directly. It should include a secure token.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <aside class="dc-login-info-card">
        <div class="dc-login-info-card-inner">
            <?php if ($hasGroupLink): ?>
                <h2>Which option should I use?</h2>

                <p>
                    Most Group volunteers can continue with the Group link.
                </p>

                <p>
                    Use Microsoft sign-in only if you already have a District Microsoft 365 account.
                </p>

                <div class="dc-login-warning">
                    Group-link access is shared access for your Group. It is not a personal District account.
                </div>
            <?php else: ?>
                <h2>Using a Group link?</h2>

                <p>
                    Open the link shared with your Group. It will look like:
                </p>

                <pre class="dc-code">/dc/login.php?token=...</pre>

                <p>
                    A Group link gives access to that Group’s calendar area. It is not a personal account.
                </p>
            <?php endif; ?>
        </div>
    </aside>
</div>

<?php require __DIR__ . '/layout-footer.php'; ?>