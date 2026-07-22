<?php
require_once __DIR__ . '/conexion.php';

if (!empty($_SESSION['id'])) {
    redirect('dashboard.php');
}
redirect('login.php');
