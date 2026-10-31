<?php

// CLI-only schema upgrade. Contact details belong to the personnel catalog,
// independently of approved login accounts and document name snapshots.
function install_personnel_details(PDO $db)
{
    $columns = array_column($db->query('SHOW COLUMNS FROM document_personnel')->fetchAll(), 'Field');
    foreach (array('email' => 'VARCHAR(191)', 'position' => 'VARCHAR(255)', 'campus' => 'VARCHAR(80)') as $name => $type) {
        if (!in_array($name, $columns, true)) $db->exec('ALTER TABLE document_personnel ADD COLUMN `' . $name . '` ' . $type . ' DEFAULT NULL');
    }
}

function personnel_legacy_details(array $profile)
{
    $details = array();
    foreach (array('email' => 191, 'position' => 255, 'campus' => 80) as $field => $limit) {
        $value = $profile[$field === 'campus' ? 'campus' : 'acc_' . $field] ?? null;
        if ($value !== null) {
            if (!is_string($value) || preg_match_all('/./us', $value, $characters) === false || count($characters[0]) > $limit) {
                throw new RuntimeException('A legacy personnel ' . $field . ' is invalid or too long.');
            }
            $value = trim($value);
            if ($value === '') $value = null;
        }
        // Preserve the recorded value; imported email syntax is reviewed at edit time.
        $details[$field] = $value;
    }
    return $details;
}

function backfill_personnel_details(PDO $db, array $profiles)
{
    $db->beginTransaction();
    try {
        // NULL means not imported yet. Values saved by Records are never overwritten.
        $save = $db->prepare('UPDATE document_personnel SET email = COALESCE(email, ?), position = COALESCE(position, ?), campus = COALESCE(campus, ?)
            WHERE legacy_profile_id = ? AND (email IS NULL OR position IS NULL OR campus IS NULL)');
        $updated = 0;
        foreach ($profiles as $profile) {
            $details = personnel_legacy_details($profile);
            $save->execute(array($details['email'], $details['position'], $details['campus'], $profile['acc_id']));
            $updated += $save->rowCount();
        }
        $db->commit();
        return $updated;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
