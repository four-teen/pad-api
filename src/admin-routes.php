<?php

if (strpos($path, '/api/admin/') === 0) {
    require_once __DIR__ . '/Administration.php';
    $admin = new Administration(database(), $user);

    if ($method === 'GET' && $path === '/api/admin/overview') {
        $accounts = $admin->accounts();
        json_response(array('data' => array(
            'users' => count($accounts),
            'active_users' => count(array_filter($accounts, function ($account) { return (int) $account['is_active'] === 1; })),
            'offices' => (int) database()->query('SELECT COUNT(*) FROM offices WHERE is_active = 1')->fetchColumn(),
            'documents' => (int) database()->query('SELECT COUNT(*) FROM documents')->fetchColumn()
        )));
    }
    if ($method === 'GET' && $path === '/api/admin/accounts') json_response(array('data' => $admin->accounts()));
    if ($method === 'POST' && $path === '/api/admin/accounts') {
        $id = $admin->saveAccount(request_json());
        json_response(array('id' => $id, 'message' => 'Account approved and PAD access configured.'), 201);
    }
    if ($method === 'POST' && preg_match('#^/api/admin/accounts/([0-9]+)$#', $path, $matches)) {
        $admin->saveAccount(request_json(), (int) $matches[1]);
        json_response(array('message' => 'Account updated.'));
    }
    if ($method === 'GET' && $path === '/api/admin/offices') json_response(array('data' => $admin->offices()));
    if ($method === 'POST' && $path === '/api/admin/offices') {
        $id = $admin->saveOffice(request_json());
        json_response(array('id' => $id, 'message' => 'Routing office created.'), 201);
    }
    if ($method === 'POST' && preg_match('#^/api/admin/offices/([0-9]+)$#', $path, $matches)) {
        $admin->saveOffice(request_json(), (int) $matches[1]);
        json_response(array('message' => 'Routing office updated.'));
    }
}
