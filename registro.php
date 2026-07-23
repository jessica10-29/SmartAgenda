<?php
require_once __DIR__ . '/conexion.php';

if (!empty($_SESSION['id'])) {
    redirect('dashboard.php');
}

$lang = current_language();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $nombre = input('nombre');
    $correo = strtolower(input('correo'));
    $telefono = input('telefono');
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if (mb_strlen($nombre) < 3 || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = $lang === 'en' ? 'Please enter your name and a valid email.' : 'Escribe tu nombre y un correo válido.';
    } elseif ($password !== $passwordConfirm) {
        $error = $lang === 'en' ? 'The passwords do not match.' : 'Las contraseñas no coinciden.';
    } else {
        $strengthErrors = validate_password_strength($password);
        if ($strengthErrors) {
            $error = password_policy_message($lang);
        } else {
            try {
                $stmt = db()->prepare('INSERT INTO usuarios (nombre, correo, telefono, password) VALUES (?, ?, ?, ?)');
                $stmt->execute([$nombre, $correo, $telefono ?: null, password_hash($password, PASSWORD_DEFAULT)]);
                $userId = (int) db()->lastInsertId();
                db()->prepare('INSERT INTO agenda_config (usuario_id) VALUES (?)')->execute([$userId]);
                flash('success', $lang === 'en' ? 'Account created. You can sign in now.' : 'Cuenta creada. Ya puedes ingresar.');
                redirect('login.php');
            } catch (PDOException $exception) {
                $error = $exception->getCode() === '23000'
                    ? ($lang === 'en' ? 'That email is already registered.' : 'Ese correo ya está registrado.')
                    : ($lang === 'en' ? 'The account could not be created. Check the database.' : 'No se pudo crear la cuenta. Revisa la base de datos.');
            }
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(translate('auth.title_register')) ?></title>
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
            <div class="auth-topbar"><span class="eyebrow"><?= e(translate('auth.first_step')) ?></span><select class="lang-select" onchange="const url=new URL(window.location.href); url.searchParams.set('lang', this.value); window.location.href=url.toString();"><option value="es" <?= $lang === 'es' ? 'selected' : '' ?>>Español</option><option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option></select></div>
            <h2><?= e(translate('auth.create_account')) ?></h2>
            <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="stack-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <label><?= e(translate('auth.full_name')) ?><input name="nombre" autocomplete="name" placeholder="Ej. Ana Gómez" required></label>
                <label><?= e(translate('auth.email')) ?><input type="email" name="correo" autocomplete="email" placeholder="tu@correo.com" required></label>
                <label><?= e(translate('auth.phone')) ?> <span class="optional"><?= e(translate('auth.optional')) ?></span><input name="telefono" autocomplete="tel" placeholder="+57 300 000 0000"></label>
                <label><?= e(translate('auth.password')) ?><input type="password" name="password" autocomplete="new-password" placeholder="Mínimo 12 caracteres" required></label>
                <label><?= e(translate('auth.password_confirm')) ?><input type="password" name="password_confirm" autocomplete="new-password" placeholder="Repite la contraseña" required></label>
                <p class="form-hint"><?= e(password_policy_message($lang)) ?></p>
                <button class="btn btn-primary btn-block" type="submit"><?= e(translate('auth.create_button')) ?> <span>→</span></button>
            </form>
            <p class="auth-footer"><?= e(translate('auth.has_account')) ?> <a href="login.php"><?= e(translate('auth.enter')) ?></a></p>
        </section>
    </main>
</body>
</html>
