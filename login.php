<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (is_logged_in()) {
    redirect(user_needs_group_onboarding() ? '/onboarding.php' : '/index.php');
}

$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
$authError = $_SESSION['auth_error'] ?? '';
$oldEmail = $_SESSION['old_email'] ?? '';

unset($_SESSION['auth_error'], $_SESSION['old_email']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sign in | <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

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

    <style>
        :root {
            --lt-purple: #7413dc;
            --lt-purple-dark: #4d0b93;
            --lt-purple-deep: #2f005c;
            --lt-teal: #00a794;
            --lt-yellow: #ffdd00;
            --lt-ink: #101820;
            --lt-muted: #4b5563;
            --lt-border: #d8dde3;
            --lt-canvas: #f5f6f8;
            --lt-white: #ffffff;
            --lt-error: #d4351c;
            --lt-width: 1120px;
        }

        * {
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
            background: var(--lt-canvas);
        }

        body {
            min-height: 100vh;
            margin: 0;
            background:
                linear-gradient(180deg, var(--lt-purple-deep) 0 265px, var(--lt-canvas) 265px 100%);
            color: var(--lt-ink);
            font-family: "Nunito Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            text-rendering: optimizeLegibility;
        }

        a {
            color: var(--lt-purple-dark);
            text-decoration-thickness: 2px;
            text-underline-offset: 0.16em;
        }

        a:hover {
            color: var(--lt-purple-deep);
            text-decoration-thickness: 3px;
        }

        a:focus,
        button:focus,
        input:focus {
            outline: 3px solid var(--lt-yellow);
            outline-offset: 0;
            box-shadow: 0 0 0 5px #000000;
        }

        .lt-login-page {
            min-height: 100vh;
            padding: clamp(1rem, 4vw, 3rem);
        }

        .lt-login-shell {
            width: min(var(--lt-width), 100%);
            margin: 0 auto;
        }

        .lt-login-service-mark {
            display: inline-flex;
            align-items: center;
            gap: 0.85rem;
            margin-bottom: 1.25rem;
            color: #ffffff;
            text-decoration: none;
        }

        .lt-login-service-mark:hover {
            color: #ffffff;
            text-decoration: none;
        }

        .lt-login-service-mark img {
            width: auto;
            height: 64px;
            max-width: 210px;
            object-fit: contain;
        }

        .lt-login-service-text {
            display: block;
            min-width: 0;
        }

        .lt-login-service-name {
            display: block;
            color: #ffffff;
            font-size: 1.05rem;
            font-weight: 900;
            line-height: 1.1;
        }

        .lt-login-service-subtitle {
            display: block;
            margin-top: 0.12rem;
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.9rem;
            font-weight: 800;
            line-height: 1.15;
        }

        .lt-login-card {
            overflow: hidden;
            background: #ffffff;
            border: 1px solid rgba(16, 24, 32, 0.18);
            border-top: 10px solid var(--lt-teal);
            box-shadow: 0 18px 50px rgba(0, 0, 0, 0.24);
        }

        .lt-login-grid {
            display: grid;
            min-height: 620px;
        }

        @media (min-width: 920px) {
            .lt-login-grid {
                grid-template-columns: minmax(0, 0.94fr) minmax(0, 1.06fr);
            }
        }

        .lt-login-form-side {
            padding: clamp(1.5rem, 4vw, 3rem);
            background: #ffffff;
        }

        .lt-login-kicker {
            display: inline-block;
            margin-bottom: 0.9rem;
            padding: 0.22rem 0.5rem;
            background: var(--lt-teal);
            color: #ffffff;
            font-size: 0.9rem;
            font-weight: 900;
            line-height: 1.2;
        }

        .lt-login-form-side h1 {
            max-width: 620px;
            margin: 0;
            color: var(--lt-ink);
            font-size: clamp(2.05rem, 5vw, 3.45rem);
            font-weight: 900;
            line-height: 0.98;
            letter-spacing: -0.055em;
        }

        .lt-login-lede {
            max-width: 620px;
            margin: 1rem 0 0;
            color: var(--lt-ink);
            font-size: clamp(1.03rem, 1.8vw, 1.16rem);
            font-weight: 800;
            line-height: 1.45;
        }

        .lt-login-error-summary {
            margin-top: 1.5rem;
            padding: 1rem;
            border: 4px solid var(--lt-error);
            background: #ffffff;
        }

        .lt-login-error-summary h2 {
            margin: 0;
            color: var(--lt-ink);
            font-size: 1.25rem;
            font-weight: 900;
            line-height: 1.2;
        }

        .lt-login-error-summary p {
            margin: 0.6rem 0 0;
            color: var(--lt-ink);
            font-weight: 800;
            line-height: 1.45;
        }

        .lt-login-form {
            max-width: 480px;
            margin-top: 1.75rem;
        }

        .lt-form-group {
            margin-bottom: 1.4rem;
        }

        .lt-form-group--error {
            padding-left: 1rem;
            border-left: 5px solid var(--lt-error);
        }

        .lt-label {
            display: block;
            margin-bottom: 0.45rem;
            color: var(--lt-ink);
            font-size: 1.06rem;
            font-weight: 900;
            line-height: 1.25;
        }

        .lt-hint {
            display: block;
            margin: -0.2rem 0 0.55rem;
            color: var(--lt-muted);
            font-size: 0.98rem;
            font-weight: 700;
            line-height: 1.45;
        }

        .lt-error-message {
            display: block;
            margin: 0 0 0.55rem;
            color: var(--lt-error);
            font-size: 0.98rem;
            font-weight: 900;
            line-height: 1.35;
        }

        .lt-input {
            display: block;
            width: 100%;
            min-height: 48px;
            padding: 0.6rem 0.75rem;
            border: 2px solid #101820;
            border-radius: 0;
            background: #ffffff;
            color: var(--lt-ink);
            font: inherit;
            font-size: 1.08rem;
            font-weight: 800;
            line-height: 1.35;
            appearance: none;
        }

        .lt-input:hover {
            background: #f8f8f8;
        }

        .lt-input--error {
            border-color: var(--lt-error);
        }

        .lt-login-actions {
            display: flex;
            flex-direction: column;
            gap: 0.9rem;
            margin-top: 1.7rem;
        }

        .lt-login-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            min-width: 190px;
            min-height: 52px;
            padding: 0.85rem 1.2rem;
            background: var(--lt-purple);
            border: 2px solid var(--lt-purple);
            border-radius: 0;
            color: #ffffff;
            box-shadow: 0 4px 0 #000000;
            cursor: pointer;
            font: inherit;
            font-size: 1.05rem;
            font-weight: 900;
            line-height: 1.15;
            text-align: center;
            text-decoration: none;
        }

        .lt-login-button:hover,
        .lt-login-button:focus {
            background: var(--lt-purple-dark);
            border-color: var(--lt-purple-dark);
            color: #ffffff;
            text-decoration: none;
        }

        .lt-login-secondary-link {
            align-self: flex-start;
            color: var(--lt-purple-dark);
            font-size: 0.98rem;
            font-weight: 900;
        }

        .lt-login-sso-panel {
            margin-top: 1.8rem;
            padding: 1rem;
            border-left: 8px solid var(--lt-teal);
            background: #f7f3fc;
        }

        .lt-login-sso-panel h2 {
            margin: 0;
            color: var(--lt-ink);
            font-size: 1.08rem;
            font-weight: 900;
            line-height: 1.2;
        }

        .lt-login-sso-panel p {
            margin: 0.55rem 0 0;
            color: var(--lt-muted);
            font-size: 0.98rem;
            font-weight: 700;
            line-height: 1.5;
        }

        .lt-login-visual-side {
            position: relative;
            display: flex;
            min-height: 420px;
            padding: clamp(1.5rem, 4vw, 3rem);
            background:
                linear-gradient(135deg, rgba(77, 11, 147, 0.96), rgba(116, 19, 220, 0.96)),
                var(--lt-purple-dark);
            color: #ffffff;
            overflow: hidden;
        }

        .lt-login-visual-side::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(90deg, rgba(255, 221, 0, 0.22) 0 8px, transparent 8px 100%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.08) 0 1px, transparent 1px 100%);
            background-size: 96px 100%, 100% 56px;
            opacity: 0.42;
            pointer-events: none;
        }

        .lt-visual-content {
            position: relative;
            z-index: 1;
            display: flex;
            width: 100%;
            flex-direction: column;
            justify-content: space-between;
            gap: 2rem;
        }

        .lt-visual-label {
            display: inline-block;
            width: fit-content;
            padding: 0.22rem 0.5rem;
            background: var(--lt-yellow);
            color: #101820;
            font-size: 0.85rem;
            font-weight: 900;
            line-height: 1.2;
        }

        .lt-visual-content h2 {
            max-width: 560px;
            margin: 1rem 0 0;
            color: #ffffff;
            font-size: clamp(1.9rem, 4vw, 3rem);
            font-weight: 900;
            line-height: 1;
            letter-spacing: -0.045em;
        }

        .lt-visual-content p {
            max-width: 540px;
            margin: 1rem 0 0;
            color: rgba(255, 255, 255, 0.92);
            font-size: 1.04rem;
            font-weight: 800;
            line-height: 1.48;
        }

        .lt-dashboard-mockup {
            width: min(100%, 520px);
            margin-left: auto;
            border: 2px solid rgba(255, 255, 255, 0.85);
            background: #ffffff;
            box-shadow: 12px 12px 0 rgba(0, 0, 0, 0.32);
            color: var(--lt-ink);
        }

        .lt-dashboard-topbar {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            min-height: 46px;
            padding: 0.75rem;
            border-bottom: 2px solid #101820;
            background: #f3f2f1;
        }

        .lt-dashboard-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: var(--lt-purple);
        }

        .lt-dashboard-dot:nth-child(2) {
            background: var(--lt-teal);
        }

        .lt-dashboard-dot:nth-child(3) {
            background: var(--lt-yellow);
        }

        .lt-dashboard-body {
            padding: 1rem;
        }

        .lt-dashboard-heading {
            width: 68%;
            height: 18px;
            margin-bottom: 1rem;
            background: var(--lt-ink);
        }

        .lt-dashboard-row {
            display: grid;
            grid-template-columns: 52px 1fr;
            gap: 0.8rem;
            align-items: center;
            padding: 0.78rem 0;
            border-top: 1px solid var(--lt-border);
        }

        .lt-dashboard-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 52px;
            height: 52px;
            background: #f7f3fc;
            border-left: 6px solid var(--lt-teal);
            color: var(--lt-purple-dark);
            font-size: 1.35rem;
            font-weight: 900;
        }

        .lt-dashboard-line {
            height: 12px;
            margin-bottom: 0.45rem;
            background: #101820;
        }

        .lt-dashboard-line--short {
            width: 58%;
            margin-bottom: 0;
            background: #b1b4b6;
        }

        .lt-login-meta {
            margin-top: 1rem;
            color: var(--lt-muted);
            font-size: 0.95rem;
            font-weight: 900;
            line-height: 1.35;
            text-align: center;
        }

        .lt-login-meta span {
            color: var(--lt-purple-dark);
        }

        @media (max-width: 919.98px) {
            body {
                background:
                    linear-gradient(180deg, var(--lt-purple-deep) 0 220px, var(--lt-canvas) 220px 100%);
            }

            .lt-login-grid {
                min-height: 0;
            }

            .lt-login-visual-side {
                min-height: 0;
                border-top: 6px solid var(--lt-teal);
            }

            .lt-dashboard-mockup {
                margin-left: 0;
            }
        }

        @media (max-width: 560px) {
            .lt-login-page {
                padding: 0.75rem;
            }

            .lt-login-service-mark {
                align-items: flex-start;
                flex-direction: column;
                gap: 0.45rem;
            }

            .lt-login-service-mark img {
                height: 54px;
                max-width: 180px;
            }

            .lt-login-button {
                width: 100%;
            }

            .lt-dashboard-mockup {
                box-shadow: 8px 8px 0 rgba(0, 0, 0, 0.32);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                transition-duration: 0.001ms !important;
                animation-duration: 0.001ms !important;
                animation-iteration-count: 1 !important;
            }
        }
    </style>
