<?php
require_once __DIR__ . '/conexion.php';

if (!empty($_SESSION['id'])) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $nombre = input('nombre');
    $correo = strtolower(input('correo'));
    $telefono = input('telefono');
    $password = (string) ($_POST['password'] ?? '');

    if (mb_strlen($nombre) < 3 || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'Escribe tu nombre y un correo válido.';
    } elseif (mb_strlen($password) < 8) {
        $error = 'La contraseña debe tener mínimo 8 caracteres.';
    } else {
        try {
            $stmt = db()->prepare('INSERT INTO usuarios (nombre, correo, telefono, password) VALUES (?, ?, ?, ?)');
            $stmt->execute([$nombre, $correo, $telefono ?: null, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) db()->lastInsertId();
            db()->prepare('INSERT INTO agenda_config (usuario_id) VALUES (?)')->execute([$userId]);
            flash('success', 'Cuenta creada. Ya puedes ingresar.');
            redirect('login.php');
        } catch (PDOException $exception) {
            $error = $exception->getCode() === '23000'
                ? 'Ese correo ya está registrado.'
                : 'No se pudo crear la cuenta. Revisa la base de datos.';
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Crear cuenta · SmartAgenda</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-brand">
            <span class="brand-mark">SA</span>
            <p class="eyebrow">Comienza hoy</p>
            <h1>Tu tiempo merece<br><em>más claridad.</em></h1>
            <p class="muted-on-dark">Crea un espacio seguro para planear, guardar y encontrar lo que necesitas.</p>
        </section>
        <section class="auth-card">
            <div class="mobile-brand"><span class="brand-mark">SA</span><strong>SmartAgenda</strong></div>
            <p class="eyebrow">Primer paso</p>
            <h2>Crear cuenta</h2>
            <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="stack-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <label>Nombre completo<input name="nombre" autocomplete="name" placeholder="Ej. Ana Gómez" required></label>
                <label>Correo electrónico<input type="email" name="correo" autocomplete="email" placeholder="tu@correo.com" required></label>
                <label>Teléfono <span class="optional">opcional</span><input name="telefono" autocomplete="tel" placeholder="+57 300 000 0000"></label>
                <label>Contraseña<input type="password" name="password" minlength="8" autocomplete="new-password" placeholder="Mínimo 8 caracteres" required></label>
                <button class="btn btn-primary btn-block" type="submit">Crear mi agenda <span>→</span></button>
            </form>
            <p class="auth-footer">¿Ya tienes cuenta? <a href="login.php">Ingresar</a></p>
        </section>
    </main>
</body>
</html>
