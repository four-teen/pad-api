<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/DocumentAttachmentSchema.php';
install_optional_document_files(database());
echo 'Optional document files and later uploads ready. Existing attachments preserved.' . PHP_EOL;
