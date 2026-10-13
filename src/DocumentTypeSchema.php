<?php

// CLI-only migrations; API requests never change the schema or read sam.pad.
function install_document_registration(PDO $db)
{
    $db->exec("CREATE TABLE IF NOT EXISTS document_types (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        legacy_type_id INT UNSIGNED DEFAULT NULL,
        name VARCHAR(120) NOT NULL,
        details_kind ENUM('personnel', 'travel_order') NOT NULL DEFAULT 'personnel',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY document_types_legacy_unique (legacy_type_id),
        UNIQUE KEY document_types_name_unique (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec('CREATE TABLE IF NOT EXISTS document_personnel (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        legacy_profile_id INT UNSIGNED DEFAULT NULL,
        name VARCHAR(160) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY document_personnel_legacy_unique (legacy_profile_id),
        KEY document_personnel_name_index (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $columns = array_column($db->query('SHOW COLUMNS FROM documents')->fetchAll(), 'Field');
    if (!in_array('document_type_id', $columns, true)) {
        $db->exec('ALTER TABLE documents ADD COLUMN document_type_id INT UNSIGNED DEFAULT NULL,
            ADD CONSTRAINT documents_type_foreign FOREIGN KEY (document_type_id) REFERENCES document_types(id)');
    }
    $db->exec('CREATE TABLE IF NOT EXISTS document_people (
        document_id INT UNSIGNED NOT NULL,
        personnel_id INT UNSIGNED NOT NULL,
        name_snapshot VARCHAR(160) NOT NULL,
        PRIMARY KEY (document_id, personnel_id),
        CONSTRAINT document_people_document_foreign FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
        CONSTRAINT document_people_personnel_foreign FOREIGN KEY (personnel_id) REFERENCES document_personnel(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec("CREATE TABLE IF NOT EXISTS document_travel_details (
        document_id INT UNSIGNED NOT NULL PRIMARY KEY,
        destination TEXT NOT NULL,
        purpose TEXT NOT NULL,
        order_date DATE NOT NULL,
        departure_date DATE NOT NULL,
        return_date DATE NOT NULL,
        travel_type ENUM('Official Time', 'Official Business') NOT NULL,
        vehicle_requirement ENUM('Yes', 'Not Necessary') NOT NULL,
        remarks TEXT DEFAULT NULL,
        CONSTRAINT document_travel_document_foreign FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function import_document_catalog(PDO $db, array $types, array $personnel)
{
    $db->beginTransaction();
    try {
        $saveType = $db->prepare('INSERT INTO document_types (legacy_type_id, name, details_kind) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name), details_kind = VALUES(details_kind)');
        foreach ($types as $type) {
            $name = trim($type['doctype_desc']);
            if ($name === '' || preg_match_all('/./us', $name, $characters) === false || count($characters[0]) > 120) {
                throw new RuntimeException('A source document type has a missing or invalid name.');
            }
            $saveType->execute(array($type['docid'], $name, strtoupper(preg_replace('/\s+/', ' ', $name)) === 'TRAVEL ORDER' ? 'travel_order' : 'personnel'));
        }
        $savePerson = $db->prepare('INSERT INTO document_personnel (legacy_profile_id, name) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name)');
        $importedPeople = 0;
        foreach ($personnel as $person) {
            $name = trim($person['acc_name']);
            if ($name === '') continue;
            if (preg_match_all('/./us', $name, $characters) === false || count($characters[0]) > 160) {
                throw new RuntimeException('A source personnel name is invalid or too long.');
            }
            $savePerson->execute(array($person['acc_id'], $name));
            $importedPeople++;
        }
        // Keep historical free-text names; link only those that match a catalog entry.
        $db->exec('UPDATE documents d JOIN document_types t ON TRIM(d.document_type) = t.name
            SET d.document_type_id = t.id WHERE d.document_type_id IS NULL');
        $db->commit();
        return array('types' => count($types), 'personnel' => $importedPeople);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
