<?php

// CLI-only migration: a document can be routed before its file is available.
function install_optional_document_files(PDO $db)
{
    $columns = array_column($db->query('SHOW COLUMNS FROM documents')->fetchAll(), null, 'Field');
    foreach (array('file_name' => 'VARCHAR(255)', 'stored_file_name' => 'VARCHAR(255)',
        'file_mime' => 'VARCHAR(150)', 'file_size' => 'INT UNSIGNED') as $column => $type) {
        if ($columns[$column]['Null'] !== 'YES') $db->exec('ALTER TABLE documents MODIFY `' . $column . '` ' . $type . ' DEFAULT NULL');
    }
    $actions = array_column($db->query('SHOW COLUMNS FROM document_routes')->fetchAll(), 'Type', 'Field');
    if (strpos($actions['action'], "'file_uploaded'") === false) {
        $db->exec("ALTER TABLE document_routes MODIFY action ENUM('created', 'externally_received', 'routed', 'received', 'returned', 'acted', 'completed', 'delivered', 'file_uploaded') NOT NULL");
    }
}
