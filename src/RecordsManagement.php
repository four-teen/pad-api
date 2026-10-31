<?php

function install_records_management(PDO $db)
{
    // No foreign keys: correction evidence survives deletion of an unsent document,
    // removal of an account, and administrative clearing of operational records.
    $db->exec("CREATE TABLE IF NOT EXISTS record_changes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        entity_type VARCHAR(20) NOT NULL,
        entity_id INT UNSIGNED NOT NULL,
        entity_reference VARCHAR(255) NOT NULL,
        action VARCHAR(40) NOT NULL,
        before_data MEDIUMTEXT NOT NULL,
        after_data MEDIUMTEXT NOT NULL,
        reason VARCHAR(2000) NOT NULL,
        actor_user_id INT UNSIGNED NOT NULL,
        actor_name VARCHAR(255) NOT NULL,
        actor_role VARCHAR(40) NOT NULL,
        actor_office_id INT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL,
        KEY record_changes_entity_index (entity_type, entity_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

final class RecordsManagement
{
    private $db;
    private $actor;
    private $ready;
    private $personnelReady;

    public function __construct(PDO $db, array $actor) { $this->db = $db; $this->actor = $actor; }

    public function ready()
    {
        if ($this->ready === null) $this->ready = (bool) $this->db->query("SHOW TABLES LIKE 'record_changes'")->fetchColumn();
        return $this->ready;
    }

    public function isCentralRecords()
    {
        return ($this->actor['active_role'] ?? '') === 'ovp_central_records'
            && in_array('ovp_central_records', account_roles($this->actor), true);
    }

    public function assertReady()
    {
        if (!$this->ready()) throw new ApiException('Records management setup is required. Run scripts/setup-records-management.php.', 503);
    }

    public function assertAccess()
    {
        if (!$this->isCentralRecords()) throw new ApiException('Only the active OVP Central Records role can manage records catalogs or correct particulars.', 403);
        $query = $this->db->prepare('SELECT id, code FROM offices WHERE id = ? AND is_active = 1');
        $query->execute(array($this->actor['office_id'] ?? 0));
        $office = $query->fetch();
        if (!$office || is_presidential_office($office)) throw new ApiException('An active Central Records routing office is required.', 403);
        $this->assertReady();
    }

    public static function version(array $data) { return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }

    public static function checkVersion($input, $actual)
    {
        if (!is_string($input) || !hash_equals($actual, $input)) throw new ApiException('This record changed. Reopen it before saving your changes.', 409);
    }

    public function audit($type, $id, $reference, $action, array $before, array $after, $reason)
    {
        $this->assertReady();
        $query = $this->db->prepare('INSERT INTO record_changes (entity_type, entity_id, entity_reference, action, before_data, after_data,
            reason, actor_user_id, actor_name, actor_role, actor_office_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $query->execute(array($type, $id, $reference, $action, json_encode($before, JSON_UNESCAPED_UNICODE), json_encode($after, JSON_UNESCAPED_UNICODE),
            $reason, $this->actor['id'], $this->actor['name'], $this->actor['active_role'], $this->actor['office_id'] ?? null));
    }

    public function history($type, $id)
    {
        if (!$this->ready()) return array();
        $query = $this->db->prepare('SELECT id, action, before_data, after_data, reason, actor_name, actor_role, created_at FROM record_changes
            WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC');
        $query->execute(array($type, $id));
        $rows = $query->fetchAll();
        foreach ($rows as &$row) {
            $row['before'] = json_decode($row['before_data'], true);
            $row['after'] = json_decode($row['after_data'], true);
            unset($row['before_data'], $row['after_data']);
        }
        return $rows;
    }

    public static function reason(array $input)
    {
        $value = $input['reason'] ?? '';
        if (!is_string($value) || trim($value) === '' || preg_match_all('/./us', $value, $matches) === false || count($matches[0]) > 2000) {
            throw new ApiException('Enter a change reason of up to 2,000 characters.', 422);
        }
        return trim($value);
    }

    private function table($catalog)
    {
        $tables = array('personnel' => 'document_personnel', 'sources' => 'document_sources');
        if (!isset($tables[$catalog])) throw new ApiException('Catalog not found.', 404);
        return $tables[$catalog];
    }

    private function catalogRow(array $row)
    {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['version'] = self::version($row);
        return $row;
    }

    private function catalogColumns($catalog)
    {
        if ($catalog !== 'personnel') return 'id, name, is_active';
        if ($this->personnelReady === null) {
            $columns = array_column($this->db->query('SHOW COLUMNS FROM document_personnel')->fetchAll(), 'Field');
            $this->personnelReady = !array_diff(array('email', 'position', 'campus'), $columns);
        }
        if (!$this->personnelReady) throw new ApiException('Personnel details setup is required. Run scripts/setup-personnel-details.php.', 503);
        return 'id, name, is_active, email, position, campus';
    }

    private function personnelDetails(array $input, array $before)
    {
        $details = array();
        foreach (array('email' => 191, 'position' => 255, 'campus' => 80) as $field => $limit) {
            if ($before && !array_key_exists($field, $input)) {
                $details[$field] = $before[$field];
                continue;
            }
            $value = $input[$field] ?? null;
            if (!is_string($value) || trim($value) === '' || preg_match_all('/./us', $value, $characters) === false || count($characters[0]) > $limit) {
                throw new ApiException('Enter a ' . $field . ' of up to ' . $limit . ' characters.', 422);
            }
            $value = trim($value);
            if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) throw new ApiException('Enter a valid personnel email address.', 422);
            if ($field === 'campus' && in_array(strtolower($value), array('unknown', 'n/a', 'na'), true)) throw new ApiException('Enter the actual campus for this person.', 422);
            $details[$field] = $value;
        }
        if ($details['email'] !== null && (!$before || $details['email'] !== $before['email'])) {
            $duplicate = $this->db->prepare('SELECT id FROM document_personnel WHERE LOWER(TRIM(email)) = LOWER(?) AND id <> ? LIMIT 1');
            $duplicate->execute(array($details['email'], $before['id'] ?? 0));
            if ($duplicate->fetchColumn()) throw new ApiException('That email address already belongs to a personnel entry. Edit or reactivate that entry.', 409);
        }
        return $details;
    }

    public function catalog($catalog)
    {
        $this->assertAccess();
        $rows = $this->db->query('SELECT ' . $this->catalogColumns($catalog) . ' FROM ' . $this->table($catalog) . ' ORDER BY name, id')->fetchAll();
        return array('data' => array_map(array($this, 'catalogRow'), $rows));
    }

    public function saveCatalog($catalog, array $input, $id = null)
    {
        $this->assertAccess();
        $table = $this->table($catalog);
        $columns = $this->catalogColumns($catalog);
        $allowed = array('name', 'is_active', 'version', 'reason');
        if ($catalog === 'personnel') $allowed = array_merge($allowed, array('email', 'position', 'campus'));
        if (array_diff(array_keys($input), $allowed)) throw new ApiException('This request contains fields unavailable for this catalog.', 422);
        $reason = self::reason($input);
        $name = $input['name'] ?? null;
        $limit = $catalog === 'personnel' ? 160 : 255;
        if (!is_string($name) || trim($name) === '' || preg_match_all('/./us', $name, $matches) === false || count($matches[0]) > $limit) throw new ApiException('Enter a valid name of up to ' . $limit . ' characters.', 422);
        $name = trim($name);
        $active = array_key_exists('is_active', $input) ? $input['is_active'] : null;
        if (array_key_exists('is_active', $input) && !is_bool($active)) throw new ApiException('Active status must be true or false.', 422);
        $this->db->beginTransaction();
        try {
            $before = array();
            if ($id !== null) {
                $query = $this->db->prepare('SELECT ' . $columns . ' FROM ' . $table . ' WHERE id = ? FOR UPDATE');
                $query->execute(array($id));
                $before = $query->fetch();
                if (!$before) throw new ApiException('Catalog entry not found.', 404);
                $before = $this->catalogRow($before);
                self::checkVersion($input['version'] ?? null, $before['version']);
            }
            if ($active === null) $active = $before ? $before['is_active'] : true;
            $details = $catalog === 'personnel' ? $this->personnelDetails($input, $before) : array();
            // Existing duplicate legacy names remain usable; avoid introducing new duplicates.
            if (!$before || $name !== $before['name']) {
                $duplicate = $this->db->prepare('SELECT id FROM ' . $table . ' WHERE LOWER(TRIM(name)) = LOWER(?) AND id <> ? LIMIT 1');
                $duplicate->execute(array($name, $id ?? 0));
                if ($duplicate->fetchColumn()) throw new ApiException('That name already exists. Edit or reactivate the existing entry.', 409);
            }
            if ($id === null) {
                $query = $this->db->prepare('INSERT INTO ' . $table . ' (name, is_active' . ($details ? ', email, position, campus' : '') . ') VALUES (?, ?' . ($details ? ', ?, ?, ?' : '') . ')');
                $query->execute(array_merge(array($name, (int) $active), array_values($details)));
                $id = (int) $this->db->lastInsertId();
            } else {
                $this->db->prepare('UPDATE ' . $table . ' SET name = ?, is_active = ?' . ($details ? ', email = ?, position = ?, campus = ?' : '') . ' WHERE id = ?')
                    ->execute(array_merge(array($name, (int) $active), array_values($details), array($id)));
            }
            $after = $this->catalogRow(array_merge(array('id' => $id, 'name' => $name, 'is_active' => $active), $details));
            if ($before !== $after) $this->audit($catalog, $id, $name, $before ? 'catalog_updated' : 'catalog_created', $before, $after, $reason);
            $this->db->commit();
            return array('data' => $after, 'message' => 'Catalog entry saved.');
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }
}
