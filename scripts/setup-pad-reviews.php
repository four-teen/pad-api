<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/PadReviewSchema.php';

$db = database();
$snapshot = function () use ($db) {
    $result = array();
    foreach (array('users', 'offices', 'documents', 'document_routes') as $table) {
        $rows = $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
        // The only added historical field is empty; ignore it when comparing history.
        if ($table === 'document_routes') foreach ($rows as &$row) unset($row['actor_role']);
        unset($row);
        $result[$table] = hash('sha256', json_encode($rows));
    }
    return $result;
};
$before = $snapshot();
install_pad_reviews($db);
if ($before !== $snapshot()) throw new RuntimeException('Setup verification failed: existing data changed.');
echo 'PAD review schema ready. Existing accounts, offices, documents and history preserved; no historical decisions created.' . PHP_EOL;
