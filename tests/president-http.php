<?php
// Test router for the real public entry point in an isolated database.
if (PHP_SAPI !== 'cli-server' || !preg_match('/^pad_president_test_[a-f0-9]{12}$/', getenv('DB_DATABASE') ?: '')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', array('127.0.0.1', '::1'), true)) {
    http_response_code(404);
    exit;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__) . '/public/index.php';
