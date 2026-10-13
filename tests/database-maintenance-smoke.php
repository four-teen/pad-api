<?php
// Destructive operations are exercised only in a generated empty test database.
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/DatabaseMaintenance.php';
$checks = 0; $tokens = array(); $server = null;
// The second connection commits fixture changes at a specific transaction boundary.
class MaintenanceConcurrentPDO extends PDO {
    public $beforeQuery;
    #[\ReturnTypeWillChange]
    public function query($query, ...$arguments) {
        if ($query === 'SELECT DATABASE()' && $this->beforeQuery) {
            $callback = $this->beforeQuery; $this->beforeQuery = null; $callback();
        }
        return parent::query($query, ...$arguments);
    }
}
function maintenance_check($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function maintenance_rejects(callable $callback, $code, $message) {
    try { $callback(); } catch (ApiException $error) { maintenance_check($error->getCode() === $code, $message); return; }
    throw new RuntimeException($message);
}
$source = env_value('DB_DATABASE');
if (!preg_match('/^[A-Za-z0-9_]+$/', $source)) throw new RuntimeException('Invalid source database.');
$manager = new PDO('mysql:host=' . env_value('DB_HOST') . ';port=' . env_value('DB_PORT', '3306') . ';dbname=' . $source . ';charset=utf8mb4', env_value('DB_USERNAME'), env_value('DB_PASSWORD', ''), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$testDatabase = 'pad_routing_test_' . bin2hex(random_bytes(6));
$manager->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$fixtureFile = tempnam(sys_get_temp_dir(), 'pad-maintenance-upload-');
$serverLog = tempnam(sys_get_temp_dir(), 'pad-maintenance-http-');
try {
    putenv('DB_DATABASE=' . $testDatabase);
    $db = database();
    $db->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    install_database_maintenance($db); install_database_maintenance($db);
    $db->exec("INSERT INTO offices (id, code, name, is_active, created_at, updated_at) VALUES (9, 'FIXTURE', 'Sample Office', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $db->exec("INSERT INTO users (id, name, email, role, roles, office_id, ovp_pad_office_id, ovp_central_records_office_id, is_active, created_at, updated_at)
        VALUES (1, 'Administrator fixture', 'admin@example.test', 'admin', 'admin,ovp_pad,ovp_central_records,sksu_officials', 9, 2, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $db->exec("INSERT INTO office_officials (office_id, name, position) VALUES (9, 'Sample Official', 'Director')");
    $db->exec("CREATE TABLE fixture_accounts (acc_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, acc_name VARCHAR(160), acc_position VARCHAR(255), acc_email VARCHAR(191), acc_type VARCHAR(30), campus VARCHAR(80)) ENGINE=InnoDB");
    $db->exec("INSERT INTO fixture_accounts (acc_name, acc_email, acc_type) VALUES ('Administrator fixture', 'admin@example.test', 'user')");
    putenv('AUTH_ACCOUNT_TABLE=fixture_accounts'); putenv('AUTH_ACCOUNT_EMAIL_COLUMN=acc_email');
    $db->exec("INSERT INTO document_types (id, name) VALUES (1, 'Memo')");
    $db->exec("INSERT INTO document_sources (id, name) VALUES (1, 'Source fixture')");
    $db->exec("INSERT INTO document_personnel (id, name) VALUES (1, 'Person fixture')");
    $db->exec("INSERT INTO document_number_series (series_year, last_number) VALUES (2026, 42)");
    $addDocument = $db->prepare("INSERT INTO documents (reference_no, subject, document_type, document_type_id, origin_type, source_division_id, source_office_id, current_office_id, created_by_user_id, stored_file_name, created_at, updated_at)
        VALUES (?, 'Original subject', 'Memo', 1, 'office', 1, 9, 9, 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    file_put_contents($fixtureFile, 'retained fixture attachment');
    $addDocument->execute(array('PAD-2026-000042', basename($fixtureFile)));
    $documentId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO document_routes (document_id, from_office_id, to_office_id, action, acted_by_user_id, actor_role, created_at) VALUES ($documentId, 9, 9, 'created', 1, 'sksu_officials', UTC_TIMESTAMP())");
    $routeId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO document_pad_reviews (document_id, submission_route_id, pad_office_id, central_records_office_id, decision, created_at) VALUES ($documentId, $routeId, 2, 1, 'approved', UTC_TIMESTAMP())");
    $db->exec("INSERT INTO document_people (document_id, personnel_id, name_snapshot) VALUES ($documentId, 1, 'Person fixture')");
    $db->exec("INSERT INTO document_travel_details (document_id, destination, purpose, order_date, departure_date, return_date, travel_type, vehicle_requirement) VALUES ($documentId, 'Destination fixture', 'Purpose fixture', '2026-10-10', '2026-10-11', '2026-10-12', 'Official Business', 'Not Necessary')");
    $actor = $db->query('SELECT * FROM users WHERE id = 1')->fetch(); $actor['active_role'] = 'admin';
    $maintenance = new DatabaseMaintenance($db, $actor);
    $review = function ($table) use ($maintenance, &$tokens) { $value = $maintenance->preview($table); $tokens[] = $value['token']; return $value; };
    $input = function ($review) { return array('token' => $review['token'], 'confirmation' => $review['confirmation'], 'acknowledged' => true, 'reason' => 'Isolated test reset'); };
    $inventory = $maintenance->inventory();
    $tables = array_column($inventory['tables'], null, 'name');
    maintenance_check($tables['offices']['group'] === 'preparation' && $tables['documents']['group'] === 'operations' && $tables['fixture_accounts']['group'] === 'system', 'Tables are grouped by preparation, document operations, and protected system usage.');
    maintenance_check($inventory['audit_ready'] && $tables['documents']['count'] === 1 && $tables['documents']['can_clear'], 'Inventory shows exact counts and the document clear operation.');
    foreach (array('users', 'fixture_accounts', 'document_number_series', 'admin_maintenance_events') as $protected) maintenance_check(!$tables[$protected]['can_clear'], $protected . ' is protected.');
    foreach (array('document_types', 'document_sources', 'document_personnel', 'offices', 'document_routes', 'document_pad_reviews') as $blocked) maintenance_check(!$tables[$blocked]['can_clear'], $blocked . ' cannot be cleared while referenced or needed by live documents.');
    maintenance_rejects(function () use ($db, $actor) { new DatabaseMaintenance($db, array_merge($actor, array('active_role' => 'ovp_pad'))); }, 403, 'A multi-role account in PAD mode cannot use maintenance.');
    maintenance_rejects(function () use ($maintenance) { $maintenance->preview('documents; DROP TABLE users'); }, 422, 'Arbitrary SQL table input is rejected.');
    maintenance_rejects(function () use ($maintenance) { $maintenance->preview('users'); }, 422, 'Protected account targets cannot be reviewed.');
    maintenance_rejects(function () use ($maintenance) { $maintenance->preview('document_types'); }, 409, 'Parent-table references are enforced.');
    $documentReview = $review('documents');
    maintenance_check($documentReview['total_records'] === 5 && count($documentReview['counts']) === 5, 'Preview includes every related document table before any deletion.');
    maintenance_check($db->query('SELECT COUNT(*) FROM documents')->fetchColumn() == 1 && $db->query('SELECT COUNT(*) FROM admin_maintenance_events')->fetchColumn() == 0, 'Inventory and preview do not clear or log records.');
    maintenance_rejects(function () use ($maintenance, $input, $documentReview) { $data = $input($documentReview); $data['confirmation'] = 'CLEAR'; $maintenance->clear($data); }, 422, 'Exact typed confirmation is required.');
    maintenance_rejects(function () use ($maintenance, $input, $documentReview) { $data = $input($documentReview); $data['acknowledged'] = false; $maintenance->clear($data); }, 422, 'Explicit operation acknowledgement is required.');
    maintenance_rejects(function () use ($maintenance, $input, $documentReview) { $data = $input($documentReview); $data['reason'] = ''; $maintenance->clear($data); }, 422, 'An administrative reason is required.');
    maintenance_rejects(function () use ($maintenance, $input, $documentReview) { $data = $input($documentReview); $data['token'] .= 'x'; $maintenance->clear($data); }, 422, 'Tampered operation reviews are rejected.');
    $db->exec("UPDATE documents SET subject = 'Changed subject' WHERE id = $documentId");
    maintenance_rejects(function () use ($maintenance, $input, $documentReview) { $maintenance->clear($input($documentReview)); }, 409, 'Same-count row edits invalidate the earlier review.');
    $db->exec("UPDATE documents SET subject = 'Original subject' WHERE id = $documentId");
    $db->exec("UPDATE users SET roles = 'sksu_officials', role = 'router' WHERE id = 1");
    maintenance_rejects(function () use ($maintenance, $input, $documentReview) { $maintenance->clear($input($documentReview)); }, 403, 'Administrator access is rechecked at operation time.');
    $db->exec("UPDATE users SET roles = 'admin,ovp_pad,ovp_central_records,sksu_officials', role = 'admin' WHERE id = 1");
    $db->exec("CREATE TRIGGER fixture_clear_failure BEFORE DELETE ON documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fixture rollback failure'");
    try { $maintenance->clear($input($documentReview)); throw new RuntimeException('Expected rollback failure.'); } catch (PDOException $error) { maintenance_check(true, 'A database failure rolls back the operation.'); }
    foreach (array('documents', 'document_routes', 'document_pad_reviews', 'document_people', 'document_travel_details') as $table) maintenance_check($db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() == 1, $table . ' is restored by transaction rollback.');
    maintenance_check($db->query('SELECT COUNT(*) FROM admin_maintenance_events')->fetchColumn() == 0, 'Failed clears leave no success audit entry.');
    $nonce = json_decode(base64_decode(explode('.', $documentReview['token'])[0]), true)['nonce'];
    maintenance_check(!is_file(PAD_ROOT . '/storage/maintenance-backups/' . $nonce . '.json'), 'Failed clears remove only their own incomplete recovery backup.');
    $db->exec('DROP TRIGGER fixture_clear_failure');
    $result = $maintenance->clear($input($documentReview));
    foreach ($result['cleared_counts'] as $table => $count) maintenance_check($count === 1 && $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() == 0, $table . ' clears in the combined operation.');
    maintenance_check($db->query('SELECT last_number FROM document_number_series WHERE series_year = 2026')->fetchColumn() == 42, 'Document-number counters are preserved.');
    maintenance_check($db->query('SELECT COUNT(*) FROM users')->fetchColumn() == 1 && $db->query('SELECT COUNT(*) FROM fixture_accounts')->fetchColumn() == 1, 'The administrator profile and approved login are preserved.');
    maintenance_check(is_file($fixtureFile), 'Uploaded attachments are retained for recovery.');
    $backup = json_decode(file_get_contents($maintenance->backupFile($result['event_id'])), true);
    maintenance_check($backup['tables']['documents'][0]['subject'] === 'Original subject' && count($backup['tables']) === 5, 'Recovery backup contains the complete pre-clear data.');
    maintenance_check($db->query('SELECT reason FROM admin_maintenance_events')->fetchColumn() === 'Isolated test reset', 'Successful operations record their administrative reason.');
    maintenance_rejects(function () use ($maintenance, $input, $documentReview) { $maintenance->clear($input($documentReview)); }, 409, 'A successful review token cannot be replayed.');
    $db->exec('CREATE TABLE fixture_source_links (id INT PRIMARY KEY, source_id INT UNSIGNED NOT NULL, FOREIGN KEY (source_id) REFERENCES document_sources(id) ON DELETE CASCADE) ENGINE=InnoDB');
    $concurrent = new MaintenanceConcurrentPDO('mysql:host=' . env_value('DB_HOST') . ';port=' . env_value('DB_PORT', '3306') . ';dbname=' . $testDatabase . ';charset=utf8mb4', env_value('DB_USERNAME'), env_value('DB_PASSWORD', ''),
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
    $concurrentMaintenance = new DatabaseMaintenance($concurrent, $actor);
    $sourceReview = $concurrentMaintenance->preview('document_sources'); $tokens[] = $sourceReview['token'];
    $concurrent->beforeQuery = function () use ($db) { $db->exec('INSERT INTO fixture_source_links (id, source_id) VALUES (1, 1)'); };
    maintenance_rejects(function () use ($concurrentMaintenance, $input, $sourceReview) { $concurrentMaintenance->clear($input($sourceReview)); }, 409, 'Current dependency checks see references committed after the transaction read snapshot.');
    maintenance_check($db->query('SELECT COUNT(*) FROM fixture_source_links')->fetchColumn() == 1 && $db->query('SELECT COUNT(*) FROM document_sources')->fetchColumn() == 1, 'Concurrent references cannot be removed by an unreviewed cascade.');
    $db->exec('DELETE FROM fixture_source_links');
    $db->exec("UPDATE document_sources SET name = 'Intermediate source' WHERE id = 1");
    $concurrent->beforeQuery = function () use ($db) { $db->exec("UPDATE document_sources SET name = 'Source fixture' WHERE id = 1"); };
    $sourceResult = $concurrentMaintenance->clear($input($sourceReview));
    $sourceBackup = json_decode(file_get_contents($maintenance->backupFile($sourceResult['event_id'])), true);
    maintenance_check($sourceBackup['tables']['document_sources'][0]['name'] === 'Source fixture', 'Backup reads the current locked rows instead of an earlier transaction snapshot.');
    maintenance_check($db->query('SELECT COUNT(*) FROM document_sources')->fetchColumn() == 0 && $db->query('SELECT COUNT(*) FROM document_types')->fetchColumn() == 1, 'An unreferenced catalog clears independently of other tables.');
    maintenance_check($db->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn() == 1, 'Foreign-key enforcement stays enabled.');
    // Real HTTP authentication/active-role checks, using the isolated test database.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); $address = stream_socket_get_name($socket, false); fclose($socket);
    $pipes = array();
    $serverPhp = $argv[1] ?? PHP_BINARY;
    if (!is_file($serverPhp)) throw new RuntimeException('HTTP test PHP executable not found.');
    $server = proc_open(array($serverPhp, '-S', $address, __DIR__ . '/office-routing-http.php'), array(0 => array('pipe', 'r'), 1 => array('file', $serverLog, 'a'), 2 => array('file', $serverLog, 'a')), $pipes);
    if (!is_resource($server)) throw new RuntimeException('HTTP server could not start.'); fclose($pipes[0]);
    $port = (int) substr($address, strrpos($address, ':') + 1);
    for ($attempt = 0; $attempt < 50; $attempt++) { $connection = @fsockopen('127.0.0.1', $port, $errno, $error, .1); if ($connection) { fclose($connection); break; } usleep(20000); }
    $token = create_access_token($actor);
    $http = function ($path, $role = 'admin', $body = null) use ($address, $token) {
        $context = stream_context_create(array('http' => array('method' => $body === null ? 'GET' : 'POST', 'header' => 'Authorization: Bearer ' . $token . "\r\nX-PAD-Role: " . $role . "\r\nContent-Type: application/json\r\n", 'content' => $body === null ? '' : json_encode($body), 'ignore_errors' => true, 'timeout' => 5)));
        $response = file_get_contents('http://' . $address . $path, false, $context); preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
        return array('status' => (int) $status[1], 'data' => json_decode($response, true));
    };
    maintenance_check($http('/api/admin/database')['status'] === 200, 'Administrator HTTP inventory is available.');
    maintenance_check($http('/api/admin/database', 'ovp_pad')['status'] === 403 && $http('/api/admin/database', 'sksu_officials')['status'] === 403, 'Office module modes cannot read table counts.');
    maintenance_check($http('/api/admin/database/preview', 'ovp_pad', array('table' => 'document_types'))['status'] === 403, 'Office roles cannot preview clear operations.');
    $httpReview = $http('/api/admin/database/preview', 'admin', array('table' => 'document_types'));
    maintenance_check($httpReview['status'] === 200, 'HTTP preview accepts eligible table clearing.'); $tokens[] = $httpReview['data']['token'];
    $httpResult = $http('/api/admin/database/clear', 'admin', $input($httpReview['data']));
    maintenance_check($httpResult['status'] === 200 && $db->query('SELECT COUNT(*) FROM document_types')->fetchColumn() == 0, 'HTTP confirmed clear updates only the reviewed table.');
    maintenance_check($http('/api/admin/database/backups/' . $httpResult['data']['event_id'])['data']['tables']['document_types'][0]['name'] === 'Memo', 'Administrator can download its recovery backup.');
    maintenance_check($http('/api/admin/database/backups/' . $httpResult['data']['event_id'], 'sksu_officials')['status'] === 403, 'Backup download requires active administrator mode.');
    $db->exec("UPDATE users SET roles = 'sksu_officials', role = 'router' WHERE id = 1");
    maintenance_check($http('/api/admin/database')['status'] === 403, 'Revoked administrator access takes effect on existing tokens.');
    echo 'Database maintenance checks passed: ' . $checks . PHP_EOL;
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ($tokens as $token) {
        $payload = json_decode(base64_decode(explode('.', $token)[0]), true); $nonce = $payload['nonce'] ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/D', $nonce)) throw new RuntimeException('Unsafe fixture backup cleanup.');
        $path = PAD_ROOT . '/storage/maintenance-backups/' . $nonce . '.json'; if (is_file($path)) unlink($path);
    }
    if (is_file($fixtureFile)) unlink($fixtureFile); if (is_file($serverLog)) unlink($serverLog);
    if (!preg_match('/^pad_routing_test_[a-f0-9]{12}$/D', $testDatabase)) throw new RuntimeException('Unsafe fixture database cleanup.');
    $manager->exec('DROP DATABASE `' . $testDatabase . '`');
}
