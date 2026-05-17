<?php

declare(strict_types=1);

$ctx = dc_context(false);
$pageTitle = $pageTitle ?? 'District Calendar';
$heroTitle = $heroTitle ?? $pageTitle;
$heroText = $heroText ?? null;
$active = $active ?? '';
$groups = $ctx['groups'] ?? [];
$displayName = $ctx['name'] ?? 'Group access';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($pageTitle) ?> | Irwell Valley Leader Tool</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/css/scoutstrap.min.css" integrity="sha384-5Kguc7IDQdynmm22yUyn9psYyP8LQhAWCCKJT/RrZJAWqdUAw5eADwc25JoYsXH6" crossorigin="anonymous">
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,400;1,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/leader-tool.css">
</head>
<body>
<header class="lt-header">
    <div class="lt-header-inner">
        <a class="lt-brand" href="/dc/">
            <img src="/assets/img/black-ir-logo.png" alt="Irwell Valley District Scouts" onerror="this.style.display='none';">
            <span>
                <span class="lt-brand-title">District Calendar</span>
                <span class="lt-brand-subtitle">Irwell Valley Leader Tool</span>
            </span>
        </a>
        <button class="lt-menu-toggle" type="button" data-menu-toggle aria-expanded="false" aria-controls="dc-main-nav">Menu</button>
        <nav id="dc-main-nav" class="lt-nav dc-nav" aria-label="District Calendar navigation">
            <a class="<?= $active === 'home' ? 'active' : '' ?>" href="/dc/">Calendar</a>
            <a class="<?= $active === 'add' ? 'active' : '' ?>" href="/dc/add-event.php">Add event</a>
            <a class="<?= $active === 'risk' ? 'active' : '' ?>" href="/dc/risk-assessments.php">Risk assessments</a>
            <a class="<?= $active === 'map' ? 'active' : '' ?>" href="/dc/map.php">Map</a>
            <?php if (($ctx['is_reviewer'] ?? false)): ?>
                <a class="<?= $active === 'review' ? 'active' : '' ?>" href="/dc/reviewer/">Review</a>
            <?php endif; ?>
            <?php if (($ctx['is_signed_in'] ?? false)): ?>
                <a href="/index.php">Leader Tool</a>
            <?php else: ?>
                <a href="/dc/logout.php">Leave group access</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<section class="lt-hero dc-hero">
    <div class="lt-hero-inner">
        <h1><?= e($heroTitle) ?></h1>
        <?php if ($heroText): ?><p><?= e($heroText) ?></p><?php endif; ?>
    </div>
</section>

<div class="lt-breadcrumb">
    <div class="lt-breadcrumb-inner">
        <a href="/dc/">District Calendar</a>
        <?php if ($pageTitle !== 'District Calendar'): ?> <span aria-hidden="true">›</span> <?= e($pageTitle) ?><?php endif; ?>
        <span class="dc-context-label"><?= e($displayName) ?></span>
    </div>
</div>

<main class="lt-main dc-main">
