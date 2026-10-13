<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/RoutingSchema.php';
install_office_routing(database());
echo 'Office Document Routing schema ready. Existing documents and references preserved.' . PHP_EOL;
