<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();
csrf_verify_or_die();

logout_user();
redirect('login.php');
