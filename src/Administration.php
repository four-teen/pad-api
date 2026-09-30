<?php

final class Administration
{
    private $db;
    private $actor;
    private $table;
    private $emailColumn;

    public function __construct(PDO $db, array $actor)
    {
        if (($actor['role'] ?? '') !== 'admin') {
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
        return $this->db->query('SELECT a.*, u.id AS user_id, COALESCE(u.role, \'staff\') AS role,
            u.office_id, COALESCE(u.is_active, 1) AS is_active, u.last_login_at,
            o.name AS office_name, o.campus AS office_campus
            FROM (SELECT MIN(acc_id) AS id, MIN(acc_name) AS name, MIN(acc_position) AS position,
                LOWER(TRIM(' . $this->emailColumn . ')) AS email, MIN(campus) AS campus, COUNT(*) AS source_records
                FROM ' . $this->table . ' WHERE TRIM(' . $this->emailColumn . ') <> \'\'
                GROUP BY LOWER(TRIM(' . $this->emailColumn . '))) a
            LEFT JOIN users u ON u.email = a.email LEFT JOIN offices o ON o.id = u.office_id
            ORDER BY a.name, a.email')->fetchAll();
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
            if ($existing && $email !== $existing['email']) $this->fail('An existing login email cannot be changed. Add the new email as a separate account.');
            $name = $this->text($input['name'] ?? ($existing['name'] ?? ''), 'Name', 160, true);
            $position = $this->text($input['position'] ?? ($existing['position'] ?? ''), 'Position', 255);
            $campus = $this->text($input['campus'] ?? ($existing['campus'] ?? ''), 'Campus', 80);
            $role = $input['role'] ?? ($profile['role'] ?? 'staff');
            if (!in_array($role, array('staff', 'records', 'pad', 'admin'), true)) $this->fail('Select a valid role.');
            $active = array_key_exists('is_active', $input) ? $this->active($input['is_active']) : (int) ($profile['is_active'] ?? 1);
            $officeId = array_key_exists('office_id', $input) ? $input['office_id'] : ($profile['office_id'] ?? null);
            $officeId = $officeId === null || $officeId === '' ? null : filter_var($officeId, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
            if ($officeId === false) $this->fail('Select a valid office.');
            if ($officeId !== null) {
                $office = $this->db->prepare('SELECT is_active FROM offices WHERE id = ?');
                $office->execute(array($officeId));
                $officeActive = $office->fetchColumn();
                if ($officeActive === false || (!(int) $officeActive && (int) ($profile['office_id'] ?? 0) !== $officeId)) $this->fail('Select an active routing office.');
            }
            if ($profile && (int) $profile['id'] === (int) $this->actor['id'] && ($role !== 'admin' || !$active)) {
                $this->fail('You cannot remove your own administrator access.');
            }
            if (!$existing) {
                $find = $this->db->prepare('SELECT acc_id FROM ' . $this->table . ' WHERE LOWER(TRIM(' . $this->emailColumn . ')) = ? LIMIT 1');
                $find->execute(array($email));
                if ($find->fetch()) $this->fail('That email is already in the account list.', 409);
                $find = $this->db->prepare('SELECT id FROM users WHERE email = ?');
                $find->execute(array($email));
                if ($find->fetch()) $this->fail('A PAD profile already uses that email. Restore its account-list record before editing access.', 409);
                $insert = $this->db->prepare('INSERT INTO ' . $this->table . ' (acc_name, acc_position, ' . $this->emailColumn . ', acc_type, campus) VALUES (?, ?, ?, \'user\', ?)');
                $insert->execute(array($name, $position, $email, $campus));
                $id = (int) $this->db->lastInsertId();
            } else {
                $update = $this->db->prepare('UPDATE ' . $this->table . ' SET acc_name = ?, acc_position = ?, campus = ? WHERE LOWER(TRIM(' . $this->emailColumn . ')) = ?');
                $update->execute(array($name, $position, $campus, $email));
            }
            if ($profile) {
                $save = $this->db->prepare('UPDATE users SET role = ?, office_id = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
                $save->execute(array($role, $officeId, $active, $profile['id']));
            } else {
                $save = $this->db->prepare('INSERT INTO users (name, email, role, office_id, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
                $save->execute(array($name, $email, $role, $officeId, $active));
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
        $offices = $this->db->query('SELECT o.*, (SELECT COUNT(*) FROM users u WHERE u.office_id = o.id) AS user_count
            FROM offices o ORDER BY o.is_active DESC, o.campus, o.name')->fetchAll();
        $officials = $this->db->query('SELECT * FROM office_officials ORDER BY id')->fetchAll();
        $grouped = array();
        foreach ($officials as $official) $grouped[$official['office_id']][] = $official;
        foreach ($offices as &$office) $office['officials'] = $grouped[$office['id']] ?? array();
        unset($office);
        return $offices;
    }

    public function saveOffice(array $input, $id = null)
    {
        $existing = null;
        if ($id !== null) {
            $find = $this->db->prepare('SELECT * FROM offices WHERE id = ?');
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
        if (in_array($code, array('RECORDS', 'PAD', 'OP'), true) && !$active) $this->fail('Records, PAD, and the Office of the President must remain available for routing.');
        $check = $this->db->prepare('SELECT id FROM offices WHERE code = ? AND id <> ?');
        $check->execute(array($code, $id ?: 0));
        if ($check->fetch()) $this->fail('That office code is already in use.', 409);
        $officials = null;
        if (array_key_exists('officials', $input)) {
            if (!is_array($input['officials']) || count($input['officials']) > 30) $this->fail('Provide at most 30 officials for an office.');
            $officials = array();
            foreach ($input['officials'] as $official) {
                if (!is_array($official)) $this->fail('Official details are invalid.');
                $officials[] = array(
                    $this->text($official['name'] ?? '', 'Official name', 160, true),
                    $this->text($official['position'] ?? '', 'Official position', 255, true),
                    $this->email($official['email'] ?? '', 'Official email'),
                    $this->email($official['alternate_email'] ?? '', 'Alternate email')
                );
            }
        }
        $this->db->beginTransaction();
        try {
            if ($existing) {
                $save = $this->db->prepare('UPDATE offices SET name = ?, description = ?, domain = ?, campus = ?, contact_email = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
                $save->execute(array($name, $description, $domain, $campus, $contact, $active, $id));
            } else {
                $save = $this->db->prepare('INSERT INTO offices (code, name, description, domain, campus, contact_email, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                $save->execute(array($code, $name, $description, $domain, $campus, $contact, $active));
                $id = (int) $this->db->lastInsertId();
            }
            if ($officials !== null) {
                $this->db->prepare('DELETE FROM office_officials WHERE office_id = ?')->execute(array($id));
                $insert = $this->db->prepare('INSERT INTO office_officials (office_id, name, position, email, alternate_email) VALUES (?, ?, ?, ?, ?)');
                foreach ($officials as $official) $insert->execute(array_merge(array($id), $official));
            }
            $this->db->commit();
            return (int) $id;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
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
