<?php

function install_account_roles(PDO $db)
{
    $columns = array_column($db->query('SHOW COLUMNS FROM users')->fetchAll(), 'Type', 'Field');
    if (strpos($columns['role'], "'router'") === false) {
        $db->exec("ALTER TABLE users MODIFY role ENUM('staff', 'records', 'pad', 'admin', 'router') NOT NULL DEFAULT 'router'");
    }
    if (!isset($columns['roles'])) {
        $db->exec("ALTER TABLE users ADD roles SET('admin', 'router', 'ovp_pad', 'ovp_central_records', 'sksu_officials') DEFAULT NULL AFTER role");
    } else {
        // Add the module choices while retaining existing Router memberships.
        $db->exec("ALTER TABLE users MODIFY roles SET('admin', 'router', 'ovp_pad', 'ovp_central_records', 'sksu_officials') DEFAULT NULL");
    }
    // Keep existing assignments intact; account_roles() resolves legacy Router access from its office.
    $db->exec("UPDATE users SET roles = IF(role = 'admin', 'admin', 'router') WHERE roles IS NULL");
    $db->exec("ALTER TABLE users MODIFY roles SET('admin', 'router', 'ovp_pad', 'ovp_central_records', 'sksu_officials') NOT NULL DEFAULT 'router'");

    foreach (array('ovp_pad_office_id', 'ovp_central_records_office_id') as $column) {
        if (!isset($columns[$column])) $db->exec('ALTER TABLE users ADD ' . $column . ' INT UNSIGNED DEFAULT NULL');
    }
    // Preserve the office previously used by each existing OVP role. Future edits select an explicit office.
    $defaults = module_office_ids();
    $update = $db->prepare('UPDATE users SET ovp_pad_office_id = ?, ovp_central_records_office_id = ? WHERE id = ?');
    foreach ($db->query('SELECT id, role, roles, office_id, ovp_pad_office_id, ovp_central_records_office_id FROM users')->fetchAll() as $user) {
        $roles = account_roles($user);
        $pad = $user['ovp_pad_office_id'];
        $records = $user['ovp_central_records_office_id'];
        if ($pad === null && in_array('ovp_pad', $roles, true)) $pad = $defaults['ovp_pad'];
        if ($records === null && in_array('ovp_central_records', $roles, true)) $records = $defaults['ovp_central_records'];
        if ($pad !== $user['ovp_pad_office_id'] || $records !== $user['ovp_central_records_office_id']) $update->execute(array($pad, $records, $user['id']));
    }
    $foreignKeys = array_column($db->query("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND REFERENCED_TABLE_NAME = 'offices'")->fetchAll(), 'COLUMN_NAME');
    foreach (array('ovp_pad_office_id', 'ovp_central_records_office_id') as $column) {
        if (!in_array($column, $foreignKeys, true)) $db->exec('ALTER TABLE users ADD CONSTRAINT users_' . $column . '_foreign FOREIGN KEY (' . $column . ') REFERENCES offices(id) ON DELETE RESTRICT');
    }
}
