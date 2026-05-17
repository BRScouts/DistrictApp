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
        gap: 1rem;
    }

    @media (min-width: 992px) {
        .dc-login-grid {
            grid-template-columns: minmax(0, 1.4fr) minmax(320px, .8fr);
            align-items: start;
        }
    }

    .dc-login-card {
        background: #ffffff;
        border: 1px solid #e6e6e6;
        border-radius: .75rem;
        padding: 1.25rem;
    }

    .dc-login-card-primary {
        border-top: 6px solid #7413dc;
    }

    .dc-login-card-secondary {
        background: #f7f5fb;
    }

    .dc-login-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        margin-top: 1rem;
    }

    .dc-login-note {
        margin-top: 1rem;
        padding: 1rem;
        background: #fff8d6;
        border-left: 5px solid #ffdd00;
        font-weight: 700;
    }

    .dc-group-link-summary {
        margin: 1rem 0;
        padding: 1rem;
        background: #ffffff;
        border: 1px solid #e6e6e6;
        border-radius: .5rem;
    }

    .dc-group-link-summary strong {
        display: block;
        color: #4d0b93;
        font-size: 1.05rem;
        font-weight: 900;
    }

    .dc-login-small {
        color: #555;
        font-size: .95rem;
        font-weight: 700;
    }

    .dc-code {
        display: block;
        padding: .75rem;
        background: #1d1d1b;
        color: #ffffff;
        border-radius: .35rem;
        overflow-x: auto;
    }
</style>

<div class="dc-login-grid">
    <section class="dc-login-card dc-login-card-primary">
        <?php if ($hasGroupLink): ?>
            <h2 class="lt-section-title">Recommended: sign in with Microsoft</h2>

            <p class="lt-lede">
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
            <h2 class="lt-section-title">Sign in with Microsoft</h2>

            <p class="lt-lede">
                District users and Group Lead Volunteers should sign in with their District Microsoft 365 account.
            </p>

            <div class="dc-login-actions">
                <a class="btn btn-primary lt-btn" href="/auth/microsoft-start.php">
                    Sign in with Microsoft
                </a>
            </div>
        <?php endif; ?>
    </section>

    <aside class="dc-login-card dc-login-card-secondary">
        <?php if ($hasGroupLink): ?>
            <h2 class="h4 font-weight-bold">Using the Group link</h2>

            <p>
                You can continue with the Group link if you do not yet have a District Microsoft 365 account.
            </p>

            <p>
                Group-link access is limited to the Group calendar area and is not a personal account.
            </p>

            <p class="mb-0">
                If you should have a District account, ask your Group Lead Volunteer to add you through Group Admin.
            </p>
        <?php else: ?>
            <h2 class="h4 font-weight-bold">Using a Group link?</h2>

            <p>
                Open the link shared with your Group. It will look like:
            </p>

            <pre class="dc-code">/dc/login.php?token=...</pre>

            <p class="mb-0">
                A Group link gives access to that Group’s calendar area. It is not a personal account.
            </p>
        <?php endif; ?>
    </aside>
</div>

<?php require __DIR__ . '/layout-footer.php'; ?>