<?php

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Administration.php';
require dirname(__DIR__) . '/src/DocumentRouting.php';
require dirname(__DIR__) . '/src/PadReviewSchema.php';
require dirname(__DIR__) . '/src/AccountRoleSchema.php';

$checks = 0;
function admin_check($condition, $message)
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function admin_rejects(callable $action, $status, $message)
{
    try { $action(); } catch (ApiException $error) {
        admin_check($error->getCode() === $status, $message . ' (HTTP status)');
        return;
    }
    throw new RuntimeException($message);
}

// Generated, empty test database: no real PAD account, document, or office changes.
$sourceDatabase = env_value('DB_DATABASE');
if (!preg_match('/^[A-Za-z0-9_]+$/', $sourceDatabase)) throw new RuntimeException('Unsupported source database name.');
$management = new PDO('mysql:host=' . env_value('DB_HOST') . ';port=' . env_value('DB_PORT', '3306') . ';dbname=' . $sourceDatabase . ';charset=utf8mb4',
    env_value('DB_USERNAME'), env_value('DB_PASSWORD', ''), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$testDatabase = 'pad_admin_test_' . bin2hex(random_bytes(6));
$management->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
foreach (array('offices', 'users', 'office_officials', 'documents', 'document_routes') as $table) {
    $management->exec('CREATE TABLE `' . $testDatabase . '`.`' . $table . '` LIKE `' . $sourceDatabase . '`.`' . $table . '`');
}
putenv('DB_DATABASE=' . $testDatabase);
$db = database();
install_pad_reviews($db);
$db->exec('CREATE TABLE pad_admin_test_accounts (
    acc_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, acc_name VARCHAR(160), acc_position VARCHAR(255),
    acc_email VARCHAR(191), acc_type VARCHAR(30), campus VARCHAR(80)) ENGINE=InnoDB');
putenv('AUTH_ACCOUNT_TABLE=pad_admin_test_accounts');
putenv('AUTH_ACCOUNT_EMAIL_COLUMN=acc_email');

admin_rejects(function () use ($db) { new Administration($db, array('id' => 1, 'role' => 'staff')); }, 403, 'Staff cannot manage administration.');
$setup = new Administration($db, array('id' => 999999, 'role' => 'admin'));
$actorAccountId = $setup->saveAccount(array('name' => 'Test Administrator', 'email' => 'admin@example.test', 'role' => 'admin'));
$actor = $db->query("SELECT * FROM users WHERE email = 'admin@example.test'")->fetch();
$admin = new Administration($db, $actor);
admin_rejects(function () use ($admin, $actorAccountId) { $admin->deleteAccount($actorAccountId); }, 422, 'Self-deletion is blocked.');
admin_rejects(function () use ($admin, $actorAccountId) { $admin->saveAccount(array('role' => 'staff'), $actorAccountId); }, 422, 'Self-demotion is blocked.');
admin_rejects(function () use ($admin, $actorAccountId) { $admin->saveAccount(array('email' => 'other@example.test'), $actorAccountId); }, 422, 'Changing your own login email is blocked.');

$officeId = $admin->saveOffice(array('code' => 'TEST-ONE', 'name' => 'Test Routing Office', 'campus' => 'Test Campus',
    'contact_email' => ' OFFICE@Example.Test ', 'officials' => array(array('name' => 'Test Official', 'position' => 'Director', 'email' => 'OFFICIAL@Example.Test'))));
$office = $admin->record('offices', $officeId);
admin_check($office['contact_email'] === 'office@example.test' && count($office['officials']) === 1, 'Create an office with normalized contact emails.');
$officialId = (int) $office['officials'][0]['id'];
$admin->saveOffice(array('name' => 'Updated Office'), $officeId);
admin_check((int) $admin->record('offices', $officeId)['officials'][0]['id'] === $officialId, 'Metadata edits preserve officials.');
$admin->saveOffice(array('officials' => array(array('id' => $officialId, 'name' => 'Renamed Official', 'position' => 'Dean', 'email' => 'updated@example.test'))), $officeId);
admin_check($admin->record('officials', $officialId)['name'] === 'Renamed Official', 'Nested updates retain official IDs.');
$secondOfficeId = $admin->saveOffice(array('code' => 'TEST-TWO', 'name' => 'Second Office'));
admin_rejects(function () use ($admin, $secondOfficeId, $officialId) {
    $admin->saveOffice(array('name' => 'Must Roll Back', 'officials' => array(array('id' => $officialId, 'name' => 'Forged', 'position' => 'Director'))), $secondOfficeId);
}, 422, 'An office cannot overwrite another office official.');
admin_check($admin->record('offices', $secondOfficeId)['name'] === 'Second Office', 'A rejected nested update rolls back office metadata.');

$newOfficialId = $admin->saveOfficial(array('office_id' => $officeId, 'name' => 'Second Official', 'position' => 'Secretary', 'alternate_email' => ' ALT@Example.Test '));
admin_check($admin->record('officials', $newOfficialId)['alternate_email'] === 'alt@example.test', 'Create and read an individual official.');
$admin->saveOfficial(array('office_id' => $secondOfficeId, 'name' => 'Moved Official'), $newOfficialId);
admin_check((int) $admin->record('officials', $newOfficialId)['office_id'] === $secondOfficeId, 'Move an official without changing its ID.');
admin_rejects(function () use ($admin) { $admin->saveOfficial(array('office_id' => 999999, 'name' => 'Invalid', 'position' => 'Director')); }, 422, 'Official assignment requires a real office.');
admin_rejects(function () use ($admin, $newOfficialId) { $admin->saveOfficial(array('email' => 'invalid'), $newOfficialId); }, 422, 'Invalid official email is rejected.');
$admin->deleteOfficial($newOfficialId);
admin_rejects(function () use ($admin, $newOfficialId) { $admin->record('officials', $newOfficialId); }, 404, 'Deleted officials are no longer readable.');

$accountId = $admin->saveAccount(array('name' => 'Test Staff', 'email' => ' STAFF@Example.Test ', 'roles' => array('sksu_officials'), 'office_id' => $officeId, 'position' => 'Clerk'));
$account = $admin->record('accounts', $accountId);
admin_check($account['email'] === 'staff@example.test' && (int) $account['office_id'] === $officeId, 'Create, read, and assign an approved account.');
admin_rejects(function () use ($admin) { $admin->saveAccount(array('name' => 'Duplicate', 'email' => 'STAFF@EXAMPLE.TEST')); }, 409, 'Duplicate email approval is rejected.');
admin_rejects(function () use ($admin, $officeId) { $admin->deleteOffice($officeId); }, 409, 'Offices with assigned accounts cannot be deleted.');
$db->exec("INSERT INTO pad_admin_test_accounts (acc_name, acc_email) VALUES ('Legacy duplicate', ' Staff@Example.Test ')");
admin_check((int) $admin->record('accounts', $accountId)['source_records'] === 2, 'Legacy duplicate approvals appear as one account.');
$userId = (int) $account['user_id'];
$profile = $db->query('SELECT * FROM users WHERE id = ' . $userId)->fetch();
$oldToken = create_access_token($profile);
$db->exec("UPDATE users SET google_subject = 'old-google-subject', avatar_url = 'https://example.test/avatar.png', last_login_at = NOW() WHERE id = " . $userId);
$admin->saveAccount(array('name' => 'Managed Name', 'email' => 'renamed@example.test'), $accountId);
$profile = $db->query('SELECT * FROM users WHERE id = ' . $userId)->fetch();
admin_check($profile['name'] === 'Managed Name' && $profile['email'] === 'renamed@example.test' && $profile['google_subject'] === null && $profile['avatar_url'] === null, 'Renaming email retains the profile and clears its old Google identity.');
admin_check((int) $db->query("SELECT COUNT(*) FROM pad_admin_test_accounts WHERE acc_email = 'renamed@example.test'")->fetchColumn() === 2, 'Email changes update every duplicate approval.');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $oldToken;
admin_rejects(function () { current_user(); }, 401, 'Old sessions are revoked after an email change.');
sync_google_user(array('email' => 'renamed@example.test', 'name' => 'Google Supplied Name', 'sub' => 'new-google-subject'));
admin_check($db->query('SELECT name FROM users WHERE id = ' . $userId)->fetchColumn() === 'Managed Name', 'Google sign-in preserves the managed display name.');
$admin->saveAccount(array('is_active' => false), $accountId);
admin_rejects(function () { sync_google_user(array('email' => 'renamed@example.test', 'sub' => 'new-google-subject')); }, 403, 'Disabled accounts cannot sign in.');
$admin->saveAccount(array('is_active' => true), $accountId);
$admin->deleteAccount($accountId);
admin_check((int) $db->query("SELECT COUNT(*) FROM pad_admin_test_accounts WHERE acc_email = 'renamed@example.test'")->fetchColumn() === 0, 'Deleting an account removes all duplicate approvals.');
admin_check(!$db->query('SELECT id FROM users WHERE id = ' . $userId)->fetch(), 'Deleting an unused account removes its profile.');

$historyAccountId = $admin->saveAccount(array('name' => 'History Staff', 'email' => 'history@example.test', 'roles' => array('sksu_officials'), 'office_id' => $officeId));
$historyUserId = (int) $admin->record('accounts', $historyAccountId)['user_id'];
$db->prepare("INSERT INTO documents (reference_no, subject, origin_type, source_office_id, recipient_office_id, current_office_id, status,
    file_name, stored_file_name, file_mime, file_size, created_by_user_id, created_at, updated_at)
    VALUES ('PAD-TEST-HISTORY', 'Test document', 'office', ?, ?, ?, 'received', 'test.pdf', 'test.pdf', 'application/pdf', 1, ?, NOW(), NOW())")
    ->execute(array($officeId, $officeId, $officeId, $historyUserId));
$documentId = (int) $db->lastInsertId();
admin_rejects(function () use ($admin, $historyAccountId) { $admin->deleteAccount($historyAccountId); }, 409, 'An account with document history cannot be deleted.');
admin_rejects(function () use ($admin, $officeId) { $admin->deleteOffice($officeId); }, 409, 'An office referenced by a document cannot be deleted.');
$db->prepare("INSERT INTO document_routes (document_id, from_office_id, to_office_id, action, acted_by_user_id, created_at)
    VALUES (?, ?, ?, 'routed', ?, NOW())")->execute(array($documentId, $secondOfficeId, $officeId, $historyUserId));
admin_rejects(function () use ($admin, $secondOfficeId) { $admin->deleteOffice($secondOfficeId); }, 409, 'Historical routing offices cannot be deleted.');
$admin->saveOffice(array('is_active' => false), $officeId);
admin_check((int) $admin->record('offices', $officeId)['is_active'] === 0, 'A used office can be deactivated without losing history.');
admin_rejects(function () use ($admin, $officeId) { $admin->saveAccount(array('name' => 'Invalid assignment', 'email' => 'inactive@example.test', 'roles' => array('sksu_officials'), 'office_id' => $officeId)); }, 422, 'New account assignments cannot use inactive offices.');

$coreId = $admin->saveOffice(array('code' => 'RECORDS', 'name' => 'Records Office'));
admin_rejects(function () use ($admin, $coreId) { $admin->deleteOffice($coreId); }, 422, 'Core offices cannot be deleted.');
admin_rejects(function () use ($admin, $coreId) { $admin->saveOffice(array('is_active' => false), $coreId); }, 422, 'Core offices cannot be deactivated.');
$unusedId = $admin->saveOffice(array('code' => 'TEST-UNUSED', 'name' => 'Unused Office', 'officials' => array(array('name' => 'Disposable Official', 'position' => 'Director'))));
$removedOfficialId = (int) $admin->record('offices', $unusedId)['officials'][0]['id'];
$admin->deleteOffice($unusedId);
admin_rejects(function () use ($admin, $unusedId) { $admin->record('offices', $unusedId); }, 404, 'Deleting an unused office removes its routing destination.');
admin_rejects(function () use ($admin, $removedOfficialId) { $admin->record('officials', $removedOfficialId); }, 404, 'Deleting an unused office removes its officials.');
admin_rejects(function () use ($admin) { $admin->deleteAccount(999999); }, 404, 'Deleting a missing account returns 404.');
admin_rejects(function () use ($admin) { $admin->deleteOffice(999999); }, 404, 'Deleting a missing office returns 404.');
admin_rejects(function () use ($admin) { $admin->deleteOfficial(999999); }, 404, 'Deleting a missing official returns 404.');

// One Google identity can select all four roles, each using its saved routing office.
$admin->saveOfficial(array('office_id' => $secondOfficeId, 'name' => 'SKSU Contact', 'position' => 'Director'));
$padOfficeId = $admin->saveOffice(array('code' => 'TEST-PAD', 'name' => 'Exact President PAD Office'));
$recordsOfficeId = $admin->saveOffice(array('code' => 'TEST-CR', 'name' => 'Exact Central Records Office'));
$fourRoles = array('admin', 'ovp_pad', 'ovp_central_records', 'sksu_officials');
$dualId = $admin->saveAccount(array('name' => 'Four Roles', 'email' => 'dual@example.test', 'roles' => $fourRoles,
    'office_id' => $secondOfficeId, 'ovp_pad_office_id' => $padOfficeId, 'ovp_central_records_office_id' => $recordsOfficeId));
$dual = $admin->record('accounts', $dualId);
admin_check($dual['roles'] === $fourRoles && (int) $dual['ovp_pad_office_id'] === $padOfficeId
    && (int) $dual['ovp_central_records_office_id'] === $recordsOfficeId, 'All four roles persist with independent exact office IDs.');
admin_check($dual['ovp_pad_office_name'] === 'Exact President PAD Office', 'Administration reports the saved PAD office name.');
foreach (array('ovp_pad', 'ovp_central_records') as $role) {
    admin_rejects(function () use ($admin, $role) { $admin->saveAccount(array('name' => 'Missing office', 'email' => $role . '@example.test', 'roles' => array($role))); }, 422, 'New OVP assignments require an explicit office.');
    foreach (array(null, 'invalid', 999999, $officeId) as $invalidOffice) {
        admin_rejects(function () use ($admin, $dualId, $role, $invalidOffice) { $admin->saveAccount(array($role . '_office_id' => $invalidOffice), $dualId); }, 422, 'Missing, malformed, unknown, and inactive OVP offices are rejected.');
    }
}
admin_rejects(function () use ($admin, $dualId, $recordsOfficeId) { $admin->saveAccount(array('ovp_pad_office_id' => $recordsOfficeId), $dualId); }, 422, 'PAD and Central Records cannot share the same office.');
admin_rejects(function () use ($admin, $dualId, $secondOfficeId) { $admin->saveAccount(array('ovp_pad_office_id' => $secondOfficeId), $dualId); }, 422, 'SKSU and OVP assignments use separate offices.');
admin_rejects(function () use ($admin, $padOfficeId) { $admin->deleteOffice($padOfficeId); }, 409, 'A selected OVP office cannot be deleted.');
admin_rejects(function () use ($admin, $padOfficeId) { $admin->saveOffice(array('is_active' => false), $padOfficeId); }, 422, 'A selected OVP office cannot be deactivated.');
admin_check((int) $admin->record('offices', $padOfficeId)['user_count'] === 1, 'Office account counts use saved OVP assignments.');
admin_rejects(function () use ($admin) { $admin->saveAccount(array('name' => 'Missing SKSU office', 'email' => 'no-office@example.test', 'roles' => array('sksu_officials'))); }, 422, 'SKSU assignment requires an office.');
foreach (array(array(), array('admin', 'unknown'), array('sksu_officials', 'sksu_officials'), array('router')) as $invalidRoles) {
    admin_rejects(function () use ($admin, $dualId, $invalidRoles) { $admin->saveAccount(array('roles' => $invalidRoles), $dualId); }, 422, 'Empty, unknown, and duplicate role selections are rejected.');
}
$dualProfile = $db->query('SELECT * FROM users WHERE id = ' . (int) $dual['user_id'])->fetch();
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . create_access_token($dualProfile);
unset($_SERVER['HTTP_X_PAD_ROLE']);
$pendingRole = current_user();
admin_check($pendingRole['active_role'] === null && $pendingRole['roles'] === $fourRoles, 'Multiple roles require a module selection.');
admin_rejects(function () use ($db, $pendingRole) { new Administration($db, $pendingRole); }, 403, 'Unselected multi-role sessions cannot access administration.');
$_SERVER['HTTP_X_PAD_ROLE'] = 'sksu_officials';
$routerSession = current_user();
admin_check($routerSession['active_role'] === 'sksu_officials' && $routerSession['office_name'] === 'Second Office' && !is_administrator($routerSession) && !is_records_user($routerSession), 'SKSU login uses its own office without OVP or administrator privileges.');
admin_rejects(function () use ($db, $routerSession) { new Administration($db, $routerSession); }, 403, 'SKSU mode blocks administration even when the identity also has that role.');
$_SERVER['HTTP_X_PAD_ROLE'] = 'ovp_pad';
$padSession = current_user();
admin_check((int) $padSession['office_id'] === $padOfficeId && $padSession['office_name'] === 'Exact President PAD Office', 'PAD login uses the administrator-selected office instead of the environment default.');
$insert = $db->prepare("INSERT INTO documents (reference_no,subject,document_type,origin_type,source_office_id,current_office_id,pending_office_id,status,created_by_user_id,created_at,updated_at) VALUES ('PAD-2026-000003','Isolated incoming fixture','EMAIL','office',?,?,?,'in_transit',?,NOW(),NOW())");
$insert->execute(array($secondOfficeId, $recordsOfficeId, $padOfficeId, $dual['user_id']));
$incoming = (new DocumentRouting($db, $padSession))->workspace(array('queue' => 'incoming'));
admin_check(count($incoming['data']) === 1 && $incoming['data'][0]['reference_no'] === 'PAD-2026-000003', 'Documents pending at the selected PAD office appear in its Incoming queue.');
$_SERVER['HTTP_X_PAD_ROLE'] = 'ovp_central_records';
$recordsSession = current_user();
admin_check((int) $recordsSession['office_id'] === $recordsOfficeId && is_records_user($recordsSession), 'Central Records login uses its independently selected office.');
admin_check(count((new DocumentRouting($db, $recordsSession))->workspace(array('queue' => 'incoming'))['data']) === 0, 'A document pending at PAD does not appear in Central Records Incoming.');
$newPadId = $admin->saveOffice(array('code' => 'TEST-PAD-TWO', 'name' => 'Another PAD Office'));
$admin->saveAccount(array('ovp_pad_office_id' => $newPadId), $dualId);
$_SERVER['HTTP_X_PAD_ROLE'] = 'ovp_pad';
admin_check((int) current_user()['office_id'] === $newPadId, 'Updating PAD office takes effect on the next request with the existing login token.');
admin_check(count((new DocumentRouting($db, current_user()))->workspace(array('queue' => 'incoming'))['data']) === 0, 'A different PAD assignment does not receive documents addressed to the previous office.');
putenv('PAD_OVP_PAD_OFFICE_ID=' . $coreId);
putenv('PAD_CENTRAL_RECORDS_OFFICE_ID=' . $secondOfficeId);
$db->exec("INSERT INTO users (name,email,role,roles,is_active,created_at,updated_at) VALUES ('Existing OVP account','migrated@example.test','router','ovp_pad,ovp_central_records',1,NOW(),NOW())");
install_account_roles($db);
install_account_roles($db);
$migrated = $db->query("SELECT * FROM users WHERE email = 'migrated@example.test'")->fetch();
admin_check((int) $migrated['ovp_pad_office_id'] === $coreId && (int) $migrated['ovp_central_records_office_id'] === $secondOfficeId,
    'Setup preserves the prior configured OVP offices for existing accounts.');
admin_check((int) $admin->record('accounts', $dualId)['ovp_pad_office_id'] === $newPadId,
    'Rerunning setup preserves an administrator-selected office instead of resetting to defaults.');
$_SERVER['HTTP_X_PAD_ROLE'] = 'admin';
admin_check(is_administrator(current_user()), 'The same Google session can select Administrator.');
$_SERVER['HTTP_X_PAD_ROLE'] = 'invented-role';
admin_rejects(function () { current_user(); }, 403, 'Unassigned role headers are rejected.');
$admin->saveAccount(array('roles' => array('sksu_officials'), 'office_id' => $secondOfficeId), $dualId);
$_SERVER['HTTP_X_PAD_ROLE'] = 'admin';
admin_rejects(function () { current_user(); }, 403, 'Removing a role revokes that mode for an existing token.');
$_SERVER['HTTP_X_PAD_ROLE'] = 'sksu_officials';
admin_check(current_user()['active_role'] === 'sksu_officials', 'Remaining SKSU access stays available after Administrator removal.');
admin_check($admin->record('accounts', $dualId)['ovp_pad_office_id'] === null && $admin->record('accounts', $dualId)['ovp_central_records_office_id'] === null, 'Removing OVP roles clears their assignments.');
admin_rejects(function () use ($admin, $actorAccountId, $secondOfficeId) { $admin->saveAccount(array('roles' => array('sksu_officials'), 'office_id' => $secondOfficeId), $actorAccountId); }, 422, 'Multi-role editing cannot remove your own administrator access.');
$admin->saveAccount(array('roles' => array('admin', 'sksu_officials'), 'office_id' => $secondOfficeId), $actorAccountId);
admin_check($admin->record('accounts', $actorAccountId)['roles'] === array('admin', 'sksu_officials'), 'A signed-in administrator can add SKSU while retaining Administrator.');
unset($_SERVER['HTTP_X_PAD_ROLE']);

echo 'Passed ' . $checks . ' administration checks in an isolated test database.' . PHP_EOL;
} finally {
    $management->exec('DROP DATABASE `' . $testDatabase . '`');
}
