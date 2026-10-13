<?php

// Run from the CLI setup script; API requests read only PAD's source catalog.
function install_document_sources(PDO $db)
{
    $db->exec('CREATE TABLE IF NOT EXISTS document_sources (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        legacy_division_id INT UNSIGNED DEFAULT NULL,
        name VARCHAR(255) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY document_sources_legacy_unique (legacy_division_id),
        KEY document_sources_name_index (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $columns = array_column($db->query('SHOW COLUMNS FROM documents')->fetchAll(), 'Field');
    if (!in_array('source_division_id', $columns, true)) {
        $db->exec('ALTER TABLE documents ADD COLUMN source_division_id INT UNSIGNED DEFAULT NULL,
            ADD CONSTRAINT documents_division_foreign FOREIGN KEY (source_division_id) REFERENCES document_sources(id)');
    }
}

function import_document_sources(PDO $db, array $divisions)
{
    $db->beginTransaction();
    try {
        $save = $db->prepare('INSERT INTO document_sources (legacy_division_id, name) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name)');
        $count = 0;
        foreach ($divisions as $division) {
            $name = trim($division['division_desc']);
            if ($name === '') continue;
            if (preg_match_all('/./us', $name, $characters) === false || count($characters[0]) > 255) {
                throw new RuntimeException('A source division name is invalid or too long.');
            }
            $save->execute(array($division['divisionid'], $name));
            $count++;
        }
        $db->commit();
        return $count;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
