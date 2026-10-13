<?php

// Module roles use the shared document service within their assigned office.
$moduleRoles = array('ovp_pad', 'ovp_central_records', 'sksu_officials');
if (!in_array($user['active_role'] ?? '', $moduleRoles, true) || !in_array($user['active_role'], account_roles($user), true)) {
    throw new ApiException('Select an assigned document module role to use Office Document Routing.', 403);
}
$path = '/api/' . substr($path, strlen('/api/doc_router/'));
