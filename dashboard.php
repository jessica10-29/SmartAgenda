<?php
require_once __DIR__ . '/conexion.php';
require_login();

$pdo = db();
$userId = auth_user_id();
$actionError = '';
$revealedSecret = null;
$duplicateInfo = null;
$revealedKeyId = (int) ($_SESSION['revealed_key_id'] ?? 0);

if (isset($_GET['export']) && $_GET['export'] === '1') {
    $events = $pdo->prepare('SELECT titulo, descripcion, fecha_inicio, fecha_fin, prioridad, estado, ubicacion, repeticion, recordatorio_minutos FROM agenda_eventos WHERE usuario_id = ? ORDER BY fecha_inicio');
    $events->execute([$userId]);
    $documents = $pdo->prepare('SELECT nombre, mime, tamano, categoria, notas, creado_en FROM agenda_documentos WHERE usuario_id = ? ORDER BY creado_en DESC');
    $documents->execute([$userId]);
    $contacts = $pdo->prepare('SELECT nombre, telefono, correo, tipo, notas, bloqueado FROM agenda_contactos WHERE usuario_id = ? ORDER BY nombre');
    $contacts->execute([$userId]);
    $export = [
        'aplicacion' => 'SmartAgenda',
        'exportado_en' => date('c'),
        'eventos' => $events->fetchAll(),
        'documentos' => $documents->fetchAll(),
        'contactos' => $contacts->fetchAll(),
        'nota' => 'Las claves no se exportan en texto plano. Descarga los documentos desde la agenda.',
    ];
    audit($userId, 'Exportación de información');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="smartagenda-respaldo-' . date('Y-m-d') . '.json"');
    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input('action');

    try {
        switch ($action) {
            case 'save_event':
                $eventId = (int) ($_POST['event_id'] ?? 0);
                $title = input('titulo');
                $start = database_datetime(input('fecha_inicio'));
                $end = database_datetime(input('fecha_fin'));
                if ($title === '' || !$start) {
                    throw new RuntimeException('El título y la fecha de inicio son obligatorios.');
                }
                $duplicateSql = 'SELECT id, titulo, fecha_inicio, ubicacion FROM agenda_eventos WHERE usuario_id = ? AND LOWER(TRIM(titulo)) = LOWER(TRIM(?)) AND fecha_inicio = ?';
                $duplicateParams = [$userId, $title, $start];
                if ($eventId) {
                    $duplicateSql .= ' AND id <> ?';
                    $duplicateParams[] = $eventId;
                }
                $duplicate = $pdo->prepare($duplicateSql);
                $duplicate->execute($duplicateParams);
                $duplicateInfo = $duplicate->fetch();
                if ($duplicateInfo) {
                    flash('warning', 'Ya existe “' . $duplicateInfo['titulo'] . '” en la agenda el ' . human_datetime($duplicateInfo['fecha_inicio']) . '. Revisa ese registro antes de crear otro.');
                } elseif ($eventId) {
                    $stmt = $pdo->prepare('UPDATE agenda_eventos SET titulo = ?, descripcion = ?, fecha_inicio = ?, fecha_fin = ?, prioridad = ?, estado = ?, ubicacion = ?, repeticion = ?, recordatorio_minutos = ? WHERE id = ? AND usuario_id = ?');
                    $stmt->execute([$title, input('descripcion') ?: null, $start, $end, input('prioridad', 'media'), input('estado', 'pendiente'), input('ubicacion') ?: null, input('repeticion', 'ninguna'), max(0, (int) ($_POST['recordatorio_minutos'] ?? 30)), $eventId, $userId]);
                    audit($userId, 'Actualizó un evento');
                    flash('success', 'Evento actualizado correctamente.');
                } else {
                    $stmt = $pdo->prepare('INSERT INTO agenda_eventos (usuario_id, titulo, descripcion, fecha_inicio, fecha_fin, prioridad, estado, ubicacion, repeticion, recordatorio_minutos) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$userId, $title, input('descripcion') ?: null, $start, $end, input('prioridad', 'media'), 'pendiente', input('ubicacion') ?: null, input('repeticion', 'ninguna'), max(0, (int) ($_POST['recordatorio_minutos'] ?? 30))]);
                    audit($userId, 'Creó un evento');
                    flash('success', 'Evento guardado. Te avisaremos según el recordatorio configurado.');
                }
                if (!$duplicateInfo) {
                    redirect('dashboard.php#agenda');
                }
                break;

            case 'toggle_event':
                $eventId = (int) ($_POST['event_id'] ?? 0);
                $stmt = $pdo->prepare("UPDATE agenda_eventos SET estado = IF(estado = 'completada', 'pendiente', 'completada') WHERE id = ? AND usuario_id = ?");
                $stmt->execute([$eventId, $userId]);
                flash('success', 'Estado del evento actualizado.');
                redirect('dashboard.php#agenda');
                break;

            case 'delete_event':
                if (($_POST['confirm_delete'] ?? '0') !== '1') {
                    throw new RuntimeException('Confirma la eliminación desde el botón de borrar.');
                }
                $stmt = $pdo->prepare('DELETE FROM agenda_eventos WHERE id = ? AND usuario_id = ?');
                $stmt->execute([(int) ($_POST['event_id'] ?? 0), $userId]);
                audit($userId, 'Eliminó un evento');
                flash('success', 'Evento eliminado.');
                redirect('dashboard.php#agenda');
                break;

            case 'upload_document':
                $file = $_FILES['archivo'] ?? null;
                if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Selecciona un archivo válido.');
                }
                if ($file['size'] > 10 * 1024 * 1024) {
                    throw new RuntimeException('El archivo supera el límite de 10 MB.');
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/plain', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                if (!in_array($mime, $allowed, true)) {
                    throw new RuntimeException('Tipo no permitido. Usa PDF, imagen, TXT o DOCX.');
                }
                $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                    throw new RuntimeException('No se pudo preparar la carpeta de archivos.');
                }
                $storedName = bin2hex(random_bytes(16)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '-', basename($file['name']));
                if (!move_uploaded_file($file['tmp_name'], $uploadDir . DIRECTORY_SEPARATOR . $storedName)) {
                    throw new RuntimeException('No se pudo guardar el archivo.');
                }
                $stmt = $pdo->prepare('INSERT INTO agenda_documentos (usuario_id, nombre, archivo, mime, tamano, categoria, notas) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$userId, input('nombre_documento') ?: basename($file['name']), $storedName, $mime, (int) $file['size'], input('categoria', 'documento'), input('notas') ?: null]);
                audit($userId, 'Guardó un documento');
                flash('success', 'Archivo guardado en tu agenda.');
                redirect('dashboard.php#documentos');
                break;

            case 'save_signed_document':
                $signedFile = $_FILES['archivo_firmado'] ?? null;
                if (!$signedFile || $signedFile['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('No se recibió el documento firmado.');
                }
                if ($signedFile['size'] > 15 * 1024 * 1024) {
                    throw new RuntimeException('El documento firmado supera el límite de 15 MB.');
                }
                $signedFinfo = new finfo(FILEINFO_MIME_TYPE);
                $signedMime = $signedFinfo->file($signedFile['tmp_name']);
                $signedAllowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
                if (!in_array($signedMime, $signedAllowed, true)) {
                    throw new RuntimeException('Solo puedes guardar como firmado un PDF o una imagen.');
                }
                $signedDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
                if (!is_dir($signedDir) && !mkdir($signedDir, 0755, true)) {
                    throw new RuntimeException('No se pudo preparar la carpeta de archivos.');
                }
                $signedName = bin2hex(random_bytes(16)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '-', basename($signedFile['name']));
                if (!move_uploaded_file($signedFile['tmp_name'], $signedDir . DIRECTORY_SEPARATOR . $signedName)) {
                    throw new RuntimeException('No se pudo guardar el documento firmado.');
                }
                $signedTitle = input('nombre_documento') ?: basename($signedFile['name']);
                $stmt = $pdo->prepare('INSERT INTO agenda_documentos (usuario_id, nombre, archivo, mime, tamano, categoria, notas) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$userId, $signedTitle, $signedName, $signedMime, (int) $signedFile['size'], 'firmado', 'Firma visual agregada desde SmartAgenda']);
                audit($userId, 'Guardó un documento firmado');
                if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true, 'message' => 'Documento firmado guardado en la agenda.']);
                    exit;
                }
                flash('success', 'Documento firmado guardado en la agenda.');
                redirect('dashboard.php#documentos');
                break;

            case 'delete_document':
                if (($_POST['confirm_delete'] ?? '0') !== '1') {
                    throw new RuntimeException('Confirma la eliminación antes de borrar el archivo.');
                }
                $stmt = $pdo->prepare('SELECT archivo FROM agenda_documentos WHERE id = ? AND usuario_id = ?');
                $stmt->execute([(int) ($_POST['document_id'] ?? 0), $userId]);
                $document = $stmt->fetch();
                if ($document) {
                    $path = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($document['archivo']);
                    if (is_file($path)) {
                        unlink($path);
                    }
                    $pdo->prepare('DELETE FROM agenda_documentos WHERE id = ? AND usuario_id = ?')->execute([(int) $_POST['document_id'], $userId]);
                    audit($userId, 'Eliminó un documento');
                }
                flash('success', 'Documento eliminado.');
                redirect('dashboard.php#documentos');
                break;

            case 'save_key':
                $service = input('servicio');
                $plain = (string) ($_POST['clave'] ?? '');
                if ($service === '' || strlen($plain) < 4) {
                    throw new RuntimeException('Indica el servicio y una clave de mínimo 4 caracteres.');
                }
                $encrypted = app_encrypt($plain, $userId);
                $stmt = $pdo->prepare('INSERT INTO agenda_claves (usuario_id, servicio, usuario, clave_cifrada, iv) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$userId, $service, input('usuario_clave') ?: null, $encrypted['cipher'], $encrypted['iv']]);
                audit($userId, 'Guardó una clave cifrada');
                flash('success', 'Clave guardada con cifrado AES-256.');
                redirect('dashboard.php#claves');
                break;

            case 'show_key':
                $stmt = $pdo->prepare('SELECT servicio, clave_cifrada, iv FROM agenda_claves WHERE id = ? AND usuario_id = ?');
                $stmt->execute([(int) ($_POST['key_id'] ?? 0), $userId]);
                $key = $stmt->fetch();
                if ($key) {
                    $_SESSION['revealed_key_id'] = (int) ($_POST['key_id'] ?? 0);
                    $revealedSecret = ['servicio' => $key['servicio'], 'value' => app_decrypt($key['clave_cifrada'], $key['iv'], $userId)];
                }
                break;

            case 'hide_key':
                unset($_SESSION['revealed_key_id']);
                $revealedSecret = null;
                break;

            case 'delete_key':
                if (($_POST['confirm_delete'] ?? '0') !== '1') {
                    throw new RuntimeException('Confirma la eliminación de la clave.');
                }
                $pdo->prepare('DELETE FROM agenda_claves WHERE id = ? AND usuario_id = ?')->execute([(int) ($_POST['key_id'] ?? 0), $userId]);
                audit($userId, 'Eliminó una clave');
                flash('success', 'Clave eliminada.');
                redirect('dashboard.php#claves');
                break;

            case 'save_contact':
                $name = input('nombre_contacto');
                if ($name === '') {
                    throw new RuntimeException('El nombre del contacto es obligatorio.');
                }
                $stmt = $pdo->prepare('INSERT INTO agenda_contactos (usuario_id, nombre, telefono, correo, tipo, notas, bloqueado) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$userId, $name, input('telefono_contacto') ?: null, input('correo_contacto') ?: null, input('tipo_contacto', 'contacto'), input('notas_contacto') ?: null, isset($_POST['bloqueado']) ? 1 : 0]);
                audit($userId, 'Guardó un contacto');
                flash('success', 'Contacto guardado.');
                redirect('dashboard.php#contactos');
                break;

            case 'toggle_contact':
                $pdo->prepare('UPDATE agenda_contactos SET bloqueado = IF(bloqueado = 1, 0, 1) WHERE id = ? AND usuario_id = ?')->execute([(int) ($_POST['contact_id'] ?? 0), $userId]);
                flash('success', 'Estado de bloqueo actualizado.');
                redirect('dashboard.php#contactos');
                break;

            case 'delete_contact':
                if (($_POST['confirm_delete'] ?? '0') !== '1') {
                    throw new RuntimeException('Confirma la eliminación del contacto.');
                }
                $pdo->prepare('DELETE FROM agenda_contactos WHERE id = ? AND usuario_id = ?')->execute([(int) ($_POST['contact_id'] ?? 0), $userId]);
                flash('success', 'Contacto eliminado.');
                redirect('dashboard.php#contactos');
                break;

            case 'change_password':
                $currentPassword = (string) ($_POST['current_password'] ?? '');
                $newPassword = (string) ($_POST['new_password'] ?? '');
                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
                $stmt = $pdo->prepare('SELECT password FROM usuarios WHERE id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $userRecord = $stmt->fetch();
                if (!$userRecord || !password_verify($currentPassword, $userRecord['password'])) {
                    throw new RuntimeException('La contraseña actual no coincide.');
                }
                if ($newPassword !== $confirmPassword) {
                    throw new RuntimeException('La nueva contraseña y la confirmación no coinciden.');
                }
                $strengthErrors = validate_password_strength($newPassword);
                if ($strengthErrors) {
                    throw new RuntimeException(password_policy_message(current_language()));
                }
                $pdo->prepare('UPDATE usuarios SET password = ? WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
                audit($userId, 'Cambió la contraseña');
                flash('success', 'Contraseña actualizada correctamente.');
                redirect('dashboard.php#herramientas');
                break;

            case 'save_settings':
                $backupEmail = input('correo_respaldo');
                if ($backupEmail !== '' && !filter_var($backupEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('El correo de respaldo no es válido.');
                }
                $stmt = $pdo->prepare('INSERT INTO agenda_config (usuario_id, correo_respaldo, notificaciones) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE correo_respaldo = VALUES(correo_respaldo), notificaciones = VALUES(notificaciones)');
                $stmt->execute([$userId, $backupEmail ?: null, isset($_POST['notificaciones']) ? 1 : 0]);
                flash('success', 'Preferencias guardadas.');
                redirect('dashboard.php#herramientas');
                break;
        }
    } catch (Throwable $exception) {
        if ($action === 'save_signed_document' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
            exit;
        }
        $actionError = $exception->getMessage();
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$like = '%' . $search . '%';
$eventQuery = 'SELECT * FROM agenda_eventos WHERE usuario_id = ?';
$eventParams = [$userId];
if ($search !== '') {
    $eventQuery .= ' AND (titulo LIKE ? OR descripcion LIKE ? OR ubicacion LIKE ?)';
    array_push($eventParams, $like, $like, $like);
}
$eventQuery .= ' ORDER BY fecha_inicio ASC LIMIT 60';
$eventStmt = $pdo->prepare($eventQuery);
$eventStmt->execute($eventParams);
$events = $eventStmt->fetchAll();

$documentQuery = 'SELECT * FROM agenda_documentos WHERE usuario_id = ?';
$documentParams = [$userId];
if ($search !== '') {
    $documentQuery .= ' AND (nombre LIKE ? OR notas LIKE ? OR categoria LIKE ?)';
    array_push($documentParams, $like, $like, $like);
}
$documentQuery .= ' ORDER BY creado_en DESC LIMIT 40';
$documentStmt = $pdo->prepare($documentQuery);
$documentStmt->execute($documentParams);
$documents = $documentStmt->fetchAll();

$contactStmt = $pdo->prepare('SELECT * FROM agenda_contactos WHERE usuario_id = ? ORDER BY bloqueado DESC, nombre ASC LIMIT 40');
$contactStmt->execute([$userId]);
$contacts = $contactStmt->fetchAll();
$keyStmt = $pdo->prepare('SELECT id, servicio, usuario, actualizado_en FROM agenda_claves WHERE usuario_id = ? ORDER BY servicio ASC');
$keyStmt->execute([$userId]);
$keys = $keyStmt->fetchAll();
$configStmt = $pdo->prepare('SELECT * FROM agenda_config WHERE usuario_id = ? LIMIT 1');
$configStmt->execute([$userId]);
$config = $configStmt->fetch() ?: ['correo_respaldo' => '', 'notificaciones' => 1];

if ($revealedKeyId > 0 && $revealedSecret === null) {
    $revealedStmt = $pdo->prepare('SELECT servicio, clave_cifrada, iv FROM agenda_claves WHERE id = ? AND usuario_id = ?');
    $revealedStmt->execute([$revealedKeyId, $userId]);
    $revealedKey = $revealedStmt->fetch();
    if ($revealedKey) {
        $revealedSecret = ['servicio' => $revealedKey['servicio'], 'value' => app_decrypt($revealedKey['clave_cifrada'], $revealedKey['iv'], $userId)];
    }
}

$countEventsStmt = $pdo->prepare('SELECT COUNT(*) FROM agenda_eventos WHERE usuario_id = ?');
$countEventsStmt->execute([$userId]);
$totalEvents = (int) $countEventsStmt->fetchColumn();
$todayStmt = $pdo->prepare('SELECT COUNT(*) FROM agenda_eventos WHERE usuario_id = ? AND DATE(fecha_inicio) = CURDATE() AND estado <> "completada"');
$todayStmt->execute([$userId]);
$todayEvents = (int) $todayStmt->fetchColumn();
$completedStmt = $pdo->prepare('SELECT COUNT(*) FROM agenda_eventos WHERE usuario_id = ? AND estado = "completada"');
$completedStmt->execute([$userId]);
$completedEvents = (int) $completedStmt->fetchColumn();
$docCountStmt = $pdo->prepare('SELECT COUNT(*) FROM agenda_documentos WHERE usuario_id = ?');
$docCountStmt->execute([$userId]);
$documentCount = (int) $docCountStmt->fetchColumn();
$upcomingStmt = $pdo->prepare('SELECT * FROM agenda_eventos WHERE usuario_id = ? AND estado <> "completada" AND fecha_inicio >= NOW() ORDER BY fecha_inicio ASC LIMIT 5');
$upcomingStmt->execute([$userId]);
$upcoming = $upcomingStmt->fetchAll();

$editingEvent = null;
if (!empty($_GET['edit'])) {
    $editStmt = $pdo->prepare('SELECT * FROM agenda_eventos WHERE id = ? AND usuario_id = ?');
    $editStmt->execute([(int) $_GET['edit'], $userId]);
    $editingEvent = $editStmt->fetch() ?: null;
}
$flashMessage = take_flash();
$todayLabel = (new DateTime())->format('l, d \d\e F');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101828">
    <title>Mi agenda · SmartAgenda</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="app-page">
<div class="app-layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand"><span class="brand-mark small">SA</span><span>SmartAgenda</span></div>
        <nav class="main-nav" aria-label="Navegación principal">
            <p class="nav-label">Espacio de trabajo</p>
            <a href="#resumen" class="nav-link active"><span>⌂</span> Resumen</a>
            <a href="#agenda" class="nav-link"><span>▦</span> Mi agenda <b><?= $totalEvents ?></b></a>
            <a href="#documentos" class="nav-link"><span>▤</span> Documentos <b><?= $documentCount ?></b></a>
            <a href="#claves" class="nav-link"><span>▣</span> Bóveda de claves</a>
            <a href="#contactos" class="nav-link"><span>♙</span> Contactos y bloqueos</a>
            <p class="nav-label nav-label-spaced">Herramientas</p>
            <a href="#documentos" class="nav-link"><span>✎</span> Firmar documentos</a>
            <a href="#herramientas" class="nav-link"><span>⌁</span> Centro de herramientas</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="privacy-mini"><span>◉</span><div><strong>Privacidad activa</strong><small>Tus datos son por usuario</small></div></div>
            <div class="user-mini"><span class="avatar"><?= e(mb_strtoupper(mb_substr($_SESSION['usuario'], 0, 1))) ?></span><div><strong><?= e($_SESSION['usuario']) ?></strong><small><?= e($_SESSION['correo'] ?? '') ?></small></div><a href="logout.php" title="Cerrar sesión">↪</a></div>
        </div>
    </aside>

    <div class="page-content">
        <header class="topbar">
            <button class="mobile-menu" id="mobileMenu" aria-label="Abrir menú">☰</button>
            <form class="search-form" method="get" role="search">
                <span>⌕</span><input name="q" value="<?= e($search) ?>" placeholder="Buscar en tu agenda..." autocomplete="off"><kbd>⌘ K</kbd>
            </form>
            <div class="topbar-actions"><select class="lang-select" onchange="const url=new URL(window.location.href); url.searchParams.set('lang', this.value); window.location.href=url.toString();"><option value="es" <?= current_language() === 'es' ? 'selected' : '' ?>>ES</option><option value="en" <?= current_language() === 'en' ? 'selected' : '' ?>>EN</option></select><button class="icon-button" id="notificationButton" title="Recordatorios">♢</button><span class="topbar-date"><?= e($todayLabel) ?></span><a class="btn btn-primary btn-compact" href="#agenda">+ Nuevo evento</a></div>
        </header>

        <main class="dashboard-main" id="resumen">
            <?php if ($flashMessage): ?><div class="alert alert-<?= e($flashMessage['type']) ?> dismissible"><?= e($flashMessage['message']) ?><button type="button" data-dismiss>×</button></div><?php endif; ?>
            <?php if ($actionError): ?><div class="alert alert-error dismissible"><?= e($actionError) ?><button type="button" data-dismiss>×</button></div><?php endif; ?>
            <section class="hero-row">
                <div><p class="eyebrow">Tu centro de control</p><h1>Hola, <?= e(explode(' ', trim($_SESSION['usuario']))[0]) ?> <span class="wave">✦</span></h1><p class="page-subtitle">Este es el pulso de tu día. Mantén todo importante cerca.</p></div>
                <div class="hero-date"><span class="calendar-icon">▣</span><div><strong><?= date('d') ?> <?= date('M') ?></strong><small>Hoy, <?= date('Y') ?></small></div></div>
            </section>

            <section class="stats-grid">
                <article class="stat-card"><div class="stat-icon blue">▦</div><div><small>Eventos totales</small><strong><?= $totalEvents ?></strong><span class="stat-caption">En tu agenda</span></div></article>
                <article class="stat-card"><div class="stat-icon orange">◷</div><div><small>Para hoy</small><strong><?= $todayEvents ?></strong><span class="stat-caption">Pendientes</span></div></article>
                <article class="stat-card"><div class="stat-icon green">✓</div><div><small>Completados</small><strong><?= $completedEvents ?></strong><span class="stat-caption">Tareas realizadas</span></div></article>
                <article class="stat-card"><div class="stat-icon violet">▤</div><div><small>Archivos</small><strong><?= $documentCount ?></strong><span class="stat-caption">Documentos guardados</span></div></article>
            </section>

            <section class="content-grid overview-grid">
                <article class="panel upcoming-panel"><div class="panel-heading"><div><p class="eyebrow">Próximos pasos</p><h2>Tu agenda</h2></div><a href="#agenda" class="text-link">Ver todo →</a></div>
                    <?php if (!$upcoming): ?><div class="empty-state compact"><span>☀</span><p>No tienes eventos próximos.</p><a href="#agenda">Planear algo ahora</a></div><?php else: ?>
                    <div class="upcoming-list"><?php foreach ($upcoming as $event): ?><div class="upcoming-item"><div class="event-time"><strong><?= (new DateTime($event['fecha_inicio']))->format('H:i') ?></strong><small><?= (new DateTime($event['fecha_inicio']))->format('d M') ?></small></div><div class="event-line"></div><div class="event-info"><strong><?= e($event['titulo']) ?></strong><span><?= e($event['ubicacion'] ?: 'Sin ubicación') ?></span></div><span class="priority-dot <?= e($event['prioridad']) ?>"></span></div><?php endforeach; ?></div><?php endif; ?>
                </article>
                <article class="panel assistant-panel"><div class="assistant-orb">✦</div><p class="eyebrow">Asistente local</p><h2>Encuentra lo que necesitas</h2><p>Busca eventos, documentos y contactos usando palabras o tu voz.</p><form class="assistant-search" method="get"><input name="q" placeholder="Ej. reunión, contrato..." value="<?= e($search) ?>"><button type="submit">→</button></form><button class="voice-button" type="button" id="voiceSearch">◉ Buscar con voz</button></article>
            </section>

            <section class="section-block" id="agenda"><div class="section-heading"><div><p class="eyebrow">Planifica sin perder el hilo</p><h2>Agenda y recordatorios</h2></div><span class="section-note">La fecha se actualiza automáticamente</span></div>
                <div class="content-grid agenda-grid"><article class="panel form-panel"><div class="panel-heading"><h3><?= $editingEvent ? 'Editar evento' : 'Nuevo evento' ?></h3><?php if ($editingEvent): ?><a class="text-link" href="dashboard.php#agenda">Cancelar</a><?php endif; ?></div>
                    <form method="post" class="form-grid" data-spellcheck="true">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_event"><input type="hidden" name="event_id" value="<?= (int) ($editingEvent['id'] ?? 0) ?>">
                        <label class="full">Título<input name="titulo" value="<?= e($editingEvent['titulo'] ?? '') ?>" placeholder="Ej. Reunión con el equipo" required spellcheck="true"></label>
                        <label>Inicio<input type="datetime-local" name="fecha_inicio" value="<?= e(input_datetime($editingEvent['fecha_inicio'] ?? date('Y-m-d H:i:s', strtotime('+1 hour')))) ?>" required></label>
                        <label>Fin <span class="optional">opcional</span><input type="datetime-local" name="fecha_fin" value="<?= e(input_datetime($editingEvent['fecha_fin'] ?? '')) ?>"></label>
                        <label>Prioridad<select name="prioridad"><option value="baja" <?= (($editingEvent['prioridad'] ?? '') === 'baja') ? 'selected' : '' ?>>Baja</option><option value="media" <?= (($editingEvent['prioridad'] ?? 'media') === 'media') ? 'selected' : '' ?>>Media</option><option value="alta" <?= (($editingEvent['prioridad'] ?? '') === 'alta') ? 'selected' : '' ?>>Alta</option></select></label>
                        <label>Repetir<select name="repeticion"><option value="ninguna">No repetir</option><option value="diaria" <?= (($editingEvent['repeticion'] ?? '') === 'diaria') ? 'selected' : '' ?>>Cada día</option><option value="semanal" <?= (($editingEvent['repeticion'] ?? '') === 'semanal') ? 'selected' : '' ?>>Cada semana</option><option value="mensual" <?= (($editingEvent['repeticion'] ?? '') === 'mensual') ? 'selected' : '' ?>>Cada mes</option></select></label>
                        <label>Recordarme<select name="recordatorio_minutos"><option value="0" <?= ((int) ($editingEvent['recordatorio_minutos'] ?? 30) === 0) ? 'selected' : '' ?>>A la hora</option><option value="10" <?= ((int) ($editingEvent['recordatorio_minutos'] ?? 30) === 10) ? 'selected' : '' ?>>10 minutos antes</option><option value="30" <?= ((int) ($editingEvent['recordatorio_minutos'] ?? 30) === 30) ? 'selected' : '' ?>>30 minutos antes</option><option value="60" <?= ((int) ($editingEvent['recordatorio_minutos'] ?? 30) === 60) ? 'selected' : '' ?>>1 hora antes</option><option value="1440" <?= ((int) ($editingEvent['recordatorio_minutos'] ?? 30) === 1440) ? 'selected' : '' ?>>1 día antes</option></select></label>
                        <label>Ubicación <span class="optional">opcional</span><input name="ubicacion" value="<?= e($editingEvent['ubicacion'] ?? '') ?>" placeholder="Oficina, enlace o dirección"></label>
                        <label class="full">Descripción<textarea name="descripcion" rows="3" placeholder="Notas, tareas o detalles..." spellcheck="true"><?= e($editingEvent['descripcion'] ?? '') ?></textarea></label>
                        <?php if ($editingEvent): ?><label>Estado<select name="estado"><option value="pendiente" <?= $editingEvent['estado'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option><option value="completada" <?= $editingEvent['estado'] === 'completada' ? 'selected' : '' ?>>Completada</option></select></label><?php endif; ?>
                        <div class="form-actions full"><button class="btn btn-primary" type="submit"><?= $editingEvent ? 'Guardar cambios' : 'Guardar evento' ?></button><span class="form-hint">Los duplicados se detectan antes de guardar.</span></div>
                    </form>
                </article>
                <article class="panel event-list-panel"><div class="panel-heading"><h3><?= $search ? 'Resultados de agenda' : 'Todos tus eventos' ?></h3><span class="count-badge"><?= count($events) ?></span></div>
                    <?php if (!$events): ?><div class="empty-state"><span>☷</span><p><?= $search ? 'No encontramos coincidencias.' : 'Tu agenda está lista para estrenarse.' ?></p></div><?php else: ?><div class="table-list"><?php foreach ($events as $event): ?><div class="table-row <?= $event['estado'] === 'completada' ? 'is-complete' : '' ?>"><form method="post" class="check-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_event"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><button class="check-button" type="submit" title="Cambiar estado"><?= $event['estado'] === 'completada' ? '✓' : '' ?></button></form><div class="row-main"><strong><?= e($event['titulo']) ?></strong><span><?= e(human_datetime($event['fecha_inicio'])) ?> · <?= e($event['ubicacion'] ?: 'Sin ubicación') ?></span></div><span class="priority-label <?= e($event['prioridad']) ?>"><?= e(ucfirst($event['prioridad'])) ?></span><a class="row-action" href="?edit=<?= (int) $event['id'] ?>#agenda" title="Editar">✎</a><form method="post" class="inline-form" onsubmit="return confirmDelete(this, 'este evento')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_event"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="confirm_delete" value="0"><button class="row-action danger" type="submit" title="Eliminar">×</button></form></div><?php endforeach; ?></div><?php endif; ?>
                </article></div>
            </section>

            <section class="section-block" id="documentos"><div class="section-heading"><div><p class="eyebrow">Tu archivo personal</p><h2>Documentos e imágenes</h2></div><span class="section-note">PDF, DOCX, TXT e imágenes · máximo 10 MB</span></div><div class="content-grid documents-grid"><article class="panel upload-panel"><div class="upload-icon">↑</div><h3>Guardar un archivo</h3><p>Sube contratos, fotos, comprobantes o cualquier documento de trabajo.</p><form method="post" enctype="multipart/form-data" class="stack-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="upload_document"><label>Archivo<input type="file" name="archivo" accept=".pdf,.docx,.txt,image/*" required></label><label>Nombre visible <span class="optional">opcional</span><input name="nombre_documento" placeholder="Ej. Contrato 2026"></label><div class="two-cols"><label>Categoría<select name="categoria"><option value="documento">Documento</option><option value="trabajo">Trabajo</option><option value="personal">Personal</option><option value="foto">Imagen / foto</option></select></label><label>Notas<input name="notas" placeholder="Referencia rápida"></label></div><button class="btn btn-secondary btn-block" type="submit">Guardar archivo</button></form></article><article class="panel documents-panel"><div class="panel-heading"><h3>Archivos recientes</h3><span class="count-badge"><?= count($documents) ?></span></div><?php if (!$documents): ?><div class="empty-state"><span>▤</span><p>Aún no tienes archivos guardados.</p></div><?php else: ?><div class="file-list"><?php foreach ($documents as $document): ?><div class="file-row"><div class="file-type <?= str_contains($document['mime'], 'image') ? 'image' : 'pdf' ?>"><?= str_contains($document['mime'], 'image') ? '▧' : '▤' ?></div><div class="row-main"><strong><?= e($document['nombre']) ?></strong><span><?= e(strtoupper($document['categoria'])) ?> · <?= number_format(((int) $document['tamano']) / 1024, 0) ?> KB</span></div><a class="row-action" href="download.php?id=<?= (int) $document['id'] ?>" title="Descargar">↓</a><form method="post" class="inline-form" onsubmit="return confirmDelete(this, 'este archivo')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_document"><input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>"><input type="hidden" name="confirm_delete" value="0"><button class="row-action danger" type="submit" title="Eliminar">×</button></form></div><?php endforeach; ?></div><?php endif; ?></article></div></section>

            <section class="content-grid lower-grid"><article class="panel" id="claves"><div class="panel-heading"><div><p class="eyebrow">Protección AES-256</p><h2>Bóveda de claves</h2></div><span class="secure-chip">Cifrada</span></div><p class="panel-description">Guarda credenciales sin almacenarlas en texto plano. La contraseña de acceso nunca se muestra por defecto.</p><form method="post" class="form-grid compact-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_key"><label>Servicio<input name="servicio" placeholder="Correo, banco, portal..." required></label><label>Usuario <span class="optional">opcional</span><input name="usuario_clave" placeholder="usuario o correo"></label><label class="full">Clave<input type="password" name="clave" placeholder="Se cifra antes de guardar" required></label><div class="form-actions full"><button class="btn btn-secondary" type="submit">Guardar clave</button></div></form><div class="key-list"><?php foreach ($keys as $key): ?><div class="key-row"><span class="key-symbol">▣</span><div class="row-main"><strong><?= e($key['servicio']) ?></strong><span><?= e($key['usuario'] ?: 'Sin usuario') ?></span></div><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="<?= ((int) $key['id'] === $revealedKeyId) ? 'hide_key' : 'show_key' ?>"><input type="hidden" name="key_id" value="<?= (int) $key['id'] ?>"><button class="row-action" type="submit"><?= ((int) $key['id'] === $revealedKeyId) ? 'Ocultar' : 'Mostrar' ?></button></form><form method="post" class="inline-form" onsubmit="return confirmDelete(this, 'esta clave')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_key"><input type="hidden" name="key_id" value="<?= (int) $key['id'] ?>"><input type="hidden" name="confirm_delete" value="0"><button class="row-action danger" type="submit">×</button></form></div><?php endforeach; ?><?php if (!$keys): ?><div class="empty-inline">No hay claves guardadas todavía.</div><?php endif; ?></div><?php if ($revealedSecret): ?><div class="secret-reveal"><strong><?= e($revealedSecret['servicio']) ?></strong><code><?= e($revealedSecret['value']) ?></code><span>Cierra esta pestaña o elimina este mensaje cuando termines.</span></div><?php endif; ?></article></section>

            <section class="section-block" id="contactos"><div class="section-heading"><div><p class="eyebrow">Personas y seguridad</p><h2>Contactos y números bloqueados</h2></div><span class="section-note">Marca números sospechosos para reconocerlos</span></div><div class="content-grid contacts-grid"><article class="panel form-panel"><div class="panel-heading"><h3>Nuevo contacto</h3></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_contact"><label>Nombre<input name="nombre_contacto" placeholder="Nombre o empresa" required></label><label>Teléfono<input name="telefono_contacto" placeholder="Número sospechoso o conocido"></label><label>Correo<input type="email" name="correo_contacto" placeholder="correo@ejemplo.com"></label><label>Tipo<select name="tipo_contacto"><option value="contacto">Contacto</option><option value="trabajo">Trabajo</option><option value="spam">Spam / sospechoso</option></select></label><label class="full">Notas<textarea name="notas_contacto" rows="2" placeholder="Por qué lo guardas..."></textarea></label><label class="check-label full"><input type="checkbox" name="bloqueado"> Marcar como bloqueado / sospechoso</label><div class="form-actions full"><button class="btn btn-secondary" type="submit">Guardar contacto</button></div></form></article><article class="panel"><div class="panel-heading"><h3>Lista de confianza</h3><span class="count-badge"><?= count($contacts) ?></span></div><?php if (!$contacts): ?><div class="empty-state"><span>♙</span><p>Añade un contacto o número para identificarlo.</p></div><?php else: ?><div class="contact-list"><?php foreach ($contacts as $contact): ?><div class="contact-row <?= $contact['bloqueado'] ? 'blocked' : '' ?>"><span class="avatar contact-avatar"><?= e(mb_strtoupper(mb_substr($contact['nombre'], 0, 1))) ?></span><div class="row-main"><strong><?= e($contact['nombre']) ?><?php if ($contact['bloqueado']): ?><span class="blocked-pill">Bloqueado</span><?php endif; ?></strong><span><?= e($contact['telefono'] ?: $contact['correo'] ?: 'Sin datos') ?></span></div><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_contact"><input type="hidden" name="contact_id" value="<?= (int) $contact['id'] ?>"><button class="row-action" type="submit"><?= $contact['bloqueado'] ? 'Desbloquear' : 'Bloquear' ?></button></form><form method="post" class="inline-form" onsubmit="return confirmDelete(this, 'este contacto')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_contact"><input type="hidden" name="contact_id" value="<?= (int) $contact['id'] ?>"><input type="hidden" name="confirm_delete" value="0"><button class="row-action danger" type="submit">×</button></form></div><?php endforeach; ?></div><?php endif; ?></article></div></section>

            <section class="section-block" id="herramientas"><div class="section-heading"><div><p class="eyebrow">Conecta tu día</p><h2>Centro de herramientas</h2></div></div><div class="tools-grid"><button class="tool-card" type="button" id="gpsButton"><span class="tool-icon">⌖</span><strong>Mi ubicación</strong><small>Usa GPS con permiso</small></button><button class="tool-card" type="button" id="bluetoothButton"><span class="tool-icon">♢</span><strong>Bluetooth</strong><small>Detecta dispositivos cercanos</small></button><button class="tool-card" type="button" id="shareButton"><span class="tool-icon">↗</span><strong>Compartir agenda</strong><small>WhatsApp, correo u otras apps</small></button><button class="tool-card" type="button" id="cameraButton"><span class="tool-icon">▣</span><strong>Comprobar cámara</strong><small>Captura con permiso</small></button><a class="tool-card" href="?export=1"><span class="tool-icon">↓</span><strong>Crear respaldo</strong><small>Descarga tus datos en JSON</small></a></div><div class="content-grid settings-grid"><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Seguridad</p><h3>Cambiar contraseña</h3></div></div><p class="panel-description">Cambia tu contraseña para reforzar el acceso a la agenda. Usa una combinación larga y única.</p><form method="post" class="settings-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="change_password"><label>Contraseña actual<input type="password" name="current_password" autocomplete="current-password" required></label><label>Nueva contraseña<input type="password" name="new_password" autocomplete="new-password" required></label><label>Confirmar contraseña<input type="password" name="confirm_password" autocomplete="new-password" required></label><p class="form-hint"><?= e(password_policy_message(current_language())) ?></p><button class="btn btn-secondary" type="submit">Cambiar contraseña</button></form></article><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Continuidad</p><h3>Correo de respaldo</h3></div></div><p class="panel-description">Guarda un correo alternativo para organizar futuras copias. El envío automático necesita configurar SMTP en el servidor.</p><form method="post" class="settings-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_settings"><label>Correo alternativo<input type="email" name="correo_respaldo" value="<?= e($config['correo_respaldo'] ?? '') ?>" placeholder="respaldo@ejemplo.com"></label><label class="check-label"><input type="checkbox" name="notificaciones" <?= !empty($config['notificaciones']) ? 'checked' : '' ?>> Activar recordatorios del navegador</label><button class="btn btn-secondary" type="submit">Guardar preferencias</button></form></article><article class="panel info-panel"><span class="assistant-orb small-orb">i</span><h3>Lo que el navegador protege</h3><p>Las llamadas, WhatsApp, Bluetooth, GPS y la cámara solo funcionan con tu permiso y según las capacidades del dispositivo. La agenda no puede espiar llamadas ni tomar fotos en secreto.</p><span class="browser-note">Puedes conectar WhatsApp mediante Compartir.</span></article></div></section>
        </main>
        <footer class="app-footer">SmartAgenda · Privacidad primero · <?= date('Y') ?></footer>
    </div>
</div>
<div class="toast" id="toolToast" role="status"></div>
<div class="camera-modal" id="cameraModal" hidden><div class="camera-dialog"><button class="modal-close" id="closeCamera">×</button><p class="eyebrow">Permiso de cámara</p><h2>Captura segura</h2><p>La cámara solo se activa después de tu permiso y mientras esta ventana esté abierta.</p><video id="cameraVideo" autoplay playsinline></video><canvas id="cameraCanvas" hidden></canvas><button class="btn btn-primary btn-block" id="takePhoto" type="button">Tomar captura</button><img id="cameraSnapshot" alt="Captura tomada" hidden></div></div>
<div class="document-modal" id="documentModal" hidden><div class="document-dialog"><button class="modal-close" id="closeDocument">×</button><div class="document-dialog-heading"><div><p class="eyebrow">Visor y firma</p><h2 id="documentTitle">Documento</h2></div><span class="secure-chip">Original protegido</span></div><div class="document-preview"><iframe id="documentFrame" title="Vista previa del documento" hidden></iframe><img id="documentImage" alt="Vista previa de la imagen" hidden><pre id="documentText" hidden></pre><div id="documentUnsupported" class="empty-state" hidden><span>▤</span><p>Este formato no se puede previsualizar aquí.</p><a id="downloadDocumentLink" class="btn btn-ghost" href="#">Descargar archivo</a></div></div><div class="sign-document-panel"><div class="panel-heading"><div><p class="eyebrow">Firma visual</p><h3>Dibuja tu firma</h3></div><span class="section-note">Se coloca en la esquina inferior derecha</span></div><div class="document-signature-box"><canvas id="documentSignatureCanvas" width="560" height="150"></canvas><span id="documentSignaturePlaceholder">Dibuja aquí con el mouse o tu dedo</span></div><div class="signature-actions"><button class="btn btn-primary" type="button" id="applyDocumentSignature">Firmar y guardar copia</button><button class="btn btn-ghost" type="button" id="clearDocumentSignature">Limpiar</button><a class="btn btn-ghost" id="downloadSignedDocument" href="#" download hidden>Descargar copia</a></div><p class="form-hint">La firma original no se modifica. Se crea una copia firmada dentro de Documentos.</p></div></div></div>
<script>window.smartAgenda = { csrf: <?= json_encode(csrf_token()) ?>, shareText: <?= json_encode('Mi agenda en SmartAgenda · ' . $totalEvents . ' eventos organizados.') ?>, reminders: <?= json_encode(array_map(static function (array $event): array { return ['title' => $event['titulo'], 'start' => $event['fecha_inicio'], 'minutes' => (int) $event['recordatorio_minutos']]; }, $upcoming), JSON_UNESCAPED_UNICODE) ?> };</script>
<script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
<script src="app.js"></script>
</body>
</html>
