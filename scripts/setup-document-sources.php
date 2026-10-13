<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/DocumentSourceSchema.php';

$sourceConfig = $argv[1] ?? dirname(__DIR__, 2) . '/sam.pad/db.php';
if (!is_file($sourceConfig)) throw new RuntimeException('Pass the path to sam.pad/db.php as the first argument.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ob_start();
try { require $sourceConfig; } finally { ob_end_clean(); }
if (!isset($conn) || !($conn instanceof mysqli)) throw new RuntimeException('The source configuration must provide the sam.pad connection.');
mysqli_set_charset($conn, 'utf8mb4');
$divisions = mysqli_fetch_all(mysqli_query($conn, 'SELECT divisionid, division_desc FROM tbldivisions ORDER BY division_desc, divisionid'), MYSQLI_ASSOC);
if (!$divisions) throw new RuntimeException('The sam.pad division list is empty.');

$db = database();
install_document_sources($db);
$count = import_document_sources($db, $divisions);
mysqli_close($conn);
echo 'Document sources ready: imported ' . $count . ' agencies / divisions from sam.pad.tbldivisions. Existing document source names preserved.' . PHP_EOL;
