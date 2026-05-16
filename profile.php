<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley District Scouts');

$displayName = $user['full_name'] ?: $user['email'];
$initials = strtoupper(substr($displayName, 0, 1));

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>My Profile | <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css"
          crossorigin="anonymous">

    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,600&display=swap"
          rel="stylesheet">

    <style>
        body {
            background: #f5f5f5;
        }

        .avatar-lg {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            object-fit: cover;
            background: #7413dc;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 800;
        }

        .profile-card {
            border: 0;
            border-radius: 1rem;
            box-shadow: 0 1rem 2rem rgba(0, 0, 0, 0.08);
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <a class="navbar-brand" href="/index.php"><?= e($appName) ?></a>

    <div class="ml-auto">
        <a class="btn btn-outline-light btn-sm mr-2" href="/index.php">Dashboard</a>
        <a class="btn btn-outline-light btn-sm" href="/logout.php">Sign out</a>
    </div>
</nav>

<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card profile-card">
                <div class="card-body p-5">
                    <div class="d-flex align-items-center mb-4">
                        <img src="/auth/profile-photo.php"
                             alt=""
                             class="avatar-lg mr-4"
                             onerror="this.style.display='none'; document.getElementById('fallbackAvatar').style.display='inline-flex';">

                        <div id="fallbackAvatar" class="avatar-lg mr-4" style="display:none;">
                            <?= e($initials) ?>
                        </div>

                        <div>
                            <h1 class="h3 mb-1"><?= e($displayName) ?></h1>
                            <p class="text-muted mb-0"><?= e($user['email']) ?></p>
                        </div>
                    </div>

                    <hr>

                    <dl class="row mb-0">
                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8"><?= e($user['full_name']) ?></dd>

                        <dt class="col-sm-4">Email</dt>
                        <dd class="col-sm-8"><?= e($user['email']) ?></dd>

                        <dt class="col-sm-4">Role</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-primary"><?= e($user['role']) ?></span>
                        </dd>

                        <dt class="col-sm-4">Authentication</dt>
                        <dd class="col-sm-8"><?= e($user['auth_provider']) ?></dd>

                        <dt class="col-sm-4">Microsoft Object ID</dt>
                        <dd class="col-sm-8">
                            <code><?= e($user['microsoft_oid']) ?></code>
                        </dd>
                    </dl>
                </div>
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