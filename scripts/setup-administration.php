<?php

// Apply the additive admin migration and import the reviewed official directory.
// Run from the command line: php scripts/setup-administration.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
$db = database();
$columns = array_column($db->query('SHOW COLUMNS FROM offices')->fetchAll(), 'Type', 'Field');
foreach (array(
    'domain' => "VARCHAR(160) NOT NULL DEFAULT ''",
    'campus' => "VARCHAR(80) NOT NULL DEFAULT ''",
    'contact_email' => "VARCHAR(191) NOT NULL DEFAULT ''",
    'source_url' => 'VARCHAR(500) DEFAULT NULL',
    'source_checked_at' => 'DATE DEFAULT NULL'
) as $column => $definition) {
    if (!isset($columns[$column])) $db->exec('ALTER TABLE offices ADD COLUMN `' . $column . '` ' . $definition);
}
if (($columns['description'] ?? '') === 'varchar(255)') $db->exec('ALTER TABLE offices MODIFY description VARCHAR(1000) DEFAULT NULL');
$db->exec("CREATE TABLE IF NOT EXISTS office_officials (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    office_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    position VARCHAR(255) NOT NULL,
    email VARCHAR(191) NOT NULL DEFAULT '',
    alternate_email VARCHAR(191) NOT NULL DEFAULT '',
    KEY office_officials_office_index (office_id),
    CONSTRAINT office_officials_office_foreign FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Widen existing roster fields without changing account data or legacy roles.
$table = sql_identifier(env_value('AUTH_ACCOUNT_TABLE', 'tblaccount'), 'AUTH_ACCOUNT_TABLE');
$columns = array_column($db->query('SHOW COLUMNS FROM ' . $table)->fetchAll(), 'Type', 'Field');
foreach (array('acc_name' => 160, 'acc_position' => 255, env_value('AUTH_ACCOUNT_EMAIL_COLUMN', 'acc_email') => 191, 'campus' => 80) as $column => $size) {
    if (!isset($columns[$column])) throw new RuntimeException('Account management requires the existing PAD account schema: missing ' . $column);
    if (preg_match('/^varchar\((\d+)\)$/i', $columns[$column], $matches) && (int) $matches[1] < $size) {
        $db->exec('ALTER TABLE ' . $table . ' MODIFY ' . sql_identifier($column, 'Account column') . ' VARCHAR(' . $size . ') NOT NULL');
    }
}

$manifest = json_decode(file_get_contents(dirname(__DIR__) . '/database/sksu-key-officials.json'), true);
if (!$manifest || count($manifest['offices']) !== 78 || array_sum(array_map(function ($office) { return count($office['officials']); }, $manifest['offices'])) !== 79) {
    throw new RuntimeException('Official directory failed its completeness check.');
}
$imported = 0;
$skipped = 0;
$db->beginTransaction();
try {
    foreach ($manifest['offices'] as $office) {
        $find = $db->prepare('SELECT id, source_url FROM offices WHERE code = ?');
        $find->execute(array($office['code']));
        $existing = $find->fetch();
        // Re-running setup must preserve administrator edits and disabled offices.
        if ($existing && ($existing['source_url'] || $office['code'] !== 'RECORDS')) { $skipped++; continue; }
        if ($existing) {
            $id = $existing['id'];
            $save = $db->prepare('UPDATE offices SET domain = ?, campus = ?, contact_email = ?, source_url = ?, source_checked_at = ?, updated_at = NOW() WHERE id = ?');
            $save->execute(array($office['domain'], $office['campus'], $office['contact_email'], $manifest['source_url'], $manifest['checked_on'], $id));
        } else {
            $save = $db->prepare('INSERT INTO offices (code, name, domain, campus, contact_email, description, source_url, source_checked_at, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())');
            $save->execute(array($office['code'], $office['name'], $office['domain'], $office['campus'], $office['contact_email'], $office['description'], $manifest['source_url'], $manifest['checked_on']));
            $id = (int) $db->lastInsertId();
        }
        $save = $db->prepare('INSERT INTO office_officials (office_id, name, position, email, alternate_email) VALUES (?, ?, ?, ?, ?)');
        foreach ($office['officials'] as $official) $save->execute(array($id, $official['name'], $official['position'], $official['email'], $official['alternate_email']));
        $imported++;
    }
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}
echo 'Administration schema ready. Imported ' . $imported . ' offices; preserved ' . $skipped . ' existing offices.' . PHP_EOL;
echo 'Total routing offices: ' . $db->query('SELECT COUNT(*) FROM offices')->fetchColumn() . '; officials: ' . $db->query('SELECT COUNT(*) FROM office_officials')->fetchColumn() . PHP_EOL;
