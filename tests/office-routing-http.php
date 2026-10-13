<?php

// Loopback-only router for the isolated routing test database; uses real PAD authentication and document endpoints.
if (PHP_SAPI !== 'cli-server' || !preg_match('/^pad_routing_test_[a-f0-9]{12}$/', getenv('DB_DATABASE') ?: '')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', array('127.0.0.1', '::1'), true)) {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';
set_exception_handler(function ($error) {
    if ($error instanceof ApiException) api_error($error->getMessage(), $error->getCode());
    api_error('Test request failed.', 500);
});
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$user = current_user();
require dirname(__DIR__) . '/src/admin-routes.php';
require dirname(__DIR__) . '/src/document-routes.php';
api_error('Unknown test endpoint.', 404);
