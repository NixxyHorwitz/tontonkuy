<?php
// console/auth.php — admin auth middleware
declare(strict_types=1);
// Must be required AFTER bootstrap.php
require_once dirname(__DIR__) . '/bootstrap.php';

if (empty($_SESSION['admin'])) {
    $redir = urlencode($_SERVER['REQUEST_URI'] ?? '/console/');
    redirect("/console/login?next={$redir}");
}

// Rotate session every 30min
if (empty($_SESSION['admin_last_rotate']) || (time() - $_SESSION['admin_last_rotate']) > 1800) {
    session_regenerate_id(true);
    $_SESSION['admin_last_rotate'] = time();
}
