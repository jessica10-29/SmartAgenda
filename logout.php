<?php
require_once __DIR__ . '/conexion.php';

if (!empty($_SESSION['id'])) {
    audit((int) $_SESSION['id'], 'Cierre de sesión');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
}
session_destroy();
redirect('login.php');
