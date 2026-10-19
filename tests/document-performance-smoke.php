<?php
// Generated MySQL database only. Live documents, accounts, and uploads are untouched.
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/DocumentRouting.php';
require dirname(__DIR__) . '/src/PadReviewSchema.php';
require dirname(__DIR__) . '/src/DocumentPerformanceSchema.php';

$checks = 0;
function performance_check($condition, $message)
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

$source = env_value('DB_DATABASE');
if (!preg_match('/^[A-Za-z0-9_]+$/D', $source)) throw new RuntimeException('Invalid source database.');
$management = new PDO('mysql:host=' . env_value('DB_HOST') . ';port=' . env_value('DB_PORT', '3306') . ';dbname=' . $source . ';charset=utf8mb4',
    env_value('DB_USERNAME'), env_value('DB_PASSWORD', ''), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$fixtureDatabase = 'pad_loading_test_' . bin2hex(random_bytes(6));
$management->exec('CREATE DATABASE `' . $fixtureDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    foreach (array('offices', 'users', 'documents', 'document_routes') as $table) {
        $management->exec('CREATE TABLE `' . $fixtureDatabase . '`.`' . $table . '` LIKE `' . $source . '`.`' . $table . '`');
    }
    putenv('DB_DATABASE=' . $fixtureDatabase);
    $db = database();
    install_pad_reviews($db);
    foreach (document_performance_indexes() as $table => $definitions) {
        $names = array_column($db->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(), 'Key_name');
        foreach ($definitions as $name => $columns) if (in_array($name, $names, true)) $db->exec('ALTER TABLE `' . $table . '` DROP INDEX `' . $name . '`');
    }
    $db->exec("INSERT INTO offices (id, code, name, created_at, updated_at) VALUES
        (2, 'RECORDS', 'Records', NOW(), NOW()), (80, 'PAD', 'PAD', NOW(), NOW()),
        (9, 'OFFICE', 'Origin', NOW(), NOW()), (10, 'OTHER', 'Other', NOW(), NOW())");
    $db->exec("INSERT INTO users (id, name, email, roles, office_id, ovp_pad_office_id, ovp_central_records_office_id, created_at, updated_at)
        VALUES (1, 'Fixture', 'fixture@example.test', 'admin,ovp_pad,ovp_central_records,sksu_officials', 9, 80, 2, NOW(), NOW())");
    $insert = $db->prepare("INSERT INTO documents (id, reference_no, subject, origin_type, source_office_id,
        current_office_id, status, created_by_user_id, created_at, updated_at)
        VALUES (?, ?, 'Query fixture', 'office', 9, 2, 'received', 1, '2026-01-01 00:00:00', '2026-01-02 00:00:00')");
    $event = $db->prepare("INSERT INTO document_routes (document_id, from_office_id, to_office_id, action, actor_role,
        dispatched_at, received_at, acted_by_user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)");
    $review = $db->prepare("INSERT INTO document_pad_reviews (document_id, submission_route_id, pad_office_id,
        central_records_office_id, decision, result_route_id, created_at) VALUES (?, ?, 80, 2, ?, ?, NOW())");
    $db->beginTransaction();
    $lastDispatch = array();
    for ($i = 1; $i <= 100; $i++) {
        $kind = $i % 10;
        $insert->execute(array($i, 'PERF-' . $i));
        $event->execute(array($i, null, 9, 'created', 'sksu_officials', null, '2026-01-01 00:00:00', '2026-01-01 00:00:00'));
        for ($n = 0; $n < 30; $n++) $event->execute(array($i, 9, 9, 'file_uploaded', null, null, null, '2026-01-01 01:00:00'));
        if ($kind === 0) {
            $db->exec("UPDATE documents SET current_office_id=9, status='created' WHERE id=$i");
            continue;
        }
        $event->execute(array($i, 2, 80, 'routed', 'ovp_central_records', '2026-01-01 02:00:00', '2026-01-01 03:00:00', '2026-01-01 02:00:00'));
        $submission = (int) $db->lastInsertId();
        if ($kind === 1) {
            $db->exec('UPDATE documents SET current_office_id=80 WHERE id=' . $i);
            continue;
        }
        $event->execute(array($i, $kind === 7 ? 10 : 80, 2, 'returned', in_array($kind, array(6, 7), true) ? null : 'ovp_pad',
            '2026-01-01 04:00:00', '2026-01-01 05:00:00', '2026-01-01 04:00:00'));
        $lastDispatch[$i] = (int) $db->lastInsertId();
        if (in_array($kind, array(2, 3, 4, 5), true)) {
            $review->execute(array($i, $submission, 'approved', $kind === 3 ? null : $lastDispatch[$i]));
        }
        if ($kind === 2) $db->exec("UPDATE documents SET pending_office_id=2, current_office_id=80, status='in_transit' WHERE id=$i");
        if ($kind === 5) {
            $event->execute(array($i, 2, 80, 'routed', 'ovp_central_records', '2026-01-01 06:00:00', null, '2026-01-01 06:00:00'));
            $review->execute(array($i, (int) $db->lastInsertId(), null, null));
        }
        if ($kind === 8) $db->exec("UPDATE documents SET movement_type='return' WHERE id=$i");
        if ($kind === 9) {
            $event->execute(array($i, 2, 9, 'acted', 'ovp_central_records', '2026-01-01 06:00:00', '2026-01-01 07:00:00', '2026-01-01 06:00:00'));
            $event->execute(array($i, 9, 10, 'routed', 'sksu_officials', '2026-01-01 08:00:00', null, '2026-01-01 08:00:00'));
            $db->exec("UPDATE documents SET pending_office_id=10, current_office_id=9, status='in_transit' WHERE id=$i");
        }
        // Higher IDs can be backdated and can contain no handoff at all.
        $event->execute(array($i, 2, 2, 'file_uploaded', null, null, null, '2025-01-01 00:00:00'));
    }
    $db->commit();
    $snapshot = function () use ($db) {
        $data = array();
        foreach (array('documents', 'document_routes', 'document_pad_reviews') as $table) $data[$table] = $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
        return hash('sha256', json_encode($data));
    };
    $before = $snapshot();
    performance_check(array_column(install_document_performance_indexes($db, false), 'status') === array('missing', 'missing'), 'Read-only check finds both missing indexes.');
    performance_check(array_column(install_document_performance_indexes($db, false), 'status') === array('missing', 'missing'), 'Read-only check does not create indexes.');
    performance_check(array_column(install_document_performance_indexes($db), 'status') === array('added', 'added'), 'Online setup adds the required indexes.');
    performance_check(array_column(install_document_performance_indexes($db), 'status') === array('present', 'present'), 'Repeated installation does not duplicate indexes.');
    performance_check($snapshot() === $before, 'Index installation preserves every document, route and review field.');
    foreach (array('documents', 'document_routes', 'document_pad_reviews') as $table) $db->query('ANALYZE TABLE ' . $table)->fetchAll();
    $plan = $db->query('EXPLAIN SELECT id FROM documents ORDER BY updated_at DESC, id DESC LIMIT 20')->fetch();
    performance_check($plan['key'] === 'documents_updated_id_index' && strpos($plan['Extra'], 'filesort') === false, 'Timeline page uses the ordering index without a filesort.');
    $plans = $db->query('EXPLAIN SELECT d.id, (SELECT r.id FROM document_routes r WHERE r.document_id=d.id AND r.dispatched_at IS NOT NULL
        ORDER BY r.document_id DESC, r.id DESC LIMIT 1) AS latest FROM documents d LIMIT 20')->fetchAll();
    $routePlan = array_values(array_filter($plans, function ($row) { return $row['table'] === 'r'; }))[0];
    performance_check($routePlan['key'] === 'routes_document_latest_index' && strpos($routePlan['Extra'], 'filesort') === false
        && strpos($routePlan['Extra'], 'Using index') !== false, 'Correlated latest route uses the covering index without sorting on MySQL 5.7.');

    $user = $db->query('SELECT * FROM users WHERE id=1')->fetch();
    $condition = new ReflectionMethod(DocumentRouting::class, 'queueCondition'); $condition->setAccessible(true);
    foreach (array('ovp_central_records' => 2, 'ovp_pad' => 80, 'sksu_officials' => 9) as $role => $office) {
        $actor = $user; $actor['active_role'] = $role; $actor['office_id'] = $office;
        $routing = new DocumentRouting($db, $actor);
        foreach (array($office, 10, null) as $scope) foreach (array('my_documents', 'incoming', 'received', 'outgoing', 'returned', 'acted', 'delivered', 'all', 'attention', 'for_approval', 'for_action', 'pad_results') as $queue) {
            $ids = array();
            foreach (array(false, true) as $joined) {
                $args = array();
                $predicate = $condition->invokeArgs($routing, array($queue, $scope, &$args, $joined));
                $sql = 'SELECT d.id FROM documents d';
                if ($joined) $sql .= ' LEFT JOIN document_routes movement ON movement.id =
                    (SELECT r.id FROM document_routes r WHERE r.document_id=d.id AND r.dispatched_at IS NOT NULL ORDER BY r.document_id DESC, r.id DESC LIMIT 1)
                    LEFT JOIN document_pad_reviews review ON review.id = (SELECT MAX(p.id) FROM document_pad_reviews p WHERE p.document_id=d.id)';
                $query = $db->prepare($sql . ' WHERE (' . $predicate . ') ORDER BY d.id');
                $query->execute($args);
                $ids[] = $query->fetchAll(PDO::FETCH_COLUMN);
            }
            performance_check($ids[0] === $ids[1], 'Joined counts and list predicates agree for ' . $role . '/' . $queue . '/' . ($scope ?: 'all') . '.');
        }
    }
    $actor = $user; $actor['active_role'] = 'ovp_central_records'; $actor['office_id'] = 2;
    $routing = new DocumentRouting($db, $actor);
    $received = $routing->workspace(array('queue' => 'received', 'search' => 'PERF-3', 'status' => 'received'));
    performance_check(in_array('PERF-3', array_column($received['data'], 'reference_no'), true), 'A decided legacy review with no result route remains ordinary Received rather than disappearing through SQL NULL logic.');
    performance_check((int) $routing->record('PERF-4')['last_movement_id'] === $lastDispatch[4], 'Later backdated file uploads do not replace the latest dispatch.');
    $db->exec('ALTER TABLE document_routes RENAME INDEX routes_document_latest_index TO fixture_equivalent_latest_index');
    $plans = install_document_performance_indexes($db);
    performance_check(array_column($plans, 'status') === array('present', 'present') && $plans[1]['existing_index'] === 'fixture_equivalent_latest_index', 'An equivalent existing index is reused even under another name.');
    performance_check($snapshot() === $before, 'Query checks do not change documents or history.');
    echo 'Document performance checks passed: ' . $checks . PHP_EOL;
} finally {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if (!preg_match('/^pad_loading_test_[a-f0-9]{12}$/D', $fixtureDatabase)) throw new RuntimeException('Unsafe fixture cleanup.');
    $management->exec('DROP DATABASE `' . $fixtureDatabase . '`');
}
