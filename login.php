<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (is_logged_in()) {
    redirect(user_needs_group_onboarding() ? '/onboarding.php' : '/index.php');
}

$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
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
            --lt-width: 1040px;
        }

        * {
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
            background: var(--lt-purple-deep);
        }

        body {
            min-height: 100vh;
            margin: 0;
            background:
                linear-gradient(135deg, var(--lt-purple-deep) 0%, var(--lt-purple-dark) 48%, var(--lt-purple) 100%);
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
        button:focus {
            outline: 3px solid var(--lt-yellow);
            outline-offset: 3px;
            box-shadow: 0 0 0 5px #000000;
        }

        .lt-login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
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
            margin-bottom: 1rem;
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
            background: #ffffff;
            border: 1px solid rgba(16, 24, 32, 0.18);
            border-top: 10px solid var(--lt-teal);
            box-shadow: 0 18px 50px rgba(0, 0, 0, 0.28);
        }

        .lt-login-grid {
            display: grid;
        }

        @media (min-width: 900px) {
            .lt-login-grid {
                grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.1fr);
            }
        }

        .lt-login-intro {
            background: var(--lt-purple-dark);
            color: #ffffff;
            padding: clamp(1.5rem, 4vw, 2.25rem);
        }

        .lt-login-intro h1 {
            margin: 0;
            color: #ffffff;
            font-size: clamp(2rem, 5vw, 3.4rem);
            font-weight: 900;
            line-height: 0.98;
            letter-spacing: -0.05em;
        }

        .lt-login-intro p {
            max-width: 520px;
            margin: 1rem 0 0;
            color: rgba(255, 255, 255, 0.94);
            font-size: 1.05rem;
            font-weight: 800;
            line-height: 1.45;
        }

        .lt-login-panel {
            padding: clamp(1.5rem, 4vw, 2.5rem);
            background: #ffffff;
        }

        .lt-login-panel h2 {
            margin: 0;
            color: var(--lt-ink);
            font-size: clamp(1.7rem, 4vw, 2.6rem);
            font-weight: 900;
            line-height: 1;
            letter-spacing: -0.045em;
        }

        .lt-login-lede {
            max-width: 640px;
            margin: 1rem 0 0;
            color: var(--lt-ink);
            font-size: clamp(1.05rem, 2vw, 1.2rem);
            font-weight: 800;
            line-height: 1.45;
        }

        .lt-login-action-panel {
            margin-top: 1.5rem;
            padding: 1.25rem;
            background: #f7f3fc;
            border-left: 8px solid var(--lt-purple);
        }

        .lt-login-action-panel h3 {
            margin: 0;
            color: var(--lt-ink);
            font-size: 1.2rem;
            font-weight: 900;
            line-height: 1.15;
            letter-spacing: -0.015em;
        }

        .lt-login-action-panel p {
            margin: 0.65rem 0 0;
            color: var(--lt-ink);
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.5;
        }

        .lt-login-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 54px;
            margin-top: 1rem;
            padding: 0.85rem 1rem;
            background: var(--lt-purple);
            border: 2px solid var(--lt-purple);
            color: #ffffff;
            box-shadow: 0 4px 0 #000000;
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

        .lt-login-note {
            margin-top: 1.5rem;
            padding: 1rem;
            background: #ffffff;
            border: 1px solid var(--lt-border);
            border-left: 8px solid var(--lt-teal);
        }

        .lt-login-note h3 {
            margin: 0;
            color: var(--lt-ink);
            font-size: 1.05rem;
            font-weight: 900;
            line-height: 1.2;
        }

        .lt-login-note p {
            margin: 0.55rem 0 0;
            color: var(--lt-muted);
            font-size: 0.98rem;
            font-weight: 700;
            line-height: 1.5;
        }

        .lt-login-meta {
            margin-top: 1rem;
            color: rgba(255, 255, 255, 0.94);
            font-size: 0.95rem;
            font-weight: 900;
            line-height: 1.35;
            text-align: center;
        }

        @media (max-width: 899.98px) {
            .lt-login-page {
                align-items: flex-start;
            }

            .lt-login-service-mark {
                margin-bottom: 0.75rem;
            }

            .lt-login-service-mark img {
                height: 54px;
                max-width: 180px;
            }

            .lt-login-intro {
                border-bottom: 6px solid var(--lt-teal);
            }
        }

        @media (max-width: 520px) {
            .lt-login-page {
                padding: 0.75rem;
            }

            .lt-login-service-mark {
                align-items: flex-start;
                flex-direction: column;
                gap: 0.45rem;
            }

            .lt-login-action-panel,
            .lt-login-note {
                padding: 1rem;
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
                <div class="lt-login-intro">
                    <h1 id="login-title">Sign in to the District Dashboard</h1>
                    <p>
                        Use your District Microsoft account to access leader tools, directory details and Group information.
                    </p>
                </div>

                <div class="lt-login-panel">
                    <h2>Sign in</h2>

                    <p class="lt-login-lede">
                        This is for Irwell Valley Scouts volunteers with a District Microsoft account.
                    </p>

                    <div class="lt-login-action-panel">
                        <h3>Microsoft account</h3>
                        <p>
                            Sign in to open your dashboard and continue to the tools available to your role.
                        </p>

                        <a href="/auth/microsoft-start.php" class="lt-login-button">
                            Sign in with Microsoft
                        </a>
                    </div>

                    <div class="lt-login-note">
                        <h3>Using a Group calendar link?</h3>
                        <p>
                            Open the link provided by your Group. A Group calendar link gives access to that Group’s calendar area only and does not sign you in as a named person.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <p class="lt-login-meta"><?= e($appName) ?></p>
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