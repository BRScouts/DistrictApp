<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
unset($_SESSION['dc_group_link']);
redirect('/login.php');
