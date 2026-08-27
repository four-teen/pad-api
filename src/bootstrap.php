<?php

/**
 * PAD API bootstrap. Deliberately dependency-free for cPanel hosting.
 * Compatible with PHP 7.4+ and MySQL/MariaDB installations commonly available
 * on shared hosting.
 */

define('PAD_ROOT', dirname(__DIR__));

load_environment(PAD_ROOT . DIRECTORY_SEPARATOR . '.env');

function load_environment($path)
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function env_value($key, $default = null)
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function app_is_debug()
{
    return filter_var(env_value('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
}

function json_response($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error($message, $status = 400, $details = null)
{
    $payload = array('message' => $message);
    if ($details !== null && app_is_debug()) {
        $payload['details'] = $details;
    }
    json_response($payload, $status);
}

function apply_cors()
{
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    $allowed = array_filter(array_map('trim', explode(',', env_value('APP_ALLOWED_ORIGINS', ''))));

    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: false');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function request_json()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return array();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        api_error('The request body must be valid JSON.', 422);
    }

    return $decoded;
}

function database()
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = env_value('DB_HOST');
    $database = env_value('DB_DATABASE');
    $username = env_value('DB_USERNAME');

    if (!$host || !$database || !$username) {
        api_error('Database configuration is incomplete.', 500);
    }

    try {
        $connection = new PDO(
            'mysql:host=' . $host . ';port=' . env_value('DB_PORT', '3306') . ';dbname=' . $database . ';charset=utf8mb4',
            $username,
            env_value('DB_PASSWORD', ''),
            array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            )
        );
    } catch (PDOException $exception) {
        api_error('Unable to connect to the database.', 500, $exception->getMessage());
    }

    return $connection;
}

function base64url_encode($value)
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64url_decode($value)
{
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    return base64_decode(strtr($value, '-_', '+/'));
}

function session_secret()
{
    $secret = env_value('APP_KEY');
    if (!$secret || strpos($secret, 'replace-with') === 0) {
        api_error('APP_KEY has not been configured.', 500);
    }
    return $secret;
}

function create_access_token($user)
{
    $header = base64url_encode(json_encode(array('alg' => 'HS256', 'typ' => 'JWT')));
    $payload = base64url_encode(json_encode(array(
        'iss' => env_value('APP_URL', 'pad-api'),
        'sub' => (int) $user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'iat' => time(),
        'exp' => time() + 60 * 60 * 8
    )));
    $signature = base64url_encode(hash_hmac('sha256', $header . '.' . $payload, session_secret(), true));

    return $header . '.' . $payload . '.' . $signature;
}

function authorization_header()
{
    // Apache/CGI and FastCGI hosts expose Authorization using different
    // server-variable names. Check each safe standard form for cPanel and
    // local Windows Apache compatibility.
    $header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if ($header === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'authorization') {
                $header = $value;
                break;
            }
        }
    }

    return trim($header);
}

function current_user()
{
    $header = authorization_header();
    if (!preg_match('/^Bearer\\s+(.+)$/i', $header, $matches)) {
        api_error('Authentication is required.', 401);
    }

    $parts = explode('.', $matches[1]);
    if (count($parts) !== 3) {
        api_error('Your session is invalid.', 401);
    }

    $expected = base64url_encode(hash_hmac('sha256', $parts[0] . '.' . $parts[1], session_secret(), true));
    if (!hash_equals($expected, $parts[2])) {
        api_error('Your session is invalid.', 401);
    }

    $claims = json_decode(base64url_decode($parts[1]), true);
    if (!is_array($claims) || !isset($claims['sub'], $claims['exp']) || $claims['exp'] < time()) {
        api_error('Your session has expired.', 401);
    }

    $statement = database()->prepare(
        'SELECT users.id, users.email, users.name, users.avatar_url, users.role, users.office_id, users.is_active, offices.code AS office_code, offices.name AS office_name
         FROM users LEFT JOIN offices ON offices.id = users.office_id WHERE users.id = ? LIMIT 1'
    );
    $statement->execute(array((int) $claims['sub']));
    $user = $statement->fetch();

    if (!$user || !(int) $user['is_active']) {
        api_error('This account is not active.', 403);
    }

    return $user;
}

function is_records_user($user)
{
    return $user['role'] === 'admin' || $user['role'] === 'records' || $user['office_code'] === 'RECORDS';
}

function require_administrator($user)
{
    if (!isset($user['role']) || $user['role'] !== 'admin') {
        api_error('Administrator access is required.', 403);
    }
}

function google_token_info($credential)
{
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($credential);
    $response = false;

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($status !== 200) {
            $response = false;
        }
    } elseif (ini_get('allow_url_fopen')) {
        $response = @file_get_contents($url);
    }

    if ($response === false) {
        api_error('Google could not verify this sign-in. Please try again.', 401);
    }

    $claims = json_decode($response, true);
    if (!is_array($claims)) {
        api_error('Google sign-in could not be validated.', 401);
    }

    $clientId = env_value('GOOGLE_CLIENT_ID');
    $issuer = isset($claims['iss']) ? $claims['iss'] : '';
    $emailVerified = isset($claims['email_verified']) ? filter_var($claims['email_verified'], FILTER_VALIDATE_BOOLEAN) : false;
    $allowedIssuers = array('accounts.google.com', 'https://accounts.google.com');

    if (!$clientId || !isset($claims['aud']) || !hash_equals($clientId, $claims['aud']) || empty($claims['email']) || !$emailVerified || !in_array($issuer, $allowedIssuers, true)) {
        api_error('Google sign-in could not be validated.', 401);
    }

    return $claims;
}

