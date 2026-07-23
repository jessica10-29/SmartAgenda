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
    private string $host;
    private int $port;
    private string $dbname;
    private string $user;
    private string $password;

    public function __construct()
    {
        $this->host = (string) (getenv('DB_HOST') ?: 'localhost');
        $this->port = (int) (getenv('DB_PORT') ?: 3306);
        $this->dbname = (string) (getenv('DB_NAME') ?: 'smartagenda');
        $this->user = (string) (getenv('DB_USER') ?: 'root');
        $this->password = (string) (getenv('DB_PASSWORD') ?: '');
    }

    public function conectar(): PDO
    {
        try {
            $conexion = new PDO(
                "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset=utf8mb4",
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

function current_language(): string
{
    $lang = $_GET['lang'] ?? $_POST['lang'] ?? $_SESSION['lang'] ?? $_COOKIE['smartagenda_lang'] ?? 'es';
    $lang = in_array($lang, ['es', 'en'], true) ? $lang : 'es';
    $_SESSION['lang'] = $lang;
    if (!headers_sent()) {
        setcookie('smartagenda_lang', $lang, ['expires' => time() + 60 * 60 * 24 * 365, 'path' => '/', 'samesite' => 'Lax']);
    }
    return $lang;
}

function translate(string $key, array $params = []): string
{
    $lang = current_language();
    $translations = [
        'es' => [
            'auth.title_login' => 'Ingresar · SmartAgenda',
            'auth.title_register' => 'Crear cuenta · SmartAgenda',
            'auth.welcome_back' => 'Bienvenido de nuevo',
            'auth.sign_in' => 'Iniciar sesión',
            'auth.email' => 'Correo electrónico',
            'auth.password' => 'Contraseña',
            'auth.login_button' => 'Entrar a mi agenda',
            'auth.no_account' => '¿Aún no tienes cuenta?',
            'auth.create_free' => 'Crear cuenta gratis',
            'auth.create_account' => 'Crear cuenta',
            'auth.first_step' => 'Primer paso',
            'auth.full_name' => 'Nombre completo',
            'auth.phone' => 'Teléfono',
            'auth.optional' => 'opcional',
            'auth.create_button' => 'Crear mi agenda',
            'auth.has_account' => '¿Ya tienes cuenta?',
            'auth.enter' => 'Ingresar',
            'auth.password_hint' => 'Usa 12+ caracteres con mayúsculas, minúsculas, número y símbolo.',
            'auth.password_current' => 'Contraseña actual',
            'auth.password_new' => 'Nueva contraseña',
            'auth.password_confirm' => 'Confirmar nueva contraseña',
            'auth.change_password' => 'Cambiar contraseña',
            'auth.password_updated' => 'Contraseña actualizada correctamente.',
            'auth.save_key' => 'Guardar clave',
            'auth.show' => 'Mostrar',
            'auth.hide' => 'Ocultar',
            'auth.key_saved' => 'Clave guardada con cifrado AES-256.',
            'nav.language' => 'Idioma',
            'nav.spanish' => 'Español',
            'nav.english' => 'English',
            'settings.security' => 'Seguridad',
            'settings.change_password_help' => 'Cambia tu contraseña para reforzar el acceso a la agenda.',
            'settings.backup_email' => 'Correo de respaldo',
            'settings.notifications' => 'Activar recordatorios del navegador',
            'settings.save_preferences' => 'Guardar preferencias',
        ],
        'en' => [
            'auth.title_login' => 'Sign in · SmartAgenda',
            'auth.title_register' => 'Create account · SmartAgenda',
            'auth.welcome_back' => 'Welcome back',
            'auth.sign_in' => 'Sign in',
            'auth.email' => 'Email address',
            'auth.password' => 'Password',
            'auth.login_button' => 'Enter my agenda',
            'auth.no_account' => 'Don’t have an account yet?',
            'auth.create_free' => 'Create account for free',
            'auth.create_account' => 'Create account',
            'auth.first_step' => 'First step',
            'auth.full_name' => 'Full name',
            'auth.phone' => 'Phone',
            'auth.optional' => 'optional',
            'auth.create_button' => 'Create my agenda',
            'auth.has_account' => 'Already have an account?',
            'auth.enter' => 'Sign in',
            'auth.password_hint' => 'Use 12+ characters with uppercase, lowercase, number and symbol.',
            'auth.password_current' => 'Current password',
            'auth.password_new' => 'New password',
            'auth.password_confirm' => 'Confirm new password',
            'auth.change_password' => 'Change password',
            'auth.password_updated' => 'Password updated successfully.',
            'auth.save_key' => 'Save key',
            'auth.show' => 'Show',
            'auth.hide' => 'Hide',
            'auth.key_saved' => 'Key saved with AES-256 encryption.',
            'nav.language' => 'Language',
            'nav.spanish' => 'Español',
            'nav.english' => 'English',
            'settings.security' => 'Security',
            'settings.change_password_help' => 'Change your password to strengthen access to the agenda.',
            'settings.backup_email' => 'Backup email',
            'settings.notifications' => 'Enable browser reminders',
            'settings.save_preferences' => 'Save preferences',
        ],
    ];

    $text = $translations[$lang][$key] ?? $translations['es'][$key] ?? $key;
    if ($params) {
        $text = strtr($text, $params);
    }
    return $text;
}

function validate_password_strength(string $password): array
{
    $errors = [];
    if (mb_strlen($password) < 12) {
        $errors[] = 'minimum_12';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'lowercase';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'uppercase';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'number';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'special';
    }
    if (preg_match('/\s/', $password)) {
        $errors[] = 'spaces';
    }
    return $errors;
}

function password_policy_message(string $lang = 'es'): string
{
    $messages = [
        'es' => 'Usa al menos 12 caracteres, una mayúscula, una minúscula, un número y un símbolo, sin espacios.',
        'en' => 'Use at least 12 characters, one uppercase, one lowercase, one number and one symbol, with no spaces.',
    ];
    return $messages[$lang] ?? $messages['es'];
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
