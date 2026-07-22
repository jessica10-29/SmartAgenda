<?php
declare(strict_types=1);

date_default_timezone_set('America/Bogota');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

class Database
{
    private string $host = 'localhost';
    private string $dbname = 'smartagenda';
    private string $user = 'root';
    private string $password = '';

    public function conectar(): PDO
    {
        try {
            $conexion = new PDO(
                "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4",
                $this->user,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            return $conexion;
        } catch (PDOException $e) {
            http_response_code(500);
            exit('No se pudo conectar con la base de datos. Importa bd.sql y revisa conexion.php.');
        }
    }
}

function db(): PDO
{
    static $conexion;
    if (!$conexion) {
        $conexion = (new Database())->conectar();
    }
    return $conexion;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!$token || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(419);
        exit('La sesión del formulario expiró. Regresa y vuelve a intentarlo.');
    }
}

function require_login(): void
{
    if (empty($_SESSION['id'])) {
        redirect('login.php');
    }

    $stmt = db()->prepare('SELECT id FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['id']]);
    if (!$stmt->fetchColumn()) {
        $_SESSION = [];
        flash('warning', 'Tu sesión anterior ya no es válida. Ingresa nuevamente.');
        redirect('login.php');
    }
}

function auth_user_id(): int
{
    return (int) ($_SESSION['id'] ?? 0);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

function input(string $name, string $default = ''): string
{
    return trim((string) ($_POST[$name] ?? $default));
}

function normalize_text(string $text): string
{
    return mb_strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 'UTF-8');
}

function database_datetime(string $value): ?string
{
    if ($value === '') {
        return null;
    }
    $date = DateTime::createFromFormat('Y-m-d\TH:i', $value)
        ?: DateTime::createFromFormat('Y-m-d H:i:s', $value);
    return $date ? $date->format('Y-m-d H:i:s') : null;
}

function input_datetime(?string $value): string
{
    if (!$value) {
        return '';
    }
    return (new DateTime($value))->format('Y-m-d\TH:i');
}

function human_datetime(?string $value): string
{
    if (!$value) {
        return 'Sin fecha';
    }
    return (new DateTime($value))->format('d/m/Y · H:i');
}

function app_encrypt(string $value, int $userId): array
{
    $secret = getenv('SMARTAGENDA_SECRET') ?: 'cambia-esta-clave-en-produccion-smartagenda';
    $key = hash('sha256', $secret . '|' . $userId, true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return ['cipher' => base64_encode($cipher ?: ''), 'iv' => base64_encode($iv)];
}

function app_decrypt(string $cipher, string $iv, int $userId): string
{
    $secret = getenv('SMARTAGENDA_SECRET') ?: 'cambia-esta-clave-en-produccion-smartagenda';
    $key = hash('sha256', $secret . '|' . $userId, true);
    $plain = openssl_decrypt(base64_decode($cipher), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, base64_decode($iv));
    return is_string($plain) ? $plain : '';
}

function audit(int $userId, string $action): void
{
    $validUserId = null;
    if ($userId > 0) {
        $userStmt = db()->prepare('SELECT id FROM usuarios WHERE id = ? LIMIT 1');
        $userStmt->execute([$userId]);
        $validUserId = $userStmt->fetchColumn() ?: null;
    }
    $stmt = db()->prepare('INSERT INTO agenda_auditoria (usuario_id, accion, ip) VALUES (?, ?, ?)');
    $stmt->execute([$validUserId, $action, $_SERVER['REMOTE_ADDR'] ?? 'local']);
}
