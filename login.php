<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (is_logged_in()) {
    redirect('/index.php');
}

$appName = app_config('APP_NAME', 'Irwell Valley District Scouts');

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($appName) ?> | Sign in</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Scoutstrap CSS -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css"
          crossorigin="anonymous">

    <!-- Scoutstrap font -->
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,600&display=swap"
          rel="stylesheet">

    <style>
        :root {
            --scout-purple: #7413dc;
            --scout-purple-dark: #4d0099;
            --scout-teal: #00a794;
            --scout-blue: #006ddf;
            --text-dark: #111111;
            --text-muted: #686868;
            --white: #ffffff;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            font-family: 'Nunito Sans', sans-serif;
            background:
                linear-gradient(
                    90deg,
                    rgba(25, 0, 55, 0.88) 0%,
                    rgba(55, 0, 105, 0.72) 42%,
                    rgba(0, 0, 0, 0.28) 100%
                ),
                url('/assets/img/cub-on-raft-jpg.jpg') center center / cover no-repeat;
            color: var(--text-dark);
        }

        .login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 2rem 0;
        }

        .login-shell {
            width: 100%;
        }

        .login-card {
            border: 0;
            border-radius: 1.25rem;
            overflow: hidden;
            box-shadow: 0 1.5rem 4rem rgba(0, 0, 0, 0.28);
        }

        .brand-panel {
            background: var(--scout-purple);
            color: var(--white);
            position: relative;
            overflow: hidden;
        }

        .brand-panel::after {
            content: "Skills for life";
            position: absolute;
            right: -1.25rem;
            bottom: 1rem;
            color: rgba(255, 255, 255, 0.09);
            font-size: 3.4rem;
            font-weight: 900;
            line-height: 0.9;
            transform: rotate(-6deg);
            pointer-events: none;
        }

        .brand-kicker {
            display: inline-flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.22);
            border-radius: 999px;
            padding: 0.35rem 0.75rem;
            font-size: 0.78rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 1.25rem;
        }

        .brand-kicker::before {
            content: "";
            width: 0.55rem;
            height: 0.55rem;
            border-radius: 999px;
            background: var(--scout-teal);
            margin-right: 0.5rem;
        }

        .brand-panel h1 {
            font-weight: 900;
            letter-spacing: -0.045em;
            line-height: 1;
        }

        .brand-panel p {
            font-size: 1.02rem;
            line-height: 1.5;
        }

        .sign-in-panel {
            background: rgba(255, 255, 255, 0.98);
        }

        .option-card {
            border: 1px solid #e5e5e5;
            border-radius: 1rem;
            padding: 1.25rem;
            background: #ffffff;
        }

        .option-card.primary-option {
            border-color: #d8c4ff;
            background: #fbf8ff;
        }

        .option-label {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--scout-purple);
            margin-bottom: 0.5rem;
        }

        .option-title {
            font-size: 1.15rem;
            font-weight: 900;
            margin-bottom: 0.4rem;
            letter-spacing: -0.02em;
        }

        .option-text {
            color: var(--text-muted);
            line-height: 1.45;
            margin-bottom: 1rem;
        }

        .microsoft-button {
            font-weight: 900;
            border-radius: 999px;
            padding: 0.85rem 1.2rem;
        }

        .helper-note {
            background: #f2f7ff;
            border-left: 4px solid var(--scout-blue);
            border-radius: 0.65rem;
            padding: 0.9rem 1rem;
            color: #27415f;
            font-size: 0.92rem;
            line-height: 1.45;
        }

        .group-link-note {
            background: #f7f7f7;
            border-radius: 0.65rem;
            padding: 0.9rem 1rem;
            color: var(--text-muted);
            font-size: 0.92rem;
            line-height: 1.45;
        }

        .footer-note {
            color: rgba(255, 255, 255, 0.86);
            font-weight: 700;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.35);
        }

        @media (max-width: 767.98px) {
            body {
                background:
                    linear-gradient(
                        180deg,
                        rgba(25, 0, 55, 0.9) 0%,
                        rgba(55, 0, 105, 0.76) 55%,
                        rgba(0, 0, 0, 0.45) 100%
                    ),
                    url('/assets/img/cub-on-raft-jpg.jpg') center center / cover no-repeat;
            }

            .login-page {
                align-items: flex-start;
                padding: 1.25rem 0;
            }

            .brand-panel::after {
                font-size: 2.2rem;
            }
        }
    </style>
</head>
<body>

<main class="login-page">
    <div class="container login-shell">
        <div class="row justify-content-center">
            <div class="col-lg-10 col-xl-9">
                <div class="card login-card">
                    <div class="row no-gutters">
                        <div class="col-md-5 brand-panel p-5 d-flex flex-column justify-content-center">
                            <div class="brand-kicker">Irwell Valley Scouts</div>

                            <h1 class="display-4 mb-3">
                                District Dashboard
                            </h1>

                            <p class="mb-0">
                                Access District tools, directory information, calendar features and shared services in one place.
                            </p>
                        </div>

                        <div class="col-md-7 sign-in-panel p-4 p-md-5">
                            <h2 class="h3 font-weight-bold mb-2">Sign in</h2>

                            <p class="text-muted mb-4">
                                Choose the access method that applies to you.
                            </p>

                            <div class="option-card primary-option mb-3">
                                <span class="option-label">Recommended</span>

                                <h3 class="option-title">
                                    Sign in with your Irwell Valley Scouts Microsoft Email account
                                </h3>

                                <p class="option-text">
                                    Use this option for more functionality, including your dashboard, profile, directory features and future District tools.
                                </p>

                                <a href="/auth/microsoft-start.php"
                                   class="btn btn-primary btn-lg btn-block microsoft-button">
                                    Sign in with Microsoft
                                </a>
                            </div>

                            <div class="helper-note mb-3">
                                <strong>Not sure what your Irwell Valley Scouts Microsoft Email account is?</strong>
                                Please contact your Group Lead Volunteer.
                            </div>

                            <div class="helper-note mb-3">
                                <strong>Already signed into a work or school Microsoft account?</strong>
                                We recommend using another browser or an incognito/private browser window before signing in.
                            </div>

                            <div class="option-card">
                                <span class="option-label">Group link access</span>

                                <h3 class="option-title">
                                    Use the link provided by your Group
                                </h3>

                                <p class="option-text mb-0">
                                    If you have been given a District Calendar group link, you can continue to use that link directly. This gives access to the relevant group calendar area only.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <p class="text-center small footer-note mt-4 mb-0">
                    <?= e($appName) ?>
                </p>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"
        crossorigin="anonymous"></script>

<script src="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/js/bootstrap.min.js"
        crossorigin="anonymous"></script>

</body>
</html>