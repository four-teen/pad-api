<?php

// Real MySQL transactions in a generated, empty test database. No live documents or files are created.
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/DocumentRouting.php';
require dirname(__DIR__) . '/src/PadReviewSchema.php';
require dirname(__DIR__) . '/src/DocumentTypeSchema.php';
require dirname(__DIR__) . '/src/DocumentSourceSchema.php';
require dirname(__DIR__) . '/src/DocumentAttachmentSchema.php';

function fixture_registration($key, $subject = 'Concurrency document')
{
    return array('key' => $key, 'hash' => hash('sha256', $key . $subject), 'subject' => $subject,
        'description' => 'Sample remarks', 'type' => 'Memorandum', 'origin' => 'office', 'external' => '',
        'holder' => 1, 'recipient' => null, 'stored' => hash('sha256', $key) . '.pdf', 'mime' => 'application/pdf', 'size' => 16, 'name' => 'sample.pdf');
}

function save_fixture(DocumentRouting $routing, array $registration)
{
    // Exercise the same persistence transaction used after a successful HTTP upload.
    $method = new ReflectionMethod(DocumentRouting::class, 'saveRegistration');
    $method->setAccessible(true);
    return $method->invoke($routing, $registration);
}

if (($argv[1] ?? '') === '--worker') {
    if (!preg_match('/^pad_routing_test_[a-f0-9]{12}$/', env_value('DB_DATABASE', ''))) exit(2);
    $db = database();
    $actor = $db->query('SELECT u.*, o.code AS office_code FROM users u LEFT JOIN offices o ON o.id = u.office_id WHERE u.id = 1')->fetch();
    while (microtime(true) < (float) env_value('PAD_ROUTING_TEST_START', '0')) usleep(1000);
    echo json_encode(save_fixture(new DocumentRouting($db, $actor), fixture_registration($argv[2])));
    exit;
}

$checks = 0;
function routing_check($condition, $message)
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function routing_outgoing_check(DocumentRouting $routing, array $references, $message, array $input = array(), $deskCount = null)
{
    $workspace = $routing->workspace(array_merge($input, array('queue' => 'outgoing')));
    $actual = array_column($workspace['data'], 'reference_no');
    sort($references); sort($actual);
    $expected = count($references);
    routing_check($actual === $references && $workspace['pagination']['total'] === $expected
        && $workspace['counts']['outgoing'] === $expected
        && $workspace['desk_counts']['outgoing'] === ($deskCount === null ? $expected : $deskCount), $message);
}
function routing_rejects(callable $callback, $status, $message)
{
    try { $callback(); } catch (ApiException $error) {
        routing_check($error->getCode() === $status, $message);
        return;
    }
    throw new RuntimeException($message);
}

