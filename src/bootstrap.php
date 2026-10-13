<?php

/**
 * PAD API bootstrap. Deliberately dependency-free for Apache/PHP hosting.
 * Compatible with PHP 7.4+ and MySQL/MariaDB installations commonly available
 * on shared hosting.
 */

define('PAD_ROOT', dirname(__DIR__));
require_once __DIR__ . '/GoogleIdentity.php';

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

function module_office_ids()
{
    $pad = filter_var(env_value('PAD_OVP_PAD_OFFICE_ID', '3'), FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
    $records = filter_var(env_value('PAD_CENTRAL_RECORDS_OFFICE_ID', '2'), FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
    if ($pad === false || $records === false || $pad === $records) {
        api_error('OVP module office configuration is invalid.', 500);
    }
    return array('ovp_pad' => (int) $pad, 'ovp_central_records' => (int) $records);
}

function apply_active_module_office(array $user)
{
    $assignments = account_role_office_ids($user);
    $user['sksu_office_id'] = $user['sksu_office_id'] ?? $user['office_id'] ?? null;
    $user['sksu_office_code'] = $user['sksu_office_code'] ?? $user['office_code'] ?? null;
    $user['sksu_office_is_active'] = $user['sksu_office_is_active'] ?? $user['office_is_active'] ?? null;
    $user['role_offices'] = array();
    $ids = array_values(array_unique(array_filter($assignments)));
    $offices = array();
    if ($ids) {
        $query = database()->prepare('SELECT id, code, name, campus, is_active,
            EXISTS (SELECT 1 FROM office_officials f WHERE f.office_id = offices.id) AS office_has_officials
            FROM offices WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $query->execute($ids);
        foreach ($query->fetchAll() as $office) $offices[(int) $office['id']] = $office;
    }
    foreach (account_roles($user) as $role) {
        if (array_key_exists($role, $assignments)) $user['role_offices'][$role] = $offices[$assignments[$role]] ?? null;
    }
    $activeRole = $user['active_role'] ?? null;
    if (!in_array($activeRole, array('ovp_pad', 'ovp_central_records'), true)) {
        return $user;
    }

    $officeId = $assignments[$activeRole];
    $details = $user['role_offices'][$activeRole] ?? null;
    if (!$details || !(int) $details['is_active']) {
        throw new ApiException('No active routing office is assigned for ' . ($activeRole === 'ovp_pad' ? 'OVP PAD' : 'OVP Central Records') . '. Ask an administrator to select its routing office in your account.', 403);
    }
    $user['office_id'] = $officeId;
    $user['office_code'] = $details['code'] ?? null;
    $user['office_name'] = $details['name'] ?? null;
    $user['office_campus'] = $details['campus'] ?? null;
    $user['office_has_officials'] = $details ? (bool) $details['office_has_officials'] : false;
    return $user;
}

function account_role_office_ids(array $user)
{
    return array(
        'university_president' => president_eligible($user) ? (int) ($user['sksu_office_id'] ?? $user['office_id']) : null,
        'ovp_pad' => !empty($user['ovp_pad_office_id']) ? (int) $user['ovp_pad_office_id'] : null,
        'ovp_central_records' => !empty($user['ovp_central_records_office_id']) ? (int) $user['ovp_central_records_office_id'] : null,
        'sksu_officials' => !empty($user['sksu_office_id'] ?? $user['office_id'] ?? null) ? (int) ($user['sksu_office_id'] ?? $user['office_id']) : null
    );
}

function is_presidential_office(array $office)
{
    // OP is the immutable office code. Its display name and personnel positions
    // may change without changing the monitoring-only office identity.
    return strtoupper(trim($office['code'] ?? $office['office_code'] ?? '')) === 'OP';
}

function president_eligible(array $user)
{
    return !empty($user['sksu_office_id'] ?? $user['office_id'] ?? null)
        && !empty($user['sksu_office_is_active'] ?? $user['office_is_active'] ?? null)
        && is_presidential_office(array('code' => $user['sksu_office_code'] ?? $user['office_code'] ?? ''));
}

function is_presidential_view(array $user)
{
    return ($user['active_role'] ?? '') === 'university_president'
        && in_array('university_president', account_roles($user), true);
}

function assert_monitoring_method(array $user, $method)
{
    if (is_presidential_view($user) && $method !== 'GET') {
        throw new ApiException('The presidential view is monitoring only. Document and administrative changes are unavailable.', 403);
    }
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
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-PAD-Role');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
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
        throw new AuthenticationException('Authentication is required.', 401);
    }

    $parts = explode('.', $matches[1]);
    if (count($parts) !== 3) {
        throw new AuthenticationException('Your session is invalid.', 401);
    }

    $expected = base64url_encode(hash_hmac('sha256', $parts[0] . '.' . $parts[1], session_secret(), true));
    if (!hash_equals($expected, $parts[2])) {
        throw new AuthenticationException('Your session is invalid.', 401);
    }

    $claims = json_decode(base64url_decode($parts[1]), true);
    if (!is_array($claims) || !isset($claims['sub'], $claims['exp']) || $claims['exp'] <= time()
        || ($claims['iss'] ?? '') !== env_value('APP_URL', 'pad-api')) {
        throw new AuthenticationException('Your session has expired. Please sign in again.', 401);
    }

    $statement = database()->prepare(
        'SELECT users.id, users.email, users.name, users.avatar_url, users.role, users.roles, users.office_id, users.ovp_pad_office_id, users.ovp_central_records_office_id, users.is_active, offices.code AS office_code, offices.name AS office_name, offices.campus AS office_campus, offices.is_active AS office_is_active,
         EXISTS (SELECT 1 FROM office_officials f WHERE f.office_id = users.office_id) AS office_has_officials
         FROM users LEFT JOIN offices ON offices.id = users.office_id WHERE users.id = ? LIMIT 1'
    );
    $statement->execute(array((int) $claims['sub']));
    $user = $statement->fetch();

    if (!$user || !(int) $user['is_active']) {
        throw new AuthenticationException('This account is not active.', 401);
    }
    if (strtolower($claims['email'] ?? '') !== strtolower($user['email'])) {
        throw new AuthenticationException('Your login email has changed. Please sign in again.', 401);
    }

    // Removal from the account list also ends access for existing PAD sessions.
    assert_allowed_account_email($user['email'], 401);
    $user['roles'] = account_roles($user);
    $selected = trim($_SERVER['HTTP_X_PAD_ROLE'] ?? '');
    if ($selected !== '' && !in_array($selected, $user['roles'], true)) {
        throw new ApiException('Your selected account role is no longer assigned. Choose another account role.', 403);
    }
    $user['active_role'] = $selected !== '' ? $selected : (count($user['roles']) === 1 ? $user['roles'][0] : null);
    return apply_active_module_office($user);
}

function account_roles(array $user)
{
    $allowed = array('admin', 'ovp_pad', 'ovp_central_records', 'sksu_officials');
    if (array_key_exists('roles', $user) && $user['roles'] !== null) {
        $stored = is_array($user['roles']) ? $user['roles'] : explode(',', $user['roles']);
    } else {
        $stored = array(($user['role'] ?? 'router') === 'admin' ? 'admin' : 'router');
    }
    $roles = array_values(array_intersect($allowed, $stored));
    $legacyRouter = in_array('router', $stored, true);
    $hasModuleRole = count(array_intersect(array('ovp_pad', 'ovp_central_records', 'sksu_officials'), $roles)) > 0;
    if ($legacyRouter && !$hasModuleRole && !empty($user['office_id']) && !president_eligible($user)) {
        $officeIds = module_office_ids();
        $officeId = (int) $user['office_id'];
        if ($officeId === $officeIds['ovp_pad']) {
            $roles[] = 'ovp_pad';
        } elseif ($officeId === $officeIds['ovp_central_records']) {
            $roles[] = 'ovp_central_records';
        } else {
            $hasOfficials = array_key_exists('office_has_officials', $user)
                ? (bool) $user['office_has_officials']
                : false;
            if (!array_key_exists('office_has_officials', $user)) {
                $check = database()->prepare('SELECT 1 FROM office_officials WHERE office_id = ? LIMIT 1');
                $check->execute(array($officeId));
                $hasOfficials = (bool) $check->fetchColumn();
            }
            if ($hasOfficials) $roles[] = 'sksu_officials';
        }
    }
    if (president_eligible($user)) {
        $roles = array_values(array_diff($roles, array('sksu_officials')));
        $roles[] = 'university_president';
        $allowed[] = 'university_president';
    }
    return array_values(array_intersect($allowed, array_unique($roles)));
}

function is_administrator(array $user)
{
    return in_array('admin', account_roles($user), true)
        && (!array_key_exists('active_role', $user) || $user['active_role'] === 'admin');
}

function is_records_user($user)
{
    return is_administrator($user) || (($user['active_role'] ?? '') === 'ovp_central_records');
}

function require_administrator($user)
{
    if (!is_administrator($user)) {
        api_error('Administrator access is required.', 403);
    }
}

function google_token_info($credential)
{
    $verifier = new GoogleIdentity(env_value('GOOGLE_CLIENT_ID'), 'google_signing_certificates');
    return $verifier->verify($credential);
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

function assert_allowed_account_email($email, $status = 403)
{
    $table = sql_identifier(env_value('AUTH_ACCOUNT_TABLE', 'tblaccount'), 'AUTH_ACCOUNT_TABLE');
    $column = sql_identifier(env_value('AUTH_ACCOUNT_EMAIL_COLUMN', 'acc_email'), 'AUTH_ACCOUNT_EMAIL_COLUMN');
    $statement = database()->prepare('SELECT 1 FROM ' . $table . ' WHERE LOWER(TRIM(' . $column . ')) = LOWER(?) LIMIT 1');
    $statement->execute(array(trim($email)));

    if ($statement->fetchColumn() === false) {
        throw new AuthenticationException('Your Google account is not authorized to use PAD. Ask the Records Office to add your email to the account list.', $status);
    }
}

function authenticate_google_user($credential)
{
    return sync_google_user(google_token_info($credential));
}

// Only call after cryptographic verification of the Google credential.
function sync_google_user($claims)
{
    $email = strtolower(trim($claims['email']));
    $name = isset($claims['name']) && is_string($claims['name']) ? trim($claims['name']) : '';
    $name = $name !== '' ? $name : $email;
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 160, 'UTF-8') : substr($name, 0, 160);
    $avatar = isset($claims['picture']) && is_string($claims['picture']) && strlen($claims['picture']) <= 500
        && strpos($claims['picture'], 'https://') === 0 ? $claims['picture'] : null;

    // tblaccount is the authoritative allow-list. Google verification alone never creates access.
    assert_allowed_account_email($email);
    $pdo = database();

    $firstAdminEmail = strtolower(trim(env_value('FIRST_ADMIN_EMAIL', '')));
    $isFirstAdministrator = $firstAdminEmail !== '' && hash_equals($firstAdminEmail, $email);

    $find = $pdo->prepare('SELECT id, name, google_subject, is_active FROM users WHERE google_subject = ? LIMIT 1');
    $find->execute(array($claims['sub']));
    $existing = $find->fetch();
    if (!$existing) {
        $find = $pdo->prepare('SELECT id, name, google_subject, is_active FROM users WHERE email = ? LIMIT 1');
        $find->execute(array($email));
        $existing = $find->fetch();
    }

    if ($existing) {
        if (!(int) $existing['is_active']) {
            throw new AuthenticationException('This account is not active.', 403);
        }
        if ($existing['google_subject'] && !hash_equals($existing['google_subject'], $claims['sub'])) {
            throw new AuthenticationException('This email is linked to a different Google account. Contact the Records Office.', 403);
        }
        $conflict = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $conflict->execute(array($email, $existing['id']));
        if ($conflict->fetch()) {
            throw new AuthenticationException('This email is linked to another PAD profile. Contact the Records Office.', 403);
        }
        $update = $pdo->prepare('UPDATE users SET name = ?, email = ?, avatar_url = ?, google_subject = ?, last_login_at = NOW(), updated_at = NOW() WHERE id = ?');
        $update->execute(array($existing['name'] ?: $name, $email, $avatar, $claims['sub'], $existing['id']));
        return $existing['id'];
    }

    $role = $isFirstAdministrator ? 'admin' : 'router';
    $insert = $pdo->prepare("INSERT INTO users (name, email, avatar_url, google_subject, role, roles, is_active, last_login_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW())");
    $insert->execute(array($name, $email, $avatar, $claims['sub'], $role, $role));
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
        'SELECT r.id, r.action, r.notes, r.from_office_id, r.to_office_id, r.dispatched_at, r.received_at, r.created_at,
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
