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
    ? 'You have opened a Group calendar link. Sign in with Microsoft if you have a District account, or continue with the Group link.'
    : 'Sign in with your District Microsoft account to open the District Calendar.';

require __DIR__ . '/layout.php';
?>

<style>
    .dc-login-grid {
        display: grid;
        gap: 1.25rem;
    }

    @media (min-width: 992px) {
        .dc-login-grid {
            grid-template-columns: minmax(0, 1.35fr) minmax(320px, 0.75fr);
            align-items: start;
        }
    }

    .dc-login-card {
        background: #ffffff;
        border: 1px solid #d8dde3;
        border-radius: 0;
        box-shadow: 0 2px 0 rgba(16, 24, 32, 0.08);
    }

    .dc-login-card-primary {
        border-top: 8px solid #7413dc;
    }

    .dc-login-card-secondary {
        background: #f7f3fc;
        border-top: 8px solid #00a794;
    }

    .dc-login-card-inner {
        padding: clamp(1.25rem, 3vw, 2rem);
    }

    .dc-login-eyebrow {
        display: inline-block;
        margin-bottom: 0.75rem;
        padding: 0.3rem 0.55rem;
        background: #101820;
        color: #ffffff;
        font-size: 0.8rem;
        font-weight: 900;
        line-height: 1;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .dc-login-card h2 {
        margin: 0;
        color: #101820;
        font-size: clamp(1.65rem, 3vw, 2.4rem);
        font-weight: 900;
        line-height: 1.02;
        letter-spacing: -0.035em;
    }

    .dc-login-card-secondary h2 {
        font-size: clamp(1.25rem, 2vw, 1.6rem);
        letter-spacing: -0.02em;
    }

    .dc-login-card p {
        color: #101820;
        font-size: 1rem;
        line-height: 1.55;
    }

    .dc-login-lede {
        max-width: 760px;
        margin: 1rem 0 0;
        font-size: clamp(1.05rem, 2vw, 1.2rem) !important;
        font-weight: 700;
        line-height: 1.45 !important;
    }

    .dc-login-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 1.35rem;
    }

    .dc-login-actions .lt-btn,
    .dc-login-actions .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 48px;
        border-radius: 0;
        font-weight: 900;
        line-height: 1.15;
        text-decoration: none;
    }

    .dc-login-actions .btn-primary,
    .dc-login-actions .lt-btn.btn-primary {
        background: #7413dc;
        border-color: #7413dc;
        color: #ffffff;
        box-shadow: 0 4px 0 #000000;
    }

    .dc-login-actions .btn-primary:hover,
    .dc-login-actions .lt-btn.btn-primary:hover {
        background: #4d0b93;
        border-color: #4d0b93;
        color: #ffffff;
    }

    .dc-login-actions .lt-btn-secondary {
        background: #ffffff;
        border: 2px solid #101820;
        color: #101820;
        box-shadow: 0 4px 0 #101820;
    }

    .dc-login-actions .lt-btn-secondary:hover {
        background: #101820;
        color: #ffffff;
    }

    .dc-login-note {
        margin-top: 1.5rem;
        padding: 1rem;
        background: #fff8d6;
        border-left: 8px solid #ffdd00;
        color: #101820;
        font-weight: 800;
        line-height: 1.45;
    }

    .dc-group-link-summary {
        display: grid;
        gap: 0.25rem;
        margin: 1.25rem 0 0;
        padding: 1rem;
        background: #ffffff;
        border: 2px solid #7413dc;
    }

    .dc-group-link-summary strong {
        display: block;
        color: #4d0b93;
        font-size: 1.25rem;
        font-weight: 900;
        line-height: 1.15;
    }

    .dc-login-small {
        color: #4b5563;
        font-size: 0.9rem;
        font-weight: 900;
        line-height: 1.2;
    }

    .dc-login-list {
        margin: 1rem 0 0;
        padding-left: 1.25rem;
    }

    .dc-login-list li {
        margin-bottom: 0.5rem;
        font-weight: 700;
    }

    .dc-code {
        display: block;
        margin: 1rem 0;
        padding: 0.85rem;
        background: #101820;
        color: #ffffff;
        border-radius: 0;
        overflow-x: auto;
        font-size: 0.95rem;
        font-weight: 800;
        line-height: 1.4;
    }

    .dc-login-divider {
        height: 1px;
        margin: 1.25rem 0;
        background: #d8dde3;
        border: 0;
    }

    .dc-login-support {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid #d8dde3;
        color: #4b5563;
        font-size: 0.95rem;
        font-weight: 700;
    }

    @media (max-width: 767.98px) {
        .dc-login-actions {
            display: grid;
        }

        .dc-login-actions .lt-btn,
        .dc-login-actions .btn {
            width: 100%;
        }
    }
