<?php

// Explicit CLI installation only. No DDL or schema inspection in API requests.
function document_performance_indexes()
{
    return array(
        'documents' => array('documents_updated_id_index' => array('updated_at', 'id')),
        'document_routes' => array('routes_document_latest_index' => array('document_id', 'id', 'dispatched_at', 'received_at', 'action', 'actor_role'))
    );
}

function install_document_performance_indexes(PDO $db, $apply = true)
{
    $plans = array();
    // Preflight every table before adding any index. The routing and PAD review
    // installers must already have supplied all required columns.
    foreach (document_performance_indexes() as $table => $definitions) {
        $columns = array_column($db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(), 'Field');
        $existing = array();
        foreach ($db->query('SHOW INDEX FROM `' . $table . '`')->fetchAll() as $index) {
            // Prefix and descending/mixed indexes are not equivalent to these
            // full ascending keys on all supported MySQL/MariaDB versions.
            $existing[$index['Key_name']][(int) $index['Seq_in_index'] - 1] = empty($index['Sub_part']) && $index['Collation'] === 'A' ? $index['Column_name'] : null;
        }
        foreach ($existing as &$parts) { ksort($parts); $parts = array_values($parts); }
        unset($parts);
        foreach ($definitions as $name => $required) {
            if (array_diff($required, $columns)) throw new RuntimeException('Run the office-routing and PAD-review setup scripts before installing document performance indexes. Missing columns in ' . $table . '.');
            $equivalent = null;
            foreach ($existing as $existingName => $parts) {
                if (array_slice($parts, 0, count($required)) === $required) { $equivalent = $existingName; break; }
            }
            if ($equivalent === null && isset($existing[$name])) throw new RuntimeException('Index ' . $name . ' exists with different columns. Review it before continuing.');
            $plans[] = array('table' => $table, 'index' => $name, 'columns' => $required, 'existing_index' => $equivalent,
                'status' => $equivalent === null ? 'missing' : 'present');
        }
    }
    if ($apply) foreach ($plans as &$plan) {
        if ($plan['status'] !== 'missing') continue;
        $db->exec('ALTER TABLE `' . $plan['table'] . '` ADD INDEX `' . $plan['index'] . '` (`' . implode('`, `', $plan['columns']) . '`), ALGORITHM=INPLACE, LOCK=NONE');
        $plan['status'] = 'added';
    }
    unset($plan);
    return $plans;
}
