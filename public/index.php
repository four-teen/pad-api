<?php

require_once dirname(__DIR__) . '/src/bootstrap.php';

apply_cors();

set_exception_handler(function ($exception) {
    if ($exception instanceof ApiException) {
        api_error($exception->getMessage(), $exception->getCode());
    }
    api_error('An unexpected server error occurred.', 500, $exception->getMessage());
});

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$path = '/' . trim(str_replace('/index.php', '', $path), '/');

// When public is the virtual host document root,
// requests arrive as /api/.... Local Apache installations may expose it as
// /pad-api/public/api/.... Remove that script-directory prefix so both
// installations use the same API routes.
$scriptDirectory = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])) : '';
$scriptDirectory = rtrim($scriptDirectory, '/');
if ($scriptDirectory !== '' && $scriptDirectory !== '.' && strpos($path, $scriptDirectory . '/') === 0) {
    $path = substr($path, strlen($scriptDirectory));
}

if ($method === 'GET' && $path === '/api/health') {
    json_response(array('status' => 'ok', 'service' => 'pad-api', 'name' => 'PAD', 'full_name' => 'Presidential Action Desk', 'system' => 'Electronic Document Routing, Tracking, and Executive Action System', 'time' => gmdate('c')));
}

if ($method === 'GET' && $path === '/api/auth/google/config') {
    header('Cache-Control: no-store');
    $clientId = env_value('GOOGLE_CLIENT_ID');
    json_response(array('enabled' => !empty($clientId) && strpos($clientId, 'your-google') !== 0, 'client_id' => $clientId));
}

if ($method === 'POST' && $path === '/api/auth/google') {
    header('Cache-Control: no-store');
    $payload = request_json();
    if (empty($payload['credential']) || !is_string($payload['credential'])) {
        api_error('Google credential is required.', 422);
    }

    $userId = authenticate_google_user($payload['credential']);
    $statement = database()->prepare(
        'SELECT users.id, users.email, users.name, users.avatar_url, users.role, users.roles, users.office_id, users.ovp_pad_office_id, users.ovp_central_records_office_id, users.is_active, offices.code AS office_code, offices.name AS office_name, offices.campus AS office_campus, offices.is_active AS office_is_active,
         EXISTS (SELECT 1 FROM office_officials f WHERE f.office_id = users.office_id) AS office_has_officials
         FROM users LEFT JOIN offices ON offices.id = users.office_id WHERE users.id = ? LIMIT 1'
    );
    $statement->execute(array($userId));
    $user = $statement->fetch();
    if (!$user || !(int) $user['is_active']) {
        api_error('This account is not active.', 403);
    }
    $user['roles'] = account_roles($user);
    $user['active_role'] = count($user['roles']) === 1 ? $user['roles'][0] : null;
    $user = apply_active_module_office($user);

    json_response(array('access_token' => create_access_token($user), 'token_type' => 'Bearer', 'expires_in' => 28800, 'user' => $user));
}

$user = current_user();
assert_monitoring_method($user, $method);
if (is_presidential_view($user)) header('Cache-Control: no-store');

if ($method === 'GET' && $path === '/api/me') {
    header('Cache-Control: no-store');
    json_response(array('user' => $user));
}

require_once dirname(__DIR__) . '/src/president-routes.php';
require_once dirname(__DIR__) . '/src/admin-routes.php';

if ($method === 'GET' && $path === '/api/admin/users') {
    require_administrator($user);
    $statement = database()->query(
        'SELECT users.id, users.name, users.email, users.role, users.roles, users.office_id, users.ovp_pad_office_id, users.ovp_central_records_office_id, users.is_active, users.last_login_at, users.created_at,
         offices.name AS office_name, offices.code AS office_code
         FROM users
         LEFT JOIN offices ON offices.id = users.office_id
         ORDER BY users.is_active DESC, users.name ASC, users.email ASC
         LIMIT 500'
    );
    json_response(array('data' => $statement->fetchAll()));
}

if (preg_match('#^/api/admin/users/([0-9]+)$#', $path, $matches) && $method === 'POST') {
    require_administrator($user);
    $payload = request_json();
    $userId = (int) $matches[1];
    $pdo = database();
    $find = $pdo->prepare('SELECT id, email, role, roles, office_id, is_active FROM users WHERE id = ? LIMIT 1');
    $find->execute(array($userId));
    $target = $find->fetch();
    if (!$target) {
        api_error('User not found.', 404);
    }

    // Keep the legacy endpoint under the same multi-role and self-access checks.
    $admin = new Administration($pdo, $user);
    $accountId = null;
    foreach ($admin->accounts() as $account) if ((int) $account['user_id'] === $userId) { $accountId = $account['id']; break; }
    if ($accountId === null) api_error('Approved account not found.', 404);
    $changes = array_intersect_key($payload, array_flip(array('role', 'roles', 'office_id', 'ovp_pad_office_id', 'ovp_central_records_office_id', 'is_active')));
    $admin->saveAccount($changes, $accountId);
    json_response(array('message' => 'User access updated.'));
}

if ($method === 'GET' && $path === '/api/admin/access-list') {
    require_administrator($user);
    $table = sql_identifier(env_value('AUTH_ACCOUNT_TABLE', 'tblaccount'), 'AUTH_ACCOUNT_TABLE');
    $column = sql_identifier(env_value('AUTH_ACCOUNT_EMAIL_COLUMN', 'acc_email'), 'AUTH_ACCOUNT_EMAIL_COLUMN');
    $statement = database()->query('SELECT ' . $column . ' AS email FROM ' . $table . ' WHERE ' . $column . ' IS NOT NULL AND TRIM(' . $column . ") <> '' ORDER BY " . $column . ' ASC LIMIT 500');
    json_response(array('data' => $statement->fetchAll()));
}

if ($method === 'GET' && $path === '/api/offices') {
    $offices = database()->query('SELECT id, code, name, description, campus, domain, contact_email FROM offices WHERE is_active = 1 ORDER BY campus, name')->fetchAll();
    foreach ($offices as &$office) $office['can_receive_documents'] = !is_presidential_office($office);
    unset($office);
    json_response(array('data' => $offices));
}

require_once dirname(__DIR__) . '/src/document-routes.php';

api_error('Endpoint not found.', 404);
