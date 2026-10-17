<?php

// Read-only hosting checks. No schema changes, imports, or file writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

$failures = 0;
function production_check($ok, $label)
{
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures++;
}

function production_origin($value)
{
    $parts = parse_url($value);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || in_array(strtolower($parts['host']), array('localhost', '127.0.0.1', '::1', '[::1]'), true)
        || strpos($value, '*') !== false
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return false;
    return $value === 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
}

production_check(version_compare(PHP_VERSION, '7.4', '>='), 'PHP 7.4 or newer');
foreach (array('pdo_mysql', 'openssl', 'fileinfo') as $extension) {
    production_check(extension_loaded($extension), 'PHP extension: ' . $extension);
}
production_check(function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN), 'Outbound HTTPS support for Google verification');
production_check(env_value('APP_ENV') === 'production', 'APP_ENV is production');
production_check(!app_is_debug(), 'APP_DEBUG is disabled');
production_check(rtrim(env_value('APP_URL', ''), '/') === 'https://padapi.sksu-orms.net', 'APP_URL is the intended HTTPS API domain');
$key = env_value('APP_KEY', '');
production_check(strlen($key) >= 32 && strpos($key, 'replace-with') !== 0, 'APP_KEY is configured with at least 32 characters');
$client = env_value('GOOGLE_CLIENT_ID', '');
production_check(preg_match('/^[A-Za-z0-9_-]+\.apps\.googleusercontent\.com$/', $client) && strpos($client, 'your-google') !== 0, 'Google Web application client ID is configured');

$origins = array_values(array_filter(array_map('trim', explode(',', env_value('APP_ALLOWED_ORIGINS', '')))));
production_check(count($origins) > 0, 'Verified frontend origin is configured in APP_ALLOWED_ORIGINS');
foreach ($origins as $index => $origin) {
    production_check(production_origin($origin), 'Frontend origin ' . ($index + 1) . ' is an explicit HTTPS origin without a path');
}

$public = realpath(PAD_ROOT . '/public');
$configuredUpload = env_value('UPLOAD_PATH', 'storage/documents');
$uploadPath = preg_match('/^[A-Za-z]:[\\\\\/]/', $configuredUpload) || strpos($configuredUpload, '/') === 0
    ? $configuredUpload : PAD_ROOT . DIRECTORY_SEPARATOR . $configuredUpload;
foreach (array('Storage/cache' => PAD_ROOT . '/storage', 'Documents' => $uploadPath, 'Recovery backups' => PAD_ROOT . '/storage/maintenance-backups') as $label => $directory) {
    $resolved = realpath($directory);
    $outsidePublic = $resolved !== false && $public !== false && $resolved !== $public
        && strpos($resolved . DIRECTORY_SEPARATOR, $public . DIRECTORY_SEPARATOR) !== 0;
    production_check($outsidePublic && is_dir($directory) && is_writable($directory), $label . ' directory exists, is writable, and is outside public');
}
production_check((int) env_value('MAX_UPLOAD_BYTES', '10485760') > 0, 'MAX_UPLOAD_BYTES is positive; verify web PHP upload limits separately');

$db = null;
try {
    if (!env_value('DB_HOST') || !env_value('DB_DATABASE') || !env_value('DB_USERNAME')) throw new RuntimeException('Missing database configuration');
    $db = new PDO('mysql:host=' . env_value('DB_HOST') . ';port=' . env_value('DB_PORT', '3306') . ';dbname=' . env_value('DB_DATABASE') . ';charset=utf8mb4',
        env_value('DB_USERNAME'), env_value('DB_PASSWORD', ''), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5));
    production_check(true, 'Hosting database connection');
} catch (Throwable $error) {
    production_check(false, 'Hosting database connection; check the private database settings');
}

if ($db) {
    $schema = file_get_contents(PAD_ROOT . '/database/schema.sql');
    preg_match_all('/CREATE TABLE ([A-Za-z_][A-Za-z0-9_]*)\s*\((.*?)\) ENGINE=/s', $schema, $definitions, PREG_SET_ORDER);
    production_check(count($definitions) > 0, 'Reference schema is available');
    foreach ($definitions as $definition) {
        try {
            $columns = $db->query('SHOW COLUMNS FROM `' . $definition[1] . '`')->fetchAll(PDO::FETCH_COLUMN);
            preg_match_all('/^[ \t]+([a-z_][a-z0-9_]*)[ \t]+/m', $definition[2], $expected);
            $missing = array_diff($expected[1], $columns);
            production_check(!$missing, 'Schema: ' . $definition[1] . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));
        } catch (Throwable $error) {
            production_check(false, 'Schema: ' . $definition[1] . ' is missing or unavailable');
        }
    }
    try {
        $accountTable = env_value('AUTH_ACCOUNT_TABLE', 'tblaccount');
        $accountEmail = env_value('AUTH_ACCOUNT_EMAIL_COLUMN', 'acc_email');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $accountTable) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $accountEmail)) throw new RuntimeException('Invalid account identifiers');
        $columns = $db->query('SHOW COLUMNS FROM `' . $accountTable . '`')->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff(array('acc_id', 'acc_name', 'acc_position', 'campus', $accountEmail), $columns);
        production_check(!$missing, 'Existing approved-account table supports login and administration');
    } catch (Throwable $error) {
        production_check(false, 'Existing approved-account table is available; retain the real PAD account schema');
    }
}

echo PHP_EOL . ($failures ? 'Production checks failed: ' . $failures . '. Correct these settings before publishing the frontend.' : 'Production configuration checks passed. Run check-hosting.php and browser sign-in checks next.') . PHP_EOL;
exit($failures ? 1 : 0);
