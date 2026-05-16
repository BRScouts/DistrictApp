<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

$user = current_user();
$appName = app_config('APP_NAME', 'District Portal');

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Scoutstrap CSS -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css"
          crossorigin="anonymous">

    <!-- Scoutstrap font -->
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,600&display=swap"
          rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <a class="navbar-brand" href="/index.php"><?= e($appName) ?></a>

    <div class="ml-auto">
        <a class="btn btn-outline-light btn-sm" href="/logout.php">Sign out</a>
    </div>
</nav>

<main class="container py-5">
    <div class="jumbotron bg-white shadow-sm">
        <h1 class="display-4">
            Welcome, <?= e($user['full_name'] ?: $user['email']) ?>
        </h1>

        <p class="lead">
            You are signed in with your Microsoft account.
        </p>

        <hr class="my-4">

        <dl class="row">
            <dt class="col-sm-3">Name</dt>
            <dd class="col-sm-9"><?= e($user['full_name']) ?></dd>

            <dt class="col-sm-3">Email</dt>
            <dd class="col-sm-9"><?= e($user['email']) ?></dd>

            <dt class="col-sm-3">Role</dt>
            <dd class="col-sm-9"><?= e($user['role']) ?></dd>

            <dt class="col-sm-3">Provider</dt>
            <dd class="col-sm-9"><?= e($user['auth_provider']) ?></dd>
        </dl>

        <a class="btn btn-primary btn-lg" href="/DC/">
            Open District Calendar
        </a>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"
        crossorigin="anonymous"></script>

<script src="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/js/bootstrap.min.js"
        crossorigin="anonymous"></script>

</body>
</html>