</head>

<body>
<main class="lt-login-page">
    <div class="lt-login-shell">
        <a class="lt-login-service-mark" href="/login.php" aria-label="<?= e($appName) ?>">
            <img
                src="/assets/img/white-ir-logo.png"
                alt="Irwell Valley District Scouts"
                onerror="this.style.display='none';"
            >
            <span class="lt-login-service-text">
                <span class="lt-login-service-name"><?= e($appName) ?></span>
                <span class="lt-login-service-subtitle">Irwell Valley Scout District</span>
            </span>
        </a>

        <section class="lt-login-card" aria-labelledby="login-title">
            <div class="lt-login-grid">
                <div class="lt-login-form-side">
                    <span class="lt-login-kicker">District access</span>
                    <h1 id="login-title">Access your District email and accounts</h1>
                    <p class="lt-login-lede">
                        Sign in with your District account to manage leader tools, directory details, Group information and calendar access.
                    </p>

                    <?php if ($authError !== ''): ?>
                        <div class="lt-login-error-summary" role="alert" aria-labelledby="login-error-title" tabindex="-1">
                            <h2 id="login-error-title">There is a problem</h2>
                            <p><?= e($authError) ?></p>
                        </div>
                    <?php endif; ?>

                    <form class="lt-login-form" method="post" action="/auth/login.php" novalidate>
                        <div class="lt-form-group<?= $authError !== '' ? ' lt-form-group--error' : '' ?>">
                            <label class="lt-label" for="email">Email address</label>
                            <span class="lt-hint" id="email-hint">Use your District email address.</span>
                            <?php if ($authError !== ''): ?>
                                <span class="lt-error-message" id="email-error">Enter a valid District email address.</span>
                            <?php endif; ?>
                            <input
                                class="lt-input<?= $authError !== '' ? ' lt-input--error' : '' ?>"
                                id="email"
                                name="email"
                                type="email"
                                inputmode="email"
                                autocomplete="username"
                                spellcheck="false"
                                value="<?= e($oldEmail) ?>"
                                aria-describedby="email-hint<?= $authError !== '' ? ' email-error' : '' ?>"
                                required
                            >
                        </div>

                        <div class="lt-form-group<?= $authError !== '' ? ' lt-form-group--error' : '' ?>">
                            <label class="lt-label" for="password">Password</label>
                            <?php if ($authError !== ''): ?>
                                <span class="lt-error-message" id="password-error">Enter your password.</span>
                            <?php endif; ?>
                            <input
                                class="lt-input<?= $authError !== '' ? ' lt-input--error' : '' ?>"
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                aria-describedby="<?= $authError !== '' ? 'password-error' : '' ?>"
                                required
                            >
                        </div>

                        <div class="lt-login-actions">
                            <button class="lt-login-button" type="submit">Sign in</button>
                            <a class="lt-login-secondary-link" href="/forgot-password.php">Forgotten your password?</a>
                        </div>
                    </form>

                    <div class="lt-login-sso-panel">
                        <h2>Use Microsoft single sign-on</h2>
                        <p>
                            Volunteers with a District Microsoft account can also <a href="/auth/microsoft-start.php">sign in with Microsoft</a>.
                        </p>
                    </div>
                </div>

                <aside class="lt-login-visual-side" aria-label="District account services">
                    <div class="lt-visual-content">
                        <div>
                            <span class="lt-visual-label">Irwell Valley Scouts</span>
                            <h2>One secure place for District tools.</h2>
                            <p>
                                Clean access for volunteers, with account areas designed around roles, Groups and District services.
                            </p>
                        </div>

                        <div class="lt-dashboard-mockup" aria-hidden="true">
                            <div class="lt-dashboard-topbar">
                                <span class="lt-dashboard-dot"></span>
                                <span class="lt-dashboard-dot"></span>
                                <span class="lt-dashboard-dot"></span>
                            </div>
                            <div class="lt-dashboard-body">
                                <div class="lt-dashboard-heading"></div>
                                <div class="lt-dashboard-row">
                                    <div class="lt-dashboard-icon">@</div>
                                    <div>
                                        <div class="lt-dashboard-line"></div>
                                        <div class="lt-dashboard-line lt-dashboard-line--short"></div>
                                    </div>
                                </div>
                                <div class="lt-dashboard-row">
                                    <div class="lt-dashboard-icon">✓</div>
                                    <div>
                                        <div class="lt-dashboard-line"></div>
                                        <div class="lt-dashboard-line lt-dashboard-line--short"></div>
                                    </div>
                                </div>
                                <div class="lt-dashboard-row">
                                    <div class="lt-dashboard-icon">#</div>
                                    <div>
                                        <div class="lt-dashboard-line"></div>
                                        <div class="lt-dashboard-line lt-dashboard-line--short"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </section>

        <p class="lt-login-meta"><span><?= e($appName) ?></span> · Irwell Valley Scout District</p>
    </div>
</main>

<script
    src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"
    integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo"
    crossorigin="anonymous"
></script>

<script
    src="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/js/bootstrap.min.js"
    integrity="sha384-vZA7fWbUdVwzQZlO+dkC65mKiaTlKyDvRFeWWT/+J8nBCX0A/OJE2YaFG+m4Zhv0"
    crossorigin="anonymous"
></script>
</body>
</html>
