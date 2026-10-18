<?php

// Explicitly scoped local rollback. Application code never includes this file.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PAD_LEGACY_LIBRARY_ONLY', true);
require __DIR__ . '/migrate-legacy-records.php';

function revert_assert($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

function revert_file($directory, $name)
{
    revert_assert(is_string($name) && $name !== '' && $name === basename($name)
        && !preg_match('/[\\\\\/:]/', $name) && $name !== '.' && $name !== '..', 'Unsafe attachment filename.');
    $path = realpath($directory . DIRECTORY_SEPARATOR . $name);
    revert_assert($path && is_file($path) && !is_link($path)
        && strcasecmp(dirname($path), $directory) === 0, 'Attachment is missing or outside the verified folder.');
    return $path;
}

function revert_files($directory)
{
    $files = array();
    foreach (scandir($directory) as $name) {
        if ($name === '.' || $name === '..' || !is_file($directory . DIRECTORY_SEPARATOR . $name)) continue;
        $path = revert_file($directory, $name);
        $files[$name] = array('sha256' => hash_file('sha256', $path), 'bytes' => filesize($path));
    }
    ksort($files);
    return $files;
}

function revert_matches(array $actual, array $expected)
{
    foreach ($expected as $key => $value) {
        if (!array_key_exists($key, $actual) || ($actual[$key] === null ? null : (string) $actual[$key]) !== ($value === null ? null : (string) $value)) return false;
    }
    return true;
}

function revert_plan(PDO $db, array $snapshot, array $import, array $applied, $package, $uploads)
{
    revert_assert(isset($applied['document_ids'], $applied['migration_user_id'], $applied['copied_files']), 'Applied migration map is missing.');
    $documents = array_column($snapshot['rows']['documents'], null, 'id');
    $ids = array_map('intval', array_values($applied['document_ids']));
    revert_assert(count($ids) === count($import['documents']) && count(array_unique($ids)) === count($ids), 'Import ID mapping is incomplete.');
    $idSet = array_fill_keys($ids, true);
    $delete = array(); $retained = array();
    foreach ($snapshot['rows'] as $table => $rows) $delete[$table] = array();
    $routesByDocument = array(); $peopleByDocument = array();
    foreach ($snapshot['rows']['document_routes'] as $row) {
        if (isset($idSet[$row['document_id']])) $routesByDocument[$row['document_id']][] = $row;
    }
    foreach ($routesByDocument as &$routes) usort($routes, function ($a, $b) { return (int) $a['id'] <=> (int) $b['id']; });
    unset($routes);
    foreach ($snapshot['rows']['document_people'] as $row) {
        if (isset($idSet[$row['document_id']])) $peopleByDocument[$row['document_id']][] = $row;
    }
    foreach ($peopleByDocument as &$people) usort($people, function ($a, $b) { return (int) $a['personnel_id'] <=> (int) $b['personnel_id']; });
    unset($people);
    $files = array();
    $copied = array();
    foreach ($applied['copied_files'] as $path) {
        $real = realpath($path);
        revert_assert($real && strcasecmp(dirname($real), $uploads) === 0, 'An import file is outside the agreed live document folder.');
        $copied[basename($real)] = true;
    }
    foreach ($import['documents'] as $entry) {
        $id = (int) ($applied['document_ids'][(string) $entry['legacy_id']] ?? 0);
        revert_assert($id > 0 && isset($documents[$id]), 'An imported document is missing; prepare a new rollback review.');
        $expected = $entry['row']; $expected['created_by_user_id'] = $applied['migration_user_id'];
        revert_assert(revert_matches($documents[$id], $expected), 'An imported document was edited after migration; preserve it for review.');
        $delete['documents'][] = $documents[$id];
        $actualRoutes = $routesByDocument[$id] ?? array();
        revert_assert(count($actualRoutes) === count($entry['routes']), 'Imported history has new or missing events; review required.');
        foreach ($entry['routes'] as $index => $route) {
            $route['document_id'] = $id; $route['acted_by_user_id'] = $applied['migration_user_id'];
            revert_assert(revert_matches($actualRoutes[$index], $route), 'Imported history was edited; review required.');
            $delete['document_routes'][] = $actualRoutes[$index];
        }
        $actualPeople = $peopleByDocument[$id] ?? array();
        revert_assert(count($actualPeople) === count($entry['people']), 'Imported personnel links changed; review required.');
        foreach ($entry['people'] as $index => $person) {
            revert_assert(revert_matches($actualPeople[$index], array('document_id' => $id) + $person), 'Imported personnel links changed.');
            $delete['document_people'][] = $actualPeople[$index];
        }
        // This batch deliberately imported no normalized travel rows or PAD reviews.
        revert_assert(!$entry['travel_details'], 'Normalized imported travel needs a separate rollback mapping.');
        if ($entry['attachment']) {
            $name = $entry['row']['stored_file_name'];
            $path = revert_file($uploads, $name);
            revert_assert(isset($copied[$name]) && hash_file('sha256', $path) === $entry['attachment']['sha256'], 'Import attachment changed or was not created by this migration.');
            foreach ($documents as $other) if (!isset($idSet[$other['id']])) revert_assert($other['stored_file_name'] !== $name, 'An imported attachment is used by a retained record.');
            $files[$name] = array('sha256' => $entry['attachment']['sha256'], 'bytes' => filesize($path));
        }
    }
    revert_assert(count($files) === count($copied), 'Live import attachment map is incomplete.');
    foreach (array('document_pad_reviews', 'document_travel_details') as $table) foreach ($snapshot['rows'][$table] as $row) {
        revert_assert(!isset($idSet[$row['document_id']]), 'A newer PAD review or travel record depends on an imported document.');
    }
    // Catalog additions can only be removed if unchanged and unused by any retained row.
    $fks = $db->query('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll();
    $candidates = $import['catalog_inserts'];
    $identityWasNew = true;
    $baseline = legacy_json($package . DIRECTORY_SEPARATOR . 'before.json');
    foreach ($baseline['rows']['users'] as $user) if ((int) $user['id'] === (int) $applied['migration_user_id']) $identityWasNew = false;
    if ($identityWasNew) $candidates['users'] = array(array('id' => $applied['migration_user_id']) + $import['migration_identity']);
    foreach ($candidates as $table => $rows) {
        $present = array_column($snapshot['rows'][$table], null, 'id');
        foreach ($rows as $candidate) {
            $id = $candidate['id']; $reasons = array();
            if (!isset($present[$id])) continue;
            if (!revert_matches($present[$id], $candidate)) $reasons[] = 'Edited after import';
            foreach ($fks as $fk) if ($fk['REFERENCED_TABLE_NAME'] === $table) {
                $deletingRows = array_fill_keys(array_map('json_encode', $delete[$fk['TABLE_NAME']]), true);
                foreach ($snapshot['rows'][$fk['TABLE_NAME']] as $row) {
                    if ($row[$fk['COLUMN_NAME']] !== null && (string) $row[$fk['COLUMN_NAME']] === (string) $present[$id][$fk['REFERENCED_COLUMN_NAME']]
                        && !isset($deletingRows[json_encode($row)])) { $reasons[] = 'Referenced by retained ' . $fk['TABLE_NAME']; break; }
                }
            }
            if ($reasons) $retained[] = array('table' => $table, 'id' => $id, 'reasons' => array_values(array_unique($reasons)));
            else $delete[$table][] = $present[$id];
        }
    }
    $expectedRows = array();
    foreach ($snapshot['rows'] as $table => $rows) {
        $removals = array_fill_keys(array_map('json_encode', $delete[$table]), true);
        $expectedRows[$table] = array_values(array_filter($rows, function ($row) use ($removals) { return !isset($removals[json_encode($row)]); }));
    }
    $expectedHash = hash('sha256', json_encode($expectedRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return array('format_version' => 1, 'database' => $snapshot['database'], 'schema_hash' => $snapshot['schema_hash'], 'before_data_hash' => $snapshot['data_hash'],
        'expected_data_hash' => $expectedHash, 'delete_rows' => $delete, 'files' => $files, 'retained_import_additions' => $retained,
        'expected_counts' => array_map('count', $expectedRows), 'prepared_at_utc' => gmdate('c'));
}

function revert_delete(PDO $db, array $plan)
{
    $order = array('document_people', 'document_routes', 'documents', 'document_personnel', 'document_types', 'document_sources', 'users');
    $counts = array();
    foreach ($order as $table) {
        $rows = $plan['delete_rows'][$table]; $counts[$table] = 0;
        if (!$rows) continue;
        $primary = $db->query('SHOW KEYS FROM `' . $table . '` WHERE Key_name=\'PRIMARY\'')->fetchAll();
        usort($primary, function ($a, $b) { return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']; });
        $columns = array_column($primary, 'Column_name');
        revert_assert($columns, 'A rollback table lacks a primary key.');
        foreach ($columns as $column) revert_assert((bool) preg_match('/^[a-z_]+$/D', $column), 'Unexpected primary key.');
        $where = implode(' AND ', array_map(function ($key) { return '`' . $key . '` = ?'; }, $columns));
        $query = $db->prepare('DELETE FROM `' . $table . '` WHERE ' . $where);
        foreach ($rows as $row) {
            $query->execute(array_map(function ($key) use ($row) { return $row[$key]; }, $columns));
            revert_assert($query->rowCount() === 1, 'An exact mapped row could not be removed.');
            $counts[$table]++;
        }
    }
    return $counts;
}

function revert_verify(PDO $db, array $plan)
{
    $after = legacy_snapshot($db);
    revert_assert($after['schema_hash'] === $plan['schema_hash'], 'Database table structure changed unexpectedly.');
    revert_assert($after['data_hash'] === $plan['expected_data_hash'], 'Retained rows do not match the exact expected rollback state.');
    foreach ($db->query('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll() as $fk) {
        foreach ($fk as $name) revert_assert((bool) preg_match('/^[a-z_]+$/Di', $name), 'Unexpected foreign key identifier.');
        $count = $db->query('SELECT COUNT(*) FROM `' . $fk['TABLE_NAME'] . '` c LEFT JOIN `' . $fk['REFERENCED_TABLE_NAME'] . '` p ON c.`' . $fk['COLUMN_NAME'] . '`=p.`' . $fk['REFERENCED_COLUMN_NAME'] . '` WHERE c.`' . $fk['COLUMN_NAME'] . '` IS NOT NULL AND p.`' . $fk['REFERENCED_COLUMN_NAME'] . '` IS NULL')->fetchColumn();
        revert_assert((int) $count === 0, 'Rollback left a broken relationship.');
    }
    return array('schema_unchanged' => true, 'all_retained_rows_unchanged' => true, 'foreign_keys_valid' => true, 'counts' => array_map('count', $after['rows']), 'data_hash' => $after['data_hash']);
}

function revert_rehearse(PDO $db, array $snapshot, array $plan)
{
    $name = 'pad_revert_test_' . bin2hex(random_bytes(6));
    $db->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try {
        $copy = new PDO('mysql:host=' . env_value('DB_HOST') . ';port=' . env_value('DB_PORT', '3306') . ';dbname=' . $name . ';charset=utf8mb4', env_value('DB_USERNAME'), env_value('DB_PASSWORD', ''), array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false));
        $copy->exec("SET time_zone='+00:00'"); $copy->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($snapshot['schema'] as $sql) $copy->exec($sql);
        foreach ($snapshot['rows'] as $table => $rows) {
            if (!$rows) continue;
            $insert = $copy->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($rows[0])) . '`) VALUES (' . implode(',', array_fill(0, count($rows[0]), '?')) . ')');
            foreach ($rows as $row) $insert->execute(array_values($row));
        }
        $copy->exec('SET FOREIGN_KEY_CHECKS=1');
        $copy->beginTransaction();
        revert_delete($copy, $plan); revert_verify($copy, $plan); $copy->rollBack();
        revert_assert(legacy_snapshot($copy)['data_hash'] === $snapshot['data_hash'], 'Rollback rehearsal did not restore every row.');
        $copy->beginTransaction(); $deleted = revert_delete($copy, $plan); $validation = revert_verify($copy, $plan); $copy->commit();
        return array('deleted' => $deleted, 'validation' => $validation, 'transaction_rollback_test_passed' => true, 'completed_at_utc' => gmdate('c'));
    } finally {
        revert_assert((bool) preg_match('/^pad_revert_test_[a-f0-9]{12}$/D', $name), 'Unsafe temporary database cleanup.');
        $db->exec('DROP DATABASE `' . $name . '`');
    }
}

try {
    legacy_guard();
    revert_assert(env_value('DB_DATABASE') === 'pad_db', 'This rollback is authorized only for local pad_db.');
    $mode = $argv[1] ?? '';
    $package = realpath($argv[2] ?? ''); $storage = realpath(PAD_ROOT . DIRECTORY_SEPARATOR . 'storage');
    revert_assert($package && $storage && strncasecmp($package, $storage . DIRECTORY_SEPARATOR, strlen($storage) + 1) === 0, 'Use the original private migration package.');
    $uploads = realpath(safe_upload_directory());
    revert_assert($uploads && strncasecmp($uploads, $storage . DIRECTORY_SEPARATOR, strlen($storage) + 1) === 0, 'Document folder must resolve inside local PAD storage.');
    $root = $package . DIRECTORY_SEPARATOR . 'revert-20261018';
    $db = database(); $db->exec("SET time_zone='+00:00'");
    if ($mode === 'prepare') {
        revert_assert(!file_exists($root), 'The rollback backup already exists and will not be overwritten.');
        $db->exec('SET TRANSACTION READ ONLY'); $db->beginTransaction(); $snapshot = legacy_snapshot($db);
        $import = legacy_json($package . DIRECTORY_SEPARATOR . 'plan.json'); $applied = legacy_json($package . DIRECTORY_SEPARATOR . 'applied.json');
        $plan = revert_plan($db, $snapshot, $import, $applied, $package, $uploads); $db->rollBack();
        revert_assert(mkdir($root, 0750) && mkdir($root . DIRECTORY_SEPARATOR . 'files', 0750), 'Could not create private recovery folder.');
        legacy_write($root . DIRECTORY_SEPARATOR . 'before-revert.json', $snapshot);
        $manifest = revert_files($uploads);
        foreach ($manifest as $name => $file) {
            $destination = $root . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . $name;
            revert_assert(copy(revert_file($uploads, $name), $destination) && hash_file('sha256', $destination) === $file['sha256'], 'Attachment backup failed.');
        }
        $plan['before_files'] = $manifest;
        $plan['uploads'] = $uploads;
        legacy_write($root . DIRECTORY_SEPARATOR . 'plan.json', $plan);
        $result = array('database' => 'pad_db', 'remove_rows' => array_filter(array_map('count', $plan['delete_rows'])), 'remove_live_attachments' => count($plan['files']), 'backed_up_files' => count($manifest), 'retained_import_additions' => $plan['retained_import_additions'], 'expected_counts' => $plan['expected_counts']);
    } elseif ($mode === 'rehearse') {
        $plan = legacy_json($root . DIRECTORY_SEPARATOR . 'plan.json');
        $result = revert_rehearse($db, legacy_json($root . DIRECTORY_SEPARATOR . 'before-revert.json'), $plan);
        $result['plan_sha256'] = hash_file('sha256', $root . DIRECTORY_SEPARATOR . 'plan.json');
        legacy_write($root . DIRECTORY_SEPARATOR . 'rehearsal.json', $result);
    } elseif ($mode === 'apply') {
        revert_assert(($argv[3] ?? '') === '--database=pad_db', 'Apply requires --database=pad_db.');
        revert_assert(!is_file($root . DIRECTORY_SEPARATOR . 'applied.json'), 'This rollback has already been applied.');
        $plan = legacy_json($root . DIRECTORY_SEPARATOR . 'plan.json');
        $rehearsal = legacy_json($root . DIRECTORY_SEPARATOR . 'rehearsal.json');
        revert_assert($rehearsal['transaction_rollback_test_passed'] && $rehearsal['plan_sha256'] === hash_file('sha256', $root . DIRECTORY_SEPARATOR . 'plan.json'), 'A rehearsal of this exact rollback is required.');
        revert_assert($plan['uploads'] === $uploads && $plan['database'] === 'pad_db', 'Rollback destination changed.');
        revert_assert((int) $db->query("SELECT GET_LOCK('pad-legacy-local-import',0)")->fetchColumn() === 1, 'A migration is already running.');
        $removedFiles = array(); $committed = false;
        try {
            $db->beginTransaction();
            foreach (array_keys($plan['delete_rows']) as $table) $db->query('SELECT * FROM `' . $table . '` FOR UPDATE')->fetchAll();
            $current = legacy_snapshot($db);
            revert_assert($current['data_hash'] === $plan['before_data_hash'] && $current['schema_hash'] === $plan['schema_hash'], 'Local data changed after rollback preparation; review before applying.');
            revert_assert(revert_files($uploads) === $plan['before_files'], 'Live attachment folder changed after backup.');
            $fresh = revert_plan($db, $current, legacy_json($package . DIRECTORY_SEPARATOR . 'plan.json'), legacy_json($package . DIRECTORY_SEPARATOR . 'applied.json'), $package, $uploads);
            revert_assert($fresh['expected_data_hash'] === $plan['expected_data_hash'] && $fresh['files'] === $plan['files'], 'Import dependencies changed.');
            foreach ($plan['before_files'] as $name => $file) revert_assert(hash_file('sha256', revert_file(realpath($root . DIRECTORY_SEPARATOR . 'files'), $name)) === $file['sha256'], 'Recovery file changed.');
            $deleted = revert_delete($db, $plan);
            $validation = revert_verify($db, $plan);
            foreach ($plan['files'] as $name => $file) {
                $path = revert_file($uploads, $name);
                revert_assert(hash_file('sha256', $path) === $file['sha256'], 'Live file changed during rollback.');
                revert_assert(unlink($path), 'Could not remove a mapped live attachment.');
                $removedFiles[] = $name;
            }
            $expectedFiles = array_diff_key($plan['before_files'], $plan['files']);
            revert_assert(revert_files($uploads) === $expectedFiles, 'Original or unrelated document-folder files changed.');
            $db->commit(); $committed = true;
            $result = array('database' => 'pad_db', 'deleted' => $deleted, 'live_attachments_removed' => count($removedFiles), 'other_folder_files_preserved' => count($expectedFiles), 'recoverable_backup_files' => count($plan['before_files']), 'retained_import_additions' => $plan['retained_import_additions'], 'validation' => $validation, 'completed_at_utc' => gmdate('c'));
            legacy_write($root . DIRECTORY_SEPARATOR . 'applied.json', $result);
            legacy_write($package . DIRECTORY_SEPARATOR . 'reverted.json', $result);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            if (!$committed) foreach ($removedFiles as $name) {
                $destination = $uploads . DIRECTORY_SEPARATOR . $name;
                if (!is_file($destination)) revert_assert(copy(revert_file(realpath($root . DIRECTORY_SEPARATOR . 'files'), $name), $destination), 'Could not restore an attachment after transaction rollback.');
            }
            throw $error;
        } finally { $db->query("SELECT RELEASE_LOCK('pad-legacy-local-import')"); }
    } elseif ($mode === 'verify') {
        $plan = legacy_json($root . DIRECTORY_SEPARATOR . 'plan.json');
        revert_assert(is_file($root . DIRECTORY_SEPARATOR . 'applied.json'), 'Apply the reviewed rollback before verifying it.');
        $result = revert_verify($db, $plan);
        $expectedFiles = array_diff_key($plan['before_files'], $plan['files']);
        revert_assert(revert_files($uploads) === $expectedFiles, 'Remaining live files do not match preserved attachments.');
        $result['live_import_attachments_remaining'] = 0;
        $result['other_folder_files_preserved'] = count($expectedFiles);
        $result['verified_at_utc'] = gmdate('c');
        legacy_write($root . DIRECTORY_SEPARATOR . 'verification.json', $result);
    } else throw new RuntimeException('Usage: php scripts/revert-legacy-records.php prepare|rehearse|apply|verify storage/legacy-import/RUN [--database=pad_db]');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR, 'Legacy rollback stopped: ' . $error->getMessage() . PHP_EOL); exit(1); }