$sourceDatabase = env_value('DB_DATABASE');
if (!preg_match('/^[A-Za-z0-9_]+$/', $sourceDatabase)) throw new RuntimeException('Unsupported source database name.');
$management = new PDO('mysql:host=' . env_value('DB_HOST') . ';port=' . env_value('DB_PORT', '3306') . ';dbname=' . $sourceDatabase . ';charset=utf8mb4',
    env_value('DB_USERNAME'), env_value('DB_PASSWORD', ''), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$testDatabase = 'pad_routing_test_' . bin2hex(random_bytes(6));
$management->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$workers = array();
$httpServer = null;
$uploadDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pad-routing-test-' . bin2hex(random_bytes(6));
$serverLog = $uploadDirectory . '.log';
try {
    foreach (array('offices', 'office_officials', 'users', 'documents', 'document_routes', 'document_number_series') as $table) {
        $management->exec('CREATE TABLE `' . $testDatabase . '`.`' . $table . '` LIKE `' . $sourceDatabase . '`.`' . $table . '`');
    }
    putenv('DB_DATABASE=' . $testDatabase);
    $db = database();
install_pad_reviews($db);
    install_document_registration($db);
    install_document_sources($db);
    install_optional_document_files($db);
    install_optional_document_files($db);
    $fileColumns = array_column($db->query('SHOW COLUMNS FROM documents')->fetchAll(), 'Null', 'Field');
    routing_check($fileColumns['file_name'] === 'YES' && $fileColumns['stored_file_name'] === 'YES'
        && $fileColumns['file_mime'] === 'YES' && $fileColumns['file_size'] === 'YES', 'Optional-file migration is repeatable and permits empty attachment metadata.');
    $sampleSources = array(array('divisionid' => 301, 'division_desc' => 'Sample Outside Agency'),
        array('divisionid' => 302, 'division_desc' => 'Second Outside Agency'), array('divisionid' => 303, 'division_desc' => 'Sample Outside Agency'));
    import_document_sources($db, $sampleSources);
    import_document_sources($db, $sampleSources);
    routing_check((int) $db->query('SELECT COUNT(*) FROM document_sources')->fetchColumn() === 3,
        'Source imports keep legacy identities without duplicating rows when rerun.');
    $sampleTypes = array(array('docid' => 1, 'doctype_desc' => 'Memorandum'), array('docid' => 7, 'doctype_desc' => 'TRAVEL ORDER'));
    $samplePeople = array(array('acc_id' => 101, 'acc_name' => 'Sample Traveler'), array('acc_id' => 102, 'acc_name' => 'Second Traveler'));
    import_document_catalog($db, $sampleTypes, $samplePeople);
    import_document_catalog($db, $sampleTypes, $samplePeople);
    routing_check((int) $db->query('SELECT COUNT(*) FROM document_types')->fetchColumn() === 2
        && (int) $db->query('SELECT COUNT(*) FROM document_personnel')->fetchColumn() === 2, 'Catalog imports can be rerun without duplicating types or personnel.');
    $db->exec("INSERT INTO offices (id, code, name, campus, is_active, created_at, updated_at) VALUES
        (1, 'ONE', 'Office One', 'Isulan', 1, NOW(), NOW()), (2, 'TWO', 'Office Two', 'Tacurong', 1, NOW(), NOW()),
        (3, 'THREE', 'Office Three', 'Isulan', 1, NOW(), NOW()), (4, 'RECORDS', 'Records Office', 'Isulan', 1, NOW(), NOW()),
        (5, 'INACTIVE', 'Inactive Office', '', 0, NOW(), NOW())");
    $db->exec("INSERT INTO users (id, name, email, role, office_id, is_active, created_at, updated_at) VALUES
        (1, 'Sender', 'sender@example.test', 'staff', 1, 1, NOW(), NOW()),
        (2, 'Receiver', 'receiver@example.test', 'staff', 2, 1, NOW(), NOW()),
        (3, 'Third office', 'third@example.test', 'staff', 3, 1, NOW(), NOW()),
        (4, 'Records staff', 'records@example.test', 'records', 4, 1, NOW(), NOW()),
        (5, 'Administrator', 'admin@example.test', 'admin', NULL, 1, NOW(), NOW()),
        (6, 'Unassigned', 'unassigned@example.test', 'staff', NULL, 1, NOW(), NOW()),
        (7, 'Inactive staff', 'inactive@example.test', 'staff', 5, 1, NOW(), NOW())");
    $db->exec("UPDATE users SET roles = IF(role = 'admin', 'admin', IF(role = 'records', 'ovp_central_records', 'sksu_officials')), ovp_central_records_office_id = IF(role = 'records', office_id, NULL)");
    putenv('PAD_CENTRAL_RECORDS_OFFICE_ID=4');
    $actors = array();
    foreach ($db->query('SELECT u.*, o.code AS office_code FROM users u LEFT JOIN offices o ON o.id = u.office_id')->fetchAll() as $actor) { $actor['active_role'] = $actor['roles']; $actors[$actor['id']] = new DocumentRouting($db, $actor); }
    $one = $actors[1]; $two = $actors[2]; $three = $actors[3];
    $reference = save_fixture($one, fixture_registration('workflow-fixture-0001', 'Workflow test'))['reference_no'];
    routing_check(preg_match('/^\d{2}-00001$/', $reference), 'First registration uses the two-digit year and five-digit annual number.');
    routing_check($one->record($reference)['permissions']['route'], 'The registering office holds the document.');
    routing_outgoing_check($one, array(), 'An unsent registration has no Outgoing row, count, or dashboard workload.');
    $registered = $one->record($reference, true);
    routing_check($registered['created_at'] === $registered['updated_at']
        && $registered['created_at'] === $registered['history'][0]['created_at']
        && $registered['created_at'] === $registered['history'][0]['received_at'],
        'Registration and its custody receipt record exactly the same server instant.');
    routing_check($registered['timestamp_timezone'] === 'UTC' && $registered['display_timezone'] === 'Asia/Manila'
        && substr($registered['server_time'], -1) === 'Z', 'Timeline declares timestamp zones and includes an explicit server clock.');
    routing_rejects(function () use ($three, $reference) { $three->record($reference); }, 403, 'Uninvolved offices cannot view the document.');
    routing_rejects(function () use ($one, $reference) { $one->action($reference, 'route', array('to_office_id' => 1)); }, 422, 'Routing to the same office is rejected.');
    routing_rejects(function () use ($one, $reference) { $one->action($reference, 'route', array('to_office_id' => 5)); }, 422, 'Inactive destinations are rejected.');
    $one->action($reference, 'route', array('to_office_id' => 2, 'notes' => 'Please process.'));
    routing_outgoing_check($one, array($reference), 'A pending handoff appears in its sender Outgoing list, pagination, navigation count, and dashboard count.');
    routing_outgoing_check($two, array(), 'The destination does not count an incoming handoff as Outgoing.');
    routing_check($two->workspace(array('queue' => 'all'))['pagination']['total'] === 0
        && $two->workspace(array('queue' => 'incoming'))['counts']['all'] === 0,
        'An ordinary office does not list or count a receive-only document in All Timeline.');
    routing_outgoing_check($actors[5], array($reference), 'All-office monitoring includes the unreceived handoff once.', array('office_id' => 'all'), 0);
    routing_outgoing_check($actors[5], array($reference), 'A monitored office uses its latest pending dispatch for Outgoing.', array('office_id' => 1), 0);
    $document = $two->record($reference);
    routing_check((int) $document['current_office_id'] === 1 && (int) $document['pending_office_id'] === 2, 'Sending preserves the holder until receipt.');
    routing_check($two->workspace(array('queue' => 'incoming'))['counts']['incoming'] === 1, 'The destination sees its incoming document.');
    routing_rejects(function () use ($two, $reference) { $two->action($reference, 'route', array('to_office_id' => 3)); }, 409, 'The destination cannot forward before receipt.');
    routing_rejects(function () use ($one, $reference) { $one->action($reference, 'receive', array()); }, 409, 'The sender cannot confirm destination receipt.');
    $two->action($reference, 'receive', array('notes' => 'Received original.'));
    routing_check($two->workspace(array('queue' => 'all'))['pagination']['total'] === 0
        && $two->workspace(array('queue' => 'received'))['pagination']['total'] === 1,
        'Confirmed receipt remains in Received while ordinary All Timeline stays limited to created or sent documents.');
    routing_outgoing_check($one, array(), 'Destination receipt removes the sender Outgoing row and all matching counts.');
    routing_outgoing_check($actors[5], array(), 'Destination receipt also removes the handoff from all-office Outgoing.', array('office_id' => 'all'), 0);
    routing_check($one->workspace(array('queue' => 'all'))['pagination']['total'] === 1
        && count($one->record($reference, true)['history']) === 3, 'The sender retains All Timeline access and full handoff history after receipt.');
    $document = $two->record($reference, true);
    routing_check((int) $document['current_office_id'] === 2 && $document['pending_office_id'] === null, 'Receipt transfers holding responsibility.');
    routing_check($document['updated_at'] === $document['history'][2]['created_at']
        && $document['updated_at'] === $document['history'][2]['received_at']
        && $document['updated_at'] === $document['history'][1]['received_at'],
        'The dispatch receipt, receipt event and current document use one timestamp.');
    routing_check((int) $document['history'][1]['from_office_id'] === 1 && (int) $document['history'][2]['to_office_id'] === 2,
        'Custody events retain office identities for calculating separate visits.');
    routing_check($document['history'][1]['action'] === 'routed' && $document['history'][1]['acted_by_name'] === 'Sender'
        && $document['history'][2]['action'] === 'received' && $document['history'][2]['acted_by_name'] === 'Receiver', 'Sending and receipt preserve separate actors and events.');
    routing_rejects(function () use ($two, $reference) { $two->action($reference, 'receive', array()); }, 409, 'Duplicate receipt creates no extra movement.');
    $two->action($reference, 'route', array('to_office_id' => 3));
    routing_check($two->workspace(array('queue' => 'all'))['pagination']['total'] === 1, 'Sending a received document adds it to that office All Timeline.');
    routing_outgoing_check($two, array($reference), 'Forwarding creates an Outgoing item for the new sender.');
    routing_outgoing_check($one, array(), 'A later forward does not reappear in an earlier sender Outgoing.');
    routing_outgoing_check($actors[5], array(), 'Monitoring an earlier sender excludes a different office pending handoff.', array('office_id' => 1), 0);
    $three->action($reference, 'receive', array());
    routing_outgoing_check($two, array(), 'Receipt at a third office clears the latest sender Outgoing.');
    routing_check($one->record($reference)['reference_no'] === $reference, 'Earlier offices retain timeline access after forwarding.');
    routing_rejects(function () use ($three, $reference) { $three->action($reference, 'return', array()); }, 422, 'Returning requires a reason.');
    $three->action($reference, 'return', array('notes' => 'Missing approval.'));
    routing_outgoing_check($three, array($reference), 'A pending return belongs to the returning office Outgoing.');
    routing_outgoing_check($two, array(), 'The return destination sees no Outgoing item from its earlier dispatch.');
    routing_outgoing_check($one, array(), 'A historical sender stays out of Outgoing while another office returns the document.');
    routing_check($two->workspace(array('queue' => 'returned'))['pagination']['total'] === 1
        && $three->workspace(array('queue' => 'returned'))['pagination']['total'] === 0, 'Returned queue belongs to the returning destination.');
    $two->action($reference, 'receive', array());
    routing_outgoing_check($three, array(), 'Receipt of a returned document clears the returning office Outgoing.');
    routing_check($two->workspace(array('queue' => 'received'))['pagination']['total'] === 1, 'The confirmed return appears in its current holder Received queue.');
    routing_rejects(function () use ($two, $reference) { $two->action($reference, 'act', array()); }, 422, 'Action taken requires remarks.');
    routing_rejects(function () use ($two, $reference) { $two->action($reference, 'act', array('notes' => 'Approved and signed.')); }, 409, 'Ordinary office handoffs cannot be marked Acted.');
    routing_rejects(function () use ($two, $reference) { $two->action($reference, 'deliver', array()); }, 422, 'Delivery requires recipient details.');
    $two->action($reference, 'deliver', array('notes' => 'Released to applicant at front desk.'));
    routing_outgoing_check($one, array(), 'Delivered documents with earlier dispatch history stay out of Outgoing.');
    routing_check($one->workspace(array('queue' => 'delivered'))['pagination']['total'] === 1, 'Participants can see final delivery.');
    routing_rejects(function () use ($two, $reference) { $two->action($reference, 'route', array('to_office_id' => 1)); }, 409, 'Finished documents cannot be routed again.');
    routing_rejects(function () use ($actors, $reference) { $actors[5]->action($reference, 'receive', array()); }, 403, 'Monitoring does not let an unassigned administrator receive for an office.');
    routing_rejects(function () use ($one) { $one->workspace(array('office_id' => 2)); }, 403, 'Ordinary staff cannot switch office monitoring scope.');
    routing_check($actors[6]->workspace(array())['counts']['all'] === 0, 'Unassigned staff have an empty workspace.');
    routing_check($actors[5]->workspace(array('office_id' => 'all'))['counts']['all'] === 1, 'Administrators can monitor all offices.');
    routing_rejects(function () use ($actors) { $actors[7]->register(array('subject' => 'Inactive office'), array()); }, 422, 'Inactive office accounts cannot register.');
    routing_rejects(function () use ($one) { $one->register(array('subject' => 'Missing type'), array()); }, 422, 'Registration still requires a catalog document type.');
    routing_rejects(function () use ($one) { $one->register(array('subject' => 'Outside', 'origin_type' => 'external', 'external_sender' => 'Agency'), array()); }, 403, 'Ordinary staff cannot register external documents for Records.');

    $external = fixture_registration('external-fixture-0001', 'Outside document');
    $external['origin'] = 'external'; $external['holder'] = 4; $external['external'] = 'Outside Agency';
    $externalReference = save_fixture($actors[4], $external)['reference_no'];
    $outside = $actors[4]->record($externalReference, true);
    routing_check($outside['source_office_id'] === null && (int) $outside['current_office_id'] === 4 && $outside['history'][0]['action'] === 'externally_received', 'External registration begins at Records.');
    $draft = save_fixture($one, fixture_registration('complete-fixture-0001'))['reference_no'];
    routing_check($one->record($draft)['is_my_document'] && $one->record($draft)['permissions']['delete'], 'An unsent office registration is My Document and may be deleted.');
    routing_check($one->workspace(array('queue' => 'my_documents'))['pagination']['total'] === 1
        && $one->workspace(array('queue' => 'received'))['pagination']['total'] === 0, 'Own registration is separate from actual Received handoffs.');
    routing_rejects(function () use ($one, $draft) { $one->action($draft, 'complete', array()); }, 409, 'Own registration has only Send and Delete workflow actions.');
    $one->action($draft, 'route', array('to_office_id' => 2));
    routing_rejects(function () use ($one, $draft) { $one->deleteDocument($draft); }, 409, 'A dispatched document cannot be deleted.');
    routing_check($two->record($draft)['permissions']['receive'] && $two->record($draft)['permissions']['return']
        && !$two->record($draft)['permissions']['act'], 'Awaiting receipt permits Receive and Return, without Act.');
    $two->action($draft, 'return', array('notes' => 'Wrong destination; returning before receipt.'));
    routing_outgoing_check($one, array(), 'Return before receipt removes the superseded original sender Outgoing item.');
    routing_outgoing_check($two, array($draft), 'Return before receipt uses the latest sender, even while the stored holder is the original office.');
    $declined = $one->record($draft, true);
    routing_check((int) $declined['pending_office_id'] === 1 && (int) $declined['current_office_id'] === 1
        && $declined['history'][1]['received_at'] === null, 'Return before receipt targets the exact sender without inventing a receipt.');
    routing_rejects(function () use ($two, $draft) { $two->action($draft, 'receive', array()); }, 409, 'A stale destination cannot receive a document it returned.');
    $one->action($draft, 'receive', array());
    routing_outgoing_check($two, array(), 'Receipt of a pre-receipt return ignores the older unreceived dispatch and clears Outgoing.');
    routing_check(!$one->record($draft)['is_my_document'] && !$one->record($draft)['permissions']['delete'], 'An office receives its own returned document in Received, retaining dispatch history.');
    $one->action($draft, 'route', array('to_office_id' => 2));
    $two->action($draft, 'receive', array());
    $two->action($draft, 'complete', array('notes' => 'Filed.'));
    $deletable = save_fixture($one, fixture_registration('delete-fixture-0001'))['reference_no'];
    $deleteNumber = (int) $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn();
    routing_rejects(function () use ($two, $deletable) { $two->deleteDocument($deletable); }, 403, 'Another office cannot delete an unsent registration.');
    $one->deleteDocument($deletable);
    routing_rejects(function () use ($one, $deletable) { $one->record($deletable); }, 404, 'Deleted unsent registrations no longer appear.');
    routing_check((int) $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn() === $deleteNumber, 'Deletion never rewinds the file-code series.');

    $before = (int) $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn();
    $db->exec("CREATE TRIGGER reject_test_document BEFORE INSERT ON documents FOR EACH ROW
        BEGIN IF NEW.subject = 'Force failure' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Intentional test failure'; END IF; END");
    try { save_fixture($one, fixture_registration('rollback-fixture-0001', 'Force failure')); throw new RuntimeException('Expected insertion failure.'); }
    catch (PDOException $expected) { routing_check((int) $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn() === $before, 'Failed registration rolls back its number allocation.'); }
    $db->exec('DROP TRIGGER reject_test_document');

    // Start independent PHP processes against one isolated database to produce real lock contention.
    putenv('PAD_ROUTING_TEST_START=' . (microtime(true) + 1));
    $keys = array();
    for ($i = 0; $i < 8; $i++) $keys[] = 'parallel-registration-' . $i;
    for ($i = 0; $i < 4; $i++) $keys[] = 'same-registration-key';
    foreach ($keys as $key) {
        $pipes = array();
        $process = proc_open(array(PHP_BINARY, __FILE__, '--worker', $key), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start registration worker.');
        fclose($pipes[0]); $workers[] = array($process, $pipes);
    }
    $references = array(); $retries = array();
    foreach ($workers as $index => $worker) {
        $output = stream_get_contents($worker[1][1]); $error = stream_get_contents($worker[1][2]);
        fclose($worker[1][1]); fclose($worker[1][2]); $code = proc_close($worker[0]);
        $workers[$index] = null;
        if ($code !== 0) throw new RuntimeException('Registration worker failed: ' . $error);
        $result = json_decode($output, true);
        if ($index < 8) $references[] = $result['reference_no']; else $retries[] = $result;
    }
    routing_check(count(array_unique($references)) === 8, 'Simultaneous registrations receive eight distinct official numbers.');
    routing_check(count(array_unique(array_column($retries, 'reference_no'))) === 1
        && count(array_filter($retries, function ($result) { return !$result['replayed']; })) === 1, 'Simultaneous retries save one document and return the same number.');
    routing_check((int) $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn() === $before + 9, 'Retries do not consume additional document numbers.');
    routing_rejects(function () use ($one) { save_fixture($one, fixture_registration('same-registration-key', 'Different payload')); }, 409, 'An already saved key rejects changed registration details.');
    for ($i = 0; $i < 13; $i++) save_fixture($one, fixture_registration('pagination-fixture-' . $i, 'Pagination document ' . $i));
    $page = $one->workspace(array('page' => 1)); $last = $one->workspace(array('page' => 999));
    routing_check(count($page['data']) === 20 && $page['pagination']['total'] === 24 && $last['pagination']['page'] === 2 && count($last['data']) === 4, 'Pagination uses real totals and clamps the final page.');
    $search = $one->workspace(array('search' => 'Workflow test'));
    routing_check($search['pagination']['total'] === 1 && $search['counts']['all'] === 24, 'Searching filters rows without changing queue counts.');
    routing_check($one->workspace(array('search' => "' OR 1=1 --"))['pagination']['total'] === 0, 'Search text cannot change the query.');

    // Verify actual multipart uploads, authenticated API actions, and downloads through PHP HTTP handling.
    $db->exec('CREATE TABLE fixture_accounts (acc_email VARCHAR(191) PRIMARY KEY)');
    $db->exec('INSERT INTO fixture_accounts SELECT email FROM users');
    putenv('AUTH_ACCOUNT_TABLE=fixture_accounts'); putenv('AUTH_ACCOUNT_EMAIL_COLUMN=acc_email');
    putenv('UPLOAD_PATH=' . $uploadDirectory);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    if (!$socket) throw new RuntimeException('Could not reserve a test HTTP port.');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $httpPipes = array();
    $httpServer = proc_open(array(PHP_BINARY, '-d', 'upload_tmp_dir=' . sys_get_temp_dir(), '-d', 'upload_max_filesize=12M', '-d', 'post_max_size=13M', '-S', $address, __DIR__ . '/office-routing-http.php'),
        array(0 => array('pipe', 'r'), 1 => array('file', $serverLog, 'a'), 2 => array('file', $serverLog, 'a')), $httpPipes);
    if (!is_resource($httpServer)) throw new RuntimeException('Could not start the test HTTP server.');
    fclose($httpPipes[0]);
    $port = (int) substr($address, strrpos($address, ':') + 1);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port, $socketError, $socketMessage, .1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(20000);
    }
    if (!$ready) throw new RuntimeException('The test HTTP server did not start.');
    $tokens = array();
    foreach ($db->query('SELECT * FROM users')->fetchAll() as $actor) $tokens[$actor['id']] = create_access_token($actor);
    $http = function ($path, $method = 'GET', $content = '', $contentType = 'application/json', $actor = 1, $role = null) use ($address, $tokens) {
        $headers = 'Authorization: Bearer ' . $tokens[$actor] . "\r\nContent-Type: " . $contentType . "\r\n";
        if ($role) $headers .= 'X-PAD-Role: ' . $role . "\r\n";
        $context = stream_context_create(array('http' => array('method' => $method, 'header' => $headers, 'content' => $content, 'ignore_errors' => true, 'timeout' => 5)));
        $body = file_get_contents('http://' . $address . $path, false, $context);
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
        if (substr($path, -9) !== '/download' && !is_array(json_decode($body, true))) throw new RuntimeException('Invalid test HTTP JSON: ' . $body);
        return array('status' => (int) $status[1], 'data' => json_decode($body, true), 'body' => $body);
    };
    $pdf = "%PDF-1.4\n% Isolated routing test\n";
    $multipart = function ($subject = 'HTTP registration', $file = null, $mime = 'application/pdf', array $extra = array()) use ($pdf) {
        $boundary = 'padFixtureBoundary'; $body = '';
        foreach (array_merge(array('subject' => $subject, 'origin_type' => 'office', 'document_type' => 'Memorandum', 'submission_key' => 'http-registration-0001'), $extra) as $name => $value) {
            foreach (is_array($value) ? $value : array($value) as $item) {
                $field = is_array($value) ? $name . '[]' : $name;
                $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $field . "\"\r\n\r\n" . $item . "\r\n";
            }
        }
        if ($file !== false) $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"file\"; filename=\"sample.pdf\"\r\nContent-Type: " . $mime . "\r\n\r\n" . ($file ?? $pdf) . "\r\n";
        $body .= '--' . $boundary . "--\r\n";
        return $body;
    };
    $saved = $http('/api/documents', 'POST', $multipart(), 'multipart/form-data; boundary=padFixtureBoundary');
    routing_check($saved['status'] === 201 && !$saved['data']['replayed'], 'An authenticated multipart upload registers successfully.');
    $httpReference = $saved['data']['reference_no'];
    $replayed = $http('/api/documents', 'POST', $multipart(), 'multipart/form-data; boundary=padFixtureBoundary');
    routing_check($replayed['status'] === 200 && $replayed['data']['reference_no'] === $httpReference && count(glob($uploadDirectory . '/*')) === 1, 'HTTP registration retry keeps one stored file and one official number.');
    routing_check($http('/api/documents', 'POST', $multipart('Changed registration'), 'multipart/form-data; boundary=padFixtureBoundary')['status'] === 409, 'Changed HTTP retry details are rejected.');
    routing_check($http('/api/documents', 'POST', $multipart('Bad file', 'Plain text', 'application/pdf'), 'multipart/form-data; boundary=padFixtureBoundary')['status'] === 422, 'A file with a forged MIME header is rejected.');
    routing_check($http('/api/documents/' . $httpReference . '/route', 'POST', json_encode(array('to_office_id' => 2)))['status'] === 200, 'Authenticated HTTP sending succeeds.');
    $pendingOutgoing = $http('/api/routing/workspace?queue=outgoing');
    routing_check($pendingOutgoing['status'] === 200 && $pendingOutgoing['data']['counts']['outgoing'] === 1
        && $pendingOutgoing['data']['desk_counts']['outgoing'] === 1 && $pendingOutgoing['data']['pagination']['total'] === 1
        && array_column($pendingOutgoing['data']['data'], 'reference_no') === array($httpReference), 'HTTP Outgoing returns the pending document with matching list and dashboard counts.');
    routing_check($http('/api/documents/' . $httpReference . '/receive', 'POST', '{}', 'application/json', 2)['status'] === 200, 'Authenticated destination HTTP receipt succeeds.');
    $receivedOutgoing = $http('/api/routing/workspace?queue=outgoing');
    routing_check($receivedOutgoing['status'] === 200 && $receivedOutgoing['data']['counts']['outgoing'] === 0
        && $receivedOutgoing['data']['desk_counts']['outgoing'] === 0 && $receivedOutgoing['data']['pagination']['total'] === 0
        && $receivedOutgoing['data']['data'] === array(), 'HTTP receipt removes the sender Outgoing list item and counts together.');
    $senderTimeline = $http('/api/routing/workspace?queue=all&search=' . rawurlencode($httpReference));
    routing_check($senderTimeline['status'] === 200 && $senderTimeline['data']['pagination']['total'] === 1, 'The same HTTP sender retains the document in All Timeline after receipt.');
    $detail = $http('/api/documents/' . $httpReference, 'GET', '', 'application/json', 2);
    routing_check($detail['status'] === 200 && count($detail['data']['data']['history']) === 3 && $detail['data']['data']['permissions']['route'], 'HTTP detail returns the full timeline and destination permissions.');
    $download = $http('/api/documents/' . $httpReference . '/download', 'GET', '', 'application/json', 2);
    routing_check($download['status'] === 200 && $download['body'] === $pdf, 'A participating office can download the original uploaded file.');

    $options = $http('/api/routing/registration-options');
    routing_check($options['status'] === 200 && count($options['data']['document_types']) === 2 && count($options['data']['personnel']) === 2, 'Registration exposes the PAD document type and personnel catalogs.');
    routing_check(count($options['data']['source_divisions']) === 3, 'Registration includes the imported agency / division catalog.');
    $travelType = (int) $db->query("SELECT id FROM document_types WHERE details_kind = 'travel_order'")->fetchColumn();
    $memoType = (int) $db->query("SELECT id FROM document_types WHERE name = 'Memorandum'")->fetchColumn();
    $personIds = $db->query('SELECT id FROM document_personnel ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $travelInput = array('document_type_id' => $travelType, 'submission_key' => 'http-travel-order-0001', 'personnel_ids' => $personIds,
        'travel_destination' => 'Sample destination', 'travel_purpose' => 'Sample official activity', 'travel_order_date' => '2026-10-05',
        'travel_departure_date' => '2026-10-06', 'travel_return_date' => '2026-10-07', 'travel_type' => 'Official Business',
        'travel_vehicle_requirement' => 'Yes', 'travel_remarks' => 'Sample travel remarks');
    $travelUpload = function (array $input) use ($http, $multipart) {
        return $http('/api/documents', 'POST', $multipart('Sample Travel Order', null, 'application/pdf', $input), 'multipart/form-data; boundary=padFixtureBoundary');
    };
    $numbersBefore = (int) $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn();
    $filesBefore = count(glob($uploadDirectory . '/*'));
    foreach (array(array('document_type_id' => 999999), array('personnel_ids' => array()), array('personnel_ids' => array(999999)),
        array('travel_destination' => ''), array('travel_departure_date' => '2026-02-30'), array('travel_return_date' => '2026-10-01'),
        array('travel_type' => 'Other'), array('travel_vehicle_requirement' => 'Invalid'), array('document_type_id' => $memoType)) as $invalid) {
        routing_check($travelUpload(array_merge($travelInput, $invalid))['status'] === 422, 'Invalid or mismatched Travel Order fields must be rejected.');
    }
    routing_check((int) $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn() === $numbersBefore
        && count(glob($uploadDirectory . '/*')) === $filesBefore, 'Invalid travel details allocate no number and store no file.');
    $travelSaved = $travelUpload($travelInput);
    routing_check($travelSaved['status'] === 201, 'A Travel Order saves with the selected document type and all additional entries.');
    $travelReference = $travelSaved['data']['reference_no'];
    $travelRecord = $http('/api/documents/' . $travelReference)['data']['data'];
    routing_check($travelRecord['document_type'] === 'TRAVEL ORDER' && (int) $travelRecord['document_type_id'] === $travelType
        && count($travelRecord['personnel']) === 2 && $travelRecord['travel_details']['purpose'] === $travelInput['travel_purpose']
        && $travelRecord['travel_details']['order_date'] === $travelInput['travel_order_date']
        && $travelRecord['travel_details']['departure_date'] === $travelInput['travel_departure_date']
        && $travelRecord['travel_details']['return_date'] === $travelInput['travel_return_date']
        && $travelRecord['travel_details']['travel_type'] === 'Official Business'
        && $travelRecord['travel_details']['vehicle_requirement'] === 'Yes'
        && $travelRecord['travel_details']['remarks'] === $travelInput['travel_remarks'], 'Document detail returns the saved travelers and complete travel information.');
    $travelReplay = $travelUpload(array_merge($travelInput, array('personnel_ids' => array_reverse($personIds))));
    routing_check($travelReplay['status'] === 200 && $travelReplay['data']['reference_no'] === $travelReference
        && count(glob($uploadDirectory . '/*')) === $filesBefore + 1, 'Retrying a Travel Order with reordered travelers keeps one document and file.');
    routing_check($travelUpload(array_merge($travelInput, array('travel_purpose' => 'Changed purpose')))['status'] === 409,
        'A retry cannot silently overwrite saved travel information.');
    routing_check($travelUpload(array_merge($travelInput, array('personnel_ids' => array($personIds[0]))))['status'] === 409,
        'A retry cannot silently change the saved travelers.');
    $db->exec("UPDATE document_personnel SET name = 'Renamed sample traveler' WHERE id = " . (int) $personIds[0]);
    routing_check($http('/api/documents/' . $travelReference)['data']['data']['personnel'][0]['name'] === 'Sample Traveler', 'Saved personnel names remain stable when the catalog changes.');
    $ordinary = $http('/api/documents', 'POST', $multipart('Sample non-travel document', null, 'application/pdf',
        array('document_type_id' => $memoType, 'submission_key' => 'http-other-document-0001', 'personnel_ids' => array($personIds[0]))), 'multipart/form-data; boundary=padFixtureBoundary');
    $ordinaryDetail = $http('/api/documents/' . $ordinary['data']['reference_no'])['data']['data'];
    routing_check($ordinary['status'] === 201 && count($ordinaryDetail['personnel']) === 1 && $ordinaryDetail['travel_details'] === null,
        'Other document types save personnel links without a travel record.');

    $beforeFailure = (int) $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn();
    $filesBeforeFailure = count(glob($uploadDirectory . '/*'));
    $db->exec("CREATE TRIGGER reject_test_travel BEFORE INSERT ON document_travel_details FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Intentional travel detail failure'");
    routing_check($travelUpload(array_merge($travelInput, array('submission_key' => 'http-travel-rollback-0001')))['status'] === 500,
        'A failed travel detail save fails the entire registration.');
    routing_check((int) $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn() === $beforeFailure
        && count(glob($uploadDirectory . '/*')) === $filesBeforeFailure
        && (int) $db->query("SELECT COUNT(*) FROM documents WHERE submission_key = 'http-travel-rollback-0001'")->fetchColumn() === 0,
        'A failed travel save rolls back the document, personnel, number, and staged upload.');
    $db->exec('DROP TRIGGER reject_test_travel');

    $sourceId = (int) $db->query('SELECT id FROM document_sources WHERE legacy_division_id = 301')->fetchColumn();
    $sameNameSourceId = (int) $db->query('SELECT id FROM document_sources WHERE legacy_division_id = 303')->fetchColumn();
    $sourceInput = array('document_type_id' => $memoType, 'origin_type' => 'external', 'source_division_id' => $sourceId,
        'external_sender' => 'Forged source name', 'submission_key' => 'http-external-source-0001');
    $sourceUpload = function (array $input, $actor = 5) use ($http, $multipart) {
        return $http('/api/documents', 'POST', $multipart('Sample incoming agency document', null, 'application/pdf', $input),
            'multipart/form-data; boundary=padFixtureBoundary', $actor);
    };
    $sourceNumbersBefore = (int) $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn();
    $sourceFilesBefore = count(glob($uploadDirectory . '/*'));
    foreach (array(array('source_division_id' => 999999), array('source_division_id' => ''), array('source_division_id' => array($sourceId))) as $invalidSource) {
        routing_check($sourceUpload(array_merge($sourceInput, $invalidSource))['status'] === 422, 'Missing, invalid, or mismatched source divisions are rejected.');
    }
    routing_check($sourceUpload(array_merge($sourceInput, array('origin_type' => 'office', 'source_division_id' => 999999)), 1)['status'] === 422,
        'Office registration also requires a valid source catalog identity when one is provided.');
    $db->exec('UPDATE document_sources SET is_active = 0 WHERE id = ' . $sourceId);
    routing_check($sourceUpload($sourceInput)['status'] === 422, 'An inactive source cannot be used for a new registration.');
    routing_check(count($http('/api/routing/registration-options')['data']['source_divisions']) === 2, 'Inactive sources are excluded from the selection list.');
    $db->exec('UPDATE document_sources SET is_active = 1 WHERE id = ' . $sourceId);
    routing_check($sourceUpload($sourceInput, 1)['status'] === 403, 'An ordinary office cannot bypass Records / Administrator external registration policy.');
    routing_check((int) $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn() === $sourceNumbersBefore
        && count(glob($uploadDirectory . '/*')) === $sourceFilesBefore, 'Source validation failures do not allocate numbers or store files.');
    $sourceSaved = $sourceUpload($sourceInput);
    routing_check($sourceSaved['status'] === 201, 'An administrator can register a document received from a listed agency / division.');
    $sourceReference = $sourceSaved['data']['reference_no'];
    $sourceRecord = $http('/api/documents/' . $sourceReference, 'GET', '', 'application/json', 5)['data']['data'];
    routing_check((int) $sourceRecord['source_division_id'] === $sourceId && $sourceRecord['external_sender'] === 'Sample Outside Agency'
        && $sourceRecord['origin_type'] === 'external' && $sourceRecord['source_office_id'] === null
        && (int) $sourceRecord['current_office_id'] === 4 && $sourceRecord['status'] === 'received'
        && $sourceRecord['history'][0]['action'] === 'externally_received', 'Source ID and canonical sender name are saved; incoming registration begins at Records.');
    routing_check($actors[5]->workspace(array('search' => 'Sample Outside Agency'))['pagination']['total'] === 1,
        'Documents can be searched by the saved source agency name.');
    routing_check($sourceUpload($sourceInput)['status'] === 200, 'Matching source registration retries return the original document.');
    routing_check($sourceUpload(array_merge($sourceInput, array('source_division_id' => $sameNameSourceId)))['status'] === 409,
        'Changing source identity is detected even when two divisions have the same name.');
    $db->exec("UPDATE document_sources SET name = 'Updated source name' WHERE id = " . $sourceId);
    routing_check($http('/api/documents/' . $sourceReference, 'GET', '', 'application/json', 5)['data']['data']['external_sender'] === 'Sample Outside Agency',
        'Historic document source names remain unchanged after a catalog rename.');
    routing_check($sourceUpload($sourceInput)['status'] === 200, 'A source catalog rename does not invalidate an otherwise identical retry.');

    $officeSourceInput = array_merge($sourceInput, array('origin_type' => 'office', 'submission_key' => 'http-office-source-owner-0001'));
    $officeSourceSaved = $sourceUpload($officeSourceInput, 1);
    routing_check($officeSourceSaved['status'] === 201, 'An ordinary office can select the document owner from the source catalog.');
    $officeSourceReference = $officeSourceSaved['data']['reference_no'];
    $officeSourceRecord = $http('/api/documents/' . $officeSourceReference)['data']['data'];
    routing_check((int) $officeSourceRecord['source_division_id'] === $sourceId && $officeSourceRecord['external_sender'] === 'Updated source name'
        && (int) $officeSourceRecord['source_office_id'] === 1 && (int) $officeSourceRecord['current_office_id'] === 1
        && $officeSourceRecord['status'] === 'created' && $officeSourceRecord['permissions']['upload_file'],
        'The source owner and name snapshot are saved independently of the registering and holding office.');
    routing_check($sourceUpload($officeSourceInput, 1)['status'] === 200, 'Office registration with a catalog source remains safely retryable.');
    routing_check($sourceUpload(array_merge($officeSourceInput, array('source_division_id' => $sameNameSourceId)), 1)['status'] === 409,
        'Changing the source identity in an office registration cannot overwrite its original details.');
    $db->exec('UPDATE document_sources SET is_active = 0 WHERE id = ' . $sourceId);
    routing_check($sourceUpload(array_merge($officeSourceInput, array('submission_key' => 'http-office-inactive-source-0001')), 1)['status'] === 422,
        'Office registrations cannot use an inactive source owner.');
    $db->exec('UPDATE document_sources SET is_active = 1 WHERE id = ' . $sourceId);
    $db->exec("UPDATE document_sources SET name = 'Another updated owner name' WHERE id = " . $sourceId);
    routing_check($http('/api/documents/' . $officeSourceReference)['data']['data']['external_sender'] === 'Updated source name'
        && $sourceUpload($officeSourceInput, 1)['status'] === 200, 'Saved owner names remain stable and matching office retries survive catalog renames.');

    $optionalFilesBefore = count(glob($uploadDirectory . '/*'));
    $optionalInput = array('document_type_id' => $memoType, 'submission_key' => 'http-optional-file-0001');
    $optionalBody = $multipart('Document awaiting signatures', false, 'application/pdf', $optionalInput);
    $optionalSaved = $http('/api/doc_router/documents', 'POST', $optionalBody, 'multipart/form-data; boundary=padFixtureBoundary', 1, 'sksu_officials');
    routing_check($optionalSaved['status'] === 201, 'A Router can register without attaching a file.');
    $optionalReference = $optionalSaved['data']['reference_no'];
    $optionalRecord = $http('/api/documents/' . $optionalReference)['data']['data'];
    routing_check($optionalRecord['file_name'] === null && $optionalRecord['stored_file_name'] === null
        && $optionalRecord['file_mime'] === null && $optionalRecord['file_size'] === null
        && count(glob($uploadDirectory . '/*')) === $optionalFilesBefore, 'Registration without a file stores empty metadata and creates no file.');
    routing_check($http('/api/documents/' . $optionalReference . '/download')['status'] === 404, 'Documents without a file report a clear missing-attachment response.');
    routing_check($http('/api/documents', 'POST', $optionalBody, 'multipart/form-data; boundary=padFixtureBoundary')['data']['reference_no'] === $optionalReference,
        'A registration retry without a file returns the same official number.');
    $emptyFileBody = str_replace("--padFixtureBoundary--\r\n", "--padFixtureBoundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"\"\r\nContent-Type: application/octet-stream\r\n\r\n\r\n--padFixtureBoundary--\r\n", $optionalBody);
    routing_check($http('/api/documents', 'POST', $emptyFileBody, 'multipart/form-data; boundary=padFixtureBoundary')['status'] === 200,
        'A browser file input left empty behaves like an omitted file.');
    routing_check($http('/api/documents', 'POST', $multipart('Document awaiting signatures', null, 'application/pdf', $optionalInput), 'multipart/form-data; boundary=padFixtureBoundary')['status'] === 409,
        'A later file cannot silently alter the original registration retry.');
    $optionalTravel = $http('/api/documents', 'POST', $multipart('Travel awaiting signatures', false, 'application/pdf', array_merge($travelInput,
        array('submission_key' => 'http-optional-travel-0001'))), 'multipart/form-data; boundary=padFixtureBoundary');
    routing_check($optionalTravel['status'] === 201 && count($http('/api/documents/' . $optionalTravel['data']['reference_no'])['data']['data']['personnel']) === 2,
        'Travel Order keeps its required travel details and multiple travelers while its file remains optional.');
    $deleteHttp = $http('/api/doc_router/documents', 'POST', $multipart('Unsent travel entry to delete', null, 'application/pdf', array_merge($travelInput,
        array('submission_key' => 'http-deletion-travel-0001'))), 'multipart/form-data; boundary=padFixtureBoundary', 1, 'sksu_officials');
    $deleteReference = $deleteHttp['data']['reference_no'];
    $deleteRecord = $one->record($deleteReference);
    $deleteId = (int) $deleteRecord['id'];
    $deletePath = $uploadDirectory . '/' . $deleteRecord['stored_file_name'];
    routing_check($deleteHttp['status'] === 201 && is_file($deletePath), 'Unsent travel fixture includes a real attachment.');
    routing_check($http('/api/documents/' . $deleteReference, 'DELETE', '', 'application/json', 3)['status'] === 403, 'Ordinary DELETE rejects an uninvolved office.');
    $seriesBeforeDelete = (int) $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn();
    routing_check($http('/api/doc_router/documents/' . $deleteReference, 'DELETE', '', 'application/json', 1, 'sksu_officials')['status'] === 200, 'Role-scoped DELETE removes an unsent own-office registration.');
    clearstatcache(true, $deletePath);
    routing_check(!is_file($deletePath) && $http('/api/documents/' . $deleteReference)['status'] === 404, 'Successful deletion removes the attachment and document.');
    foreach (array('document_routes', 'document_people', 'document_travel_details') as $table) {
        routing_check((int) $db->query('SELECT COUNT(*) FROM ' . $table . ' WHERE document_id=' . $deleteId)->fetchColumn() === 0, 'Deletion removes dependent ' . $table . ' rows.');
    }
    routing_check((int) $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn() === $seriesBeforeDelete, 'HTTP deletion does not recycle annual document codes.');
    $ordinaryDelete = $http('/api/documents', 'POST', $multipart('Another unsent entry', false, 'application/pdf', array_merge($optionalInput,
        array('submission_key' => 'http-ordinary-deletion-0001'))), 'multipart/form-data; boundary=padFixtureBoundary');
    routing_check($http('/api/documents/' . $ordinaryDelete['data']['reference_no'], 'DELETE')['status'] === 200, 'Ordinary DELETE shares the same unsent-document rules.');
    $numberAfterOptional = (int) $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn();
    routing_check($http('/api/documents/' . $optionalReference . '/route', 'POST', '{"to_office_id":2}')['status'] === 200, 'An office can route a document before uploading its file.');
    $uploadPath = '/api/doc_router/documents/' . $optionalReference . '/file';
    $uploadFile = function ($contents = null, $actor = 1) use ($http, $multipart, $uploadPath) {
        return $http($uploadPath, 'POST', $multipart('Ignored registration fields', $contents), 'multipart/form-data; boundary=padFixtureBoundary', $actor, 'sksu_officials');
    };
    routing_check($uploadFile(null, 2)['status'] === 403, 'A pending destination must receive before it can upload the file.');
    routing_check($uploadFile(null, 3)['status'] === 403, 'An unrelated office cannot upload a document file.');
    routing_check($http('/api/documents/' . $optionalReference . '/file', 'POST', $multipart('', null), 'multipart/form-data; boundary=padFixtureBoundary', 5)['status'] === 403,
        'Administrator monitoring alone does not allow uploading for an office.');
    routing_check($uploadFile(false)['status'] === 422 && $uploadFile('Forged PDF content')['status'] === 422,
        'A later upload requires a real allowed file even though registration attachments are optional.');
    routing_check($uploadFile($pdf . str_repeat(' ', 10485760))['status'] === 422, 'A later upload enforces the configured file size limit.');
    routing_check(count(glob($uploadDirectory . '/*')) === $optionalFilesBefore, 'Failed or unauthorized uploads leave storage unchanged.');
    routing_check($uploadFile()['status'] === 200, 'The originating office can attach a file while signatures are in progress.');
    $attached = $http('/api/documents/' . $optionalReference)['data']['data'];
    routing_check($attached['status'] === 'in_transit' && (int) $attached['pending_office_id'] === 2
        && end($attached['history'])['action'] === 'file_uploaded' && end($attached['history'])['acted_by_name'] === 'Sender',
        'A file upload preserves routing status and records its actor in the timeline.');
    $oldPath = $uploadDirectory . '/' . $attached['stored_file_name'];
    routing_check($http('/api/documents/' . $optionalReference . '/download')['body'] === $pdf, 'A file uploaded later becomes downloadable.');
    $http('/api/documents/' . $optionalReference . '/receive', 'POST', '{}', 'application/json', 2);
    $http('/api/documents/' . $optionalReference . '/route', 'POST', '{"to_office_id":3}', 'application/json', 2);
    $http('/api/documents/' . $optionalReference . '/receive', 'POST', '{}', 'application/json', 3);
    $http('/api/documents/' . $optionalReference . '/complete', 'POST', '{}', 'application/json', 3);
    routing_check($uploadFile(null, 2)['status'] === 403, 'An earlier participating office cannot replace the file after forwarding it.');
    $signedPdf = $pdf . "% Signed copy\n";
    routing_check($uploadFile($signedPdf, 3)['status'] === 200, 'The holding office can upload the signed copy after completion.');
    $signed = $http('/api/documents/' . $optionalReference)['data']['data'];
    routing_check($signed['status'] === 'completed' && $signed['stored_file_name'] !== $attached['stored_file_name']
        && !is_file($oldPath) && $http('/api/documents/' . $optionalReference . '/download')['body'] === $signedPdf,
        'A successful replacement updates the download and retains completed status.');
    $signedPath = $uploadDirectory . '/' . $signed['stored_file_name'];
    $beforeReplacementFailure = count($signed['history']);
    $db->exec("CREATE TRIGGER reject_test_attachment BEFORE INSERT ON document_routes FOR EACH ROW
        BEGIN IF NEW.action = 'file_uploaded' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Intentional upload history failure'; END IF; END");
    routing_check($uploadFile($pdf . "% Failed replacement\n")['status'] === 500, 'A failed upload history save fails the replacement.');
    $db->exec('DROP TRIGGER reject_test_attachment');
    $afterFailure = $http('/api/documents/' . $optionalReference)['data']['data'];
    routing_check($afterFailure['stored_file_name'] === $signed['stored_file_name'] && is_file($signedPath)
        && count($afterFailure['history']) === $beforeReplacementFailure && count(glob($uploadDirectory . '/*')) === $optionalFilesBefore + 1,
        'Failed replacement preserves the previous file and metadata, rolls back history, and removes the staged file.');
    routing_check($uploadFile($pdf . "% Final originating-office copy\n")['status'] === 200, 'The originating office can update a completed document too.');
    routing_check((int) $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn() === $numberAfterOptional,
        'Later uploads and replacements never allocate another official number.');
    routing_check($http('/api/documents', 'POST', $optionalBody, 'multipart/form-data; boundary=padFixtureBoundary')['status'] === 200,
        'The original file-free registration remains safely retryable after later uploads.');

    $db->exec("UPDATE users SET role = 'admin', roles = 'admin,sksu_officials' WHERE id = 1");
    routing_check($http('/api/doc_router/routing/workspace')['status'] === 403, 'A multi-role identity must choose a role before opening doc_router.');
    routing_check($http('/api/admin/offices', 'GET', '', 'application/json', 1, 'sksu_officials')['status'] === 403, 'The HTTP API denies administration while Router is selected.');
    routing_check($http('/api/doc_router/routing/workspace', 'GET', '', 'application/json', 1, 'admin')['status'] === 403, 'Administrator mode cannot bypass the doc_router role gate.');
    $routerWorkspace = $http('/api/doc_router/routing/workspace', 'GET', '', 'application/json', 1, 'sksu_officials');
    routing_check($routerWorkspace['status'] === 200 && !$routerWorkspace['data']['can_monitor'] && $routerWorkspace['data']['office']['name'] === 'Office One', 'A dual-role Router uses its office scope without admin monitoring.');
    routing_check($http('/api/doc_router/routing/registration-options', 'GET', '', 'application/json', 1, 'sksu_officials')['status'] === 200,
        'The Router module can load registration options through its role-scoped endpoint.');
    routing_check($http('/api/doc_router/routing/workspace?office_id=all', 'GET', '', 'application/json', 1, 'sksu_officials')['status'] === 403, 'Router HTTP requests cannot switch to all-office monitoring.');
    routing_check($http('/api/doc_router/documents/' . $externalReference, 'GET', '', 'application/json', 1, 'sksu_officials')['status'] === 403, 'An administrator using Router cannot read an unrelated office document.');
    routing_check($http('/api/doc_router/documents', 'POST', $multipart(), 'multipart/form-data; boundary=padFixtureBoundary', 1, 'sksu_officials')['data']['reference_no'] === $httpReference, 'doc_router registration uses the same retry key and official number.');
    routing_check($http('/api/doc_router/documents/' . $httpReference . '/download', 'GET', '', 'application/json', 1, 'sksu_officials')['body'] === $pdf, 'doc_router downloads use the original shared attachment.');
    routing_check($http('/api/doc_router/documents/' . $httpReference . '/route', 'POST', '{"to_office_id":1}', 'application/json', 2, 'sksu_officials')['status'] === 200, 'doc_router can send from the current office.');
    routing_check($http('/api/doc_router/documents/' . $httpReference . '/receive', 'POST', '{}', 'application/json', 1, 'sksu_officials')['status'] === 200, 'doc_router can confirm destination receipt.');
    routing_check($http('/api/admin/offices', 'GET', '', 'application/json', 1, 'admin')['status'] === 200, 'The same Google token can select the Administrator module.');
    $db->exec("UPDATE users SET roles = 'sksu_officials' WHERE id = 1");
    routing_check($http('/api/admin/offices', 'GET', '', 'application/json', 1, 'admin')['status'] === 403, 'Removing Administrator revokes the selected mode for an existing token.');

    // File-code administration uses the same annual lock and counter as registration.
    $year = DocumentFileCodes::currentYear();
    $codes = new DocumentFileCodes($db);
    routing_check(DocumentFileCodes::format(2026, 6549) === '26-06549', 'The requested file-code example is formatted exactly.');
    $counterBeforeRead = $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn();
    $settings = $http('/api/admin/file-codes', 'GET', '', 'application/json', 5, 'admin');
    routing_check($settings['status'] === 200 && $settings['data']['data']['current_year'] === $year, 'Administrators can read the current Manila-year file-code settings.');
    routing_check($counterBeforeRead === $db->query('SELECT SUM(last_number) FROM document_number_series')->fetchColumn(), 'Reading settings does not allocate or reserve a number.');
    routing_check($http('/api/admin/file-codes', 'GET', '', 'application/json', 2, 'sksu_officials')['status'] === 403, 'Office staff cannot read file-code administration.');
    $edit = array('year' => $year, 'next_number' => 6549, 'expected_next_number' => $settings['data']['data']['selected']['next_number']);
    routing_check($http('/api/admin/file-codes', 'PATCH', json_encode($edit), 'application/json', 2, 'sksu_officials')['status'] === 403, 'Office staff cannot change the file-code counter.');
    $savedSettings = $http('/api/admin/file-codes', 'PATCH', json_encode($edit), 'application/json', 5, 'admin');
    routing_check($savedSettings['status'] === 200 && $savedSettings['data']['data']['selected']['preview'] === DocumentFileCodes::format($year, 6549), 'Administrator edits persist the requested next file code.');
    $adjusted = save_fixture($one, fixture_registration('file-code-adjusted-0001'));
    routing_check($adjusted['reference_no'] === DocumentFileCodes::format($year, 6549), 'The next real registration uses the administrator-configured number.');
    routing_check($one->record($reference)['reference_no'] === $reference, 'Editing the counter preserves existing document references.');
    routing_check($http('/api/admin/file-codes', 'PATCH', json_encode($edit), 'application/json', 5, 'admin')['status'] === 409, 'An edit based on a stale number cannot overwrite a concurrent registration.');
    $edit['expected_next_number'] = 6550; $edit['next_number'] = 1;
    routing_check($http('/api/admin/file-codes', 'PATCH', json_encode($edit), 'application/json', 5, 'admin')['status'] === 422, 'Previously issued and reserved numbers cannot be reused.');
    foreach (array(0, -1, 1.5, true, array(), 4294967296) as $invalidNext) {
        $edit['next_number'] = $invalidNext;
        routing_check($http('/api/admin/file-codes', 'PATCH', json_encode($edit), 'application/json', 5, 'admin')['status'] === 422, 'Invalid next-number input is rejected.');
    }
    routing_check($http('/api/admin/file-codes?year=bad', 'GET', '', 'application/json', 5, 'admin')['status'] === 422, 'Invalid series years are rejected.');
    $legacy = $db->prepare("UPDATE documents SET reference_no = ?, number_year = NULL, number_sequence = NULL WHERE submission_key = 'pagination-fixture-0'");
    $legacy->execute(array(substr((string) $year, -2) . '-06570_1'));
    routing_check($codes->settings()['selected']['next_number'] === 6571, 'Imported revision codes without number metadata reserve their numeric portion.');
    $afterImport = save_fixture($one, fixture_registration('file-code-after-import'));
    routing_check($afterImport['reference_no'] === DocumentFileCodes::format($year, 6571), 'Registration continues beyond imported file codes instead of colliding or restarting.');
    $nextYear = $year + 1;
    $future = $codes->save(array('year' => $nextYear, 'next_number' => 100, 'expected_next_number' => 1));
    routing_check($future['selected']['preview'] === DocumentFileCodes::format($nextYear, 100)
        && $codes->settings()['selected']['next_number'] === 6572, 'Preparing a future annual series leaves the current year unchanged.');
    $codes->save(array('year' => $year, 'next_number' => DocumentFileCodes::MAX_NUMBER, 'expected_next_number' => 6572));
    routing_check(save_fixture($one, fixture_registration('file-code-last-number'))['reference_no'] === DocumentFileCodes::format($year, DocumentFileCodes::MAX_NUMBER), 'Large sequence numbers grow beyond the minimum five-digit padding without truncation.');
    routing_check($codes->settings()['selected']['next_number'] === null, 'An exhausted counter has no next-number preview.');
    routing_rejects(function () use ($one) { save_fixture($one, fixture_registration('file-code-exhausted')); }, 409, 'Exhausted annual counters reject registration without wrapping around.');

    echo 'Passed ' . $checks . ' office routing checks, including 12 concurrent saves, using isolated sample data.' . PHP_EOL;
} finally {
    if (is_resource($httpServer)) { proc_terminate($httpServer); proc_close($httpServer); }
    if (is_dir($uploadDirectory)) { foreach (glob($uploadDirectory . '/*') as $file) if (is_file($file)) unlink($file); rmdir($uploadDirectory); }
    if (is_file($serverLog)) unlink($serverLog);
    foreach ($workers as $worker) if ($worker) { proc_terminate($worker[0]); foreach ($worker[1] as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($worker[0]); }
    // This exact name was generated and created above; it cannot name a live database.
    if (preg_match('/^pad_routing_test_[a-f0-9]{12}$/', $testDatabase)) $management->exec('DROP DATABASE `' . $testDatabase . '`');
    putenv('DB_DATABASE=' . $sourceDatabase);
}
