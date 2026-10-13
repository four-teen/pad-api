<?php
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/DatabaseMaintenance.php';
install_database_maintenance(database());
echo "Database maintenance audit table is ready. Existing tables and records were preserved.\n";
