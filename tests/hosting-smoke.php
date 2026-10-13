<?php

// Read-only Apache checks. A locally signed PAD token exercises the workspace
// using an existing approved profile; no Google login or data changes occur.
require dirname(__DIR__) . '/src/bootstrap.php';

$origin = rtrim($argv[1] ?? 'http://localhost', '/');
if (env_value('APP_ENV') !== 'local'
    || !in_array(parse_url($origin, PHP_URL_HOST), array('localhost', '127.0.0.1', '::1'), true)) {
    throw new RuntimeException('Run this check only against the local PAD installation.');
}
if (!function_exists('curl_init')) {
    throw new RuntimeException('Enable PHP cURL to run the Apache hosting checks.');
}

$checks = 0;
function hosting_check($condition, $message)
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

function hosting_request($path, $method = 'GET', $headers = array(), $body = null)
{
    global $origin;
    $responseHeaders = array();
    $curl = curl_init($origin . $path);
    curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$responseHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            return strlen($line);
        }
    ));
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    $content = curl_exec($curl);
    if ($content === false) throw new RuntimeException('Local HTTP request failed: ' . curl_error($curl));
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return array('status' => $status, 'headers' => $responseHeaders, 'body' => $content);
}

$page = hosting_request('/pad-web/');
hosting_check($page['status'] === 200, 'Apache must serve /pad-web/.');
hosting_check(strpos($page['body'], '/src/app.js') === false, 'Apache must serve the compiled frontend.');
hosting_check(strpos($page['body'], 'data-assets-path="./assets/"') !== false, 'Template assets must support the subdirectory.');
$redirect = hosting_request('/pad-web');
hosting_check($redirect['status'] === 301 && ($redirect['headers']['location'] ?? '') === $origin . '/pad-web/', 'The URL without a slash must redirect to /pad-web/.');
preg_match_all('/(?:src|href)="\.\/(assets\/[^"<>]+)"/', $page['body'], $assets);
hosting_check(count($assets[1]) >= 10, 'The compiled page must include its scripts and styles.');
foreach (array_unique($assets[1]) as $asset) {
    $response = hosting_request('/pad-web/' . $asset);
    hosting_check($response['status'] === 200 && strlen($response['body']) > 0, 'Asset failed: ' . $asset);
}
$font = hosting_request('/pad-web/assets/vendor/fonts/boxicons/boxicons.woff2');
hosting_check($font['status'] === 200, 'The icon font must load under /pad-web/.');

foreach (array('/pad-web/.env', '/pad-web/.git/config', '/pad-web/src/app.js', '/pad-web/package.json',
    '/pad-api/.env', '/pad-api/.git/config', '/pad-api/src/bootstrap.php', '/pad-api/database/schema.sql',
    '/pad-api/storage/google-certificates.json', '/pad-api/storage/maintenance-backups/blocked.json') as $privatePath) {
    hosting_check(hosting_request($privatePath)['status'] === 403, 'Private path must be blocked: ' . $privatePath);
}

$health = hosting_request('/pad-api/public/api/health', 'GET', array('Origin: ' . $origin));
hosting_check($health['status'] === 200 && (json_decode($health['body'], true)['status'] ?? '') === 'ok', 'The API health route must work through Apache.');
hosting_check(($health['headers']['access-control-allow-origin'] ?? '') === $origin, 'The local origin must be allowed.');
$foreign = hosting_request('/pad-api/public/api/health', 'GET', array('Origin: https://untrusted.example'));
hosting_check(!isset($foreign['headers']['access-control-allow-origin']), 'Unlisted origins must not receive CORS access.');
$config = hosting_request('/pad-api/public/api/auth/google/config');
hosting_check($config['status'] === 200 && (json_decode($config['body'], true)['enabled'] ?? false), 'Google public configuration must be available.');
hosting_check(hosting_request('/pad-api/public/api/me')['status'] === 401, 'Missing bearer tokens must be rejected.');
hosting_check(hosting_request('/pad-api/public/api/me', 'GET', array('Authorization: Bearer invalid'))['status'] === 401, 'Invalid bearer tokens must be rejected.');
hosting_check(hosting_request('/pad-api/public/api/auth/google', 'POST', array('Content-Type: application/json'), '{}')['status'] === 422, 'Missing Google credentials must be rejected.');
hosting_check(hosting_request('/pad-api/public/api/auth/google', 'POST', array('Content-Type: application/json'), '{"credential":"invalid"}')['status'] === 401, 'Malformed Google credentials must be rejected.');

