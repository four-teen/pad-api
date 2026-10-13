<?php

final class Administration
{
    private $db;
    private $actor;
    private $table;
    private $emailColumn;

    public function __construct(PDO $db, array $actor)
    {
        if (!is_administrator($actor)) {
            throw new ApiException('Administrator access is required.', 403);
        }
        $this->db = $db;
        $this->actor = $actor;
        $this->table = sql_identifier(env_value('AUTH_ACCOUNT_TABLE', 'tblaccount'), 'AUTH_ACCOUNT_TABLE');
        $this->emailColumn = sql_identifier(env_value('AUTH_ACCOUNT_EMAIL_COLUMN', 'acc_email'), 'AUTH_ACCOUNT_EMAIL_COLUMN');
    }

    public function accounts()
    {
        // Legacy duplicates represent one login identity; retain every original row.
        $accounts = $this->db->query('SELECT a.*, u.id AS user_id, COALESCE(u.role, \'router\') AS role, COALESCE(u.roles, \'router\') AS roles,
            u.office_id, u.ovp_pad_office_id, u.ovp_central_records_office_id,
            pad_office.name AS ovp_pad_office_name, pad_office.campus AS ovp_pad_office_campus, pad_office.code AS ovp_pad_office_code,
            records_office.name AS ovp_central_records_office_name, records_office.campus AS ovp_central_records_office_campus, records_office.code AS ovp_central_records_office_code,
            COALESCE(u.is_active, 1) AS is_active, u.last_login_at, u.created_at, u.updated_at,
            EXISTS (SELECT 1 FROM office_officials f WHERE f.office_id = u.office_id) AS office_has_officials,
            (SELECT COUNT(*) FROM documents d WHERE d.created_by_user_id = u.id) AS document_count,
            (SELECT COUNT(*) FROM document_routes r WHERE r.acted_by_user_id = u.id) AS route_count,
            o.name AS office_name, o.code AS office_code, o.campus AS office_campus, o.is_active AS office_is_active
            FROM (SELECT MIN(acc_id) AS id, MIN(acc_name) AS name, MIN(acc_position) AS position,
                LOWER(TRIM(' . $this->emailColumn . ')) AS email, MIN(campus) AS campus, COUNT(*) AS source_records
                FROM ' . $this->table . ' WHERE TRIM(' . $this->emailColumn . ') <> \'\'
                GROUP BY LOWER(TRIM(' . $this->emailColumn . '))) a
            LEFT JOIN users u ON u.email = a.email LEFT JOIN offices o ON o.id = u.office_id
            LEFT JOIN offices pad_office ON pad_office.id = u.ovp_pad_office_id
            LEFT JOIN offices records_office ON records_office.id = u.ovp_central_records_office_id
            ORDER BY a.name, a.email')->fetchAll();
        foreach ($accounts as &$account) {
            $account['roles'] = account_roles($account);
        }
        unset($account);
        return $accounts;
    }

    public function saveAccount(array $input, $id = null)
    {
        $this->db->beginTransaction();
        try {
            $existing = null;
            $profile = null;
            if ($id !== null) {
                $find = $this->db->prepare('SELECT acc_id, acc_name AS name, acc_position AS position,
                    LOWER(TRIM(' . $this->emailColumn . ')) AS email, campus FROM ' . $this->table . ' WHERE acc_id = ? FOR UPDATE');
                $find->execute(array($id));
                $existing = $find->fetch();
                if (!$existing) $this->fail('Account not found.', 404);
                $find = $this->db->prepare('SELECT * FROM users WHERE email = ? FOR UPDATE');
                $find->execute(array($existing['email']));
                $profile = $find->fetch() ?: null;
            }
            $email = strtolower($this->text($input['email'] ?? ($existing['email'] ?? ''), 'Email', 191, true));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $this->fail('Enter a valid Google email address.');
            $emailChanged = $existing && $email !== $existing['email'];
            if ($emailChanged && $profile && (int) $profile['id'] === (int) $this->actor['id']) {
                $this->fail('You cannot change your own login email while signed in. Ask another administrator.');
            }
            if (!$existing || $emailChanged) {
                $check = $this->db->prepare('SELECT acc_id FROM ' . $this->table . ' WHERE LOWER(TRIM(' . $this->emailColumn . ')) = ? LIMIT 1');
                $check->execute(array($email));
                if ($check->fetch()) $this->fail('That email is already in the account list.', 409);
                $check = $this->db->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
                $check->execute(array($email, $profile['id'] ?? 0));
                if ($check->fetch()) $this->fail('A PAD profile already uses that email.', 409);
            }
            $name = $this->text($input['name'] ?? ($existing['name'] ?? ''), 'Name', 160, true);
            $position = $this->text($input['position'] ?? ($existing['position'] ?? ''), 'Position', 255);
            $campus = $this->text($input['campus'] ?? ($existing['campus'] ?? ''), 'Campus', 80);
            $legacyRole = $input['role'] ?? ($profile['role'] ?? 'router');
            if (!in_array($legacyRole, array('staff', 'records', 'pad', 'admin', 'router'), true)) $this->fail('Select a valid role.');
            if (array_key_exists('roles', $input)) {
                $validRoles = array('admin', 'ovp_pad', 'ovp_central_records', 'sksu_officials');
                if (!is_array($input['roles']) || !$input['roles'] || count($input['roles']) > 4
                    || count(array_unique($input['roles'], SORT_REGULAR)) !== count($input['roles'])) $this->fail('Select one or more account roles.');
                foreach ($input['roles'] as $assigned) if (!is_string($assigned) || !in_array($assigned, $validRoles, true)) $this->fail('Select a valid account role.');
                $roles = array_values(array_intersect($validRoles, $input['roles']));
            } else {
                $roles = array_key_exists('role', $input)
                    ? account_roles(array('role' => $legacyRole, 'office_id' => $input['office_id'] ?? ($profile['office_id'] ?? null)))
                    : account_roles($profile ?: array('role' => $legacyRole));
            }
            if (!$roles) $this->fail('Select at least one account role.');
            $moduleRoles = array_values(array_intersect(array('ovp_pad', 'ovp_central_records', 'sksu_officials'), $roles));
            $role = in_array('admin', $roles, true) ? 'admin' : 'router';
            $active = array_key_exists('is_active', $input) ? $this->active($input['is_active']) : (int) ($profile['is_active'] ?? 1);
            $officeId = array_key_exists('office_id', $input) ? $input['office_id'] : ($profile['office_id'] ?? null);
            $officeId = $officeId === null || $officeId === '' ? null : filter_var($officeId, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
            if ($officeId === false) $this->fail('Select a valid office.');
            $ovpOffices = array('ovp_pad' => null, 'ovp_central_records' => null);
            if ($moduleRoles) {
                $existingRoles = $profile ? account_roles($profile) : array();
                foreach (array('ovp_pad' => 'OVP PAD', 'ovp_central_records' => 'OVP Central Records') as $moduleRole => $title) {
                    if (!in_array($moduleRole, $moduleRoles, true)) continue;
                    $column = $moduleRole . '_office_id';
                    $selected = array_key_exists($column, $input) ? $input[$column]
                        : (in_array($moduleRole, $existingRoles, true) ? ($profile[$column] ?? null) : null);
                    // Legacy account requests keep their office-based role assignment.
                    if ($selected === null && !array_key_exists('roles', $input)) $selected = $officeId;
                    $selected = filter_var($selected, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
                    if ($selected === false) $this->fail('Select a routing office for ' . $title . '.');
                    $office = $this->db->prepare('SELECT is_active, code FROM offices WHERE id = ?');
                    $office->execute(array($selected));
                    $officeState = $office->fetch();
                    if (!$officeState || !(int) $officeState['is_active']) $this->fail('Select an active routing office for ' . $title . '.');
                    if (is_presidential_office($officeState)) $this->fail('OP is monitoring only. Select a receiving office for ' . $title . '.');
                    $ovpOffices[$moduleRole] = (int) $selected;
                }
                if ($ovpOffices['ovp_pad'] && $ovpOffices['ovp_pad'] === $ovpOffices['ovp_central_records']) $this->fail('OVP PAD and OVP Central Records must use different routing offices.');
                if (in_array('sksu_officials', $moduleRoles, true)) {
                    if ($officeId === null) $this->fail('Select an SKSU office listed in Office Officials.');
                    $office = $this->db->prepare('SELECT o.is_active, EXISTS (SELECT 1 FROM office_officials f WHERE f.office_id = o.id) AS has_officials FROM offices o WHERE o.id = ?');
                    $office->execute(array($officeId));
                    $officeState = $office->fetch();
                    if (!$officeState || !(int) $officeState['has_officials']) $this->fail('Select an office listed in Office Officials.');
                    if (!(int) $officeState['is_active']) $this->fail('Select an active SKSU office.');
                    if (in_array((int) $officeId, array_values($ovpOffices), true)) $this->fail('Choose a different office for SKSU Officials than this account\'s OVP role offices.');
                } else {
                    $officeId = null;
                }
            } else {
                $officeId = null;
            }
            if ($profile && (int) $profile['id'] === (int) $this->actor['id'] && (!in_array('admin', $roles, true) || !$active)) {
                $this->fail('You cannot remove your own administrator access.');
            }
            if (!$existing) {
                $insert = $this->db->prepare('INSERT INTO ' . $this->table . ' (acc_name, acc_position, ' . $this->emailColumn . ', acc_type, campus) VALUES (?, ?, ?, \'user\', ?)');
                $insert->execute(array($name, $position, $email, $campus));
                $id = (int) $this->db->lastInsertId();
            } else {
                $update = $this->db->prepare('UPDATE ' . $this->table . ' SET acc_name = ?, acc_position = ?, campus = ?, ' . $this->emailColumn . ' = ? WHERE LOWER(TRIM(' . $this->emailColumn . ')) = ?');
                $update->execute(array($name, $position, $campus, $email, $existing['email']));
            }
            if ($profile) {
                $save = $this->db->prepare('UPDATE users SET name = ?, email = ?, role = ?, roles = ?, office_id = ?, ovp_pad_office_id = ?, ovp_central_records_office_id = ?, is_active = ?, google_subject = ?, avatar_url = ?, last_login_at = ?, updated_at = NOW() WHERE id = ?');
                $save->execute(array($name, $email, $role, implode(',', $roles), $officeId, $ovpOffices['ovp_pad'], $ovpOffices['ovp_central_records'], $active, $emailChanged ? null : $profile['google_subject'], $emailChanged ? null : $profile['avatar_url'], $emailChanged ? null : $profile['last_login_at'], $profile['id']));
            } else {
                $save = $this->db->prepare('INSERT INTO users (name, email, role, roles, office_id, ovp_pad_office_id, ovp_central_records_office_id, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                $save->execute(array($name, $email, $role, implode(',', $roles), $officeId, $ovpOffices['ovp_pad'], $ovpOffices['ovp_central_records'], $active));
            }
            $this->db->commit();
            return (int) $id;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function offices()
    {
        $offices = $this->db->query('SELECT o.*,
            (SELECT COUNT(*) FROM documents d WHERE d.source_office_id = o.id OR d.recipient_office_id = o.id OR d.current_office_id = o.id) AS document_count,
            (SELECT COUNT(*) FROM document_routes r WHERE r.from_office_id = o.id OR r.to_office_id = o.id) AS route_count
            FROM offices o ORDER BY o.is_active DESC, o.campus, o.name')->fetchAll();
        $userRows = $this->db->query('SELECT role, roles, office_id, ovp_pad_office_id, ovp_central_records_office_id,
            EXISTS (SELECT 1 FROM office_officials f WHERE f.office_id = users.office_id) AS office_has_officials FROM users')->fetchAll();
        $assignedAccounts = array();
        $moduleAccounts = array();
        foreach ($userRows as $user) {
            $assigned = array();
            foreach (account_role_office_ids($user) as $role => $officeId) {
                if (!$officeId) continue;
                $assigned[] = $officeId;
                if ($role !== 'sksu_officials') $moduleAccounts[$officeId] = ($moduleAccounts[$officeId] ?? 0) + 1;
            }
            foreach (array_unique($assigned) as $officeId) {
                $assignedAccounts[$officeId] = ($assignedAccounts[$officeId] ?? 0) + 1;
            }
        }
        foreach ($offices as &$office) {
            $office['user_count'] = $assignedAccounts[(int) $office['id']] ?? 0;
            $office['module_role_count'] = $moduleAccounts[(int) $office['id']] ?? 0;
        }
        unset($office);
        $officials = $this->db->query('SELECT * FROM office_officials ORDER BY id')->fetchAll();
        $grouped = array();
        foreach ($officials as $official) $grouped[$official['office_id']][] = $official;
        foreach ($offices as &$office) $office['officials'] = $grouped[$office['id']] ?? array();
        unset($office);
        return $offices;
    }

    public function saveOffice(array $input, $id = null)
    {
        $this->db->beginTransaction();
        try {
            $existing = null;
            if ($id !== null) {
                $find = $this->db->prepare('SELECT * FROM offices WHERE id = ? FOR UPDATE');
                $find->execute(array($id));
                $existing = $find->fetch();
                if (!$existing) $this->fail('Office not found.', 404);
            }
            $values = array_merge($existing ?: array(), $input);
            $code = strtoupper($this->text($values['code'] ?? '', 'Office code', 30, true));
            if (!preg_match('/^[A-Z0-9_-]{2,30}$/D', $code)) $this->fail('Office code must use 2 to 30 letters, numbers, hyphens, or underscores.');
            if ($existing && $code !== $existing['code']) $this->fail('An existing office code cannot be changed.');
            $name = $this->text($values['name'] ?? '', 'Office name', 160, true);
            $description = $this->text($values['description'] ?? '', 'Notes', 1000);
            $domain = $this->text($values['domain'] ?? '', 'Domain', 160);
            $campus = $this->text($values['campus'] ?? '', 'Campus', 80);
            $contact = $this->email($values['contact_email'] ?? '', 'Office contact email');
            $active = $this->active($values['is_active'] ?? 1);
            if (in_array($code, array('RECORDS', 'PAD', 'OP'), true) && !$active) $this->fail('Core offices must remain active for document operations and monitoring.');
            if ($existing && !$active) {
                $assigned = $this->db->prepare('SELECT id FROM users WHERE ovp_pad_office_id = ? OR ovp_central_records_office_id = ? LIMIT 1');
                $assigned->execute(array($id, $id));
                if ($assigned->fetch()) $this->fail('This office is assigned to an OVP role. Reassign those accounts before deactivating it.');
            }
            $check = $this->db->prepare('SELECT id FROM offices WHERE code = ? AND id <> ?');
            $check->execute(array($code, $id ?: 0));
            if ($check->fetch()) $this->fail('That office code is already in use.', 409);
            $officials = null;
            if (array_key_exists('officials', $input)) {
                if (!is_array($input['officials']) || count($input['officials']) > 30) $this->fail('Provide at most 30 officials for an office.');
                $officials = array();
                foreach ($input['officials'] as $official) {
                    if (!is_array($official)) $this->fail('Official details are invalid.');
                    $officialId = empty($official['id']) ? null : filter_var($official['id'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
                    if ($officialId === false) $this->fail('Official ID is invalid.');
                    $officials[] = array('id' => $officialId, 'values' => array(
                        $this->text($official['name'] ?? '', 'Official name', 160, true),
                        $this->text($official['position'] ?? '', 'Official position', 255, true),
                        $this->email($official['email'] ?? '', 'Official email'),
                        $this->email($official['alternate_email'] ?? '', 'Alternate email')
                    ));
                }
            }
            if ($existing) {
                $save = $this->db->prepare('UPDATE offices SET name = ?, description = ?, domain = ?, campus = ?, contact_email = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
                $save->execute(array($name, $description, $domain, $campus, $contact, $active, $id));
            } else {
                $save = $this->db->prepare('INSERT INTO offices (code, name, description, domain, campus, contact_email, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                $save->execute(array($code, $name, $description, $domain, $campus, $contact, $active));
                $id = (int) $this->db->lastInsertId();
            }
            if ($officials !== null) {
                $find = $this->db->prepare('SELECT id FROM office_officials WHERE office_id = ? FOR UPDATE');
                $find->execute(array($id));
                $previousIds = array_map('intval', $find->fetchAll(PDO::FETCH_COLUMN));
                $keptIds = array();
                $insert = $this->db->prepare('INSERT INTO office_officials (office_id, name, position, email, alternate_email) VALUES (?, ?, ?, ?, ?)');
                $update = $this->db->prepare('UPDATE office_officials SET name = ?, position = ?, email = ?, alternate_email = ? WHERE id = ? AND office_id = ?');
                foreach ($officials as $official) {
                    if ($official['id'] !== null) {
                        if (!in_array($official['id'], $previousIds, true) || in_array($official['id'], $keptIds, true)) $this->fail('An official does not belong to this office or was provided twice.');
                        $update->execute(array_merge($official['values'], array($official['id'], $id)));
                        $keptIds[] = $official['id'];
                    } else {
                        $insert->execute(array_merge(array($id), $official['values']));
                    }
                }
                $delete = $this->db->prepare('DELETE FROM office_officials WHERE id = ? AND office_id = ?');
                foreach (array_diff($previousIds, $keptIds) as $removedId) $delete->execute(array($removedId, $id));
            }
            $this->db->commit();
            return (int) $id;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function officials()
    {
        return $this->db->query('SELECT f.*, o.name AS office_name, o.code AS office_code, o.campus AS office_campus,
            o.is_active AS office_active FROM office_officials f JOIN offices o ON o.id = f.office_id ORDER BY f.name, f.id')->fetchAll();
    }

    public function record($kind, $id)
    {
        foreach ($this->$kind() as $record) if ((int) $record['id'] === (int) $id) return $record;
        $this->fail('Record not found.', 404);
    }

    public function saveOfficial(array $input, $id = null)
    {
        $this->db->beginTransaction();
        try {
            $existing = null;
            if ($id !== null) {
                $find = $this->db->prepare('SELECT * FROM office_officials WHERE id = ? FOR UPDATE');
                $find->execute(array($id));
                $existing = $find->fetch();
                if (!$existing) $this->fail('Official not found.', 404);
            }
            $values = array_merge($existing ?: array(), $input);
            $officeId = filter_var($values['office_id'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
            if (!$officeId) $this->fail('Select a routing office.');
            $find = $this->db->prepare('SELECT id FROM offices WHERE id = ? FOR UPDATE');
            $find->execute(array($officeId));
            if (!$find->fetch()) $this->fail('Routing office not found.', 422);
            if (!$existing || (int) $existing['office_id'] !== $officeId) {
                $count = $this->db->prepare('SELECT COUNT(*) FROM office_officials WHERE office_id = ?');
                $count->execute(array($officeId));
                if ((int) $count->fetchColumn() >= 30) $this->fail('Provide at most 30 officials for an office.');
            }
            $parameters = array($officeId, $this->text($values['name'] ?? '', 'Official name', 160, true),
                $this->text($values['position'] ?? '', 'Official position', 255, true),
                $this->email($values['email'] ?? '', 'Official email'), $this->email($values['alternate_email'] ?? '', 'Alternate email'));
            if ($existing) {
                $save = $this->db->prepare('UPDATE office_officials SET office_id = ?, name = ?, position = ?, email = ?, alternate_email = ? WHERE id = ?');
                $save->execute(array_merge($parameters, array($id)));
            } else {
                $save = $this->db->prepare('INSERT INTO office_officials (office_id, name, position, email, alternate_email) VALUES (?, ?, ?, ?, ?)');
                $save->execute($parameters);
                $id = (int) $this->db->lastInsertId();
            }
            $this->db->commit();
            return (int) $id;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function deleteAccount($id)
    {
        $this->db->beginTransaction();
        try {
            $find = $this->db->prepare('SELECT LOWER(TRIM(' . $this->emailColumn . ')) AS email FROM ' . $this->table . ' WHERE acc_id = ? FOR UPDATE');
            $find->execute(array($id));
            $email = $find->fetchColumn();
            if ($email === false) $this->fail('Account not found.', 404);
            $find = $this->db->prepare('SELECT id FROM users WHERE email = ? FOR UPDATE');
            $find->execute(array($email));
            $userId = $find->fetchColumn();
            if ($userId !== false) {
                if ((int) $userId === (int) $this->actor['id']) $this->fail('You cannot delete your own signed-in account.');
                foreach (array('documents' => 'created_by_user_id', 'document_routes' => 'acted_by_user_id') as $table => $column) {
                    $check = $this->db->prepare('SELECT id FROM ' . $table . ' WHERE ' . $column . ' = ? LIMIT 1');
                    $check->execute(array($userId));
                    if ($check->fetch()) $this->fail('This account has document history. Disable its access instead of deleting it.', 409);
                }
                $this->db->prepare('DELETE FROM users WHERE id = ?')->execute(array($userId));
            }
            // All duplicate approvals must be removed so Google login stays revoked.
            $this->db->prepare('DELETE FROM ' . $this->table . ' WHERE LOWER(TRIM(' . $this->emailColumn . ')) = ?')->execute(array($email));
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($error instanceof PDOException && $error->getCode() === '23000') $this->fail('This account is in use. Disable its access instead.', 409);
            throw $error;
        }
    }

    public function deleteOffice($id)
    {
        $this->db->beginTransaction();
        try {
            $find = $this->db->prepare('SELECT code FROM offices WHERE id = ? FOR UPDATE');
            $find->execute(array($id));
            $code = $find->fetchColumn();
            if ($code === false) $this->fail('Office not found.', 404);
            if (in_array($code, array('RECORDS', 'PAD', 'OP'), true)) $this->fail('Core routing offices cannot be deleted.');
            $checks = array(
                'SELECT id FROM users WHERE office_id = ? OR ovp_pad_office_id = ? OR ovp_central_records_office_id = ? LIMIT 1' => array($id, $id, $id),
                'SELECT id FROM documents WHERE source_office_id = ? OR recipient_office_id = ? OR current_office_id = ? LIMIT 1' => array($id, $id, $id),
                'SELECT id FROM document_routes WHERE from_office_id = ? OR to_office_id = ? LIMIT 1' => array($id, $id)
            );
            foreach ($checks as $sql => $parameters) {
                $check = $this->db->prepare($sql);
                $check->execute($parameters);
                if ($check->fetch()) $this->fail('This office has assigned accounts or document history. Reassign the accounts or deactivate the office instead.', 409);
            }
            $this->db->prepare('DELETE FROM office_officials WHERE office_id = ?')->execute(array($id));
            $this->db->prepare('DELETE FROM offices WHERE id = ?')->execute(array($id));
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($error instanceof PDOException && $error->getCode() === '23000') $this->fail('This office is in use. Deactivate it instead.', 409);
            throw $error;
        }
    }

    public function deleteOfficial($id)
    {
        $delete = $this->db->prepare('DELETE FROM office_officials WHERE id = ?');
        $delete->execute(array($id));
        if (!$delete->rowCount()) $this->fail('Official not found.', 404);
    }

    private function text($value, $label, $max, $required = false)
    {
        if (!is_string($value) && $value !== null) $this->fail($label . ' must be text.');
        $value = trim((string) $value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if (($required && $value === '') || $length > $max) $this->fail($label . ($required ? ' is required and' : '') . ' must be ' . $max . ' characters or fewer.');
        return $value;
    }

    private function email($value, $label)
    {
        $value = strtolower($this->text($value, $label, 191));
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) $this->fail($label . ' is invalid.');
        return $value;
    }

    private function active($value)
    {
        if (!in_array($value, array(0, 1, '0', '1', false, true), true)) $this->fail('Select an active or disabled status.');
        return (int) (bool) $value;
    }

    private function fail($message, $status = 422)
    {
        throw new ApiException($message, $status);
    }
}