function sql_identifier($value, $label)
{
    if (is_string($value) === false) {
        api_error($label . ' configuration is invalid.', 500);
    }
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
        api_error($label . ' configuration is invalid.', 500);
    }
    return '`' . $value . '`';
}

function assert_allowed_account_email($email)
{
    $table = sql_identifier(env_value('AUTH_ACCOUNT_TABLE', 'tblaccount'), 'AUTH_ACCOUNT_TABLE');
    $column = sql_identifier(env_value('AUTH_ACCOUNT_EMAIL_COLUMN', 'email'), 'AUTH_ACCOUNT_EMAIL_COLUMN');
    $statement = database()->prepare('SELECT 1 FROM ' . $table . ' WHERE LOWER(' . $column . ') = LOWER(?) LIMIT 1');
    $statement->execute(array($email));

    if ($statement->fetchColumn() === false) {
        api_error('Your Google account is not authorized to use PAD. Ask the Records Office to add your email to the account list.', 403);
    }
}

function authenticate_google_user($credential)
{
    $claims = google_token_info($credential);
    $email = strtolower(trim($claims['email']));
    $name = isset($claims['name']) ? trim($claims['name']) : $email;
    $avatar = isset($claims['picture']) ? $claims['picture'] : null;

    // tblaccount is the authoritative allow-list. Google verification alone never creates access.
    assert_allowed_account_email($email);
    $pdo = database();

    $firstAdminEmail = strtolower(trim(env_value('FIRST_ADMIN_EMAIL', '')));
    $isFirstAdministrator = $firstAdminEmail !== '' && hash_equals($firstAdminEmail, $email);

    $find = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $find->execute(array($email));
    $existing = $find->fetch();

    if ($existing) {
        // The configured first administrator remains an administrator even when
        // the profile was created before FIRST_ADMIN_EMAIL was configured.
        $update = $pdo->prepare("UPDATE users SET name = ?, avatar_url = ?, google_subject = ?, role = IF(?, 'admin', role), last_login_at = NOW() WHERE id = ?");
        $update->execute(array($name, $avatar, isset($claims['sub']) ? $claims['sub'] : null, $isFirstAdministrator ? 1 : 0, $existing['id']));
        return $existing['id'];
    }

    $role = $isFirstAdministrator ? 'admin' : 'staff';
    $insert = $pdo->prepare("INSERT INTO users (name, email, avatar_url, google_subject, role, is_active, last_login_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW())");
    $insert->execute(array($name, $email, $avatar, isset($claims['sub']) ? $claims['sub'] : null, $role));
    return (int) $pdo->lastInsertId();
}

function random_reference()
{
    try {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
    } catch (Exception $exception) {
        $suffix = strtoupper(substr(sha1(uniqid('', true)), 0, 8));
    }
    return 'CYT-' . date('Ymd') . '-' . $suffix;
}

function safe_upload_directory()
{
    $configured = env_value('UPLOAD_PATH', 'storage/documents');
    $path = preg_match('/^[A-Za-z]:[\\\\\/]/', $configured) || strpos($configured, '/') === 0
        ? $configured
        : PAD_ROOT . DIRECTORY_SEPARATOR . str_replace(array('/', '\\\\'), DIRECTORY_SEPARATOR, $configured);

    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        api_error('Document storage is not writable.', 500);
    }
    return rtrim($path, DIRECTORY_SEPARATOR);
}

function document_record($reference, $user)
{
    $sql = 'SELECT d.*, source.name AS source_office_name, current.name AS current_office_name, current.code AS current_office_code,
            recipient.name AS recipient_office_name
            FROM documents d
            LEFT JOIN offices source ON source.id = d.source_office_id
            LEFT JOIN offices current ON current.id = d.current_office_id
            LEFT JOIN offices recipient ON recipient.id = d.recipient_office_id
            WHERE d.reference_no = ? LIMIT 1';
    $statement = database()->prepare($sql);
    $statement->execute(array($reference));
    $document = $statement->fetch();

    if (!$document) {
        api_error('Document not found.', 404);
    }

    if (!is_records_user($user) && (int) $user['office_id'] !== (int) $document['source_office_id'] && (int) $user['office_id'] !== (int) $document['current_office_id'] && (int) $user['office_id'] !== (int) $document['recipient_office_id']) {
        api_error('You are not allowed to access this document.', 403);
    }

    return $document;
}

function document_history($documentId)
{
    $statement = database()->prepare(
        'SELECT r.id, r.action, r.notes, r.dispatched_at, r.received_at, r.created_at,
         origin.name AS origin_office_name, destination.name AS destination_office_name,
         actor.name AS acted_by_name
         FROM document_routes r
         LEFT JOIN offices origin ON origin.id = r.from_office_id
         LEFT JOIN offices destination ON destination.id = r.to_office_id
         LEFT JOIN users actor ON actor.id = r.acted_by_user_id
         WHERE r.document_id = ? ORDER BY r.created_at ASC, r.id ASC'
    );
    $statement->execute(array($documentId));
    return $statement->fetchAll();
}
