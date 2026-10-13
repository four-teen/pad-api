<?php

// Used by the CLI installer. Schema changes never run during an API request.
function install_office_routing(PDO $db)
{
    $columns = array_column($db->query('SHOW COLUMNS FROM documents')->fetchAll(), 'Type', 'Field');
    foreach (array(
        'document_type' => "VARCHAR(120) NOT NULL DEFAULT 'General document'",
        'pending_office_id' => 'INT UNSIGNED DEFAULT NULL',
        'movement_type' => "ENUM('forward', 'return') NOT NULL DEFAULT 'forward'",
        'number_year' => 'SMALLINT UNSIGNED DEFAULT NULL',
        'number_sequence' => 'INT UNSIGNED DEFAULT NULL',
        'submission_key' => 'VARCHAR(80) DEFAULT NULL',
        'submission_hash' => 'CHAR(64) DEFAULT NULL'
    ) as $column => $definition) {
        if (!isset($columns[$column])) $db->exec('ALTER TABLE documents ADD COLUMN `' . $column . '` ' . $definition);
    }
    if (strpos($columns['status'], "'acted'") === false) {
        $db->exec("ALTER TABLE documents MODIFY status ENUM('created', 'in_transit', 'received', 'acted', 'completed', 'delivered') NOT NULL DEFAULT 'created'");
    }
    $actions = array_column($db->query('SHOW COLUMNS FROM document_routes')->fetchAll(), 'Type', 'Field');
    if (strpos($actions['action'], "'returned'") === false) {
        $db->exec("ALTER TABLE document_routes MODIFY action ENUM('created', 'externally_received', 'routed', 'received', 'returned', 'acted', 'completed', 'delivered') NOT NULL");
    }
    $indexes = array_column($db->query('SHOW INDEX FROM documents')->fetchAll(), 'Key_name');
    foreach (array(
        'documents_number_unique' => 'UNIQUE KEY documents_number_unique (number_year, number_sequence)',
        'documents_submission_unique' => 'UNIQUE KEY documents_submission_unique (created_by_user_id, submission_key)',
        'documents_pending_office_index' => 'KEY documents_pending_office_index (pending_office_id, status)'
    ) as $name => $definition) {
        if (!in_array($name, $indexes, true)) $db->exec('ALTER TABLE documents ADD ' . $definition);
    }
    $constraints = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'pending_office_id' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll();
    if (!$constraints) $db->exec('ALTER TABLE documents ADD CONSTRAINT documents_pending_office_foreign FOREIGN KEY (pending_office_id) REFERENCES offices(id) ON DELETE SET NULL');
    $indexes = array_column($db->query('SHOW INDEX FROM document_routes')->fetchAll(), 'Key_name');
    foreach (array('from', 'to') as $direction) {
        $name = 'routes_' . $direction . '_office_document_index';
        if (!in_array($name, $indexes, true)) $db->exec('ALTER TABLE document_routes ADD KEY ' . $name . ' (' . $direction . '_office_id, document_id)');
    }
    $db->exec('CREATE TABLE IF NOT EXISTS document_number_series (
        series_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
        last_number INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    // Retain existing references. Adopt an existing PAD yearly series if present.
    $db->exec("UPDATE documents SET number_year = CAST(SUBSTRING(reference_no, 5, 4) AS UNSIGNED),
        number_sequence = CAST(SUBSTRING(reference_no, 10) AS UNSIGNED)
        WHERE number_year IS NULL AND reference_no REGEXP '^PAD-[0-9]{4}-[0-9]{6,10}$'");
    $db->exec('INSERT INTO document_number_series (series_year, last_number)
        SELECT number_year, MAX(number_sequence) FROM documents WHERE number_year IS NOT NULL GROUP BY number_year
        ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))');

    // Previously an in-transit document stored its destination as the current office.
    // Recover the sender from its dispatch while retaining the destination separately.
    $db->exec("UPDATE documents d JOIN document_routes r ON r.id = (
        SELECT MAX(r2.id) FROM document_routes r2 WHERE r2.document_id = d.id AND r2.dispatched_at IS NOT NULL AND r2.received_at IS NULL
    ) SET d.pending_office_id = d.current_office_id, d.current_office_id = r.from_office_id
        WHERE d.status = 'in_transit' AND d.pending_office_id IS NULL");
}
