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

// On cPanel the public directory is normally the subdomain document root, so
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
        'SELECT users.id, users.email, users.name, users.avatar_url, users.role, users.office_id, users.is_active, offices.code AS office_code, offices.name AS office_name
         FROM users LEFT JOIN offices ON offices.id = users.office_id WHERE users.id = ? LIMIT 1'
    );
    $statement->execute(array($userId));
    $user = $statement->fetch();
    if (!$user || !(int) $user['is_active']) {
        api_error('This account is not active.', 403);
    }

    json_response(array('access_token' => create_access_token($user), 'token_type' => 'Bearer', 'expires_in' => 28800, 'user' => $user));
}

$user = current_user();

if ($method === 'GET' && $path === '/api/me') {
    json_response(array('user' => $user));
}

require_once dirname(__DIR__) . '/src/admin-routes.php';

if ($method === 'GET' && $path === '/api/admin/users') {
    require_administrator($user);
    $statement = database()->query(
        'SELECT users.id, users.name, users.email, users.role, users.office_id, users.is_active, users.last_login_at, users.created_at,
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
    $find = $pdo->prepare('SELECT id, role, office_id, is_active FROM users WHERE id = ? LIMIT 1');
    $find->execute(array($userId));
    $target = $find->fetch();
    if (!$target) {
        api_error('User not found.', 404);
    }

    $role = isset($payload['role']) ? $payload['role'] : $target['role'];
    $allowedRoles = array('staff', 'records', 'pad', 'admin');
    if (!in_array($role, $allowedRoles, true)) {
        api_error('Select a valid role.', 422);
    }

    $isActive = array_key_exists('is_active', $payload) ? (filter_var($payload['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : (int) $target['is_active'];
    $officeId = array_key_exists('office_id', $payload) && $payload['office_id'] !== null && $payload['office_id'] !== '' ? (int) $payload['office_id'] : null;

    if ($officeId !== null) {
        $office = $pdo->prepare('SELECT id FROM offices WHERE id = ? AND is_active = 1 LIMIT 1');
        $office->execute(array($officeId));
        if (!$office->fetch()) {
            api_error('Select an active office.', 422);
        }
    }

    if ((int) $user['id'] === $userId && ($role !== 'admin' || $isActive !== 1)) {
        api_error('You cannot remove your own administrator access.', 422);
    }

    $update = $pdo->prepare('UPDATE users SET role = ?, office_id = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
    $update->execute(array($role, $officeId, $isActive, $userId));
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
    json_response(array('data' => $offices));
}

if ($method === 'GET' && $path === '/api/documents') {
    $parameters = array();
    $where = array();
    if (!is_records_user($user)) {
        if (empty($user['office_id'])) {
            json_response(array('data' => array()));
        }
        $where[] = '(d.source_office_id = ? OR d.current_office_id = ? OR d.recipient_office_id = ?)';
        $parameters[] = $user['office_id'];
        $parameters[] = $user['office_id'];
        $parameters[] = $user['office_id'];
    }
    if (!empty($_GET['status'])) {
        $where[] = 'd.status = ?';
        $parameters[] = $_GET['status'];
    }

    $sql = 'SELECT d.id, d.reference_no, d.subject, d.status, d.origin_type, d.created_at, d.updated_at, d.file_name,
            source.name AS source_office_name, current.name AS current_office_name, recipient.name AS recipient_office_name
            FROM documents d
            LEFT JOIN offices source ON source.id = d.source_office_id
            LEFT JOIN offices current ON current.id = d.current_office_id
            LEFT JOIN offices recipient ON recipient.id = d.recipient_office_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY d.updated_at DESC LIMIT 100';
    $statement = database()->prepare($sql);
    $statement->execute($parameters);
    json_response(array('data' => $statement->fetchAll()));
}

if ($method === 'POST' && $path === '/api/documents') {
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        api_error('Attach a document file to upload.', 422);
    }
    if (!isset($_POST['subject']) || trim($_POST['subject']) === '') {
        api_error('Document subject is required.', 422);
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        api_error('The document upload failed.', 422);
    }
    $maxBytes = (int) env_value('MAX_UPLOAD_BYTES', '10485760');
    if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
        api_error('The file must be smaller than ' . floor($maxBytes / 1048576) . ' MB.', 422);
    }

    $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : $file['type'];
    $allowedMimes = array(
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'image/jpeg' => 'jpg',
        'image/png' => 'png'
    );
    if (!isset($allowedMimes[$mime])) {
        api_error('Only PDF, Word, Excel, JPG, and PNG files are allowed.', 422);
    }

    $originType = isset($_POST['origin_type']) && $_POST['origin_type'] === 'external' ? 'external' : 'office';
    $recipientOfficeId = isset($_POST['recipient_office_id']) && $_POST['recipient_office_id'] !== '' ? (int) $_POST['recipient_office_id'] : null;
    $pdo = database();

    $records = $pdo->query("SELECT id FROM offices WHERE code = 'RECORDS' AND is_active = 1 LIMIT 1")->fetch();
    if ($originType === 'external' && !$records) {
        api_error('Create the RECORDS office before receiving outside documents.', 422);
    }
    if ($originType === 'external' && is_records_user($user) === false) {
        api_error('Only the Records Office may receive outside documents.', 403);
    }
    if ($originType === 'office' && empty($user['office_id'])) {
        api_error('Assign this user to an office before sending documents.', 422);
    }
    if ($recipientOfficeId) {
        $checkOffice = $pdo->prepare('SELECT id FROM offices WHERE id = ? AND is_active = 1 LIMIT 1');
        $checkOffice->execute(array($recipientOfficeId));
        if (!$checkOffice->fetch()) {
            api_error('The recipient office is invalid.', 422);
        }
    }

    $reference = random_reference();
    $storedName = strtolower(str_replace('-', '', $reference)) . '-' . time() . '.' . $allowedMimes[$mime];
    $destination = safe_upload_directory() . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        api_error('The uploaded file could not be stored.', 500);
    }

    $currentOfficeId = $originType === 'external' ? $records['id'] : $user['office_id'];
    $status = $originType === 'external' ? 'received' : 'created';
    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO documents (reference_no, subject, description, origin_type, external_sender, source_office_id, recipient_office_id, current_office_id, status, file_name, stored_file_name, file_mime, file_size, created_by_user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $insert->execute(array(
            $reference,
            trim($_POST['subject']),
            isset($_POST['description']) ? trim($_POST['description']) : null,
            $originType,
            $originType === 'external' && isset($_POST['external_sender']) ? trim($_POST['external_sender']) : null,
            $originType === 'external' ? null : $user['office_id'],
            $recipientOfficeId,
            $currentOfficeId,
            $status,
            basename($file['name']),
            $storedName,
            $mime,
            (int) $file['size'],
            $user['id']
        ));
        $documentId = (int) $pdo->lastInsertId();
        $route = $pdo->prepare('INSERT INTO document_routes (document_id, from_office_id, to_office_id, action, notes, received_at, acted_by_user_id, created_at) VALUES (?, NULL, ?, ?, ?, NOW(), ?, NOW())');
        $route->execute(array($documentId, $currentOfficeId, $originType === 'external' ? 'externally_received' : 'created', $originType === 'external' ? 'Received by Records Office' : 'Created by originating office', $user['id']));
        $pdo->commit();
    } catch (Exception $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @unlink($destination);
        throw $exception;
    }

    json_response(array('reference_no' => $reference, 'message' => 'Document uploaded and logged.'), 201);
}

if (preg_match('#^/api/documents/([A-Za-z0-9-]+)$#', $path, $matches) && $method === 'GET') {
    $document = document_record($matches[1], $user);
    $document['history'] = document_history($document['id']);
    json_response(array('data' => $document));
}

if (preg_match('#^/api/documents/([A-Za-z0-9-]+)/download$#', $path, $matches) && $method === 'GET') {
    $document = document_record($matches[1], $user);
    $filePath = safe_upload_directory() . DIRECTORY_SEPARATOR . $document['stored_file_name'];
    if (!is_file($filePath)) {
        api_error('The stored document could not be found.', 404);
    }
    header('Content-Type: ' . $document['file_mime']);
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $document['file_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
}

if (preg_match('#^/api/documents/([A-Za-z0-9-]+)/route$#', $path, $matches) && $method === 'POST') {
    $document = document_record($matches[1], $user);
    $payload = request_json();
    $officeId = isset($payload['to_office_id']) ? (int) $payload['to_office_id'] : 0;
    if (!$officeId || $officeId === (int) $document['current_office_id']) {
        api_error('Choose a different destination office.', 422);
    }
    if (!is_records_user($user) && (int) $user['office_id'] !== (int) $document['current_office_id']) {
        api_error('Only the current office may route this document.', 403);
    }
    $office = database()->prepare('SELECT id, name FROM offices WHERE id = ? AND is_active = 1 LIMIT 1');
    $office->execute(array($officeId));
    if (!$office->fetch()) {
        api_error('The destination office is invalid.', 422);
    }

    $pdo = database();
    $pdo->beginTransaction();
    $update = $pdo->prepare("UPDATE documents SET current_office_id = ?, status = 'in_transit', updated_at = NOW() WHERE id = ?");
    $update->execute(array($officeId, $document['id']));
    $insert = $pdo->prepare("INSERT INTO document_routes (document_id, from_office_id, to_office_id, action, notes, dispatched_at, acted_by_user_id, created_at) VALUES (?, ?, ?, 'routed', ?, NOW(), ?, NOW())");
    $insert->execute(array($document['id'], $document['current_office_id'], $officeId, isset($payload['notes']) ? trim($payload['notes']) : null, $user['id']));
    $pdo->commit();
    json_response(array('message' => 'Document routed and marked in transit.'));
}

if (preg_match('#^/api/documents/([A-Za-z0-9-]+)/receive$#', $path, $matches) && $method === 'POST') {
    $document = document_record($matches[1], $user);
    if (!is_records_user($user) && (int) $user['office_id'] !== (int) $document['current_office_id']) {
        api_error('Only the destination office may receive this document.', 403);
    }
    if ($document['status'] !== 'in_transit') {
        api_error('Only in-transit documents can be received.', 422);
    }
    $payload = request_json();
    $pdo = database();
    $pdo->beginTransaction();
    $update = $pdo->prepare("UPDATE documents SET status = 'received', updated_at = NOW() WHERE id = ?");
    $update->execute(array($document['id']));
    $route = $pdo->prepare("UPDATE document_routes SET action = 'received', received_at = NOW(), notes = COALESCE(?, notes) WHERE document_id = ? AND to_office_id = ? AND received_at IS NULL ORDER BY id DESC LIMIT 1");
    $route->execute(array(isset($payload['notes']) ? trim($payload['notes']) : null, $document['id'], $document['current_office_id']));
    $pdo->commit();
    json_response(array('message' => 'Document receipt recorded.'));
}

api_error('Endpoint not found.', 404);
