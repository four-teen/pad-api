<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/DocumentTypeSchema.php';

$sourceConfig = $argv[1] ?? dirname(__DIR__, 2) . '/sam.pad/db.php';
if (!is_file($sourceConfig)) throw new RuntimeException('Pass the path to sam.pad/db.php as the first argument.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ob_start();
try { require $sourceConfig; } finally { ob_end_clean(); }
if (!isset($conn) || !($conn instanceof mysqli)) throw new RuntimeException('The source configuration must provide the sam.pad connection.');
mysqli_set_charset($conn, 'utf8mb4');
$types = mysqli_fetch_all(mysqli_query($conn, 'SELECT docid, doctype_desc FROM tbltypeofdocuments ORDER BY doctype_desc'), MYSQLI_ASSOC);
$personnel = mysqli_fetch_all(mysqli_query($conn, 'SELECT acc_id, acc_name FROM tblprofiles ORDER BY acc_name'), MYSQLI_ASSOC);
if (!$types) throw new RuntimeException('The sam.pad document type list is empty.');

$db = database();
install_document_registration($db);
$counts = import_document_catalog($db, $types, $personnel);
mysqli_close($conn);
echo 'Document registration ready: imported ' . $counts['types'] . ' document types and ' . $counts['personnel'] . ' personnel. Existing documents preserved.' . PHP_EOL;
