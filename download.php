<?php
require_once __DIR__ . '/conexion.php';
require_login();

$documentId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT nombre, archivo, mime, tamano FROM agenda_documentos WHERE id = ? AND usuario_id = ? LIMIT 1');
$stmt->execute([$documentId, auth_user_id()]);
$document = $stmt->fetch();
$path = $document ? __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($document['archivo']) : '';

if (!$document || !is_file($path)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $document['mime']);
header('Content-Length: ' . filesize($path));
$isInline = isset($_GET['inline']) && $_GET['inline'] === '1' && in_array($document['mime'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/plain'], true);
header('Content-Disposition: ' . ($isInline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($document['nombre']) . '"');
readfile($path);
