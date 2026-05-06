<?php
require_once __DIR__ . '/bootstrap.php';
if (auth_user($pdo)) {
    redirect('/home');
} else {
    redirect('/login');
}
