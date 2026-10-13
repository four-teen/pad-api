<?php

if (strpos($path, '/api/president/') === 0) {
    if (!is_presidential_view($user)) {
        throw new ApiException('University President monitoring access is required.', 403);
    }
    assert_monitoring_method($user, $method);
    header('Cache-Control: no-store');
    require_once __DIR__ . '/DocumentRouting.php';
    if ($path === '/api/president/offices') {
        json_response(array('data' => database()->query('SELECT id, name, code, campus, is_active FROM offices ORDER BY campus, name')->fetchAll()));
    }
    if ($path === '/api/president/statistics') {
        require_once __DIR__ . '/DocumentStatistics.php';
        json_response((new DocumentStatistics(database(), $user))->report($_GET));
    }
    if ($path === '/api/president/documents') {
        $input = $_GET;
        $input['queue'] = 'all';
        if (!isset($input['office_id']) || $input['office_id'] === '') $input['office_id'] = 'all';
        json_response((new DocumentRouting(database(), $user))->workspace($input));
    }
    if (preg_match('#^/api/president/documents/([A-Za-z0-9-]+)$#', $path, $matches)) {
        json_response(array('data' => (new DocumentRouting(database(), $user))->record($matches[1], true)));
    }
    throw new ApiException('Monitoring endpoint not found.', 404);
}
