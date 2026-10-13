<?php

// Aggregate and access checks against a generated database; never writes live records.
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/DocumentRouting.php';
require dirname(__DIR__) . '/src/DocumentStatistics.php';

$checks = 0;
function stats_check($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function stats_rejects(callable $callback, $status, $message) {
    try { $callback(); } catch (ApiException $error) { stats_check($error->getCode() === $status, $message); return; }
    throw new RuntimeException($message);
}
$source = env_value('DB_DATABASE');
if (!preg_match('/^[A-Za-z0-9_]+$/', $source)) throw new RuntimeException('Invalid source database.');
$management = new PDO('mysql:host=' . env_value('DB_HOST') . ';port=' . env_value('DB_PORT', '3306') . ';dbname=' . $source . ';charset=utf8mb4',
    env_value('DB_USERNAME'), env_value('DB_PASSWORD', ''), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$testDatabase = 'pad_routing_test_' . bin2hex(random_bytes(6));
$management->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$httpServer = null;
$serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pad-statistics-' . bin2hex(random_bytes(6)) . '.log';
try {
    foreach (array('offices', 'office_officials', 'users', 'documents', 'document_routes', 'document_pad_reviews') as $table) {
        $management->exec('CREATE TABLE `' . $testDatabase . '`.`' . $table . '` LIKE `' . $source . '`.`' . $table . '`');
    }
    putenv('DB_DATABASE=' . $testDatabase);
    $db = database();
    $db->exec("INSERT INTO offices (id, code, name, campus, is_active, created_at, updated_at) VALUES
        (2, 'RECORDS', 'Central Records', 'ACCESS', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
        (80, 'PAD', 'PAD Office', 'ACCESS', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
        (9, 'LOCAL', 'My Office', 'ISULAN', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
        (10, 'OTHER', 'Other Office', 'ISULAN', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $db->exec("INSERT INTO users (id, name, email, role, roles, office_id, ovp_pad_office_id, ovp_central_records_office_id, is_active, created_at, updated_at) VALUES
        (1, 'Sample Staff', 'sample@example.test', 'admin', 'admin,ovp_pad,ovp_central_records,sksu_officials', 9, 80, 2, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $user = $db->query('SELECT * FROM users WHERE id = 1')->fetch();
    $actor = function ($role) use ($user) { $a = $user; $a['active_role'] = $role; return apply_active_module_office($a); };
    $fixtures = array(
        array(1, '2024-01-01 00:00:00', 9, 10, null, 'received', 'Memorandum'),
        array(2, '2025-03-31 16:00:00', 9, 2, 80, 'in_transit', 'Memorandum'),
        array(3, '2025-04-10 00:00:00', 9, 9, null, 'delivered', 'Memorandum'),
        array(4, '2025-04-15 00:00:00', 10, 10, null, 'created', 'Private type'),
        array(5, '2025-04-30 16:00:00', 9, 9, null, 'created', 'Memorandum'),
        array(6, '2025-04-30 15:59:59', 9, 9, null, 'delivered', 'Travel Order'),
        array(7, '2024-01-02 00:00:00', 2, 2, null, 'received', 'Memorandum'),
        array(8, '2025-03-31 15:59:59', 9, 9, null, 'created', 'Memorandum'),
    );
    $save = $db->prepare('INSERT INTO documents (id, reference_no, subject, document_type, origin_type, source_office_id, current_office_id, pending_office_id, status, created_by_user_id, created_at, updated_at) VALUES (?, ?, ?, ?, \'office\', ?, ?, ?, ?, 1, ?, ?)');
    $event = $db->prepare('INSERT INTO document_routes (document_id, from_office_id, to_office_id, action, dispatched_at, received_at, acted_by_user_id, actor_role, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
    foreach ($fixtures as $f) {
        list($id, $created, $sourceOffice, $holder, $pending, $status, $type) = $f;
        $save->execute(array($id, 'PAD-2025-' . str_pad($id, 6, '0', STR_PAD_LEFT), 'Document ' . $id, $type, $sourceOffice, $holder, $pending, $status, $created, $created));
        $event->execute(array($id, $sourceOffice, $sourceOffice, 'created', null, $created, 'sksu_officials', $created));
    }
    $db->exec("UPDATE documents SET origin_type = 'external' WHERE id = 7");
    $add = function ($doc, $from, $to, $action, $time, $dispatch = false, $role = 'sksu_officials', $received = null) use ($event, $db) {
        $event->execute(array($doc, $from, $to, $action, $dispatch ? $time : null, $received ?: ($action === 'received' ? $time : null), $role, $time));
        return (int) $db->lastInsertId();
    };
    $add(1, 9, 10, 'routed', '2025-04-02 00:00:00', true);
    $add(1, 9, 10, 'received', '2025-04-03 00:00:00');
    $add(1, 10, 9, 'returned', '2025-04-04 00:00:00', true);
    $add(1, 10, 9, 'received', '2025-04-05 00:00:00');
    $add(1, 9, 10, 'routed', '2025-04-06 00:00:00', true);
    $add(1, 9, 10, 'received', '2025-04-07 00:00:00');
    $add(1, 10, 10, 'file_uploaded', '2025-04-29 00:00:00');
    $add(2, 9, 2, 'routed', '2025-04-02 00:00:00', true);
    $add(2, 9, 2, 'received', '2025-04-03 00:00:00');
    $add(2, 2, 80, 'routed', '2025-04-04 00:00:00', true, 'ovp_central_records');
    $review = $db->prepare('INSERT INTO document_pad_reviews (document_id, submission_route_id, pad_office_id, central_records_office_id, decision, decided_by_user_id, decided_at, created_at) VALUES (?, ?, 80, 2, ?, 1, ?, ?)');
    foreach (array(3 => 'approved', 6 => 'returned_for_correction') as $id => $decision) {
        $date = $id === 3 ? '2025-04-11 00:00:00' : '2025-05-01 00:00:00';
        $add($id, 9, 2, 'received', $date);
        $submission = $add($id, 2, 80, 'routed', $date, true, 'ovp_central_records');
        $review->execute(array($id, $submission, $decision, $date, $date));
        $add($id, 2, 9, 'acted', $date, true, 'ovp_central_records', $date);
        $add($id, 2, 9, 'received', $date);
        $add($id, 9, 9, 'delivered', $date);
    }
    $report = function ($role, $extra = array()) use ($db, $actor) {
        return (new DocumentStatistics($db, $actor($role)))->report(array_merge(array('start_date' => '2025-04-01', 'end_date' => '2025-04-30'), $extra));
    };
    $before = $db->query('SELECT SUM(id), COUNT(*) FROM document_routes')->fetch(PDO::FETCH_NUM);
    $pad = $report('ovp_pad'); $records = $report('ovp_central_records'); $local = $report('sksu_officials');
    foreach (array('snapshot', 'current_statuses', 'milestones', 'period', 'monthly', 'type_breakdown', 'pending_age', 'office_workload', 'processing_time') as $key) {
        stats_check($pad[$key] === $records[$key], 'PAD and Records share identical ' . $key . '.');
    }
    stats_check($pad['scope'] === 'global' && $local['scope'] === 'office', 'Selected roles determine report scope.');
    stats_check($pad['snapshot']['total_documents'] === 8 && $local['snapshot']['total_documents'] === 6, 'Unique totals retain sent records and exclude unrelated offices.');
    stats_check($pad['period']['registered'] === 4 && $local['period']['registered'] === 3, 'Manila inclusive dates include both boundary instants and exclude adjacent dates.');
    stats_check($pad['period']['sent'] === 3 && $pad['period']['handoffs'] === 7, 'Repeated dispatches increase activity without inflating document totals.');
    stats_check($local['period']['sent'] === 2 && $local['period']['handoffs'] === 3, 'Local sends attribute only the office’s own events.');
    stats_check($local['period']['received'] === 2 && $local['period']['acted'] === 0 && $records['period']['acted'] === 1, 'Receipt and action activity belong to the performing office.');
    stats_check($records['milestones']['acted'] === 2 && $records['current_statuses']['acted'] === 0, 'Act milestones survive later delivery.');
    stats_check($pad['snapshot']['awaiting_receipt'] === 1 && $local['snapshot']['awaiting_receipt'] === 0, 'Incoming workload belongs to the destination, not every participant.');
    stats_check($pad['snapshot']['pending_action'] === 6 && $local['snapshot']['pending_action'] === 3, 'Older backlog remains visible and delivered correction results stay pending.');
    stats_check($local['snapshot']['correction_pending'] === 1, 'Delivered correction is not treated as closed work.');
    stats_check(array_sum($pad['pending_age']) === 7 && $pad['pending_age']['16+ days'] === 7, 'Aging partitions only current pending work.');
    stats_check(!in_array('Private type', $local['document_types'], true), 'Office document-type catalog does not reveal unrelated record types.');
    stats_check(count($local['office_workload']) === 1 && (int) $local['office_workload'][0]['id'] === 9, 'Office workload cannot reveal foreign office totals.');
    stats_check(count($pad['oldest_pending']) === 1 && $pad['oldest_pending'][0]['queue'] === 'Awaiting receipt', 'Global report only exposes pending record references in the actor’s own office.');
    stats_check(count($records['oldest_pending']) === 1 && count($local['oldest_pending']) === 3, 'Pending record drill-down follows assigned-office access.');
    stats_check($pad['processing_time']['registration_to_act']['samples'] === 2 && $pad['processing_time']['registration_to_act']['seconds'] >= 0, 'Turnaround measures the registration cohort and retains completed milestones.');
    $filtered = $report('ovp_pad', array('document_type' => 'Travel Order'));
    stats_check($filtered['snapshot']['total_documents'] === 1 && $filtered['period']['registered'] === 1 && $filtered['snapshot']['correction_pending'] === 1, 'Type selection consistently filters snapshot, activity and corrections.');
    $empty = $report('ovp_pad', array('start_date' => '2020-01-01', 'end_date' => '2020-02-29'));
    stats_check(count($empty['monthly']) === 2 && $empty['period']['registered'] === 0 && $empty['snapshot']['pending_action'] === 6, 'Empty months are retained and dates cannot hide old backlog.');
    stats_rejects(function () use ($report) { $report('sksu_officials', array('office_id' => 'all')); }, 403, 'Ordinary offices cannot request all-office aggregates.');
    stats_rejects(function () use ($report) { $report('sksu_officials', array('office_id' => '10')); }, 403, 'Ordinary offices cannot substitute another office ID.');
    stats_rejects(function () use ($report) { $report('sksu_officials', array('office_id' => array(9))); }, 403, 'Array office overrides are rejected.');
    stats_rejects(function () use ($db, $user) { $a = $user; $a['active_role'] = 'ovp_pad'; $a['roles'] = array('sksu_officials'); (new DocumentStatistics($db, $a))->report(array()); }, 403, 'Forged active roles cannot grant global statistics.');
    stats_rejects(function () use ($report) { $report('ovp_pad', array('start_date' => '2025-02-30')); }, 422, 'Invalid calendar dates are rejected.');
    stats_rejects(function () use ($report) { $report('ovp_pad', array('start_date' => '2025-05-01')); }, 422, 'Reversed reporting periods are rejected.');
    stats_rejects(function () use ($report) { $report('ovp_pad', array('document_type' => array('Memorandum'))); }, 422, 'Array type filters are rejected.');
    $routingPad = new DocumentRouting($db, $actor('ovp_pad'));
    stats_rejects(function () use ($routingPad) { $routingPad->record('PAD-2025-000004'); }, 403, 'Global reporting does not expand PAD document-detail access.');
    stats_rejects(function () use ($routingPad) { $routingPad->workspace(array('office_id' => 'all')); }, 403, 'Global reporting does not grant PAD cross-office operations.');
    $workspace = (new DocumentRouting($db, $actor('ovp_central_records')))->workspace(array('office_id' => 'all'));
    stats_check($workspace['counts']['all'] === 8 && $workspace['desk_counts']['all'] === 4, 'Global monitoring and own-desk queues remain separate.');
    $attention = (new DocumentRouting($db, $actor('sksu_officials')))->workspace(array('queue' => 'attention'));
    stats_check($attention['pagination']['total'] === 1 && (int) $attention['data'][0]['id'] === 6, 'Overview attention includes received correction work and excludes unsent, dispatched and finished records.');
    stats_check($attention['data'][0]['permissions']['route'], 'Attention results retain their existing office action permissions.');
    $attentionRecords = (new DocumentRouting($db, $actor('ovp_central_records')))->workspace(array('queue' => 'attention', 'office_id' => 'all'));
    stats_check((int) $attentionRecords['office']['id'] === 2 && $attentionRecords['pagination']['total'] === 1 && (int) $attentionRecords['data'][0]['id'] === 7, 'Overview attention stays assigned-office scoped even during Records global monitoring.');
    $db->exec("UPDATE documents SET origin_type = 'external' WHERE id = 4");
    $attentionOther = (new DocumentRouting($db, array_merge($actor('sksu_officials'), array('office_id' => 10))))->workspace(array('queue' => 'attention'));
    stats_check($attentionOther['pagination']['total'] === 2 && (int) $attentionOther['data'][0]['id'] === 1 && (int) $attentionOther['data'][1]['id'] === 4, 'Attention puts the oldest office receipt first; later file uploads do not move it behind newer receipts.');
    $attentionSearch = (new DocumentRouting($db, $actor('sksu_officials')))->workspace(array('queue' => 'attention', 'search' => 'Document 6'));
    stats_check($attentionSearch['pagination']['total'] === 1, 'Overview search preserves attention scope.');
    $unassigned = $actor('admin'); $unassigned['office_id'] = null;
    stats_check((new DocumentRouting($db, $unassigned))->workspace(array('queue' => 'attention', 'office_id' => 10))['pagination']['total'] === 0, 'Attention cannot select a foreign office when an administrator has no own office assignment.');
    stats_check($before === $db->query('SELECT SUM(id), COUNT(*) FROM document_routes')->fetch(PDO::FETCH_NUM), 'Reporting preserves all document history.');
    $db->exec('CREATE TABLE fixture_accounts (acc_email VARCHAR(191) PRIMARY KEY)');
    $db->exec('INSERT INTO fixture_accounts SELECT email FROM users');
    putenv('AUTH_ACCOUNT_TABLE=fixture_accounts'); putenv('AUTH_ACCOUNT_EMAIL_COLUMN=acc_email');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Unable to reserve HTTP port.');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $pipes = array();
    $httpServer = proc_open(array(PHP_BINARY, '-S', $address, __DIR__ . '/office-routing-http.php'),
        array(0 => array('pipe', 'r'), 1 => array('file', $serverLog, 'a'), 2 => array('file', $serverLog, 'a')), $pipes);
    if (!is_resource($httpServer)) throw new RuntimeException('Unable to launch HTTP test server.');
    fclose($pipes[0]);
    $port = (int) substr($address, strrpos($address, ':') + 1);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, .1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(20000);
    }
    if (!$ready) throw new RuntimeException('HTTP test server did not start.');
    $token = create_access_token($user);
    $http = function ($path, $role) use ($address, $token) {
        $context = stream_context_create(array('http' => array('method' => 'GET', 'header' => 'Authorization: Bearer ' . $token . "\r\nX-PAD-Role: " . $role . "\r\n",
            'ignore_errors' => true, 'timeout' => 5)));
        $body = file_get_contents('http://' . $address . $path, false, $context);
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
        return array('status' => (int) $status[1], 'data' => json_decode($body, true), 'headers' => $http_response_header);
    };
    $query = '?start_date=2025-04-01&end_date=2025-04-30';
    $httpPad = $http('/api/doc_router/routing/statistics' . $query, 'ovp_pad');
    $httpRecords = $http('/api/routing/statistics' . $query, 'ovp_central_records');
    stats_check($httpPad['status'] === 200 && $httpRecords['status'] === 200 && $httpPad['data']['period'] === $httpRecords['data']['period'], 'Both HTTP endpoint families return matching OVP period statistics.');
    stats_check(in_array('Cache-Control: no-store', $httpPad['headers'], true), 'Statistics responses are not cached between account roles.');
    $httpLocal = $http('/api/doc_router/routing/statistics' . $query, 'sksu_officials');
    stats_check($httpLocal['status'] === 200 && $httpLocal['data']['snapshot']['total_documents'] === 6, 'HTTP active office role overrides the same account’s OVP assignments.');
    $httpAttention = $http('/api/doc_router/routing/workspace?queue=attention', 'sksu_officials');
    stats_check($httpAttention['status'] === 200 && $httpAttention['data']['pagination']['total'] === 1 && (int) $httpAttention['data']['data'][0]['id'] === 6, 'HTTP Overview lists only the active office’s received work needing attention.');
    stats_check($http('/api/doc_router/routing/statistics' . $query . '&office_id=all', 'sksu_officials')['status'] === 403, 'HTTP office query tampering is denied.');
    stats_check($http('/api/doc_router/routing/statistics?start_date=2025-02-30', 'ovp_pad')['status'] === 422, 'HTTP invalid dates produce validation responses.');
    stats_check($http('/api/doc_router/documents/PAD-2025-000004', 'ovp_pad')['status'] === 403, 'HTTP aggregate permission cannot reveal foreign document detail.');
    $db->exec("UPDATE users SET roles='admin,ovp_central_records,sksu_officials' WHERE id=1");
    stats_check($http('/api/doc_router/routing/statistics' . $query, 'ovp_pad')['status'] === 403, 'Removing the PAD role revokes existing-token statistics access.');
    echo 'Statistics checks passed: ' . $checks . PHP_EOL;
} finally {
    if (is_resource($httpServer)) { proc_terminate($httpServer); proc_close($httpServer); }
    if (is_file($serverLog)) unlink($serverLog);
    if (!preg_match('/^pad_routing_test_[a-f0-9]{12}$/', $testDatabase)) throw new RuntimeException('Unsafe test database cleanup.');
    $management->exec('DROP DATABASE `' . $testDatabase . '`');
}
