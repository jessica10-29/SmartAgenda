<?php
require_once __DIR__ . '/conexion.php';

if (!empty($_SESSION['id'])) {
    redirect('dashboard.php');
}

$error = '';
$notice = take_flash();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $correo = strtolower(input('correo'));
    $password = (string) ($_POST['password'] ?? '');

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Escribe un correo válido y tu contraseña.';
    } else {
        $stmt = db()->prepare('SELECT id, nombre, correo, password FROM usuarios WHERE correo = ? LIMIT 1');
        $stmt->execute([$correo]);
        $usuario = $stmt->fetch();

        if ($usuario && password_verify($password, $usuario['password'])) {
            session_regenerate_id(true);
            $_SESSION['id'] = (int) $usuario['id'];
            $_SESSION['usuario'] = $usuario['nombre'];
            $_SESSION['correo'] = $usuario['correo'];
            audit((int) $usuario['id'], 'Inicio de sesión');
            redirect('dashboard.php');
        }
        $error = 'El correo o la contraseña no son correctos.';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar · SmartAgenda</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-brand">
            <span class="brand-mark">SA</span>
            <p class="eyebrow">Agenda personal y de trabajo</p>
            <h1>Todo lo importante,<br><em>en un solo lugar.</em></h1>
            <p class="muted-on-dark">Eventos, documentos, contactos y recordatorios organizados con privacidad.</p>
            <div class="brand-points"><span>✓</span> Datos separados por usuario</div>
            <div class="brand-points"><span>✓</span> Exportación para respaldo</div>
            <div class="brand-points"><span>✓</span> Diseño para móvil y escritorio</div>
        </section>
        <section class="auth-card">
            <div class="mobile-brand"><span class="brand-mark">SA</span><strong>SmartAgenda</strong></div>
            <p class="eyebrow">Bienvenido de nuevo</p>
            <h2>Iniciar sesión</h2>
            <?php if ($notice): ?><div class="alert alert-<?= e($notice['type']) ?>"><?= e($notice['message']) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="stack-form" novalidate>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <label>Correo electrónico<input type="email" name="correo" autocomplete="email" placeholder="tu@correo.com" required></label>
                <label>Contraseña<input type="password" name="password" autocomplete="current-password" placeholder="Tu contraseña" required></label>
                <button class="btn btn-primary btn-block" type="submit">Entrar a mi agenda <span>→</span></button>
            </form>
            <p class="auth-footer">¿Aún no tienes cuenta? <a href="registro.php">Crear cuenta gratis</a></p>
        </section>
    </main>
</body>
</html>
