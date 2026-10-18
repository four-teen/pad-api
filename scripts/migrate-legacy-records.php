<?php

// Local-only, insert-only migration tooling. Never loaded by the web application.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

function legacy_json($path)
{
    $value = json_decode(file_get_contents($path), true);
    if (!is_array($value) || json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('Invalid migration JSON.');
    return $value;
}

function legacy_write($path, $data)
{
    if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Could not save the private migration report.');
    }
}

function legacy_guard()
{
    if (env_value('APP_ENV') !== 'local' || !in_array(env_value('DB_HOST'), array('localhost', '127.0.0.1', '::1'), true)) {
        throw new RuntimeException('This migration is restricted to an explicitly configured LOCAL database.');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/D', env_value('DB_DATABASE', ''))) throw new RuntimeException('Invalid local database name.');
}

function legacy_snapshot(PDO $db)
{
    $result = array('database' => $db->query('SELECT DATABASE()')->fetchColumn(), 'schema' => array(), 'rows' => array());
    foreach ($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $entry) {
        if ($entry[1] !== 'BASE TABLE' || !preg_match('/^[A-Za-z0-9_]+$/D', $entry[0])) throw new RuntimeException('Unsupported database object.');
        $table = $entry[0];
        $create = $db->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
        if (strpos($create, 'ENGINE=InnoDB') === false) throw new RuntimeException('Every destination table must support transactions.');
        $result['schema'][$table] = $create;
        $data = $db->query('SELECT * FROM `' . $table . '`')->fetchAll();
        usort($data, function ($a, $b) { return strcmp(json_encode($a), json_encode($b)); });
        $result['rows'][$table] = $data;
    }
    ksort($result['schema']); ksort($result['rows']);
    $normalized = array_map(function ($sql) { return preg_replace('/ AUTO_INCREMENT=\d+/', '', $sql); }, $result['schema']);
    $result['schema_hash'] = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $result['data_hash'] = hash('sha256', json_encode($result['rows'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $result['host'] = env_value('DB_HOST');
    $result['captured_at_utc'] = gmdate('c');
    return $result;
}

function legacy_insert(PDO $db, $table, array $row)
{
    static $statements = array();
    $allowed = array('users', 'document_sources', 'document_types', 'document_personnel', 'documents', 'document_routes', 'document_people', 'document_travel_details');
    if (!in_array($table, $allowed, true)) throw new RuntimeException('Unapproved migration table.');
    foreach (array_keys($row) as $column) if (!preg_match('/^[a-z_]+$/D', $column)) throw new RuntimeException('Invalid column name.');
    $key = spl_object_hash($db) . ':' . $table . ':' . implode(',', array_keys($row));
    if (!isset($statements[$key])) {
        $columns = '`' . implode('`, `', array_keys($row)) . '`';
        $statements[$key] = $db->prepare('INSERT INTO `' . $table . '` (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
    }
    $statements[$key]->execute(array_values($row));
    return (int) $db->lastInsertId();
}

function legacy_package_path($root, $relative)
{
    if (!is_string($relative) || preg_match('#(^/|^[A-Za-z]:|\\\\|(?:^|/)\.\.(?:/|$))#', $relative)) throw new RuntimeException('Unsafe package path.');
    $path = realpath($root . DIRECTORY_SEPARATOR . $relative);
    if (!$path || !is_file($path) || strncasecmp($path, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) throw new RuntimeException('Missing or unsafe package file.');
    return $path;
}

function legacy_validate_pdf_report(array $plan, $root)
{
    $expected = array();
    foreach ($plan['documents'] as $entry) {
        if ($entry['attachment'] && $entry['row']['file_mime'] === 'application/pdf') {
            $expected[(string) $entry['legacy_id']] = $entry['attachment'];
        }
    }
    if (!$expected) return;
    $validation = legacy_json($root . DIRECTORY_SEPARATOR . 'pdf-validation.json');
    if (($validation['all_page_counts_match'] ?? false) !== true || ($validation['all_pages_rendered'] ?? false) !== true) {
        throw new RuntimeException('Parse and render every prepared PDF before applying this package.');
    }
    $pages = 0;
    foreach ($validation['pdfs'] ?? array() as $pdf) {
        $key = (string) $pdf['legacy_id'];
        if (!isset($expected[$key]) || $expected[$key]['sha256'] !== $pdf['sha256'] || $expected[$key]['pages'] !== $pdf['pages']) {
            throw new RuntimeException('PDF validation does not match the exact prepared attachments.');
        }
        $pages += $pdf['pages'];
        unset($expected[$key]);
    }
    if ($expected || $pages !== ($validation['total_pages'] ?? null)) throw new RuntimeException('PDF validation is incomplete.');
}

function legacy_apply(PDO $db, array $plan, array $baseline, $root, $copyFiles)
{
    $before = legacy_snapshot($db);
    if ($before['schema_hash'] !== $baseline['schema_hash'] || $before['data_hash'] !== $baseline['data_hash']) throw new RuntimeException('Destination changed since preview; create and review a fresh preview.');
    $report = array('documents' => 0, 'routes' => 0, 'people' => 0, 'attachments' => 0, 'catalog_rows' => array(), 'document_ids' => array(), 'copied_files' => array());
    $db->beginTransaction();
    try {
        // Lock the reviewed rows and insertion gaps; never overwrite a live record.
        foreach (array_keys($baseline['rows']) as $table) $db->query('SELECT * FROM `' . $table . '` FOR UPDATE')->fetchAll();
        $locked = legacy_snapshot($db);
        if ($locked['data_hash'] !== $baseline['data_hash']) throw new RuntimeException('Destination changed while acquiring migration locks.');
        foreach ($plan['catalog_inserts'] as $table => $rows) {
            $report['catalog_rows'][$table] = count($rows);
            foreach ($rows as $row) legacy_insert($db, $table, $row);
        }
        $identity = $plan['migration_identity'];
        $find = $db->prepare('SELECT id,is_active,google_subject,office_id,roles FROM users WHERE email=?');
        $find->execute(array($identity['email']));
        $actor = $find->fetch();
        if ($actor) {
            if ((int) $actor['is_active'] !== 0 || $actor['google_subject'] !== null || $actor['office_id'] !== null || $actor['roles'] !== 'router') throw new RuntimeException('Migration identity conflicts with an existing account.');
            $actorId = (int) $actor['id'];
        } else $actorId = legacy_insert($db, 'users', $identity);
        $report['migration_user_id'] = $actorId;
        foreach ($plan['documents'] as $document) {
            $row = $document['row']; $row['created_by_user_id'] = $actorId;
            $attachment = $document['attachment'];
            if ($attachment) {
                $path = legacy_package_path($root, $attachment['package_file']);
                if (hash_file('sha256', $path) !== $attachment['sha256'] || filesize($path) !== $row['file_size']) throw new RuntimeException('Prepared attachment changed.');
                if ($copyFiles) {
                    $destination = safe_upload_directory() . DIRECTORY_SEPARATOR . $row['stored_file_name'];
                    if (is_file($destination)) {
                        if (hash_file('sha256', $destination) !== $attachment['sha256']) throw new RuntimeException('Attachment destination collision.');
                    } else {
                        $out = fopen($destination, 'xb');
                        if (!$out) throw new RuntimeException('Could not create an attachment safely.');
                        $in = fopen($path, 'rb');
                        try { if (stream_copy_to_stream($in, $out) !== $row['file_size']) throw new RuntimeException('Incomplete attachment copy.'); }
                        finally { fclose($in); fclose($out); }
                        $report['copied_files'][] = $destination;
                    }
                    if (hash_file('sha256', $destination) !== $attachment['sha256']) throw new RuntimeException('Attachment copy verification failed.');
                }
                $report['attachments']++;
            }
            $id = legacy_insert($db, 'documents', $row);
            $report['document_ids'][(string) $document['legacy_id']] = $id;
            foreach ($document['routes'] as $route) {
                $route['document_id'] = $id; $route['acted_by_user_id'] = $actorId;
                legacy_insert($db, 'document_routes', $route); $report['routes']++;
            }
            foreach ($document['people'] as $person) {
                legacy_insert($db, 'document_people', array('document_id' => $id) + $person); $report['people']++;
            }
            if ($document['travel_details']) legacy_insert($db, 'document_travel_details', array('document_id' => $id) + $document['travel_details']);
            $report['documents']++;
        }
        $inside = legacy_snapshot($db);
        if ($inside['schema_hash'] !== $baseline['schema_hash']) throw new RuntimeException('Schema changed unexpectedly.');
        $report['validation'] = legacy_verify($db, $plan, $baseline, $root, $copyFiles);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        // Never remove copied files automatically: retain them for recovery/audit.
        throw $error;
    }
    $report['completed_at_utc'] = gmdate('c');
    return $report;
}

function legacy_verify(PDO $db, array $plan, array $baseline, $root, $checkFiles)
{
    $now = legacy_snapshot($db);
    if ($now['schema_hash'] !== $baseline['schema_hash']) throw new RuntimeException('Schema verification failed.');
    $checks = 1;
    foreach ($baseline['rows'] as $table => $rows) {
        $present = array_fill_keys(array_map('json_encode', $now['rows'][$table]), true);
        foreach ($rows as $row) { if (!isset($present[json_encode($row)])) throw new RuntimeException('An existing row was modified: ' . $table); $checks++; }
    }
    foreach (array('offices','office_officials','tblaccount','document_pad_reviews','document_number_series') as $table) {
        if ($now['rows'][$table] !== $baseline['rows'][$table]) throw new RuntimeException('Protected table changed: ' . $table);
        $checks++;
    }
    if ($checkFiles) {
        $originalFiles=legacy_json($root.DIRECTORY_SEPARATOR.'preexisting-files.json');
        foreach ($originalFiles as $file) {
            $path=safe_upload_directory().DIRECTORY_SEPARATOR.$file['stored_file_name'];
            if ($file['sha256'] !== null && (!is_file($path) || hash_file('sha256',$path)!==$file['sha256'])) throw new RuntimeException('A preexisting attachment changed.');
            $checks++;
        }
    }
    $actor = $db->prepare('SELECT id,is_active FROM users WHERE email=?'); $actor->execute(array($plan['migration_identity']['email'])); $actor = $actor->fetch();
    if (!$actor || (int) $actor['is_active'] !== 0) throw new RuntimeException('Import identity must remain inactive.');
    $get = $db->prepare('SELECT * FROM documents WHERE created_by_user_id=? AND submission_key=?');
    $routes = $db->prepare('SELECT * FROM document_routes WHERE document_id=? ORDER BY id');
    $people = $db->prepare('SELECT personnel_id,name_snapshot FROM document_people WHERE document_id=? ORDER BY personnel_id');
    $travel = $db->prepare('SELECT * FROM document_travel_details WHERE document_id=?');
    foreach ($plan['documents'] as $entry) {
        $get->execute(array($actor['id'],$entry['row']['submission_key'])); $row = $get->fetch();
        if (!$row) throw new RuntimeException('Imported record is missing.');
        $expected = $entry['row']; $expected['created_by_user_id'] = (int) $actor['id'];
        foreach ($expected as $key => $value) if (($row[$key] === null ? null : (string) $row[$key]) !== ($value === null ? null : (string) $value)) throw new RuntimeException('Document mismatch: ' . $entry['legacy_id'] . ' ' . $key);
        $routes->execute(array($row['id'])); $actualRoutes = $routes->fetchAll();
        if (count($actualRoutes) !== count($entry['routes'])) throw new RuntimeException('History count mismatch.');
        foreach ($entry['routes'] as $i => $expectedRoute) {
            $expectedRoute['document_id'] = $row['id']; $expectedRoute['acted_by_user_id'] = $actor['id'];
            foreach ($expectedRoute as $key => $value) if (($actualRoutes[$i][$key] === null ? null : (string) $actualRoutes[$i][$key]) !== ($value === null ? null : (string) $value)) throw new RuntimeException('History mismatch.');
        }
        $people->execute(array($row['id'])); if ($people->fetchAll() != $entry['people']) throw new RuntimeException('Personnel association mismatch.');
        $travel->execute(array($row['id'])); $actualTravel=$travel->fetch();
        if (!$entry['travel_details'] && $actualTravel) throw new RuntimeException('Travel details were fabricated.');
        if ($entry['travel_details']) foreach ($entry['travel_details'] as $key=>$value) if ($actualTravel[$key] != $value) throw new RuntimeException('Travel details mismatch.');
        if ($checkFiles && $entry['attachment']) {
            $path=safe_upload_directory().DIRECTORY_SEPARATOR.$row['stored_file_name'];
            if (!is_file($path) || hash_file('sha256',$path)!==$entry['attachment']['sha256']) throw new RuntimeException('Imported attachment is missing or changed.');
        }
        $checks++;
    }
    foreach ($now['schema'] as $table=>$unused) {
        $constraints=$db->prepare('SELECT COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');
        $constraints->execute(array($table));
        foreach ($constraints->fetchAll() as $fk) {
            foreach ($fk as $name) if (!preg_match('/^[A-Za-z0-9_]+$/D',$name)) throw new RuntimeException('Unexpected constraint identifier.');
            $n=$db->query('SELECT COUNT(*) FROM `'.$table.'` c LEFT JOIN `'.$fk['REFERENCED_TABLE_NAME'].'` p ON c.`'.$fk['COLUMN_NAME'].'`=p.`'.$fk['REFERENCED_COLUMN_NAME'].'` WHERE c.`'.$fk['COLUMN_NAME'].'` IS NOT NULL AND p.`'.$fk['REFERENCED_COLUMN_NAME'].'` IS NULL')->fetchColumn();
            if ((int)$n) throw new RuntimeException('Broken foreign-key relationship: '.$table); $checks++;
        }
    }
    return array('checks'=>$checks,'schema_unchanged'=>true,'existing_rows_unchanged'=>true,'foreign_keys_valid'=>true,'counts'=>array_map('count',$now['rows']));
}

function legacy_rehearse(PDO $db, array $plan, array $baseline, $root)
{
    $name='pad_legacy_test_'.bin2hex(random_bytes(6));
    $db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try {
        $copy=new PDO('mysql:host='.env_value('DB_HOST').';port='.env_value('DB_PORT','3306').';dbname='.$name.';charset=utf8mb4',env_value('DB_USERNAME'),env_value('DB_PASSWORD',''),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));
        $copy->exec("SET time_zone='+00:00'");
        $copy->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($baseline['schema'] as $sql) $copy->exec($sql);
        foreach ($baseline['rows'] as $table=>$rows) foreach ($rows as $row) {
            $insert=$copy->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')'); $insert->execute(array_values($row));
        }
        $copy->exec('SET FOREIGN_KEY_CHECKS=1');
        $badPlan=$plan;
        $badPlan['documents'][0]['routes'][0]['from_office_id']=2147483647;
        $rolledBack=false;
        try { legacy_apply($copy,$badPlan,$baseline,$root,false); }
        catch (PDOException $error) { $rolledBack=($error->getCode()==='23000'); }
        if (!$rolledBack || legacy_snapshot($copy)['data_hash']!==$baseline['data_hash']) throw new RuntimeException('The deliberate foreign-key failure must roll back every inserted row.');
        $result=legacy_apply($copy,$plan,$baseline,$root,false);
        $duplicateRejected=false;
        try { legacy_apply($copy,$plan,$baseline,$root,false); } catch (RuntimeException $error) { $duplicateRejected=strpos($error->getMessage(),'Destination changed')!==false; }
        if (!$duplicateRejected) throw new RuntimeException('Reapplying the stale plan must be rejected.');
        $result['duplicate_replay_rejected']=true;
        $result['foreign_key_failure_rolled_back']=true;
        return $result;
    } finally {
        if (!preg_match('/^pad_legacy_test_[a-f0-9]{12}$/D',$name)) throw new RuntimeException('Unsafe scratch database cleanup.');
        $db->exec('DROP DATABASE `'.$name.'`');
    }
}

function legacy_workspace_verify(PDO $db, array $plan, array $baseline)
{
    require_once PAD_ROOT . '/src/DocumentRouting.php';
    $before = legacy_snapshot($db);
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    try {
        $actor = $db->query("SELECT * FROM users WHERE is_active=1 AND FIND_IN_SET('ovp_central_records',roles) ORDER BY id LIMIT 1")->fetch();
        if (!$actor) throw new RuntimeException('An existing Central Records account is required for the workspace check.');
        $actor['active_role'] = 'ovp_central_records';
        $actor = apply_active_module_office($actor);
        $service = new DocumentRouting($db, $actor);
        $workspace = $service->workspace(array('queue'=>'delivered','office_id'=>'all'));
        $existingClosed = count(array_filter($baseline['rows']['documents'], function ($row) { return in_array($row['status'], array('completed','delivered'),true); }));
        if ($workspace['pagination']['total'] !== count($plan['documents']) + $existingClosed) throw new RuntimeException('Delivered workspace count does not match imported and existing closed records.');
        $samples = array();
        $categories = array();
        foreach ($plan['documents'] as $entry) {
            $keys = array('first');
            if ($entry['row']['file_mime'] === 'application/pdf') $keys[] = 'pdf';
            if (!$entry['attachment']) $keys[] = 'no_attachment';
            if (strpos($entry['row']['reference_no'],'LEGACY-SAM-') === 0) $keys[] = 'alternate_reference';
            if ($entry['people']) $keys[] = 'personnel';
            if (strpos($entry['row']['description'],'Original travel details') !== false) $keys[] = 'travel_provenance';
            if (strlen($entry['row']['description']) > 4000) $keys[] = 'long_description';
            foreach ($keys as $key) if (!isset($categories[$key])) { $samples[$entry['legacy_id']] = $entry; $categories[$key] = true; }
        }
        $last = end($plan['documents']); $samples[$last['legacy_id']] = $last;
        $histories = 0;
        foreach ($samples as $entry) {
            $record = $service->record($entry['row']['reference_no'],true);
            if ($record['status'] !== 'delivered' || count($record['history']) !== count($entry['routes'])) throw new RuntimeException('Application history/status does not match migration data.');
            if (array_filter($record['permissions']) || $record['pad_decision'] !== null || $record['is_owner_release']) throw new RuntimeException('Legacy history incorrectly enables workflow actions or implies a PAD decision.');
            $search = $service->workspace(array('queue'=>'delivered','office_id'=>'all','search'=>$entry['row']['reference_no']));
            if (!in_array($record['id'],array_column($search['data'],'id'))) throw new RuntimeException('Imported reference was not found through application search.');
            $histories += count($record['history']);
        }
        $db->rollBack();
        if (legacy_snapshot($db)['data_hash'] !== $before['data_hash']) throw new RuntimeException('Read-only application verification changed data.');
        return array('read_only'=>true,'role'=>'ovp_central_records','all_offices_delivered_total'=>$workspace['pagination']['total'],'sample_records_checked'=>count($samples),'sample_history_events_checked'=>$histories,'categories'=>array_keys($categories),'reference_search_passed'=>true,'closed_records_have_no_workflow_actions'=>true,'no_pad_decisions_fabricated'=>true,'completed_at_utc'=>gmdate('c'));
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

if (defined('PAD_LEGACY_LIBRARY_ONLY') && PAD_LEGACY_LIBRARY_ONLY) return;

try {
    legacy_guard();
    $mode=$argv[1]??''; $directory=realpath($argv[2]??'');
    $private=realpath(PAD_ROOT.DIRECTORY_SEPARATOR.'storage');
    if (!$directory || !$private || strncasecmp($directory,$private.DIRECTORY_SEPARATOR,strlen($private)+1)!==0) throw new RuntimeException('Use an existing private migration directory inside PAD storage.');
    $db=database(); $db->exec("SET time_zone='+00:00'");
    if ($mode==='snapshot') {
        $path=$directory.DIRECTORY_SEPARATOR.'before.json';
        if (is_file($path)) throw new RuntimeException('The pre-migration backup already exists; it will not be overwritten.');
        $db->exec('SET TRANSACTION READ ONLY'); $db->beginTransaction(); $snapshot=legacy_snapshot($db); $db->rollBack();
        legacy_write($path,$snapshot);
        echo json_encode(array('database'=>$snapshot['database'],'schema_hash'=>$snapshot['schema_hash'],'counts'=>array_map('count',$snapshot['rows'])),JSON_PRETTY_PRINT),PHP_EOL;
    } elseif ($mode==='backup-files') {
        $baseline=legacy_json($directory.DIRECTORY_SEPARATOR.'before.json');
        if (legacy_snapshot($db)['data_hash']!==$baseline['data_hash']) throw new RuntimeException('Destination changed before its attachment backup.');
        $manifestPath=$directory.DIRECTORY_SEPARATOR.'preexisting-files.json';
        if (is_file($manifestPath)) throw new RuntimeException('Preexisting attachment backup already exists.');
        $backupRoot=$directory.DIRECTORY_SEPARATOR.'preexisting-files';
        if (!is_dir($backupRoot) && !mkdir($backupRoot,0750)) throw new RuntimeException('Could not create private attachment backup.');
        $files=array();
        foreach ($baseline['rows']['documents'] as $row) {
            $name=$row['stored_file_name'];
            if (!$name) continue;
            if ($name!==basename($name) || strpos($name,'\\')!==false) throw new RuntimeException('Unsafe preexisting attachment name.');
            $path=safe_upload_directory().DIRECTORY_SEPARATOR.$name;
            $sha=is_file($path)?hash_file('sha256',$path):null;
            if ($sha!==null && (!copy($path,$backupRoot.DIRECTORY_SEPARATOR.$name) || hash_file('sha256',$backupRoot.DIRECTORY_SEPARATOR.$name)!==$sha)) throw new RuntimeException('Preexisting attachment backup failed.');
            $files[]=array('document_id'=>$row['id'],'stored_file_name'=>$name,'sha256'=>$sha);
        }
        legacy_write($manifestPath,$files);
        echo json_encode(array('original_attachment_entries'=>count($files),'files_backed_up'=>count(array_filter($files,function($row){return $row['sha256']!==null;})))),PHP_EOL;
    } elseif (in_array($mode,array('rehearse','apply','verify','app-verify'),true)) {
        $plan=legacy_json($directory.DIRECTORY_SEPARATOR.'plan.json'); $baseline=legacy_json($directory.DIRECTORY_SEPARATOR.'before.json');
        if (($plan['format_version']??null)!==1 || $baseline['database']!==env_value('DB_DATABASE') || $plan['baseline_schema_hash']!==$baseline['schema_hash'] || $plan['baseline_data_hash']!==$baseline['data_hash']) throw new RuntimeException('Plan does not match this database backup.');
        if (hash_file('sha256',$directory.DIRECTORY_SEPARATOR.'source.sql')!==$plan['source_sha256']) throw new RuntimeException('The archived source SQL changed.');
        if ($mode==='rehearse') { $result=legacy_rehearse($db,$plan,$baseline,$directory); $result['plan_sha256']=hash_file('sha256',$directory.DIRECTORY_SEPARATOR.'plan.json'); legacy_write($directory.DIRECTORY_SEPARATOR.'rehearsal.json',$result); }
        elseif ($mode==='verify') { $result=legacy_verify($db,$plan,$baseline,$directory,true); legacy_write($directory.DIRECTORY_SEPARATOR.'verification.json',$result); }
        elseif ($mode==='app-verify') { $result=legacy_workspace_verify($db,$plan,$baseline); legacy_write($directory.DIRECTORY_SEPARATOR.'app-verification.json',$result); }
        else {
            if (($argv[3]??'')!=='--database='.env_value('DB_DATABASE')) throw new RuntimeException('Apply requires the exact destination database argument.');
            $rehearsal=legacy_json($directory.DIRECTORY_SEPARATOR.'rehearsal.json');
            if (!$rehearsal['duplicate_replay_rejected'] || empty($rehearsal['foreign_key_failure_rolled_back']) || $rehearsal['plan_sha256']!==hash_file('sha256',$directory.DIRECTORY_SEPARATOR.'plan.json')) throw new RuntimeException('Run a successful rehearsal of this exact plan first.');
            legacy_validate_pdf_report($plan,$directory);
            if (is_file($directory.DIRECTORY_SEPARATOR.'applied.json')) throw new RuntimeException('This package has already been applied. Use verify; do not replay it.');
            $locked=$db->query("SELECT GET_LOCK('pad-legacy-local-import',0)")->fetchColumn();
            if (!(int)$locked) throw new RuntimeException('Another migration is running.');
            try { $result=legacy_apply($db,$plan,$baseline,$directory,true); legacy_write($directory.DIRECTORY_SEPARATOR.'applied.json',$result); }
            finally { $db->query("SELECT RELEASE_LOCK('pad-legacy-local-import')"); }
        }
        unset($result['document_ids'],$result['copied_files']);
        echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
    } else throw new RuntimeException('Usage: php scripts/migrate-legacy-records.php snapshot|backup-files|rehearse|apply|verify|app-verify storage/legacy-import/RUN [--database=pad_db]');
} catch (Throwable $error) { fwrite(STDERR,'Legacy migration stopped: '.$error->getMessage().PHP_EOL); exit(1); }
