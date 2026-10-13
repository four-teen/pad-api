<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/AccountRoleSchema.php';
install_account_roles(database());
echo 'Four account roles and separate OVP routing office assignments are ready. Existing role offices are preserved; administrators can select exact offices in User accounts.' . PHP_EOL;