$profiles = database()->query("SELECT * FROM users WHERE is_active = 1 ORDER BY (role = 'admin') DESC, id")->fetchAll();
$actor = null;
foreach ($profiles as $profile) {
    try {
        assert_allowed_account_email($profile['email']);
        $actor = $profile;
        break;
    } catch (AuthenticationException $error) {
        // Continue to the next existing profile if its approval was removed.
    }
}
hosting_check($actor !== null, 'An existing active, approved PAD profile is required for workspace checks.');
$testRole = in_array('admin', account_roles($actor), true) ? 'admin' : 'router';
$headers = array('Authorization: Bearer ' . create_access_token($actor), 'X-PAD-Role: ' . $testRole);
$paths = array('/api/me', '/api/offices', '/api/documents', '/api/routing/registration-options');
if ($testRole === 'admin') {
    $paths = array_merge($paths, array('/api/admin/overview', '/api/admin/accounts', '/api/admin/offices', '/api/admin/officials', '/api/admin/users', '/api/admin/access-list', '/api/admin/database'));
}
foreach ($paths as $path) {
    $response = hosting_request('/pad-api/public' . $path, 'GET', $headers);
    $data = json_decode($response['body'], true);
    hosting_check($response['status'] === 200 && is_array($data) && !isset($data['message']), 'Authenticated workspace route failed: ' . $path);
    if (in_array($path, array('/api/admin/accounts', '/api/admin/offices', '/api/admin/officials'), true) && !empty($data['data'])) {
        $recordResponse = hosting_request('/pad-api/public' . $path . '/' . $data['data'][0]['id'], 'GET', $headers);
        hosting_check($recordResponse['status'] === 200 && isset(json_decode($recordResponse['body'], true)['data']['id']), 'Individual administration record must be readable: ' . $path);
    }
}

if ($testRole === 'admin') {
    $inventory = json_decode(hosting_request('/pad-api/public/api/admin/database', 'GET', $headers)['body'], true);
    $eligible = array_values(array_filter($inventory['tables'], function ($table) { return $table['can_clear']; }));
    if ($eligible) {
        // Preview is read-only: do not submit a clear operation against the live installation.
        $response = hosting_request('/pad-api/public/api/admin/database/preview', 'POST', array_merge($headers, array('Content-Type: application/json')), json_encode(array('table' => $eligible[0]['name'])));
        $review = json_decode($response['body'], true);
        if (!is_array($review)) throw new RuntimeException('Maintenance preview returned invalid JSON: ' . substr($response['body'], 0, 1000));
        hosting_check($response['status'] === 200 && isset($review['counts'], $review['token'], $review['label']) && $review['total_records'] > 0, 'The administrative clear review must load through Apache.');
    }
    // An ID larger than any INT UNSIGNED key cannot match a real PAD record.
    foreach (array('accounts', 'offices', 'officials') as $module) {
        foreach (array('GET', 'PUT', 'PATCH', 'DELETE') as $method) {
            $response = hosting_request('/pad-api/public/api/admin/' . $module . '/4294967296', $method, array_merge($headers, array('Content-Type: application/json')), '{}');
            hosting_check($response['status'] === 404, 'Missing administration record must return 404: ' . $method . ' ' . $module);
        }
    }
}

// Validation paths must leave both the database and file storage unchanged.
hosting_check(hosting_request('/pad-api/public/api/documents', 'POST', $headers)['status'] === 422, 'Upload validation must work through Apache.');
$missingReference = 'PAD-HOSTING-' . bin2hex(random_bytes(6));
foreach (array('', '/download', '/route', '/receive') as $action) {
    $method = in_array($action, array('/route', '/receive'), true) ? 'POST' : 'GET';
    hosting_check(hosting_request('/pad-api/public/api/documents/' . $missingReference . $action, $method, $headers)['status'] === 404, 'Document endpoint must handle a missing reference: ' . $action);
}

echo 'Passed ' . $checks . ' Apache hosting checks without changing PAD data.' . PHP_EOL;
