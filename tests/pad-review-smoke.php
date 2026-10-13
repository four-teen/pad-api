<?php

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/DocumentRouting.php';
require dirname(__DIR__) . '/src/PadReviewSchema.php';

$checks = 0;
function review_check($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function review_rejects(callable $callback, $status, $message) {
    try { $callback(); } catch (ApiException $e) {
        review_check($e->getCode() === $status, $message . ': ' . $e->getMessage());
        return;
    }
    throw new RuntimeException($message);
}

$source = env_value('DB_DATABASE');
if (!preg_match('/^[A-Za-z0-9_]+$/', $source)) throw new RuntimeException('Invalid source database name.');
$management = new PDO('mysql:host=' . env_value('DB_HOST') . ';port=' . env_value('DB_PORT', '3306') . ';dbname=' . $source . ';charset=utf8mb4',
    env_value('DB_USERNAME'), env_value('DB_PASSWORD', ''), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$testDatabase = 'pad_routing_test_' . bin2hex(random_bytes(6));
$management->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$finalFile = tempnam(sys_get_temp_dir(), 'pad-final-file-');
$fixtureFiles = array();
putenv('UPLOAD_PATH=' . sys_get_temp_dir());
$httpServer = null;
$serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pad-review-http-' . bin2hex(random_bytes(6)) . '.log';
try {
    foreach (array('offices', 'users', 'office_officials', 'documents', 'document_routes', 'document_people', 'document_travel_details') as $table) {
        $management->exec('CREATE TABLE `' . $testDatabase . '`.`' . $table . '` LIKE `' . $source . '`.`' . $table . '`');
    }
    putenv('DB_DATABASE=' . $testDatabase);
    $db = database();
    install_pad_reviews($db);
    install_pad_reviews($db);
    review_check((int) $db->query('SELECT COUNT(*) FROM document_pad_reviews')->fetchColumn() === 0, 'Repeatable setup creates no historical reviews.');
    $db->exec("INSERT INTO offices (id, code, name, is_active, created_at, updated_at) VALUES
        (2, 'CENTRAL', 'Central Records', 1, NOW(), NOW()), (80, 'EXACT-PAD', 'Exact PAD Office', 1, NOW(), NOW()),
        (3, 'OLD-PAD', 'Previous PAD Office', 1, NOW(), NOW()), (9, 'SKSU', 'Originating Office', 1, NOW(), NOW()),
        (10, 'SKSU-TWO', 'Other Office', 1, NOW(), NOW())");
    $db->exec("INSERT INTO users (id, name, email, role, roles, office_id, ovp_pad_office_id, ovp_central_records_office_id, is_active, created_at, updated_at) VALUES
        (1, 'Sample Staff', 'staff@example.test', 'admin', 'admin,ovp_pad,ovp_central_records,sksu_officials', 9, 80, 2, 1, NOW(), NOW()),
        (2, 'Other Staff', 'other@example.test', 'staff', 'sksu_officials', 10, NULL, NULL, 1, NOW(), NOW())");
    $user = $db->query('SELECT * FROM users WHERE id=1')->fetch();
    $actor = function ($role) use ($user) { $u = $user; $u['active_role'] = $role; return apply_active_module_office($u); };
    $pad = new DocumentRouting($db, $actor('ovp_pad'));
    $records = new DocumentRouting($db, $actor('ovp_central_records'));
    $office = new DocumentRouting($db, $actor('sksu_officials'));
    $admin = new DocumentRouting($db, $actor('admin'));
    $otherUser = $db->query('SELECT * FROM users WHERE id=2')->fetch(); $otherUser['active_role'] = 'sksu_officials';
    $other = new DocumentRouting($db, $otherUser);
    $fixture = function ($number, $holder = 2) use ($db) {
        $ref = 'PAD-2026-' . str_pad($number, 6, '0', STR_PAD_LEFT);
        $q = $db->prepare("INSERT INTO documents (reference_no, subject, document_type, origin_type, source_office_id, current_office_id, status, created_by_user_id, created_at, updated_at)
            VALUES (?, 'Sample request', 'Memorandum', 'office', 9, ?, 'received', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $q->execute(array($ref, $holder));
        return $ref;
    };
    file_put_contents($finalFile, '%PDF-1.4 final signed sample');
    $attachFixture = function ($reference) use ($db, $finalFile, &$fixtureFiles) {
        $file = tempnam(sys_get_temp_dir(), 'pad-owner-release-');
        copy($finalFile, $file);
        $fixtureFiles[] = $file;
        $query = $db->prepare('UPDATE documents SET stored_file_name=?, file_name=?, file_mime=?, file_size=? WHERE reference_no=?');
        $query->execute(array(basename($file), 'signed.pdf', 'application/pdf', filesize($file), $reference));
    };
    review_rejects(function () use ($office) { $office->workspace(array('queue' => 'for_approval')); }, 422, 'SKSU role cannot request PAD approval queue.');
    review_rejects(function () use ($pad) { $pad->workspace(array('queue' => 'pad_results')); }, 422, 'PAD cannot request Central Records results queue.');
    foreach (array('approved', 'disapproved', 'returned_for_correction') as $index => $decision) {
        $ref = $fixture($index + 1);
        $records->action($ref, 'route', array('to_office_id' => 80));
        $in = $pad->workspace(array('queue' => 'incoming'));
        review_check(in_array($ref, array_column($in['data'], 'reference_no'), true), 'Submission awaits physical PAD receipt.');
        review_check(!$pad->record($ref)['permissions']['decide'], 'Decision blocked until actual receipt.');
        review_rejects(function () use ($pad, $ref) { $pad->action($ref, 'decide', array('decision' => 'approved')); }, 409, 'Cannot decide before receipt.');
        $pad->action($ref, 'receive', array());
        $doc = $pad->record($ref, true);
        review_check($doc['permissions']['decide'] && $doc['awaiting_pad_decision'], 'Received submission allows a PAD decision.');
        review_check(in_array($ref, array_column($pad->workspace(array('queue' => 'for_approval'))['data'], 'reference_no'), true), 'Received document appears For Approval.');
        foreach (array('act', 'complete', 'deliver', 'return', 'route') as $bypass) {
            review_rejects(function () use ($pad, $ref, $bypass) { $pad->action($ref, $bypass, array('notes' => 'Bypass', 'to_office_id' => 9)); }, 409, 'Generic action cannot bypass the decision and return.');
        }
        review_rejects(function () use ($admin, $ref) { $admin->action($ref, 'decide', array('decision' => 'approved')); }, 409, 'Administrator role is not PAD decision mode.');
        review_rejects(function () use ($office, $ref) { $office->action($ref, 'decide', array('decision' => 'approved')); }, 409, 'Same identity in SKSU mode cannot decide.');
        if ($decision !== 'approved') review_rejects(function () use ($pad, $ref, $decision) { $pad->action($ref, 'decide', array('decision' => $decision)); }, 422, 'Negative decision requires a reason.');
        review_rejects(function () use ($pad, $ref) { $pad->action($ref, 'decide', array('decision' => 'unknown')); }, 422, 'Unknown outcome rejected.');
        $pad->action($ref, 'decide', array('decision' => $decision, 'notes' => $decision === 'approved' ? '' : 'Include supporting documents.', 'to_office_id' => 10));
        $doc = $pad->record($ref, true);
        review_check($doc['pad_decision'] === $decision && (int) $doc['pending_office_id'] === 2 && (int) $doc['current_office_id'] === 80,
            'Decision and return are atomic; caller cannot redirect result.');
        review_check($doc['status'] === 'in_transit' && $doc['last_sender_role'] === 'ovp_pad', 'Approval does not complete document; PAD is identified as sender.');
        review_check(count($doc['pad_reviews']) === 1 && count(array_filter($doc['history'], function ($event) { return $event['action'] === 'pad_decided'; })) === 1,
            'Decision actor/time and dedicated timeline event retained.');
        review_rejects(function () use ($pad, $ref, $decision) { $pad->action($ref, 'decide', array('decision' => $decision, 'notes' => 'Retry')); }, 409, 'Repeated submission cannot duplicate decision/return.');
        $results = $records->workspace(array('queue' => 'pad_results', 'office_id' => 'all'));
        review_check((int) $results['office']['id'] === 2 && in_array($ref, array_column($results['data'], 'reference_no'), true), 'PAD Results stays local even when global monitoring selected.');
        review_check($records->record($ref)['permissions']['receive'] && !$records->record($ref)['permissions']['route'], 'Central Records must confirm the returned hard copy.');
        $records->action($ref, 'receive', array());
        review_check(in_array($ref, array_column($records->workspace(array('queue' => 'pad_results'))['data'], 'reference_no'), true), 'Received result remains visible for distribution.');
        if ($decision === 'returned_for_correction') {
            $firstDecision = $doc['pad_reviews'][0];
            $attachFixture($ref);
            $records->action($ref, 'act', array('owner_office_id' => 9, 'hard_copy_received' => true, 'notes' => 'Released PAD correction instructions to owner.'));
            $office->action($ref, 'receive', array());
            review_check($office->record($ref)['status'] === 'delivered' && $office->record($ref)['permissions']['route'], 'A correction result confirms delivery while retaining owner resubmission.');
            $office->action($ref, 'route', array('to_office_id' => 2));
            $records->action($ref, 'receive', array());
            $records->action($ref, 'route', array('to_office_id' => 80));
            $pad->action($ref, 'receive', array());
            $pad->action($ref, 'decide', array('decision' => 'approved'));
            $again = $pad->record($ref, true);
            review_check(count($again['pad_reviews']) === 2 && $again['pad_reviews'][0] === $firstDecision && $again['pad_decision'] === 'approved', 'Resubmission retains earlier decision on same file code.');
            $records->action($ref, 'receive', array());
        }
        $attachFixture($ref);
        $records->action($ref, 'act', array('owner_office_id' => 9, 'hard_copy_received' => true, 'notes' => 'Processed PAD result and sent it to the owner.'));
        review_check(!in_array($ref, array_column($records->workspace(array('queue' => 'pad_results'))['data'], 'reference_no'), true), 'Distributed results leave current Central workload.');
        $office->action($ref, 'receive', array());
        review_check($office->record($ref)['status'] === 'delivered', 'Owner confirmation records delivery rather than another unfinished receipt.');
        review_check($office->record($ref)['pad_decision'] === ($decision === 'returned_for_correction' ? 'approved' : $decision), 'Recipient sees latest decision.');
        review_rejects(function () use ($other, $ref) { $other->record($ref); }, 403, 'Results do not broaden unrelated office access.');
    }
    // Simulate the user's existing unclassified Central->PAD handoff, not a fabricated historical approval.
    $legacy = $fixture(10);
    $db->exec("UPDATE documents SET pending_office_id=80, status='in_transit' WHERE reference_no='$legacy'");
    $id = $db->query("SELECT id FROM documents WHERE reference_no='$legacy'")->fetchColumn();
    $db->exec("INSERT INTO document_routes (document_id,from_office_id,to_office_id,action,dispatched_at,acted_by_user_id,created_at)
        VALUES ($id,2,80,'routed',UTC_TIMESTAMP(),1,UTC_TIMESTAMP())");
    install_pad_reviews($db);
    review_check($pad->record($legacy)['awaiting_pad_decision'], 'Existing exact-office handoff is identified for review.');
    review_check((int) $db->query("SELECT COUNT(*) FROM document_pad_reviews WHERE document_id=$id")->fetchColumn() === 0, 'Setup does not manufacture a legacy review/decision.');
    $pad->action($legacy, 'receive', array());
    review_check($pad->record($legacy)['permissions']['decide'], 'Actual legacy receipt opens its review cycle.');
    $pad->action($legacy, 'decide', array('decision' => 'approved'));
    review_check($records->record($legacy)['pad_decision'] === 'approved', 'Existing handoff completes new decision flow.');
    review_check((int) $db->query('SELECT COUNT(*) FROM documents')->fetchColumn() === 4, 'All handoffs retain original document records.');

    $oldResult = $fixture(12, 80);
    $oldId = $db->query("SELECT id FROM documents WHERE reference_no='$oldResult'")->fetchColumn();
    $db->exec("UPDATE documents SET pending_office_id=2, status='in_transit',movement_type='return' WHERE id=$oldId");
    $db->exec("INSERT INTO document_routes (document_id,from_office_id,to_office_id,action,dispatched_at,acted_by_user_id,created_at)
        VALUES ($oldId,80,2,'returned',UTC_TIMESTAMP(),1,UTC_TIMESTAMP())");
    review_check($records->record($oldResult)['pad_result_unrecorded'], 'Legacy PAD return explicitly has no recorded decision.');
    review_check(in_array($oldResult, array_column($records->workspace(array('queue' => 'pad_results'))['data'], 'reference_no'), true), 'Unclassified older PAD returns appear in PAD Results.');
    $records->action($oldResult, 'receive', array());
    review_check($records->record($oldResult)['pad_result_unrecorded'] && $records->record($oldResult)['pad_decision'] === null, 'Actual receipt does not guess a legacy approval.');
    $attachFixture($oldResult);
    $db->exec("UPDATE document_routes SET notes='Historical PAD routing instructions.' WHERE document_id=$oldId AND dispatched_at IS NOT NULL");
    $records->action($oldResult, 'act', array('owner_office_id' => 9, 'hard_copy_received' => true, 'notes' => 'Processed the historical PAD return for its owner.'));
    review_check(!in_array($oldResult, array_column($records->workspace(array('queue' => 'pad_results'))['data'], 'reference_no'), true), 'Distributed legacy return leaves the current results queue.');

    $rollback = $fixture(11);
    $records->action($rollback, 'route', array('to_office_id' => 80));
    $pad->action($rollback, 'receive', array());
    $before = $pad->record($rollback, true);
    $db->exec('UPDATE offices SET is_active=0 WHERE id=2');
    review_rejects(function () use ($pad, $rollback) { $pad->action($rollback, 'decide', array('decision' => 'approved')); }, 422, 'Inactive Central Records destination cannot receive a result.');
    review_check($pad->record($rollback, true)['pad_reviews'] === $before['pad_reviews'], 'Invalid destination leaves review unchanged.');
    $db->exec('UPDATE offices SET is_active=1 WHERE id=2');
    $db->exec("CREATE TRIGGER reject_result BEFORE INSERT ON document_routes FOR EACH ROW
        BEGIN IF NEW.action='returned' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Intentional result failure'; END IF; END");
    try {
        $pad->action($rollback, 'decide', array('decision' => 'approved'));
        throw new RuntimeException('Expected return insertion failure.');
    } catch (PDOException $expected) {
        $after = $pad->record($rollback, true);
        review_check($after['pad_reviews'] === $before['pad_reviews'] && $after['history'] === $before['history'] && !$after['pending_office_id'],
            'Failed dispatch rolls back the decision and both timeline events.');
    }
    $db->exec('DROP TRIGGER reject_result');

    // Finalization belongs to received PAD results at Central Records.
    $final = $fixture(700);
    $records->action($final, 'route', array('to_office_id' => 80, 'notes' => 'Submission notes from Records.'));
    $pad->action($final, 'receive', array());
    $pad->action($final, 'decide', array('decision' => 'approved', 'notes' => 'Signed and noted; finalize at Records.'));
    $adminAtRecords = $actor('admin'); $adminAtRecords['office_id'] = 2;
    $adminRecords = new DocumentRouting($db, $adminAtRecords);
    review_check(!$adminRecords->record($final)['permissions']['act'], 'Selecting Administrator at the receiving office does not grant Act authority.');
    review_rejects(function () use ($adminRecords, $final) { $adminRecords->action($final, 'return', array('notes' => 'Bypass Records action')); }, 409, 'A different module at Records cannot bypass Act by returning the result.');
    review_check($records->record($final)['permissions']['act'] && !$records->record($final)['permissions']['route']
        && !$records->record($final)['permissions']['return'], 'A pending PAD handoff supports Act with explicit receipt confirmation, without Send or Return.');
    review_check($records->record($final)['permissions']['act'] && !$records->record($final)['permissions']['complete']
        && !$records->record($final)['permissions']['deliver'], 'A received PAD result offers the final Act action at Records.');
    review_rejects(function () use ($records, $final) { $records->action($final, 'act', array('notes' => 'Finalized')); }, 422, 'Final Act requires an attachment.');
    $id = (int) $records->record($final)['id'];
    $q = $db->prepare('UPDATE documents SET stored_file_name=?, file_name=?, file_mime=?, file_size=? WHERE id=?');
    $q->execute(array(basename($finalFile), 'signed.pdf', 'application/pdf', filesize($finalFile), $id));
    review_rejects(function () use ($records, $final) { $records->action($final, 'act', array('notes' => 'Finalized', 'owner_office_id' => 9)); }, 422, 'Act requires affirmative hard-copy confirmation.');
    review_rejects(function () use ($records, $final) { $records->action($final, 'act', array('notes' => 'Finalized', 'hard_copy_received' => true)); }, 422, 'Act requires an owner office.');
    review_rejects(function () use ($records, $final) { $records->action($final, 'act', array('notes' => 'Finalized', 'hard_copy_received' => true, 'owner_office_id' => 2)); }, 422, 'Act cannot send the document back to the acting Records office.');
    $db->exec('UPDATE offices SET is_active=0 WHERE id=9');
    review_rejects(function () use ($records, $final) { $records->action($final, 'act', array('notes' => 'Finalized', 'hard_copy_received' => true, 'owner_office_id' => 9)); }, 422, 'Act rejects an inactive owner.');
    $db->exec('UPDATE offices SET is_active=1 WHERE id=9');
    $movement = (int) $records->record($final)['last_movement_id'];
    $db->exec('UPDATE document_routes SET notes=NULL WHERE id=' . $movement);
    review_rejects(function () use ($records, $final) { $records->action($final, 'act', array('notes' => 'Finalized')); }, 422, 'An older route note cannot replace missing notes on the latest PAD dispatch.');
    $db->exec("UPDATE document_routes SET notes='Signed and noted by PAD.' WHERE id=" . $movement);
    $q->execute(array('missing-final-fixture.pdf', 'signed.pdf', 'application/pdf', 16, $id));
    review_rejects(function () use ($records, $final) { $records->action($final, 'act', array('notes' => 'Finalized')); }, 422, 'Attachment metadata alone cannot finalize a missing file.');
    file_put_contents($finalFile, '%PDF-1.4 final signed sample');
    $q->execute(array(basename($finalFile), 'signed.pdf', 'application/pdf', filesize($finalFile), $id));
    review_rejects(function () use ($office, $final) { $office->action($final, 'act', array('notes' => 'Wrong module')); }, 409, 'Having Central Records in the account does not allow Act in another active module.');
    $db->exec("CREATE TRIGGER reject_final_action BEFORE INSERT ON document_routes FOR EACH ROW
        BEGIN IF NEW.action='acted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Intentional finalization failure'; END IF; END");
    $beforeFinal = $records->record($final, true);
    try { $records->action($final, 'act', array('notes' => 'Finalized', 'hard_copy_received' => true, 'owner_office_id' => 9)); throw new RuntimeException('Expected finalization rollback.'); }
    catch (PDOException $expected) {}
    $db->exec('DROP TRIGGER reject_final_action');
    $afterFinal = $records->record($final, true);
    review_check($afterFinal['status'] === 'in_transit' && (int) $afterFinal['pending_office_id'] === 2
        && $afterFinal['history'] === $beforeFinal['history'], 'A failed Act dispatch rolls back the receipt, custody change, and action together.');
    $records->action($final, 'act', array('notes' => 'Final document checked and released.', 'hard_copy_received' => true, 'owner_office_id' => 9));
    $finished = $records->record($final, true);
    review_check($finished['status'] === 'in_transit' && (int) $finished['current_office_id'] === 2 && (int) $finished['pending_office_id'] === 9
        && end($finished['history'])['action'] === 'acted' && end($finished['history'])['dispatched_at'], 'Act records the Records milestone and dispatch while awaiting real owner delivery.');
    review_check($finished['is_owner_release'] && (int) $finished['recipient_office_id'] === 9 && $finished['release_delivered_at'] === null, 'Owner destination and pending delivery are recorded independently from Acted.');
    foreach (array('route', 'return', 'receive', 'act', 'complete', 'deliver', 'upload_file', 'delete') as $action) {
        review_check(!$finished['permissions'][$action], 'Final Act closes ' . $action . '.');
    }
    foreach (array('route', 'return', 'act', 'complete', 'deliver') as $action) {
        review_rejects(function () use ($records, $final, $action) { $records->action($final, $action, array('notes' => 'Retry', 'to_office_id' => 9)); }, 409, 'Finalized documents reject further ' . $action . '.');
    }
    review_check($records->workspace(array('queue' => 'acted', 'search' => $final))['pagination']['total'] === 1
        && $records->workspace(array('queue' => 'pad_results', 'search' => $final))['pagination']['total'] === 0, 'Finalized PAD result moves from PAD Results to Acted.');
    review_check($records->workspace(array('queue' => 'delivered', 'search' => $final))['pagination']['total'] === 0, 'Act and dispatch do not fabricate owner delivery.');
    review_check($records->workspace(array('queue' => 'acted', 'status' => 'acted', 'search' => $final))['pagination']['total'] === 1, 'Acted filter includes dispatched Records actions.');
    $ownerIncoming = $office->workspace(array('queue' => 'incoming', 'search' => $final));
    review_check($ownerIncoming['pagination']['total'] === 1 && $office->record($final)['permissions']['receive'], 'Only the selected owner can confirm the final incoming handoff.');
    review_rejects(function () use ($records, $final) { $records->action($final, 'receive', array()); }, 409, 'Records cannot confirm delivery on behalf of its owner.');
    $office->action($final, 'receive', array('notes' => 'Owner received the released hard copy.'));
    $delivered = $records->record($final, true);
    review_check($delivered['status'] === 'delivered' && (int) $delivered['current_office_id'] === 9 && !$delivered['pending_office_id']
        && $delivered['release_delivered_at'] && end($delivered['history'])['action'] === 'delivered', 'Owner receipt confirms final delivery and preserves its time and custody.');
    review_check($records->workspace(array('queue' => 'acted', 'search' => $final))['pagination']['total'] === 1
        && $records->workspace(array('queue' => 'delivered', 'search' => $final))['pagination']['total'] === 1, 'Records retains its Acted archive after confirmed delivery.');
    review_rejects(function () use ($office, $final) { $office->action($final, 'receive', array()); }, 409, 'Owner receipt retry cannot duplicate final delivery.');
    $closedLegacy = $fixture(702);
    $db->exec("UPDATE documents SET status='acted' WHERE reference_no='$closedLegacy'");
    review_check(!$records->record($closedLegacy)['permissions']['route'] && !$records->record($closedLegacy)['permissions']['act']
        && $records->workspace(array('queue' => 'acted', 'search' => $closedLegacy))['pagination']['total'] === 1, 'Earlier closed Acted records remain closed and visible without being rewritten.');
    $notPad = $fixture(701, 9);
    $office->action($notPad, 'route', array('to_office_id' => 2, 'notes' => 'Ordinary office handoff.'));
    $records->action($notPad, 'receive', array());
    review_check(!$records->record($notPad)['permissions']['act'], 'Records cannot mark ordinary office handoffs Acted.');
    review_rejects(function () use ($records, $notPad) { $records->action($notPad, 'act', array('notes' => 'Wrong sender')); }, 409, 'API checks PAD sender authority.');

    // Exercise both public endpoint families with real token/role validation in this generated database.
    $db->exec('CREATE TABLE fixture_accounts (acc_email VARCHAR(191) PRIMARY KEY)');
    $db->exec('INSERT INTO fixture_accounts SELECT email FROM users');
    putenv('AUTH_ACCOUNT_TABLE=fixture_accounts'); putenv('AUTH_ACCOUNT_EMAIL_COLUMN=acc_email');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Unable to reserve test port.');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $pipes = array();
    $httpServer = proc_open(array(PHP_BINARY, '-S', $address, __DIR__ . '/office-routing-http.php'),
        array(0 => array('pipe', 'r'), 1 => array('file', $serverLog, 'a'), 2 => array('file', $serverLog, 'a')), $pipes);
    if (!is_resource($httpServer)) throw new RuntimeException('Unable to launch test HTTP server.');
    fclose($pipes[0]);
    $port = (int) substr($address, strrpos($address, ':') + 1);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, .1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(20000);
    }
    if (!$ready) throw new RuntimeException('Test server did not start.');
    $token = create_access_token($user);
    $http = function ($path, $role, $payload = null) use ($address, $token) {
        $context = stream_context_create(array('http' => array('method' => $payload === null ? 'GET' : 'POST',
            'header' => 'Authorization: Bearer ' . $token . "\r\nX-PAD-Role: " . $role . "\r\nContent-Type: application/json\r\n",
            'content' => $payload === null ? '' : json_encode($payload), 'ignore_errors' => true, 'timeout' => 5)));
        $body = file_get_contents('http://' . $address . $path, false, $context);
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
        return array('status' => (int) $status[1], 'data' => json_decode($body, true));
    };
    review_check($http('/api/doc_router/routing/workspace?queue=for_approval', 'ovp_pad')['status'] === 200, 'Role-scoped For Approval endpoint works.');
    review_check($http('/api/documents/' . $rollback . '/decide', 'admin', array('decision' => 'approved'))['status'] === 409, 'Ordinary endpoint cannot bypass PAD mode.');
    review_check($http('/api/doc_router/documents/' . $rollback . '/decide', 'ovp_pad', array('decision' => 'approved'))['status'] === 200, 'PAD module decision endpoint records return.');
    review_check($http('/api/routing/workspace?queue=pad_results', 'ovp_central_records')['status'] === 200, 'Ordinary endpoint uses the same PAD Results queue.');
    review_check($http('/api/doc_router/documents/' . $rollback . '/decide', 'ovp_pad', array('decision' => 'approved'))['status'] === 409, 'HTTP retry cannot create duplicate result.');
    $attachFixture($rollback);
    $releaseInput = array('notes' => 'Checked and dispatched to owner.', 'owner_office_id' => 9, 'hard_copy_received' => true);
    review_check($http('/api/doc_router/documents/' . $rollback . '/act', 'ovp_pad', $releaseInput)['status'] === 409, 'HTTP Act requires the active Central Records role.');
    $unconfirmedInput = $releaseInput; $unconfirmedInput['hard_copy_received'] = false;
    review_check($http('/api/doc_router/documents/' . $rollback . '/act', 'ovp_central_records', $unconfirmedInput)['status'] === 422, 'HTTP Act requires explicit physical-receipt confirmation.');
    review_check($http('/api/doc_router/documents/' . $rollback . '/act', 'ovp_central_records', $releaseInput)['status'] === 200, 'HTTP Act confirms receipt and dispatches to the owner in one request.');
    review_check($http('/api/doc_router/documents/' . $rollback . '/act', 'ovp_central_records', $releaseInput)['status'] === 409, 'HTTP Act retry cannot duplicate the owner dispatch.');
    $ownerReceipt = $http('/api/doc_router/documents/' . $rollback . '/receive', 'sksu_officials', array('notes' => 'Owner has the released copy.'));
    review_check($ownerReceipt['status'] === 200 && $ownerReceipt['data']['delivery_confirmed'] === true, 'HTTP owner receipt reports actual confirmed delivery.');
    $releasedRecord = $http('/api/doc_router/documents/' . $rollback, 'ovp_central_records')['data']['data'];
    review_check($releasedRecord['status'] === 'delivered' && $releasedRecord['is_owner_release'], 'HTTP Records view retains the Acted milestone after owner delivery.');
    $db->exec("UPDATE users SET roles='admin,ovp_central_records,sksu_officials' WHERE id=1");
    review_check($http('/api/doc_router/routing/workspace?queue=for_approval', 'ovp_pad')['status'] === 403, 'Removing PAD role revokes an existing token.');
    echo 'PAD review checks passed: ' . $checks . PHP_EOL;
} finally {
    if (is_resource($httpServer)) { proc_terminate($httpServer); proc_close($httpServer); }
    if (is_file($serverLog)) unlink($serverLog);
    if (is_file($finalFile)) unlink($finalFile);
    foreach ($fixtureFiles as $file) if (is_file($file)) unlink($file);
    $management->exec('DROP DATABASE `' . $testDatabase . '`');
}
