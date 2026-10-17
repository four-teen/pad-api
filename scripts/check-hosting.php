<?php

// Public HTTP checks only. No login, document updates, or database changes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$api = rtrim($argv[1] ?? '', '/');
$frontend = $argv[2] ?? '';
$local = ($argv[3] ?? '') === '--local';
$apiParts = parse_url($api);
$frontendParts = parse_url($frontend);
if (!$apiParts || !$frontendParts || !function_exists('curl_init')) {
    fwrite(STDERR, 'Usage: php scripts/check-hosting.php https://padapi.sksu-orms.net https://YOUR-VERCEL-DOMAIN (requires PHP cURL)' . PHP_EOL);
    exit(1);
}
$frontendOrigin = ($frontendParts['scheme'] ?? '') . '://' . ($frontendParts['host'] ?? '') . (isset($frontendParts['port']) ? ':' . $frontendParts['port'] : '');
$localApi = $local && in_array($apiParts['host'] ?? '', array('localhost', '127.0.0.1', '::1', '[::1]'), true) && ($apiParts['scheme'] ?? '') === 'http';
if (empty($frontendParts['host']) || $frontend !== $frontendOrigin || strpos($frontend, '*') !== false
    || ($frontendParts['scheme'] ?? '') !== 'https' || isset($apiParts['user']) || isset($apiParts['pass'])
    || isset($apiParts['query']) || isset($apiParts['fragment'])
    || (($apiParts['scheme'] ?? '') !== 'https' && !$localApi)) {
    fwrite(STDERR, 'Use an HTTPS API base and the exact HTTPS frontend origin, without a trailing slash or path. --local permits a loopback HTTP API only.' . PHP_EOL);
    exit(1);
}

$failures = 0;
function hosting_check_result($ok, $label)
{
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures++;
}
function hosting_http($path, $method, array $headers)
{
    global $api;
    $responseHeaders = array();
    $curl = curl_init($api . $path);
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$responseHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            return strlen($line);
        }));
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $failed = $body === false;
    curl_close($curl);
    if ($failed) throw new RuntimeException('The API could not be reached with verified TLS.');
    return array('status' => $status, 'headers' => $responseHeaders, 'json' => json_decode($body, true));
}

try {
    $health = hosting_http('/api/health', 'GET', array('Origin: ' . $frontend));
    hosting_check_result($health['status'] === 200 && ($health['json']['status'] ?? '') === 'ok' && ($health['json']['service'] ?? '') === 'pad-api', 'API health returns PAD JSON at /api/health');
    hosting_check_result(($health['headers']['access-control-allow-origin'] ?? '') === $frontend && stripos($health['headers']['vary'] ?? '', 'Origin') !== false, 'Verified frontend receives its exact CORS origin');
    $preflight = hosting_http('/api/doc_router/documents', 'OPTIONS', array('Origin: ' . $frontend, 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: authorization, content-type, x-pad-role'));
    $allowedHeaders = array_map('trim', explode(',', strtolower($preflight['headers']['access-control-allow-headers'] ?? '')));
    $allowedMethods = array_map('trim', explode(',', strtoupper($preflight['headers']['access-control-allow-methods'] ?? '')));
    hosting_check_result($preflight['status'] === 204 && ($preflight['headers']['access-control-allow-origin'] ?? '') === $frontend
        && !array_diff(array('authorization', 'content-type', 'x-pad-role'), $allowedHeaders)
        && !array_diff(array('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'), $allowedMethods), 'Preflight permits bearer tokens, selected roles, and API methods');
    $foreign = hosting_http('/api/health', 'GET', array('Origin: https://pad-untrusted-origin.invalid'));
    hosting_check_result($foreign['status'] === 200 && !isset($foreign['headers']['access-control-allow-origin']), 'Unlisted origin receives no browser CORS access');
    $google = hosting_http('/api/auth/google/config', 'GET', array('Origin: ' . $frontend));
    hosting_check_result($google['status'] === 200 && ($google['json']['enabled'] ?? false) && !empty($google['json']['client_id'])
        && ($google['headers']['access-control-allow-origin'] ?? '') === $frontend, 'Google public configuration is enabled and accessible to the frontend');
    $unauthenticated = hosting_http('/api/me', 'GET', array('Origin: ' . $frontend));
    hosting_check_result($unauthenticated['status'] === 401 && isset($unauthenticated['json']['message'])
        && ($unauthenticated['headers']['access-control-allow-origin'] ?? '') === $frontend, 'Unauthenticated requests return a readable JSON 401');
    $invalid = hosting_http('/api/me', 'GET', array('Origin: ' . $frontend, 'Authorization: Bearer invalid', 'X-PAD-Role: admin'));
    hosting_check_result($invalid['status'] === 401 && isset($invalid['json']['message']), 'Invalid bearer token is rejected');
} catch (Throwable $error) {
    hosting_check_result(false, $error->getMessage());
}
echo PHP_EOL . ($failures ? 'Hosting checks failed: ' . $failures . '.' : 'Public hosting checks passed. Complete database and browser sign-in checks before release.') . PHP_EOL;
exit($failures ? 1 : 0);
