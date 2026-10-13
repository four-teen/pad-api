<?php

if (strpos($path, '/api/admin/') === 0) {
    require_once __DIR__ . '/Administration.php';
    $admin = new Administration(database(), $user);
    header('Cache-Control: no-store');

    if (strpos($path, '/api/admin/database') === 0) {
        require_once __DIR__ . '/DatabaseMaintenance.php';
        $maintenance = new DatabaseMaintenance(database(), $user);
        if ($method === 'GET' && $path === '/api/admin/database') json_response($maintenance->inventory());
        if ($method === 'POST' && $path === '/api/admin/database/preview') json_response($maintenance->preview(request_json()['table'] ?? null));
        if ($method === 'POST' && $path === '/api/admin/database/clear') json_response($maintenance->clear(request_json()));
        if ($method === 'GET' && preg_match('#^/api/admin/database/backups/([0-9]+)$#', $path, $matches)) {
            $backup = $maintenance->backupFile((int) $matches[1]);
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="pad-recovery-backup-' . (int) $matches[1] . '.json"');
            header('X-Content-Type-Options: nosniff');
            readfile($backup);
            exit;
        }
        api_error('Database maintenance endpoint not found.', 404);
    }

    if ($method === 'GET' && $path === '/api/admin/overview') {
        $accounts = $admin->accounts();
        json_response(array('data' => array(
            'users' => count($accounts),
            'active_users' => count(array_filter($accounts, function ($account) { return (int) $account['is_active'] === 1; })),
            'offices' => (int) database()->query('SELECT COUNT(*) FROM offices WHERE is_active = 1')->fetchColumn(),
            'documents' => (int) database()->query('SELECT COUNT(*) FROM documents')->fetchColumn(),
            'legacy_role_offices' => module_office_ids()
        )));
    }
    $modules = array('accounts' => 'Account', 'offices' => 'Office', 'officials' => 'Official');
    if (preg_match('#^/api/admin/(accounts|offices|officials)(?:/([0-9]+))?$#', $path, $matches)) {
        $kind = $matches[1];
        $id = isset($matches[2]) ? (int) $matches[2] : null;
        if ($method === 'GET') {
            json_response(array('data' => $id === null ? $admin->$kind() : $admin->record($kind, $id)));
        }
        if ($method === 'POST' || ($id !== null && in_array($method, array('PUT', 'PATCH'), true))) {
            $save = 'save' . $modules[$kind];
            $savedId = $admin->$save(request_json(), $id);
            json_response(array('id' => $savedId, 'message' => $modules[$kind] . ($id === null ? ' created.' : ' updated.')), $id === null ? 201 : 200);
        }
        if ($method === 'DELETE' && $id !== null) {
            $delete = 'delete' . $modules[$kind];
            $admin->$delete($id);
            json_response(array('message' => $modules[$kind] . ' deleted.'));
        }
        header('Allow: GET, POST, PUT, PATCH, DELETE');
        api_error('This request method is not supported.', 405);
    }
}
