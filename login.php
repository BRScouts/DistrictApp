<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (is_logged_in()) {
    redirect('/index.php');
}

$appName = app_config('APP_NAME', 'District Portal');

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
        body {
            min-height: 100vh;
            background: #f5f5f5;
        }

        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
        }

        .login-card {
            border: 0;
            border-radius: 1rem;
            box-shadow: 0 1rem 2rem rgba(0, 0, 0, 0.08);
        }

        .brand-panel {
            background: #7413dc;
            color: #ffffff;
            border-radius: 1rem 1rem 0 0;
        }

        @media (min-width: 768px) {
            .brand-panel {
                border-radius: 1rem 0 0 1rem;
            }
        }

        .microsoft-button {
            font-weight: 700;
        }
    </style>
</head>
<body>

<main class="login-wrapper">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9 col-xl-8">
                <div class="card login-card overflow-hidden">
                    <div class="row no-gutters">
                        <div class="col-md-5 brand-panel p-5 d-flex flex-column justify-content-center">
                            <h1 class="h2 mb-3"><?= e($appName) ?></h1>
                            <p class="mb-0">
                                Sign in with your Scouts Microsoft account to access District tools.
                            </p>
                        </div>

                        <div class="col-md-7 bg-white p-5">
                            <h2 class="h4 mb-3">Sign in</h2>

                            <p class="text-muted">
                                Use your Microsoft account to continue.
                            </p>

                            <a href="/auth/microsoft-start.php"
                               class="btn btn-primary btn-lg btn-block microsoft-button mt-4">
                                Sign in with Microsoft
                            </a>

                            <hr class="my-4">

                            <p class="small text-muted mb-0">
                                If you only have a District Calendar group link, continue using that link directly.
                            </p>
                        </div>
                    </div>
                </div>

                <p class="text-center small text-muted mt-4 mb-0">
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