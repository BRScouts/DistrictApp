<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (is_logged_in()) {
    redirect(user_needs_group_onboarding() ? '/onboarding.php' : '/index.php');
}

$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');
$authError = $_SESSION['auth_error'] ?? '';

unset($_SESSION['auth_error']);
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
        html {
            min-height: 100%;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background: var(--iv-grey-100);
            font-family: "Nunito Sans", system-ui, -apple-system, sans-serif;
            color: var(--iv-black);
        }

        .lt-login-header {
            background: var(--iv-purple);
            padding: 1rem;
        }

        .lt-login-header-inner {
            max-width: 960px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .lt-login-header img {
            height: 52px;
            width: auto;
        }

        .lt-login-header-text {
            color: #fff;
            font-weight: 900;
            font-size: 1.1rem;
            line-height: 1.15;
        }

        .lt-login-header-text span {
            display: block;
            font-size: .88rem;
            font-weight: 700;
            opacity: .85;
            margin-top: .1rem;
        }

        .lt-login-main {
            max-width: 580px;
            margin: 0 auto;
            padding: 2.5rem 1rem 4rem;
        }

        .lt-login-card {
            background: #fff;
            border-top: 8px solid var(--iv-purple);
            padding: 2rem;
        }

        .lt-login-card h1 {
            font-size: 2rem;
            font-weight: 900;
            letter-spacing: -.03em;
            line-height: 1.05;
            margin: 0 0 .75rem;
            color: var(--iv-black);
        }

        .lt-login-card .lt-login-lede {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--iv-grey-700);
            margin: 0 0 1.5rem;
            max-width: 480px;
        }

        .lt-login-error {
            padding: 1rem;
            border: 3px solid #d4351c;
            border-left-width: 5px;
            margin-bottom: 1.5rem;
            background: #fff;
        }

        .lt-login-error h2 {
            margin: 0 0 .35rem;
            font-size: 1.1rem;
            font-weight: 900;
            color: #d4351c;
        }

        .lt-login-error p {
            margin: 0;
            font-weight: 700;
        }

        .lt-login-button {
            display: inline-block;
            padding: .9rem 1.5rem;
            background: var(--iv-purple);
            color: #fff;
            font-weight: 900;
            font-size: 1.05rem;
            text-decoration: none;
            border: 0;
            cursor: pointer;
            box-shadow: 0 3px 0 var(--iv-purple-dark);
        }

        .lt-login-button:hover,
        .lt-login-button:focus {
            background: var(--iv-purple-dark);
            color: #fff;
            text-decoration: none;
        }

        .lt-login-password-link {
            display: block;
            margin-top: 1.25rem;
            font-weight: 900;
            font-size: .95rem;
        }

        .lt-login-divider {
            border: 0;
            border-top: 1px solid var(--iv-grey-300);
            margin: 2rem 0;
        }

        .lt-login-alt h2 {
            font-size: 1.1rem;
            font-weight: 900;
            margin: 0 0 .5rem;
        }

        .lt-login-alt p {
            margin: 0;
            font-size: .95rem;
            font-weight: 700;
            color: var(--iv-grey-700);
        }

        .lt-login-footer {
            max-width: 580px;
            margin: 0 auto;
            padding: 0 1rem 2rem;
            text-align: center;
            font-size: .88rem;
            font-weight: 800;
            color: var(--iv-grey-700);
        }

        .lt-login-footer a {
            color: var(--iv-grey-700);
            font-weight: 900;
        }

        @media (max-width: 575.98px) {
            .lt-login-main {
                padding: 1.5rem .75rem 3rem;
            }

            .lt-login-card {
                padding: 1.5rem;
            }

            .lt-login-card h1 {
                font-size: 1.65rem;
            }

            .lt-login-button {
                display: block;
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>

<body>
    <header class="lt-login-header">
        <div class="lt-login-header-inner">
            <img
                src="/assets/img/white-ir-logo.png"
                alt=""
                onerror="this.style.display='none';"
            >
            <div class="lt-login-header-text">
                <?= e($appName) ?>
                <span>Irwell Valley Scout District</span>
            </div>
        </div>
    </header>

    <main class="lt-login-main">
        <div class="lt-login-card">
            <h1>Sign in</h1>
            <p class="lt-login-lede">
                Use your District Microsoft 365 account to access the Leader Tool.
            </p>

            <?php if ($authError !== ''): ?>
                <div class="lt-login-error" role="alert">
                    <h2>There is a problem</h2>
                    <p><?= e($authError) ?></p>
                </div>
            <?php endif; ?>

            <a href="/auth/microsoft-start.php" class="lt-login-button">
                Sign in with Microsoft
            </a>

            <a class="lt-login-password-link" href="https://passwordreset.microsoftonline.com/" target="_blank" rel="noopener noreferrer">
                Forgot your District email password?
            </a>

            <hr class="lt-login-divider">

            <div class="lt-login-alt">
                <h2>Using a Group calendar link?</h2>
                <p>
                    If your Group Lead Volunteer gave you a calendar link, open that link directly. You do not need to sign in here.
                </p>
            </div>
        </div>
    </main>

    <footer class="lt-login-footer">
        <p>&copy; <?= e(date('Y')) ?> Irwell Valley Scout District. Built by <a href="https://www.ckenterprises.co.uk" target="_blank" rel="noopener noreferrer">CK Enterprises UK</a></p>
        <p><a href="/privacy-notice.php">Privacy Notice</a></p>
    </footer>
</body>
</html>
