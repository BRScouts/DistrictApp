<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley District Scouts');

if (($user['role'] ?? '') === ROLE_ADMIN) {
    redirect('/index.php');
}

$pdo = db();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupId = (int) ($_POST['group_id'] ?? 0);

    if ($groupId <= 0) {
        $error = 'Please choose your group.';
    } else {
        $stmt = $pdo->prepare("
            SELECT id
            FROM groups
            WHERE id = :id
              AND is_active = 1
            LIMIT 1
        ");

        $stmt->execute([
            'id' => $groupId,
        ]);

        if (!$stmt->fetchColumn()) {
            $error = 'Please choose a valid group.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO user_groups (
                    user_id,
                    group_id,
                    source,
                    status,
                    is_primary,
                    created_at
                ) VALUES (
                    :user_id,
                    :group_id,
                    'self_selected',
                    'pending',
                    1,
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    is_primary = VALUES(is_primary),
                    source = VALUES(source)
            ");

            $stmt->execute([
                'user_id' => (int) $user['id'],
                'group_id' => $groupId,
            ]);

            redirect('/index.php');
        }
    }
}

$stmt = $pdo->query("
    SELECT id, group_name
    FROM groups
    WHERE is_active = 1
    ORDER BY group_name ASC
");

$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Choose your group | <?= e($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css"
          crossorigin="anonymous">

    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,600&display=swap"
          rel="stylesheet">

    <style>
        body {
            min-height: 100vh;
            background: #f5f5f5;
        }

        .onboarding-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
        }

        .onboarding-card {
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
    </style>
</head>
<body>

<main class="onboarding-wrapper">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9 col-xl-8">
                <div class="card onboarding-card overflow-hidden">
                    <div class="row no-gutters">
                        <div class="col-md-5 brand-panel p-5 d-flex flex-column justify-content-center">
                            <h1 class="h2 mb-3">Account setup</h1>
                            <p class="mb-0">
                                Tell us which Group you are linked to so we can personalise your District tools.
                            </p>
                        </div>

                        <div class="col-md-7 bg-white p-5">
                            <h2 class="h4 mb-3">Choose your Group</h2>

                            <p class="text-muted">
                                Signed in as <strong><?= e($user['email']) ?></strong>.
                            </p>

                            <?php if ($error): ?>
                                <div class="alert alert-danger">
                                    <?= e($error) ?>
                                </div>
                            <?php endif; ?>

                            <form method="post">
                                <div class="form-group">
                                    <label for="group_id">Group</label>
                                    <select class="form-control" id="group_id" name="group_id" required>
                                        <option value="">Select your group</option>

                                        <?php foreach ($groups as $group): ?>
                                            <option value="<?= (int) $group['id'] ?>">
                                                <?= e($group['group_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-primary btn-lg btn-block">
                                    Continue
                                </button>
                            </form>

                            <hr class="my-4">

                            <p class="small text-muted mb-0">
                                If your Group is missing or incorrect contact the District team.
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