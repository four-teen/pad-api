<?php

// Generated test database only. No live accounts, offices, documents, or files change.
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/DocumentRouting.php';
require dirname(__DIR__) . '/src/DocumentStatistics.php';
require dirname(__DIR__) . '/src/Administration.php';

$checks = 0;
function president_check($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function president_rejects(callable $action, $status, $message) {
    try { $action(); } catch (ApiException $error) { president_check($error->getCode() === $status, $message); return; }
    throw new RuntimeException($message);
}
$source = env_value('DB_DATABASE');
if (!preg_match('/^[A-Za-z0-9_]+$/', $source)) throw new RuntimeException('Invalid source database.');
$management = new PDO('mysql:host=' . env_value('DB_HOST') . ';port=' . env_value('DB_PORT', '3306') . ';dbname=' . $source . ';charset=utf8mb4',
    env_value('DB_USERNAME'), env_value('DB_PASSWORD', ''), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$testDatabase = 'pad_president_test_' . bin2hex(random_bytes(6));
$management->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$httpServer = null;
$serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pad-president-' . bin2hex(random_bytes(6)) . '.log';
try {
    foreach (array('offices', 'office_officials', 'users', 'documents', 'document_routes', 'document_pad_reviews', 'document_people', 'document_travel_details', 'document_number_series', 'document_types', 'document_personnel', 'document_sources') as $table) {
        $management->exec('CREATE TABLE `' . $testDatabase . '`.`' . $table . '` LIKE `' . $source . '`.`' . $table . '`');
    }
    putenv('DB_DATABASE=' . $testDatabase);
    putenv('AUTH_ACCOUNT_TABLE=fixture_accounts');
    putenv('AUTH_ACCOUNT_EMAIL_COLUMN=acc_email');
    $db = database();
    $db->exec('CREATE TABLE fixture_accounts (acc_id INT AUTO_INCREMENT PRIMARY KEY, acc_name VARCHAR(160), acc_position VARCHAR(255), acc_email VARCHAR(191), campus VARCHAR(80))');
    $db->exec("INSERT INTO fixture_accounts (acc_name, acc_position, acc_email, campus) VALUES ('Sample President', 'University President', 'president@example.test', 'ACCESS'), ('Sample Staff', 'Office staff', 'staff@example.test', 'ISULAN')");
    $db->exec("INSERT INTO offices (id, code, name, campus, is_active, created_at, updated_at) VALUES
        (1, 'OP', 'Office of the University President', 'ACCESS', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
        (2, 'OTHER', 'Unrelated Office', 'ISULAN', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
        (3, 'OLD', 'Archived Office', 'ACCESS', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
        (80, 'PAD', 'OVP PAD Receiving Office', 'ACCESS', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $db->exec("INSERT INTO document_types (id, name, details_kind, is_active) VALUES (1, 'Memorandum', 'personnel', 1)");
    $year = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y');
    $db->exec('INSERT INTO document_number_series (series_year, last_number) VALUES (' . (int) $year . ', 100)');
    $db->exec("INSERT INTO office_officials (office_id, name, position, email) VALUES
        (1, 'Sample President', 'University President', 'president@example.test'),
        (2, 'Sample Staff', 'Dean', 'staff@example.test')");
    $db->exec("INSERT INTO users (id, name, email, role, roles, office_id, is_active, created_at, updated_at) VALUES
        (1, 'Sample President', 'president@example.test', 'router', 'sksu_officials', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
        (2, 'Sample Staff', 'staff@example.test', 'router', 'sksu_officials', 2, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $db->exec("INSERT INTO users (id, name, email, role, roles, ovp_pad_office_id, is_active, created_at, updated_at)
        VALUES (3, 'Sample PAD Staff', 'pad@example.test', 'router', 'ovp_pad', 80, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $db->exec("INSERT INTO fixture_accounts (acc_name, acc_position, acc_email) VALUES ('Sample PAD Staff', 'PAD Staff', 'pad@example.test')");
    $db->exec("INSERT INTO documents (id, reference_no, subject, document_type, origin_type, source_office_id, current_office_id, status, created_by_user_id, created_at, updated_at) VALUES
        (1, 'PAD-2026-000001', 'Presidential office document', 'Memorandum', 'office', 1, 1, 'created', 1, '2026-01-01 00:00:00', UTC_TIMESTAMP()),
        (2, 'PAD-2026-000002', 'Unrelated office document', 'Memorandum', 'office', 2, 2, 'received', 2, '2026-01-01 00:00:00', UTC_TIMESTAMP())");
    $db->exec("INSERT INTO document_routes (document_id, from_office_id, to_office_id, action, received_at, acted_by_user_id, actor_role, created_at) VALUES
        (1, 1, 1, 'created', '2026-01-01 00:00:00', 1, 'sksu_officials', '2026-01-01 00:00:00'),
        (2, 2, 2, 'received', '2026-01-01 00:00:00', 2, 'sksu_officials', '2026-01-01 00:00:00')");
    $profile = $db->query('SELECT * FROM users WHERE id=1')->fetch();
    $token = create_access_token($profile);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    unset($_SERVER['HTTP_X_PAD_ROLE']);
    $president = current_user();
    president_check($president['roles'] === array('university_president'), 'The active OP office assignment automatically opens the monitoring role.');
    president_check($president['active_role'] === 'university_president' && (int) $president['role_offices']['university_president']['id'] === 1, 'A single presidential role lands directly in its assigned monitoring workspace.');
    president_check($db->query('SELECT roles FROM users WHERE id=1')->fetchColumn() === 'sksu_officials', 'Role detection does not rewrite account assignments or require a schema migration.');
    foreach (array('office_code' => 'PAD', 'office_is_active' => 0, 'office_id' => null) as $key => $value) {
        $candidate = array_merge($president, array($key => $value));
        if ($key === 'office_id') $candidate['sksu_office_id'] = null;
        if ($key === 'office_code') $candidate['sksu_office_code'] = $value;
        if ($key === 'office_is_active') $candidate['sksu_office_is_active'] = $value;
        president_check(!president_eligible($candidate), 'Reject mismatched presidential eligibility: ' . $key);
    }
    president_check(president_eligible(array_merge($president, array('position' => 'Administrator', 'office_name' => 'Renamed Presidential Monitoring Office'))), 'Position and office display-name edits do not change OP monitoring access.');
    president_check(account_roles(array_merge($president, array('roles' => 'router'))) === array('university_president'), 'A legacy Router assigned to OP resolves only to monitoring.');
    $multiRole = apply_active_module_office(array_merge($president, array('roles' => array('admin', 'ovp_pad', 'sksu_officials'), 'ovp_pad_office_id' => 80, 'active_role' => 'ovp_pad')));
    president_check($multiRole['office_code'] === 'PAD' && account_roles($multiRole) === array('admin', 'ovp_pad', 'university_president'), 'Selecting another assigned module preserves OP monitoring eligibility and the other role assignments.');
    president_check((new DocumentRouting($db, $multiRole))->workspace(array())['can_register'], 'An explicitly selected PAD role keeps its operational registration permission.');
    $routing = new DocumentRouting($db, $president);
    $workspace = $routing->workspace(array('office_id' => 'all'));
    president_check($workspace['pagination']['total'] === 2 && !$workspace['can_register'] && !$workspace['can_register_external'], 'Presidential monitoring spans all offices and cannot register documents.');
    president_check(!in_array(true, $workspace['data'][0]['permissions'], true) && !in_array(true, $workspace['data'][1]['permissions'], true), 'Documents in both the presidential and foreign offices have no action permissions.');
    president_check($routing->record('PAD-2026-000002', true)['history'][0]['action'] === 'received', 'The President can inspect an unrelated office document and its recorded history.');
    $stats = (new DocumentStatistics($db, $president))->report(array());
    president_check($stats['scope'] === 'global' && $stats['snapshot']['total_documents'] === 2 && count($stats['oldest_pending']) === 2, 'Global statistics include actual pending references from every office.');
    $beforeDocuments = $db->query('SELECT * FROM documents ORDER BY id')->fetchAll();
    $beforeRoutes = $db->query('SELECT * FROM document_routes ORDER BY id')->fetchAll();
    president_rejects(function () use ($routing) { $routing->register(array(), array()); }, 403, 'Registration is denied before processing fields or uploads.');
    president_rejects(function () use ($routing) { $routing->uploadFile('PAD-2026-000001', array()); }, 403, 'File changes are denied.');
    president_rejects(function () use ($routing) { $routing->deleteDocument('PAD-2026-000001'); }, 403, 'Document deletion is denied.');
    foreach (array('route', 'receive', 'return', 'act', 'complete', 'deliver', 'decide') as $action) president_rejects(function () use ($routing, $action) { $routing->action('PAD-2026-000001', $action, array()); }, 403, 'Presidential ' . $action . ' is denied.');
    president_rejects(function () use ($db, $president) { new Administration($db, $president); }, 403, 'Presidential monitoring does not grant administration access.');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Unable to reserve HTTP port.');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $pipes = array();
    $httpServer = proc_open(array(PHP_BINARY, '-S', $address, __DIR__ . '/president-http.php'),
        array(0 => array('pipe', 'r'), 1 => array('file', $serverLog, 'a'), 2 => array('file', $serverLog, 'a')), $pipes);
    if (!is_resource($httpServer)) throw new RuntimeException('Unable to launch HTTP test server.');
    fclose($pipes[0]);
    $ready = false;
    $port = (int) substr($address, strrpos($address, ':') + 1);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, .1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(20000);
    }
    if (!$ready) throw new RuntimeException('HTTP test server did not start.');
    $http = function ($path, $method = 'GET', $role = 'university_president', $session = null, $content = '') use ($address, $token) {
        $context = stream_context_create(array('http' => array('method' => $method, 'header' => 'Authorization: Bearer ' . ($session ?: $token) . "\r\nX-PAD-Role: " . $role . "\r\nContent-Type: application/json\r\n", 'content' => $content, 'ignore_errors' => true, 'timeout' => 5)));
        $body = file_get_contents('http://' . $address . $path, false, $context);
        if ($body === false || empty($http_response_header)) throw new RuntimeException('HTTP fixture request failed: ' . $method . ' ' . $path);
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
        return array('status' => (int) $status[1], 'data' => json_decode($body, true), 'headers' => $http_response_header);
    };
    $report = $http('/api/president/statistics');
    president_check($report['status'] === 200 && $report['data']['scope'] === 'global', 'The actual authenticated HTTP statistics endpoint returns global monitoring.');
    president_check(in_array('Cache-Control: no-store', $report['headers'], true), 'Presidential responses cannot be cached between identities.');
    president_check($http('/api/president/documents')['data']['pagination']['total'] === 2, 'HTTP monitoring defaults to all offices.');
    president_check($http('/api/president/documents?office_id=2&search=Unrelated&status=received')['data']['pagination']['total'] === 1, 'Search, status, and office filters apply together on the server.');
    president_check($http('/api/president/documents/PAD-2026-000002')['status'] === 200, 'The HTTP document journey is globally readable.');
    president_check(count($http('/api/president/offices')['data']['data']) === 4, 'Monitoring filters retain OP and inactive offices for historical records.');
    president_check($http('/api/doc_router/routing/workspace')['status'] === 403, 'The read-only role cannot enter the operational Router endpoint family.');
    president_check($http('/api/admin/overview')['status'] === 403, 'The read-only role cannot view administration.');
    president_check($http('/api/documents', 'GET', 'sksu_officials')['status'] === 403, 'A matching President cannot bypass monitoring by selecting the replaced SKSU role.');
    foreach (array('POST', 'PATCH', 'PUT', 'DELETE') as $method) president_check($http('/api/documents/PAD-2026-000001', $method)['status'] === 403, 'Global middleware blocks presidential ' . $method . ' requests.');
    president_check($http('/api/president/statistics?start_date=2026-02-30')['status'] === 422, 'Invalid dates produce server validation errors.');
    $staffToken = create_access_token($db->query('SELECT * FROM users WHERE id=2')->fetch());
    president_check($http('/api/president/documents', 'GET', 'sksu_officials', $staffToken)['status'] === 403, 'Ordinary staff cannot open presidential endpoints.');
    president_check($http('/api/president/documents', 'GET', 'university_president', $staffToken)['status'] === 403, 'A forged presidential role header grants no access.');
    $catalog = $http('/api/offices', 'GET', 'sksu_officials', $staffToken)['data']['data'];
    $byCode = array_column($catalog, null, 'code');
    president_check(!$byCode['OP']['can_receive_documents'] && $byCode['PAD']['can_receive_documents'] && $byCode['OTHER']['can_receive_documents'], 'Only OP is excluded from receiving destinations; PAD and ordinary offices remain available.');
    $deniedRoute = $http('/api/doc_router/documents/PAD-2026-000002/route', 'POST', 'sksu_officials', $staffToken, json_encode(array('to_office_id' => 1)));
    president_check($deniedRoute['status'] === 422 && strpos($deniedRoute['data']['message'], 'monitoring only') !== false, 'A forged direct Send to OP is blocked by the document API.');
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $staffToken;
    $_SERVER['HTTP_X_PAD_ROLE'] = 'sksu_officials';
    $staff = current_user();
    $staffRouting = new DocumentRouting($db, $staff);
    president_check($staff['roles'] === array('sksu_officials') && $staffRouting->workspace(array())['can_register'], 'Ordinary SKSU Officials retain their module and registration permission.');
    president_rejects(function () use ($staffRouting) { $staffRouting->register(array('recipient_office_id' => 1), array()); }, 422, 'OP is also unavailable as an intended registration recipient.');
    $db->exec("UPDATE fixture_accounts SET acc_position='Office staff' WHERE acc_email='president@example.test'");
    president_check($http('/api/president/documents')['status'] === 200, 'OP monitoring does not depend on personnel position wording.');
    $db->exec("UPDATE fixture_accounts SET acc_position='University President' WHERE acc_email='president@example.test'");
    $db->exec('UPDATE offices SET is_active=0 WHERE id=1');
    president_check($http('/api/president/documents')['status'] === 403, 'An inactive assigned office revokes monitoring immediately.');
    $db->exec('UPDATE offices SET is_active=1 WHERE id=1');
    $db->exec('UPDATE users SET office_id=2 WHERE id=1');
    president_check($http('/api/president/documents')['status'] === 403, 'An office reassignment revokes monitoring immediately.');
    $db->exec('UPDATE users SET office_id=1 WHERE id=1');
    $db->exec("INSERT INTO fixture_accounts (acc_name, acc_position, acc_email) VALUES ('Duplicate', 'University   President', ' PRESIDENT@EXAMPLE.TEST ')");
    president_check($http('/api/president/documents')['status'] === 200, 'Duplicate personnel rows leave the assigned OP monitoring identity unchanged.');
    $db->exec("UPDATE fixture_accounts SET acc_position='Office staff' WHERE acc_name='Duplicate'");
    president_check($http('/api/president/documents')['status'] === 200, 'Duplicate personnel position values do not override the assigned OP office identity.');
    president_check($beforeDocuments === $db->query('SELECT * FROM documents ORDER BY id')->fetchAll() && $beforeRoutes === $db->query('SELECT * FROM document_routes ORDER BY id')->fetchAll(), 'All monitoring and denied actions leave documents and routing history unchanged.');
    $registered = $staffRouting->register(array('subject' => 'Ordinary SKSU registration', 'document_type_id' => 1), array());
    $newDocument = $staffRouting->record($registered['reference_no']);
    president_check((int) $newDocument['source_office_id'] === 2 && $newDocument['status'] === 'created', 'An ordinary SKSU office can still register a real document.');
    $staffRouting->action($registered['reference_no'], 'route', array('to_office_id' => 80));
    president_check((int) $staffRouting->record($registered['reference_no'])['pending_office_id'] === 80, 'An ordinary SKSU office can still send documents to OVP PAD.');
    $padToken = create_access_token($db->query('SELECT * FROM users WHERE id=3')->fetch());
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $padToken;
    $_SERVER['HTTP_X_PAD_ROLE'] = 'ovp_pad';
    $pad = current_user();
    $padRouting = new DocumentRouting($db, $pad);
    president_check($padRouting->record($registered['reference_no'])['permissions']['receive'], 'OVP PAD remains the accepting account for documents sent to its office.');
    $padRouting->action($registered['reference_no'], 'receive', array());
    president_check($padRouting->record($registered['reference_no'])['status'] === 'received', 'OVP PAD can still confirm actual receipt.');
    $db->exec("INSERT INTO document_types (name, details_kind, is_active) VALUES
        ('Travel Order', 'travel_order', 1), ('Request Letter', 'personnel', 1), ('Inactive unused type', 'personnel', 0)");
    $db->exec("INSERT INTO documents (id, reference_no, subject, document_type, origin_type, source_office_id, current_office_id, status, created_by_user_id, created_at, updated_at) VALUES
        (4, 'PAD-ACT-TRAVEL', 'Delivered travel order with repeated Acts', 'Travel Order', 'office', 2, 2, 'delivered', 2, '" . $year . "-01-10 00:00:00', UTC_TIMESTAMP()),
        (5, 'PAD-ACT-LEGACY', 'Historical document type', 'Legacy type', 'office', 2, 2, 'acted', 2, '" . ((int) $year - 1) . "-01-10 00:00:00', UTC_TIMESTAMP())");
    $db->exec("UPDATE documents SET status='delivered' WHERE id=2");
    $actEvent = $db->prepare('INSERT INTO document_routes (document_id, from_office_id, to_office_id, action, acted_by_user_id, actor_role, created_at) VALUES (?, 2, 2, ?, 2, \'sksu_officials\', ?)');
    foreach (array(
        array(2, 'acted', $year . '-01-31 15:59:59'),
        array(2, 'acted', $year . '-02-28 15:59:59'),
        array(2, 'delivered', $year . '-02-28 16:00:00'),
        array(4, 'acted', $year . '-02-05 00:00:00'),
        array(4, 'acted', $year . '-02-06 00:00:00'),
        array(4, 'delivered', $year . '-02-07 00:00:00'),
        array(5, 'acted', $year . '-01-31 16:00:00')
    ) as $event) $actEvent->execute($event);
    $typePeriod = '?start_date=' . $year . '-02-01&end_date=' . $year . '-02-28';
    $typeReport = $http('/api/president/statistics' . $typePeriod);
    president_check($typeReport['status'] === 200, 'Presidential Act type cards use the real statistics HTTP endpoint.');
    $actedCounts = array_column($typeReport['data']['acted_type_breakdown'], 'documents', 'name');
    $recordedCounts = array_column($typeReport['data']['recorded_type_breakdown'], 'documents', 'name');
    president_check($recordedCounts['Memorandum'] === 3 && $recordedCounts['Travel Order'] === 1 && $recordedCounts['Legacy type'] === 1, 'Recorded cards include unacted documents, repeated-Act documents once, and previous-year registrations.');
    president_check($recordedCounts['Request Letter'] === 0 && !isset($recordedCounts['Inactive unused type']), 'Recorded cards retain active zero-count types and omit inactive unused types.');
    president_check(array_sum($recordedCounts) === $typeReport['data']['snapshot']['total_documents'], 'The sum of the recorded type cards equals the full registry snapshot.');
    president_check($actedCounts['Memorandum'] === 1 && $actedCounts['Travel Order'] === 1, 'Each acted document counts once by type, including repeated Acts and later delivery.');
    president_check($actedCounts['Legacy type'] === 1, 'Historical document types and the inclusive Manila starting instant are retained.');
    president_check($actedCounts['Request Letter'] === 0 && !isset($actedCounts['Inactive unused type']), 'Active unused types show zero, while inactive unused types are omitted.');
    president_check(array_sum($actedCounts) === $typeReport['data']['period']['acted'] && $typeReport['data']['period']['registered'] === 0, 'Type counts match recorded Acts rather than registration totals for the same period.');
    $beforeEnd = $http('/api/president/statistics?start_date=' . $year . '-02-01&end_date=' . $year . '-02-27');
    president_check($beforeEnd['data']['recorded_type_breakdown'] === $typeReport['data']['recorded_type_breakdown'], 'Changing reporting dates does not hide earlier recorded documents from the cards.');
    president_check(array_column($beforeEnd['data']['acted_type_breakdown'], 'documents', 'name')['Memorandum'] === 0, 'Acts outside the selected period cannot inflate a document-type count.');
    $onlyTravel = $http('/api/president/statistics' . $typePeriod . '&document_type=Travel%20Order');
    president_check($onlyTravel['data']['recorded_type_breakdown'] === array(array('name' => 'Travel Order', 'documents' => 1)) && $onlyTravel['data']['snapshot']['total_documents'] === 1, 'The document-type filter applies consistently to recorded cards and their total.');
    president_check($onlyTravel['data']['acted_type_breakdown'] === array(array('name' => 'Travel Order', 'documents' => 1)), 'The same document-type filter applies to Act cards and the Acted total.');
    president_check($http('/api/routing/statistics' . $typePeriod, 'GET', 'ovp_pad', $padToken)['data']['acted_type_breakdown'] === array(), 'The new per-type cards do not change ordinary OVP reports.');
    president_check($http('/api/routing/statistics' . $typePeriod, 'GET', 'ovp_pad', $padToken)['data']['recorded_type_breakdown'] === array(), 'The new recorded type data is limited to presidential reports.');
    echo 'Presidential monitoring checks passed: ' . $checks . PHP_EOL;
} finally {
    if (is_resource($httpServer)) { proc_terminate($httpServer); proc_close($httpServer); }
    if (is_file($serverLog)) unlink($serverLog);
    if (!preg_match('/^pad_president_test_[a-f0-9]{12}$/', $testDatabase)) throw new RuntimeException('Unsafe test database cleanup.');
    $management->exec('DROP DATABASE `' . $testDatabase . '`');
}
