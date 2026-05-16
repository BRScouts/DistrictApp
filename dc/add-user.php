<?php
declare(strict_types=1);

require_once __DIR__ . '/jbkjlbconfig.php';

/**
 * TEMPORARY USER CREATION SCRIPT
 *
 * Delete this file after use.
 *
 * Before using:
 * 1. Change TEMP_SETUP_KEY
 * 2. Optionally restrict by IP as well
 */

const TEMP_SETUP_KEY = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET';

$error = '';
$success = '';
$form = [
    'setup_key' => '',
    'full_name' => '',
    'email'     => '',
    'role'      => 'reviewer',
    'password'  => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['setup_key'] = trim((string)($_POST['setup_key'] ?? ''));
    $form['full_name'] = trim((string)($_POST['full_name'] ?? ''));
    $form['email']     = trim((string)($_POST['email'] ?? ''));
    $form['role']      = trim((string)($_POST['role'] ?? 'reviewer'));
    $form['password']  = (string)($_POST['password'] ?? '');

    if (!hash_equals(TEMP_SETUP_KEY, $form['setup_key'])) {
        $error = 'Invalid setup key.';
    } elseif ($form['full_name'] === '' || $form['email'] === '' || $form['password'] === '') {
        $error = 'Please complete all required fields.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!in_array($form['role'], ['admin', 'reviewer'], true)) {
        $error = 'Invalid role selected.';
    } elseif (strlen($form['password']) < 12) {
        $error = 'Password must be at least 12 characters long.';
    } else {
        $pdo = db();

        $check = $pdo->prepare("
            SELECT id
            FROM admin_users
            WHERE email = :email
            LIMIT 1
        ");
        $check->execute(['email' => $form['email']]);

        if ($check->fetch()) {
            $error = 'A user with that email already exists.';
        } else {
            $passwordHash = password_hash($form['password'], PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO admin_users (
                    full_name,
                    email,
                    password_hash,
                    role,
                    is_active,
                    created_at,
                    updated_at
                ) VALUES (
                    :full_name,
                    :email,
                    :password_hash,
                    :role,
                    1,
                    NOW(),
                    NOW()
                )
            ");

            $stmt->execute([
                'full_name'     => $form['full_name'],
                'email'         => $form['email'],
                'password_hash' => $passwordHash,
                'role'          => $form['role'],
            ]);

            $success = 'User created successfully.';
            $form = [
                'setup_key' => '',
                'full_name' => '',
                'email'     => '',
                'role'      => 'reviewer',
                'password'  => '',
            ];
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Create Admin User</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css"
          integrity="sha384-5Kguc7IDQdynmm22yUyn9psYyP8LQhAWCCKJT/RrZJAWqdUAw5eADwc25JoYsXH6"
          crossorigin="anonymous">

    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700;800&display=swap"
          rel="stylesheet">

    <style>
        body { font-family: 'Nunito Sans', sans-serif; }
    </style>
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h3 mb-3">Temporary User Creation</h1>
                    <p class="text-muted">
                        Create an admin or reviewer account, then delete this file from the server.
                    </p>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?= e($error) ?></div>
                    <?php endif; ?>

                    <?php if ($success !== ''): ?>
                        <div class="alert alert-success"><?= e($success) ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <div class="form-group">
                            <label for="setup_key">Setup key</label>
                            <input type="password" class="form-control" id="setup_key" name="setup_key" required>
                        </div>

                        <div class="form-group">
                            <label for="full_name">Full name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name"
                                   value="<?= e($form['full_name']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="email">Email address</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= e($form['email']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="role">Role</label>
                            <select class="form-control" id="role" name="role" required>
                                <option value="reviewer" <?= $form['role'] === 'reviewer' ? 'selected' : '' ?>>Reviewer</option>
                                <option value="admin" <?= $form['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="password">Temporary password</label>
                            <input type="text" class="form-control" id="password" name="password"
                                   value="<?= e($form['password']) ?>" required>
                            <small class="form-text text-muted">Use at least 12 characters.</small>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">Create user</button>
                    </form>

                    <hr>

                    <div class="small text-danger">
                        Delete this file after use.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"
        integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3V9zzTtmI3UksdQRVvoxMfooAo"
        crossorigin="anonymous"></script>

<script src="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/js/bootstrap.min.js"
        integrity="sha384-vZA7fWbUdVwzQZlO+dkC65mKiaTlKyDvRFeWWT/+J8nBCX0A/OJE2YaFG+m4Zhv0"
        crossorigin="anonymous"></script>
</body>
</html>