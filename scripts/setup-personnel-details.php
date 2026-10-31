<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/PersonnelDetailsSchema.php';

// With no source argument, upgrade only. Import is explicit and local/CLI only.
$profiles = null;
if (isset($argv[1])) {
    if (!is_file($argv[1])) throw new RuntimeException('The legacy PAD configuration file was not found.');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    ob_start();
    try { require $argv[1]; } finally { ob_end_clean(); }
    if (!isset($conn) || !($conn instanceof mysqli)) throw new RuntimeException('The source configuration must provide a MySQLi connection.');
    mysqli_set_charset($conn, 'utf8mb4');
    $profiles = mysqli_fetch_all(mysqli_query($conn, 'SELECT acc_id, acc_email, acc_position, campus FROM tblprofiles'), MYSQLI_ASSOC);
    mysqli_close($conn);
}
$db = database();
install_personnel_details($db);
$count = $profiles === null ? 0 : backfill_personnel_details($db, $profiles);
echo 'Personnel details schema ready. ' . $count . ' existing personnel entries enriched from recorded legacy details. Existing saved values, names, documents, and login accounts are unchanged.' . PHP_EOL;