</style>

<div class="dc-login-grid">
    <section class="dc-login-card dc-login-card-primary">
        <div class="dc-login-card-inner">
            <?php if ($hasGroupLink): ?>
                <span class="dc-login-eyebrow">Recommended</span>

                <h2 class="lt-section-title">Sign in with Microsoft</h2>

                <p class="lt-lede dc-login-lede">
                    If you have a District Microsoft 365 account, use it to sign in.
                    This gives you a personal account, better security and the full District Calendar experience.
                </p>

                <div class="dc-group-link-summary">
                    <span class="dc-login-small">Group link recognised for</span>
                    <strong><?= e((string) ($groupLink['group_name'] ?? 'your Group')) ?></strong>
                </div>

                <div class="dc-login-actions">
                    <a class="btn btn-primary lt-btn" href="/auth/microsoft-start.php">
                        Sign in with Microsoft
                    </a>

                    <a class="btn lt-btn lt-btn-secondary" href="/dc/login.php?continue=group-link">
                        Continue with Group link
                    </a>
                </div>

                <div class="dc-login-note">
                    A Group link is shared access for your Group. It is useful as a fallback,
                    but your own Microsoft sign-in is preferred where possible.
                </div>
            <?php else: ?>
                <span class="dc-login-eyebrow">District account</span>

                <h2 class="lt-section-title">Sign in with Microsoft</h2>

                <p class="lt-lede dc-login-lede">
                    District users and Group Lead Volunteers should sign in with their District Microsoft 365 account.
                </p>

                <div class="dc-login-actions">
                    <a class="btn btn-primary lt-btn" href="/auth/microsoft-start.php">
                        Sign in with Microsoft
                    </a>
                </div>

                <div class="dc-login-support">
                    Use the account provided for your District role. Group-link access is only available from a valid shared Group link.
                </div>
            <?php endif; ?>
        </div>
    </section>

    <aside class="dc-login-card dc-login-card-secondary">
        <div class="dc-login-card-inner">
            <?php if ($hasGroupLink): ?>
                <span class="dc-login-eyebrow">Group access</span>

                <h2 class="h4 font-weight-bold">Using the Group link</h2>

                <p>
                    You can continue with the Group link if you do not yet have a District Microsoft 365 account.
                </p>

                <ul class="dc-login-list">
                    <li>Access is limited to the Group calendar area.</li>
                    <li>It is shared access, not a personal account.</li>
                    <li>Microsoft sign-in is preferred where available.</li>
                </ul>

                <hr class="dc-login-divider">

                <p class="mb-0">
                    If you should have a District account, ask your Group Lead Volunteer to add you through Group Admin.
                </p>
            <?php else: ?>
                <span class="dc-login-eyebrow">Shared link</span>

                <h2 class="h4 font-weight-bold">Using a Group link?</h2>

                <p>
                    Open the link shared with your Group. It will look like:
                </p>

                <pre class="dc-code">/dc/login.php?token=...</pre>

                <p class="mb-0">
                    A Group link gives access to that Group’s calendar area. It is not a personal account.
                </p>
            <?php endif; ?>
        </div>
    </aside>
</div>

<?php require __DIR__ . '/layout-footer.php'; ?>