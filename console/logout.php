<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
unset($_SESSION['admin'], $_SESSION['admin_last_rotate'], $_SESSION['csrf_token']);
session_destroy();
redirect('/console/login');
