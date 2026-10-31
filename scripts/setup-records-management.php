<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/RecordsManagement.php';
install_records_management(database());
echo 'Records management correction history is ready. Existing records and catalogs are unchanged.' . PHP_EOL;
