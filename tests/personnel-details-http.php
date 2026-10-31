<?php
// Read-only check through the local Vite proxy. No accounts or personnel are created.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/RecordsManagement.php';
if (env_value('APP_ENV') !== 'local') throw new RuntimeException('Run this check only against the local PAD installation.');
$checks = 0;
function personnel_http_check($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$actor = null;
foreach (database()->query('SELECT * FROM users WHERE is_active = 1')->fetchAll() as $profile) {
    if (!in_array('ovp_central_records', account_roles($profile), true)) continue;
    try {
        assert_allowed_account_email($profile['email']);
        $profile['active_role'] = 'ovp_central_records';
        $actor = apply_active_module_office($profile);
        break;
    } catch (AuthenticationException $error) { /* Existing approval must still be active. */ }
}
personnel_http_check($actor !== null, 'An existing approved Central Records account is required.');
$expected = (new RecordsManagement(database(), $actor))->catalog('personnel')['data'];
$curl = curl_init('http://localhost:5174/pad-api/public/api/doc_router/routing/management/personnel');
curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . create_access_token($actor), 'X-PAD-Role: ovp_central_records')));
$body = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
$response = json_decode($body ?: '', true);
personnel_http_check($status === 200 && isset($response['data']), 'The personnel API must load through localhost:5174.');
personnel_http_check($response['data'] === $expected, 'The local API must return the full current personnel profiles and versions.');
$enriched = 0;
foreach ($response['data'] as $row) {
    personnel_http_check(!array_diff(array('name', 'email', 'position', 'campus', 'is_active', 'version'), array_keys($row)), 'Every managed profile must expose its detail fields.');
    personnel_http_check(!isset($row['acc_type']) && !isset($row['roles']), 'Personnel profiles must not include account permissions.');
    if ($row['email'] && $row['position'] && $row['campus']) $enriched++;
}
echo 'Local personnel API checks passed: ' . $checks . '. Profiles: ' . count($response['data']) . '; with recorded email, position and campus: ' . $enriched . '.' . PHP_EOL;
