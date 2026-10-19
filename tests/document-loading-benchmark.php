<?php
// Isolated, repeatable workspace benchmark. Never writes to the application's database.
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/DocumentRouting.php';
require dirname(__DIR__) . '/src/PadReviewSchema.php';
require dirname(__DIR__) . '/src/DocumentPerformanceSchema.php';

$options = getopt('', array('documents:', 'history:', 'profile', 'baseline-file:', 'indexes', 'without-indexes'));
if (isset($options['indexes'], $options['without-indexes'])) throw new RuntimeException('Choose --indexes or --without-indexes.');
$documentCount = isset($options['documents']) ? (int) $options['documents'] : 1000;
$historyCount = isset($options['history']) ? (int) $options['history'] : 0;
if ($documentCount < 100 || $documentCount > 20000 || $documentCount % 5 !== 0 || $historyCount < 0 || $historyCount > 100) {
    throw new RuntimeException('Use 100–20000 documents (a multiple of 5) and 0–100 history events per document.');
}
$routingClass = 'DocumentRouting';
if (!empty($options['baseline-file'])) {
    // Developer comparison against an explicitly supplied local snapshot.
    require $options['baseline-file'];
    $routingClass = 'DocumentRoutingBaseline';
}

class LoadingBenchmarkStatement extends PDOStatement
{
    public static $queries = array();
    public function execute(?array $params = null): bool
    {
        $start = microtime(true);
        $result = parent::execute($params);
        self::$queries[] = array($this->queryString, $params, round((microtime(true) - $start) * 1000, 1));
        return $result;
    }
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
    // Remove only performance indexes in this generated fixture when measuring
    // the earlier plan after the source installation has already been upgraded.
    if (isset($options['without-indexes'])) foreach (document_performance_indexes() as $table => $definitions) {
        $names = array_column($db->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(), 'Key_name');
        foreach ($definitions as $name => $columns) if (in_array($name, $names, true)) $db->exec('ALTER TABLE `' . $table . '` DROP INDEX `' . $name . '`');
    }
    if (isset($options['indexes'])) install_document_performance_indexes($db);
    $db->exec("INSERT INTO offices (id, code, name, created_at, updated_at) VALUES
        (2, 'RECORDS', 'Central Records', NOW(), NOW()), (80, 'PAD', 'PAD Office', NOW(), NOW()),
        (9, 'ORIGIN', 'Origin Office', NOW(), NOW()), (10, 'OTHER', 'Other Office', NOW(), NOW())");
    $db->exec("INSERT INTO users (id, name, email, roles, office_id, ovp_pad_office_id, ovp_central_records_office_id, created_at, updated_at)
        VALUES (1, 'Benchmark', 'benchmark@example.test', 'ovp_pad,ovp_central_records,sksu_officials', 9, 80, 2, NOW(), NOW())");
    $insert = $db->prepare("INSERT INTO documents (reference_no, subject, document_type, origin_type, source_office_id,
        current_office_id, pending_office_id, status, created_by_user_id, created_at, updated_at)
        VALUES (?, ?, 'Memorandum', 'office', 9, ?, ?, ?, 1, '2026-01-01 00:00:00', '2026-01-02 00:00:00')");
    $route = $db->prepare("INSERT INTO document_routes (document_id, from_office_id, to_office_id, action, actor_role,
        dispatched_at, received_at, acted_by_user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, '2026-01-01 00:00:00')");
    $review = $db->prepare("INSERT INTO document_pad_reviews (document_id, submission_route_id, pad_office_id, central_records_office_id,
        decision, decided_by_user_id, decided_at, result_route_id, created_at) VALUES (?, ?, 80, 2, 'approved', 1, '2026-01-01 05:00:00', ?, '2026-01-01 05:00:00')");
    $db->beginTransaction();
    for ($i = 1; $i <= $documentCount; $i++) {
        $kind = $i % 5;
        $insert->execute(array('BENCH-' . $i, 'Request ' . $i, $kind === 0 ? 9 : ($kind === 1 ? 2 : ($kind === 2 ? 80 : ($kind === 3 ? 2 : 10))),
            $kind === 1 ? 80 : null, $kind === 0 ? 'created' : ($kind === 1 ? 'in_transit' : 'received')));
        $id = (int) $db->lastInsertId();
        $route->execute(array($id, null, 9, 'created', 'sksu_officials', null, '2026-01-01 00:00:00'));
        // Longer, realistic ledgers make repeated MAX scans visible. These
        // historical uploads do not change custody, the latest receipt, or dispatch.
        for ($history = 0; $history < $historyCount; $history++) {
            $route->execute(array($id, 9, 9, 'file_uploaded', 'sksu_officials', null, null));
        }
        if ($kind === 0) continue;
        $route->execute(array($id, 9, 2, 'routed', 'sksu_officials', '2026-01-01 01:00:00', '2026-01-01 02:00:00'));
        $route->execute(array($id, 2, 80, 'routed', 'ovp_central_records', '2026-01-01 03:00:00', $kind === 1 ? null : '2026-01-01 04:00:00'));
        $submission = (int) $db->lastInsertId();
        if ($kind < 3) continue;
        $route->execute(array($id, 80, 2, 'returned', 'ovp_pad', '2026-01-01 05:00:00', '2026-01-01 06:00:00'));
        $review->execute(array($id, $submission, (int) $db->lastInsertId()));
        if ($kind === 4) $route->execute(array($id, 2, 10, 'acted', 'ovp_central_records', '2026-01-01 07:00:00', '2026-01-01 08:00:00'));
    }
    $db->commit();
    foreach (array('documents', 'document_routes', 'document_pad_reviews', 'offices', 'users') as $table) $db->query('ANALYZE TABLE ' . $table)->fetchAll();
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('LoadingBenchmarkStatement'));
    if (isset($options['profile'])) {
        foreach (array(
            'SELECT id FROM documents ORDER BY updated_at DESC, id DESC LIMIT 20',
            'SELECT r.id FROM document_routes r WHERE r.document_id=2 AND r.dispatched_at IS NOT NULL ORDER BY r.id DESC LIMIT 1',
            'SELECT d.id, (SELECT r.id FROM document_routes r WHERE r.document_id=d.id AND r.dispatched_at IS NOT NULL ORDER BY r.id DESC LIMIT 1) AS latest FROM documents d LIMIT 20',
            'SELECT d.id, (SELECT r.id FROM document_routes r WHERE r.document_id=d.id AND r.dispatched_at IS NOT NULL ORDER BY r.document_id DESC, r.id DESC LIMIT 1) AS latest FROM documents d LIMIT 20'
        ) as $sql) {
            echo $sql . PHP_EOL . json_encode($db->query('EXPLAIN ' . $sql)->fetchAll()) . PHP_EOL;
        }
    }
    $user = $db->query('SELECT * FROM users WHERE id = 1')->fetch();
    $scenarios = array(
        array('ovp_central_records', array('queue' => 'all')),
        array('ovp_central_records', array('queue' => 'for_action')),
        array('ovp_central_records', array('queue' => 'all', 'office_id' => 10, 'search' => 'Request')),
        array('ovp_pad', array('queue' => 'for_approval')),
        array('sksu_officials', array('queue' => 'all', 'page' => 3)),
        array('sksu_officials', array('queue' => 'incoming'))
    );
    $results = array();
    foreach ($scenarios as $scenario) {
        list($role, $input) = $scenario;
        $actor = $user; $actor['active_role'] = $role;
        $actor['office_id'] = $role === 'ovp_pad' ? 80 : ($role === 'ovp_central_records' ? 2 : 9);
        $times = array();
        for ($run = 0; $run < 4; $run++) {
            LoadingBenchmarkStatement::$queries = array();
            $routing = new $routingClass($db, $actor);
            $before = (int) $db->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
            $start = microtime(true);
            $workspace = $routing->workspace($input);
            $elapsed = (microtime(true) - $start) * 1000;
            $selects = (int) $db->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1] - $before;
            if ($run > 0) $times[] = $elapsed;
        }
        sort($times);
        $expectedTotal = $input['queue'] === 'incoming' ? 0 : ($input['queue'] === 'all' && !isset($input['office_id']) ? $documentCount : $documentCount / 5);
        if ($workspace['pagination']['total'] !== $expectedTotal || count($workspace['data']) !== min(20, $expectedTotal)) {
            throw new RuntimeException('Incorrect benchmark page or total: ' . $role . ' ' . json_encode($input));
        }
        if ($selects > 8) throw new RuntimeException('Workspace query count regressed: ' . $selects);
        $results[] = array('role' => $role, 'input' => $input, 'median_ms' => round($times[1], 1), 'selects' => $selects,
            'total' => $workspace['pagination']['total'], 'rows' => count($workspace['data']),
            'result_hash' => hash('sha256', json_encode($workspace)));
        if (in_array('--profile', $argv, true)) {
            $queries = LoadingBenchmarkStatement::$queries;
            foreach ($queries as $query) {
                echo $role . ' ' . json_encode($input) . ' ' . $query[2] . " ms\n";
                if ($query[2] < 20) continue;
                $plan = $db->prepare('EXPLAIN ' . $query[0]);
                $plan->execute($query[1]);
                echo json_encode(array_values(array_filter($plan->fetchAll(), function ($row) {
                    return $row['type'] === 'ALL' || (int) $row['rows'] > 20;
                }))) . "\n";
            }
        }
    }
    echo json_encode(array('database_version' => $db->query('SELECT VERSION()')->fetchColumn(), 'documents' => $documentCount,
        'routes' => (int) $db->query('SELECT COUNT(*) FROM document_routes')->fetchColumn(), 'extra_history_per_document' => $historyCount,
        'results' => $results), JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    // Only the random database created by this script is removed.
    $management->exec('DROP DATABASE `' . $fixtureDatabase . '`');
}
