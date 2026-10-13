<?php

require_once __DIR__ . '/DocumentRouting.php';
if (array_key_exists('active_role', $user) && $user['active_role'] === null) {
    throw new ApiException('Choose an account role before opening a module.', 403);
}
if (strpos($path, '/api/doc_router/') === 0) {
    require __DIR__ . '/doc_router/routes.php';
}
$routing = new DocumentRouting(database(), $user);

if ($method === 'GET' && $path === '/api/routing/statistics') {
    require_once __DIR__ . '/DocumentStatistics.php';
    header('Cache-Control: no-store');
    json_response((new DocumentStatistics(database(), $user))->report($_GET));
}

if ($method === 'GET' && $path === '/api/routing/registration-options') {
    json_response($routing->registrationOptions());
}

if ($method === 'GET' && $path === '/api/routing/workspace') {
    json_response($routing->workspace($_GET));
}

if ($method === 'GET' && $path === '/api/documents') {
    json_response($routing->workspace($_GET));
}

if ($method === 'POST' && $path === '/api/documents') {
    $result = $routing->register($_POST, isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : array());
    $result['message'] = 'Document registered.';
    json_response($result, $result['replayed'] ? 200 : 201);
}

if (preg_match('#^/api/documents/([A-Za-z0-9-]+)$#', $path, $matches) && $method === 'GET') {
    json_response(array('data' => $routing->record($matches[1], true)));
}

if (preg_match('#^/api/documents/([A-Za-z0-9-]+)$#', $path, $matches) && $method === 'DELETE') {
    json_response($routing->deleteDocument($matches[1]));
}

if (preg_match('#^/api/documents/([A-Za-z0-9-]+)/download$#', $path, $matches) && $method === 'GET') {
    $document = $routing->record($matches[1]);
    if (!$document['stored_file_name']) throw new ApiException('No document file has been uploaded yet.', 404);
    $filePath = safe_upload_directory() . DIRECTORY_SEPARATOR . basename($document['stored_file_name']);
    if (!is_file($filePath)) throw new ApiException('The stored document could not be found.', 404);
    header('Content-Type: ' . $document['file_mime']);
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: attachment; filename="' . str_replace(array('"', "\r", "\n"), '', $document['file_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
}

if (preg_match('#^/api/documents/([A-Za-z0-9-]+)/file$#', $path, $matches) && $method === 'POST') {
    json_response($routing->uploadFile($matches[1], isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : array()));
}

if (preg_match('#^/api/documents/([A-Za-z0-9-]+)/(route|receive|return|act|complete|deliver|decide)$#', $path, $matches) && $method === 'POST') {
    // Resolve missing/inaccessible references before reporting an office assignment error.
    $routing->record($matches[1]);
    json_response($routing->action($matches[1], $matches[2], request_json()));
}
