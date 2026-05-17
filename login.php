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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css" integrity="sha384-5Kguc7IDQdynmm22yUyn9psYyP8LQhAWCCKJT/RrZJAWqdUAw5eADwc25JoYsXH6" crossorigin="anonymous">
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/leader-tool.css">
</head>
<body class="lt-login-bg">
<main class="lt-login-wrap">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10 col-xl-9">
                <div class="lt-login-card">
                    <div class="row no-gutters">
                        <div class="col-md-5 lt-login-brand">
                            <img src="/assets/img/white-ir-logo.png" alt="Irwell Valley District Scouts" style="max-width: 190px; height: auto;" class="mb-4" onerror="this.style.display='none';">
                            <p class="font-weight-bold text-uppercase mb-2">Leader Tool</p>
                            <h1>Sign in to the District Dashboard</h1>
                            <p class="mt-3 mb-0 font-weight-bold">A simple place for leaders to manage district tools, directory details and group information.</p>
                        </div>
                        <div class="col-md-7 lt-login-panel">
                            <h2 class="lt-page-title mb-3">Sign in</h2>
                            <p class="lt-lede mb-4">Use your Irwell Valley Scouts Microsoft account. New or unmapped users from the tenant can complete onboarding and add themselves to their Group.</p>

                            <div class="lt-panel mb-4">
                                <h3 class="lt-section-title">Microsoft account</h3>
                                <p class="mb-3 font-weight-bold">This gives access to your profile, the directory and any Group-level tools connected to your account.</p>
                                <a href="/auth/microsoft-start.php" class="btn btn-primary btn-lg btn-block lt-btn">Sign in with Microsoft</a>
                            </div>

                            <div class="lt-panel-grey">
                                <h3 class="h5 font-weight-bold">Using a Group calendar link?</h3>
                                <p class="mb-0">Open the link provided by your Group. It only gives access to that Group's calendar form and does not sign you in as a named person.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="text-center mt-4 mb-0 text-white font-weight-bold"><?= e($appName) ?></p>
            </div>
        </div>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js" integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/js/bootstrap.min.js" integrity="sha384-vZA7fWbUdVwzQZlO+dkC65mKiaTlKyDvRFeWWT/+J8nBCX0A/OJE2YaFG+m4Zhv0" crossorigin="anonymous"></script>
</body>
</html>
