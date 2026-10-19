<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/DocumentPerformanceSchema.php';

$check = in_array('--check', $argv, true);
try {
    $db = database();
    // Bound metadata-lock waiting; don't leave setup queued behind a long transaction.
    $db->exec('SET SESSION lock_wait_timeout = 10');
    foreach (install_document_performance_indexes($db, !$check) as $index) {
        echo $index['table'] . '.' . $index['index'] . ': ' . $index['status']
            . ($index['existing_index'] ? ' (' . $index['existing_index'] . ')' : '') . PHP_EOL;
    }
    echo $check ? 'Read-only index check complete.' . PHP_EOL : 'Document performance indexes ready. Document rows, history, references, and files are unchanged.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Document index setup failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
