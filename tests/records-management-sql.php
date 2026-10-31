<?php
// Execute the phpMyAdmin upgrade against a generated, disposable database only.
require dirname(__DIR__) . '/src/bootstrap.php';
$source = env_value('DB_DATABASE');
if (!preg_match('/^[A-Za-z0-9_]+$/', $source)) throw new RuntimeException('Invalid source database name.');
$db = new PDO('mysql:host=' . env_value('DB_HOST') . ';port=' . env_value('DB_PORT', '3306') . ';dbname=' . $source . ';charset=utf8mb4',
    env_value('DB_USERNAME'), env_value('DB_PASSWORD', ''), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$fixture = 'pad_sql_test_' . bin2hex(random_bytes(6));
$db->exec('CREATE DATABASE `' . $fixture . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $db->exec('USE `' . $fixture . '`');
    $db->exec('CREATE TABLE document_personnel (id INT UNSIGNED PRIMARY KEY, name VARCHAR(160), is_active TINYINT DEFAULT 1) ENGINE=InnoDB');
    $db->exec("INSERT INTO document_personnel (id, name) VALUES (1, 'Preserved fixture')");
    $sql = file_get_contents(dirname(__DIR__) . '/database/upgrade-records-management.sql');
    foreach (array(1, 2) as $pass) {
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
            if (trim($statement) === '') continue;
            $query = $db->query($statement);
            if ($query) $query->closeCursor();
        }
        $columns = $db->query('SHOW COLUMNS FROM document_personnel')->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff(array('email', 'position', 'campus'), $columns)) throw new RuntimeException('Personnel detail columns missing.');
        if ((int) $db->query('SELECT COUNT(*) FROM document_personnel')->fetchColumn() !== 1
            || $db->query('SELECT name FROM document_personnel WHERE id = 1')->fetchColumn() !== 'Preserved fixture') throw new RuntimeException('Existing data changed.');
        if ($pass === 1) $db->exec("UPDATE document_personnel SET email = 'preserved@example.test', position = 'Faculty', campus = 'Isulan' WHERE id = 1");
    }
    if ($db->query('SELECT email FROM document_personnel WHERE id = 1')->fetchColumn() !== 'preserved@example.test') throw new RuntimeException('Rerun changed contact details.');
    $columns = $db->query('SHOW COLUMNS FROM record_changes')->fetchAll(PDO::FETCH_COLUMN);
    if (array_diff(array('entity_type', 'entity_id', 'before_data', 'after_data', 'reason', 'actor_user_id', 'created_at'), $columns)) throw new RuntimeException('Audit fields missing.');
    echo 'SQL upgrade passed: missing table and columns created; second run preserved records and contact details.' . PHP_EOL;
} finally {
    $db->exec('USE `' . $source . '`');
    if (preg_match('/^pad_sql_test_[a-f0-9]{12}$/', $fixture)) $db->exec('DROP DATABASE `' . $fixture . '`');
}
