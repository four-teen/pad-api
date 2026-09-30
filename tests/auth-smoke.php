<?php

require dirname(__DIR__) . '/src/bootstrap.php';

$checks = 0;
function check($condition, $message)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function rejects(callable $action, $status, $message)
{
    try {
        $action();
    } catch (AuthenticationException $error) {
        check($error->getCode() === $status, $message . ' (HTTP status)');
        return;
    }
    throw new RuntimeException($message);
}

// Locally generated keys and invented identities: no Google account is used.
$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
if (!$key) {
    throw new RuntimeException('OpenSSL could not generate the test key. Check OPENSSL_CONF.');
}
$publicKey = openssl_pkey_get_details($key)['key'];
$clientId = 'pad-test.apps.googleusercontent.com';
$verifier = new GoogleIdentity($clientId, function () use ($publicKey) { return array('test-key' => $publicKey); });
$claims = array(
    'iss' => 'https://accounts.google.com', 'aud' => $clientId,
    'sub' => 'pad-test-subject', 'email' => 'allowed@example.test', 'email_verified' => true,
    'iat' => time(), 'exp' => time() + 3600, 'name' => 'PAD Test User'
);
$sign = function ($payload, $header = null) use ($key) {
    $header = $header ?? array('alg' => 'RS256', 'kid' => 'test-key');
    $data = base64url_encode(json_encode($header)) . '.' . base64url_encode(json_encode($payload));
    openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
    return $data . '.' . base64url_encode($signature);
};
check($verifier->verify($sign($claims))['sub'] === $claims['sub'], 'Accept a correctly signed Google identity.');
foreach (array(
    'wrong audience' => array('aud' => 'another-client'),
    'wrong issuer' => array('iss' => 'https://example.test'),
    'expired identity' => array('exp' => time() - 1),
    'future identity' => array('iat' => time() + 3600),
    'unverified email' => array('email_verified' => false),
    'missing subject' => array('sub' => ''),
    'invalid email' => array('email' => 'not-an-email')
) as $label => $changes) {
    rejects(function () use ($verifier, $sign, $claims, $changes) { $verifier->verify($sign(array_merge($claims, $changes))); }, 401, 'Reject ' . $label);
}
foreach (array(array('alg' => 'HS256', 'kid' => 'test-key'), array('alg' => 'RS256', 'kid' => 'unknown')) as $header) {
    rejects(function () use ($verifier, $sign, $claims, $header) { $verifier->verify($sign($claims, $header)); }, 401, 'Reject unsupported signing key or algorithm.');
}
$parts = explode('.', $sign($claims));
$parts[1] = base64url_encode(json_encode(array_merge($claims, array('email' => 'attacker@example.test'))));
rejects(function () use ($verifier, $parts) { $verifier->verify(implode('.', $parts)); }, 401, 'Reject tampered claims.');
rejects(function () use ($verifier) { $verifier->verify('not-a-google-credential'); }, 401, 'Reject malformed credentials.');

// Connection-scoped temporary tables shadow the real tables and vanish at exit.
// No existing account, user, office, or document is changed by this test.
$db = database();
foreach (array('users', 'offices') as $table) {
    $definition = $db->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
    // MySQL temporary tables cannot use foreign keys or LIKE their own name.
    $definition = preg_replace('/,\n\s+CONSTRAINT[^\n]+/', '', $definition);
    $db->exec(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition));
}
$db->exec('CREATE TEMPORARY TABLE pad_auth_test_accounts (acc_email VARCHAR(191))');
putenv('AUTH_ACCOUNT_TABLE=pad_auth_test_accounts');
putenv('AUTH_ACCOUNT_EMAIL_COLUMN=acc_email');
putenv('FIRST_ADMIN_EMAIL=admin@example.test');
$db->exec("INSERT INTO pad_auth_test_accounts VALUES (' Allowed@Example.Test '), ('admin@example.test'), ('changed@example.test')");

$id = sync_google_user($verifier->verify($sign($claims)));
$user = $db->query('SELECT * FROM users WHERE id = ' . (int) $id)->fetch();
check($user['role'] === 'staff' && $user['email'] === 'allowed@example.test', 'An allowed email creates a staff profile.');
check((int) sync_google_user($claims) === (int) $id, 'Repeated login reuses the profile.');
check((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'Repeated login does not duplicate profiles.');

rejects(function () use ($claims) { sync_google_user(array_merge($claims, array('email' => 'denied@example.test', 'sub' => 'denied-subject'))); }, 403, 'An unlisted Google account is denied.');
check((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'Denied login never creates a PAD profile.');
rejects(function () use ($claims) { sync_google_user(array_merge($claims, array('sub' => 'different-subject'))); }, 403, 'A different Google subject cannot take over an existing email.');

$token = create_access_token($user);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
check((int) current_user()['id'] === (int) $id, 'A signed PAD session authenticates.');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token . 'tampered';
rejects(function () { current_user(); }, 401, 'Tampered PAD sessions are rejected.');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
$db->exec('DELETE FROM pad_auth_test_accounts WHERE TRIM(acc_email) = \'Allowed@Example.Test\'');
rejects(function () { current_user(); }, 401, 'Removing an allow-listed email revokes an existing session.');
$db->exec("INSERT INTO pad_auth_test_accounts VALUES ('allowed@example.test')");

$db->exec('UPDATE users SET is_active = 0 WHERE id = ' . (int) $id);
rejects(function () use ($claims) { sync_google_user($claims); }, 403, 'Inactive users cannot sign in.');
rejects(function () { current_user(); }, 401, 'Inactive users cannot retain sessions.');
$db->exec('UPDATE users SET is_active = 1 WHERE id = ' . (int) $id);
check((int) sync_google_user(array_merge($claims, array('email' => 'changed@example.test'))) === (int) $id, 'An approved email change retains the same Google profile.');
check($db->query('SELECT email FROM users WHERE id = ' . (int) $id)->fetchColumn() === 'changed@example.test', 'The approved new email is saved.');

$adminClaims = array_merge($claims, array('email' => 'admin@example.test', 'sub' => 'admin-subject'));
$adminId = sync_google_user($adminClaims);
check($db->query('SELECT role FROM users WHERE id = ' . (int) $adminId)->fetchColumn() === 'admin', 'The configured, approved first administrator is bootstrapped.');
$db->exec("UPDATE users SET role = 'staff' WHERE id = " . (int) $adminId);
sync_google_user($adminClaims);
check($db->query('SELECT role FROM users WHERE id = ' . (int) $adminId)->fetchColumn() === 'staff', 'Subsequent login respects administrator role changes.');
$db->exec("UPDATE users SET role = 'admin', google_subject = NULL WHERE id = " . (int) $adminId);
check((int) sync_google_user($adminClaims) === (int) $adminId, 'First Google login links a pre-created administrator profile.');
$linkedAdmin = $db->query('SELECT role, google_subject FROM users WHERE id = ' . (int) $adminId)->fetch();
check($linkedAdmin['role'] === 'admin' && $linkedAdmin['google_subject'] === $adminClaims['sub'], 'Linking Google preserves the assigned administrator role.');
check((int) $db->query('SELECT COUNT(*) FROM pad_auth_test_accounts')->fetchColumn() === 3, 'Login does not modify the account list.');

echo 'Passed ' . $checks . ' authentication checks using temporary tables.' . PHP_EOL;
