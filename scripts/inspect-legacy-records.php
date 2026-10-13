<?php

// Read-only comparison of sam.pad and the current PAD database. No records are imported.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ob_start();
try { require dirname(__DIR__, 2) . '/sam.pad/db.php'; } finally { ob_end_clean(); }
mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, 'START TRANSACTION READ ONLY');
$db = database();
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    $legacyTables = array_column(mysqli_fetch_all(mysqli_query($conn, 'SHOW TABLES'), MYSQLI_NUM), 0);
    $result = array('legacy_tables' => $legacyTables, 'legacy' => array(), 'current' => array());
    foreach (array('tbl_documents_registry', 'tbl_document_actions', 'tbl_document_images', 'tbl_office_heads', 'tbldivisions',
        'tbltypeofdocuments', 'tblother_information', 'tbl_travel_order', 'tbl_travel_order_faculty', 'tblpresident_actions', 'tbl_acted_to_office', 'tblprofiles', 'tblaccount', 'tbl_file_series') as $table) {
        if (!in_array($table, $legacyTables, true)) continue;
        $result['legacy'][$table] = array(
            'count' => (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM `' . $table . '`'))[0],
            'columns' => array_map(function ($column) { return array_intersect_key($column, array_flip(array('Field', 'Type', 'Null', 'Key'))); },
                mysqli_fetch_all(mysqli_query($conn, 'SHOW COLUMNS FROM `' . $table . '`'), MYSQLI_ASSOC))
        );
    }
    foreach (array('documents', 'document_routes', 'document_pad_reviews', 'document_sources', 'document_types', 'document_personnel',
        'document_people', 'document_travel_details', 'offices', 'users', 'document_number_series') as $table) {
        $result['current'][$table] = array('count' => (int) $db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn(),
            'columns' => array_map(function ($column) { return array_intersect_key($column, array_flip(array('Field', 'Type', 'Null', 'Key'))); }, $db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll()));
    }
    $rows = function ($sql) use ($conn) { return mysqli_fetch_all(mysqli_query($conn, $sql), MYSQLI_ASSOC); };
    $result['audit'] = array(
        'time_context' => $rows('SELECT @@session.time_zone AS session_zone, @@global.time_zone AS global_zone, @@system_time_zone AS system_zone, NOW() AS database_now, UTC_TIMESTAMP() AS utc_now'),
        'document_range' => $rows('SELECT MIN(date_received) AS first_received, MAX(date_received) AS last_received, MIN(created_at) AS first_created, MAX(created_at) AS last_created FROM tbl_documents_registry'),
        'reference_quality' => $rows("SELECT SUM(TRIM(file_code) = '') AS empty_codes, SUM(CHAR_LENGTH(file_code) > 40) AS over_current_limit, SUM(CHAR_LENGTH(particular) > 255) AS long_titles, SUM(TRIM(particular) = '') AS empty_titles FROM tbl_documents_registry"),
        'reference_route_compatibility' => $rows("SELECT SUM(file_code NOT REGEXP '^[A-Za-z0-9-]+$') AS incompatible_codes, SUM(file_code <> TRIM(file_code)) AS padded_codes FROM tbl_documents_registry"),
        'incompatible_code_examples' => $rows("SELECT doc_id, file_code FROM tbl_documents_registry WHERE file_code NOT REGEXP '^[A-Za-z0-9-]+$'"),
        'duplicate_codes' => $rows('SELECT COUNT(*) AS duplicate_groups, COALESCE(SUM(n - 1), 0) AS extra_rows FROM (SELECT COUNT(*) AS n FROM tbl_documents_registry GROUP BY UPPER(TRIM(file_code)) HAVING COUNT(*) > 1) duplicates'),
        'reference_formats' => $rows("SELECT CASE WHEN file_code REGEXP '^PAD-[0-9]{4}-[0-9]+$' THEN 'PAD-year-number' ELSE SUBSTRING_INDEX(file_code, '-', 1) END AS prefix, COUNT(*) AS count, MAX(CHAR_LENGTH(file_code)) AS max_length FROM tbl_documents_registry GROUP BY prefix"),
        'action_types' => $rows('SELECT action_type, COUNT(*) AS count FROM tbl_document_actions GROUP BY action_type ORDER BY count DESC'),
        'latest_statuses' => $rows("SELECT COALESCE(a.action_type, 'No action') AS action_type, COUNT(*) AS count FROM tbl_documents_registry d LEFT JOIN (SELECT doc_id, MAX(action_id) AS last_id FROM tbl_document_actions GROUP BY doc_id) last_action ON last_action.doc_id = d.doc_id LEFT JOIN tbl_document_actions a ON a.action_id = last_action.last_id GROUP BY a.action_type ORDER BY count DESC"),
        'latest_acted_offices' => $rows("SELECT a.from_office_id, a.to_office_id, COUNT(*) AS count FROM tbl_documents_registry d JOIN (SELECT doc_id, MAX(action_id) AS last_id FROM tbl_document_actions GROUP BY doc_id) l ON l.doc_id = d.doc_id JOIN tbl_document_actions a ON a.action_id = l.last_id WHERE a.action_type = 'Acted' GROUP BY a.from_office_id, a.to_office_id"),
        'delivered_destinations' => $rows("SELECT SUM(to_office_id = 0) AS to_person_without_office, COUNT(*) AS count FROM tbl_document_actions WHERE action_type = 'Delivered'"),
        'orphan_actions' => $rows('SELECT COUNT(*) AS count FROM tbl_document_actions a LEFT JOIN tbl_documents_registry d ON d.doc_id = a.doc_id WHERE d.doc_id IS NULL'),
        'missing_history' => $rows('SELECT COUNT(*) AS count FROM tbl_documents_registry d WHERE NOT EXISTS (SELECT 1 FROM tbl_document_actions a WHERE a.doc_id = d.doc_id)'),
        'registry_links' => $rows('SELECT SUM(v.divisionid IS NULL) AS unknown_source, SUM(t.docid IS NULL) AS unknown_type, SUM(o.office_id IS NULL) AS unknown_receiving_office, SUM(u.office_id IS NULL) AS unknown_uni_division FROM tbl_documents_registry d LEFT JOIN tbldivisions v ON v.divisionid = d.office_division LEFT JOIN tbltypeofdocuments t ON t.docid = d.type_of_documents LEFT JOIN tbl_office_heads o ON o.office_id = d.received_by LEFT JOIN tbl_office_heads u ON u.office_id = d.uni_divisionid'),
        'missing_source_ids' => $rows('SELECT d.office_division, COUNT(*) AS count FROM tbl_documents_registry d LEFT JOIN tbldivisions v ON v.divisionid = d.office_division WHERE v.divisionid IS NULL GROUP BY d.office_division'),
        'action_office_links' => $rows('SELECT SUM(a.from_office_id > 0 AND f.office_id IS NULL) AS invalid_from, SUM(a.to_office_id > 0 AND t.office_id IS NULL) AS invalid_to, SUM(a.from_office_id = 0) AS zero_from, SUM(a.to_office_id = 0) AS zero_to FROM tbl_document_actions a LEFT JOIN tbl_office_heads f ON f.office_id = a.from_office_id LEFT JOIN tbl_office_heads t ON t.office_id = a.to_office_id'),
        'attachment_groups' => $rows('SELECT COUNT(*) AS documents_with_files, SUM(n > 1) AS documents_with_multiple_files, MAX(n) AS maximum_files FROM (SELECT i.doc_id, COUNT(*) AS n FROM tbl_document_images i INNER JOIN tbl_documents_registry d ON d.doc_id = i.doc_id GROUP BY i.doc_id) file_groups'),
        'orphan_file_rows' => $rows('SELECT COUNT(*) AS count FROM tbl_document_images i LEFT JOIN tbl_documents_registry d ON d.doc_id = i.doc_id WHERE d.doc_id IS NULL'),
        'travel_quality' => $rows("SELECT COUNT(*) AS count, COUNT(DISTINCT t.doc_id) AS distinct_documents, SUM(d.doc_id IS NULL) AS orphan_rows, SUM(t.to_destination IS NULL OR TRIM(t.to_destination) = '' OR t.to_purpose IS NULL OR TRIM(t.to_purpose) = '' OR t.to_date IS NULL OR t.to_departure_date IS NULL OR t.to_return_date IS NULL OR t.to_type IS NULL OR t.to_vehicle IS NULL) AS missing_required_fields, SUM(t.to_return_date < t.to_departure_date) AS reversed_dates FROM tbl_travel_order t LEFT JOIN tbl_documents_registry d ON d.doc_id = t.doc_id"),
        'duplicate_travel_documents' => $rows('SELECT COUNT(*) AS duplicate_documents, COALESCE(SUM(n - 1), 0) AS extra_rows FROM (SELECT doc_id, COUNT(*) AS n FROM tbl_travel_order GROUP BY doc_id HAVING COUNT(*) > 1) duplicates'),
        'personnel_links' => $rows('SELECT COUNT(*) AS count, SUM(d.doc_id IS NULL) AS orphan_document, SUM(p.acc_id IS NULL) AS orphan_personnel FROM tblother_information i LEFT JOIN tbl_documents_registry d ON d.doc_id = i.doc_id LEFT JOIN tblprofiles p ON p.acc_id = i.acc_id'),
        'travel_personnel_links' => $rows('SELECT COUNT(*) AS count, SUM(t.to_id IS NULL) AS orphan_travel, SUM(p.acc_id IS NULL) AS orphan_personnel FROM tbl_travel_order_faculty f LEFT JOIN tbl_travel_order t ON t.to_id = f.to_id LEFT JOIN tblprofiles p ON p.acc_id = f.acc_id'),
        'legacy_series' => $rows('SELECT series_prefix, series_number FROM tbl_file_series'),
    );
    $legacyOffices = $rows('SELECT office_id, office_name FROM tbl_office_heads');
    $currentOffices = $db->query('SELECT id, name, code, campus FROM offices')->fetchAll();
    $normalName = function ($name) { return preg_replace('/\s+/', ' ', strtolower(trim($name))); };
    $officeNames = array();
    foreach ($currentOffices as $office) $officeNames[$normalName($office['name'])][] = $office;
    $officeMatches = array('exact_name_matches' => 0, 'ambiguous_names' => 0, 'unmatched' => array(), 'id_name_conflicts' => 0);
    foreach ($legacyOffices as $office) {
        $matches = $officeNames[$normalName($office['office_name'])] ?? array();
        if (count($matches) === 1) $officeMatches['exact_name_matches']++;
        elseif ($matches) $officeMatches['ambiguous_names']++;
        else $officeMatches['unmatched'][] = $office;
        foreach ($currentOffices as $current) if ((int) $current['id'] === (int) $office['office_id'] && $normalName($current['name']) !== $normalName($office['office_name'])) $officeMatches['id_name_conflicts']++;
    }
    $result['audit']['office_mapping'] = $officeMatches;
    $legacyCodes = array_column($rows('SELECT file_code FROM tbl_documents_registry'), 'file_code');
    $normalizedCodes = array_map(function ($code) { return strtoupper(trim($code)); }, $legacyCodes);
    $overlaps = array();
    foreach ($db->query('SELECT reference_no FROM documents')->fetchAll() as $document) if (in_array(strtoupper(trim($document['reference_no'])), $normalizedCodes, true)) $overlaps[] = $document['reference_no'];
    $result['audit']['existing_reference_collisions'] = $overlaps;
    $result['audit']['missing_imported_personnel'] = count(array_diff(array_column($rows('SELECT acc_id FROM tblprofiles'), 'acc_id'), array_column($db->query('SELECT legacy_profile_id FROM document_personnel')->fetchAll(), 'legacy_profile_id')));
    $result['audit']['missing_imported_types'] = count(array_diff(array_column($rows('SELECT docid FROM tbltypeofdocuments'), 'docid'), array_column($db->query('SELECT legacy_type_id FROM document_types')->fetchAll(), 'legacy_type_id')));
    $result['audit']['missing_imported_sources'] = count(array_diff(array_column($rows('SELECT divisionid FROM tbldivisions'), 'divisionid'), array_column($db->query('SELECT legacy_division_id FROM document_sources')->fetchAll(), 'legacy_division_id')));
    $files = array('present' => 0, 'missing' => 0, 'unsafe_paths' => 0, 'readable' => 0, 'bytes' => 0, 'over_10_mb' => 0, 'empty' => 0, 'extensions' => array(), 'mime_types' => array());
    $mimeInspector = new finfo(FILEINFO_MIME_TYPE);
    $uploadRoot = realpath(dirname(__DIR__, 2) . '/sam.pad/uploads');
    foreach ($rows('SELECT img_filename FROM tbl_document_images') as $image) {
        $name = str_replace('\\', '/', $image['img_filename']);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $files['extensions'][$extension] = ($files['extensions'][$extension] ?? 0) + 1;
        if ($name !== basename($name)) { $files['unsafe_paths']++; continue; }
        $path = $uploadRoot ? realpath($uploadRoot . DIRECTORY_SEPARATOR . $name) : false;
        if (!$path || !is_file($path)) { $files['missing']++; continue; }
        if (strncasecmp($path, $uploadRoot . DIRECTORY_SEPARATOR, strlen($uploadRoot) + 1) !== 0) { $files['unsafe_paths']++; continue; }
        $files['present']++; $files['readable'] += (int) is_readable($path);
        $size = filesize($path); $files['bytes'] += $size; $files['over_10_mb'] += (int) ($size > 10485760); $files['empty'] += (int) ($size === 0);
        $mime = $mimeInspector->file($path); $files['mime_types'][$mime] = ($files['mime_types'][$mime] ?? 0) + 1;
    }
    $result['audit']['physical_files'] = $files;
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} finally {
    mysqli_rollback($conn); mysqli_close($conn); $db->rollBack();
}
