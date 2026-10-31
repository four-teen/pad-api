<?php

function install_database_maintenance(PDO $db)
{
    $db->exec("CREATE TABLE IF NOT EXISTS admin_maintenance_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        request_id CHAR(32) NOT NULL,
        actor_user_id INT UNSIGNED NOT NULL,
        actor_email VARCHAR(191) NOT NULL,
        target_table VARCHAR(64) NOT NULL,
        reason VARCHAR(1000) NOT NULL,
        cleared_counts LONGTEXT NOT NULL,
        backup_file VARCHAR(80) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY maintenance_request_unique (request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Table-level operations intentionally stay separate from ordinary document deletion.
final class DatabaseMaintenance
{
    private $db;
    private $actor;
    private $tables;
    private $foreignKeys;
    private $catalog = array(
        'documents' => 'Document registry',
        'document_routes' => 'Routing history',
        'document_pad_reviews' => 'PAD review decisions',
        'document_people' => 'Document personnel links',
        'document_travel_details' => 'Travel-order details',
        'document_types' => 'Document type catalog',
        'document_sources' => 'Document source catalog',
        'document_personnel' => 'Personnel catalog',
        'office_officials' => 'Office officials',
        'offices' => 'Routing offices'
    );
    private $documentTables = array('document_pad_reviews', 'document_people', 'document_travel_details', 'document_routes', 'documents');

    public function __construct(PDO $db, array $actor)
    {
        if (!is_administrator($actor)) throw new ApiException('Administrator access is required.', 403);
        $this->db = $db;
        $this->actor = $actor;
        $this->tables = array();
        foreach ($db->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll() as $row) {
            $this->tables[$row['TABLE_NAME']] = $row['ENGINE'];
        }
        $this->foreignKeys = $db->query("SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION")->fetchAll();
    }

    private function identifier($name) { return '`' . str_replace('`', '``', $name) . '`'; }
    private function encode($value)
    {
        $encoded = json_encode($value);
        if ($encoded === false) throw new ApiException('The selected data could not be encoded for a recovery backup.', 503);
        return $encoded;
    }
    private function count($name) { return (int) $this->db->query('SELECT COUNT(*) FROM ' . $this->identifier($name))->fetchColumn(); }
    private function operationTables($name) { return $name === 'documents' ? array_values(array_intersect($this->documentTables, array_keys($this->tables))) : array($name); }
    private function group($name)
    {
        if ($name === env_value('AUTH_ACCOUNT_TABLE', 'tblaccount')) return 'system';
        if (in_array($name, $this->documentTables, true)) return 'operations';
        return isset($this->catalog[$name]) ? 'preparation' : 'system';
    }

    private function blockers($name, $lock = false)
    {
        if (!isset($this->tables[$name])) return array('Table not found.');
        if ($name === 'users' || $name === env_value('AUTH_ACCOUNT_TABLE', 'tblaccount')) return array('Login profiles and approved Google accounts are protected. Use User accounts to manage access.');
        if ($name === 'document_number_series') return array('Document-number counters are retained so cleared file codes cannot be reused.');
        if ($name === 'admin_maintenance_events') return array('The administrative operation log is protected.');
        if ($name === 'record_changes') return array('Document corrections and catalog change history are protected.');
        if (!isset($this->catalog[$name])) return array('This table is outside the managed PAD data tables.');
        if ($this->tables[$name] !== 'InnoDB') return array('This table does not support transactional clearing.');
        if (in_array($name, array('document_routes', 'document_pad_reviews'), true) && isset($this->tables['documents']) && $this->count('documents') > 0) {
            return array('Routing history and decisions must be cleared together with the document registry. Use Clear all document records.');
        }
        $selected = $this->operationTables($name);
        foreach ($selected as $table) if ($this->tables[$table] !== 'InnoDB') return array('All affected tables must support transactional clearing.');
        $database = $this->db->query('SELECT DATABASE()')->fetchColumn();
        $groups = array();
        foreach ($this->foreignKeys as $key) {
            if (!in_array($key['REFERENCED_TABLE_NAME'], $selected, true) || in_array($key['TABLE_NAME'], $selected, true) && $key['TABLE_SCHEMA'] === $database) continue;
            $group = $key['TABLE_SCHEMA'] . '.' . $key['TABLE_NAME'] . '.' . $key['CONSTRAINT_NAME'];
            $groups[$group][] = $key;
        }
        $blocked = array();
        foreach ($groups as $keys) {
            $key = $keys[0];
            if ($key['TABLE_SCHEMA'] !== $database) { $blocked[] = 'Referenced by another database; this operation is unavailable.'; continue; }
            $on = array();
            foreach ($keys as $column) $on[] = 'child.' . $this->identifier($column['COLUMN_NAME']) . ' = parent.' . $this->identifier($column['REFERENCED_COLUMN_NAME']);
            $join = ' FROM ' . $this->identifier($key['TABLE_NAME']) . ' child INNER JOIN '
                . $this->identifier($key['REFERENCED_TABLE_NAME']) . ' parent ON ' . implode(' AND ', $on);
            // A locking read sees committed references even if an earlier query established a transaction snapshot.
            if ($lock) {
                if ($this->db->query('SELECT 1' . $join . ' LIMIT 1 FOR UPDATE')->fetchColumn()) {
                    $blocked[] = $key['TABLE_NAME'] . '.' . $key['COLUMN_NAME'] . ' has linked records.';
                }
            } else {
                $count = (int) $this->db->query('SELECT COUNT(*)' . $join)->fetchColumn();
                if ($count) $blocked[] = $key['TABLE_NAME'] . '.' . $key['COLUMN_NAME'] . ' references ' . $count . ' record(s).';
            }
        }
        return $blocked;
    }

    public function inventory()
    {
        $rows = array();
        foreach ($this->tables as $name => $engine) {
            $blocked = $this->blockers($name);
            $counts = array();
            foreach ($this->operationTables($name) as $table) $counts[$table] = $this->count($table);
            $rows[] = array('name' => $name, 'label' => $this->catalog[$name] ?? $name, 'group' => $this->group($name), 'count' => $this->count($name),
                'can_clear' => !$blocked && array_sum($counts) > 0, 'blocked_reasons' => $blocked, 'affected_counts' => $counts);
        }
        return array('tables' => $rows, 'audit_ready' => isset($this->tables['admin_maintenance_events']),
            'events' => isset($this->tables['admin_maintenance_events']) ? $this->db->query('SELECT id, actor_email, target_table, reason, cleared_counts, created_at FROM admin_maintenance_events ORDER BY id DESC LIMIT 20')->fetchAll() : array());
    }

    private function target($name, $lock = false)
    {
        if (!is_string($name) || !isset($this->catalog[$name]) || !isset($this->tables[$name])) throw new ApiException('Select a managed PAD data table.', 422);
        if (!isset($this->tables['admin_maintenance_events'])) throw new ApiException('Database maintenance setup is required.', 503);
        $blocked = $this->blockers($name, $lock);
        if ($blocked) throw new ApiException(implode(' ', $blocked), 409);
        return $this->operationTables($name);
    }

    private function rowsSql($table, $lock)
    {
        $order = array();
        foreach ($this->db->query('SHOW INDEX FROM ' . $this->identifier($table) . " WHERE Key_name = 'PRIMARY'")->fetchAll() as $column) $order[(int) $column['Seq_in_index']] = $this->identifier($column['Column_name']);
        if (!$order) throw new ApiException('A managed table is missing its primary key.', 409);
        ksort($order);
        return 'SELECT * FROM ' . $this->identifier($table) . ' ORDER BY ' . implode(', ', $order) . ($lock ? ' FOR UPDATE' : '');
    }

    private function snapshot(array $tables, $lock = false)
    {
        $snapshot = array();
        // Lock document parents first, like normal routing operations. Full scans also lock insertion gaps.
        $ordered = $tables;
        if (in_array('documents', $ordered, true)) $ordered = array_merge(array('documents'), array_diff($ordered, array('documents')));
        foreach ($ordered as $table) {
            $hash = hash_init('sha256'); $count = 0;
            $query = $this->db->query($this->rowsSql($table, $lock));
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) { hash_update($hash, $this->encode($row) . "\n"); $count++; }
            $snapshot[$table] = array('count' => $count, 'hash' => hash_final($hash));
        }
        ksort($snapshot);
        return $snapshot;
    }

    private function signingKey()
    {
        $key = env_value('APP_KEY', '');
        if (strlen($key) < 32) throw new ApiException('Configure a strong application key before using database maintenance.', 503);
        return $key;
    }

    public function preview($name)
    {
        $tables = $this->target($name);
        $snapshot = $this->snapshot($tables);
        $counts = array_map(function ($row) { return $row['count']; }, $snapshot);
        if (!array_sum($counts)) throw new ApiException('The selected tables are already empty.', 409);
        $payload = array('actor' => (int) $this->actor['id'], 'target' => $name, 'snapshot' => $snapshot, 'expires' => time() + 600, 'nonce' => bin2hex(random_bytes(16)));
        $encoded = base64_encode($this->encode($payload));
        return array('target' => $name, 'label' => $this->catalog[$name], 'counts' => $counts, 'total_records' => array_sum($counts),
            'confirmation' => 'CLEAR ' . $name, 'expires_in' => 600, 'token' => $encoded . '.' . hash_hmac('sha256', $encoded, $this->signingKey()));
    }

    private function backupDirectory()
    {
        $directory = PAD_ROOT . '/storage/maintenance-backups';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new ApiException('The backup directory could not be created.', 503);
        return $directory;
    }

    private function backup(array $tables, array $counts, $name, $reason, $nonce)
    {
        $path = $this->backupDirectory() . '/' . $nonce . '.json';
        $stream = @fopen($path, 'x');
        if (!$stream) throw new ApiException('The recovery backup could not be created.', 503);
        $write = function ($text) use ($stream) {
            $length = strlen($text); $position = 0;
            while ($position < $length) { $written = fwrite($stream, substr($text, $position)); if ($written === false || $written === 0) throw new ApiException('The recovery backup could not be written.', 503); $position += $written; }
        };
        try {
            $metadata = array('version' => 1, 'created_at' => gmdate('c'), 'actor_email' => $this->actor['email'], 'target' => $name, 'reason' => $reason, 'counts' => $counts,
                'attachment_policy' => 'Uploaded document files are retained in the existing private upload directory.');
            $write('{"metadata":' . $this->encode($metadata) . ',"tables":{');
            $first = true;
            foreach ($tables as $table) {
                $write(($first ? '' : ',') . $this->encode($table) . ':['); $first = false; $firstRow = true;
                $query = $this->db->query($this->rowsSql($table, true));
                while ($row = $query->fetch(PDO::FETCH_ASSOC)) { $write(($firstRow ? '' : ',') . $this->encode($row)); $firstRow = false; }
                $write(']');
            }
            $write('}}');
            if (!fflush($stream)) throw new ApiException('The recovery backup could not be saved.', 503);
        } catch (Throwable $error) { fclose($stream); @unlink($path); throw $error; }
        fclose($stream);
        return $path;
    }

    public function clear(array $input)
    {
        $token = $input['token'] ?? null;
        if (!is_string($token) || strlen($token) > 20000 || substr_count($token, '.') !== 1) throw new ApiException('Review the operation before clearing records.', 422);
        list($encoded, $signature) = explode('.', $token, 2);
        if (!hash_equals(hash_hmac('sha256', $encoded, $this->signingKey()), $signature)) throw new ApiException('The operation review is invalid.', 422);
        $payload = json_decode(base64_decode($encoded, true), true);
        if (!is_array($payload) || ($payload['actor'] ?? null) !== (int) $this->actor['id'] || !isset($payload['expires']) || $payload['expires'] < time()
            || !preg_match('/^[a-f0-9]{32}$/D', $payload['nonce'] ?? '')) throw new ApiException('The operation review expired or belongs to another administrator. Review it again.', 409);
        $name = $payload['target'];
        if (($input['confirmation'] ?? '') !== 'CLEAR ' . $name || ($input['acknowledged'] ?? null) !== true) throw new ApiException('Type the exact confirmation and acknowledge the clear operation.', 422);
        $reason = $input['reason'] ?? '';
        if (!is_string($reason) || strlen(trim($reason)) < 3 || strlen($reason) > 1000) throw new ApiException('Enter a reason for this administrative operation (3–1000 characters).', 422);
        $reason = trim($reason);
        $backup = null;
        $this->db->beginTransaction();
        try {
            $actor = $this->db->prepare('SELECT id, email, roles, role, is_active FROM users WHERE id = ? FOR UPDATE');
            $actor->execute(array($this->actor['id']));
            $current = $actor->fetch();
            if (!$current || !$current['is_active'] || !is_administrator(array_merge($current, array('active_role' => 'admin')))) throw new ApiException('Administrator access is no longer active.', 403);
            $used = $this->db->prepare('SELECT id FROM admin_maintenance_events WHERE request_id = ?'); $used->execute(array($payload['nonce']));
            if ($used->fetch()) throw new ApiException('This operation review has already been used.', 409);
            $tables = $this->target($name);
            $snapshot = $this->snapshot($tables, true);
            if ($snapshot !== $payload['snapshot']) throw new ApiException('Records changed after the review. Refresh the counts and review this operation again.', 409);
            // References may have been created since preview; foreign-key checks also remain enabled.
            $this->target($name, true);
            $counts = array_map(function ($row) { return $row['count']; }, $snapshot);
            $backup = $this->backup($tables, $counts, $name, $reason, $payload['nonce']);
            foreach ($tables as $table) {
                $deleted = $this->db->exec('DELETE FROM ' . $this->identifier($table));
                if ($deleted !== $counts[$table]) throw new ApiException('The selected records changed during clearing. The operation was rolled back.', 409);
            }
            $audit = $this->db->prepare('INSERT INTO admin_maintenance_events (request_id, actor_user_id, actor_email, target_table, reason, cleared_counts, backup_file, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
            $audit->execute(array($payload['nonce'], $this->actor['id'], $current['email'], $name, $reason, $this->encode($counts), basename($backup)));
            $id = (int) $this->db->lastInsertId();
            if (!$this->db->commit()) throw new ApiException('The database commit could not be confirmed. Refresh the operation log before retrying.', 503);
            return array('message' => 'Records cleared. A recovery backup and operation log were saved.', 'cleared_counts' => $counts, 'event_id' => $id);
        } catch (Throwable $error) {
            $rolledBack = $this->db->inTransaction() ? $this->db->rollBack() : false;
            // Keep the backup if the database connection cannot confirm whether commit succeeded.
            if ($backup && $rolledBack) @unlink($backup);
            if ($error instanceof PDOException && in_array($error->getCode(), array('23000', '40001'), true)) {
                throw new ApiException('Linked records or an active transaction changed during clearing. Refresh and review the operation again.', 409);
            }
            throw $error;
        }
    }

    public function backupFile($id)
    {
        if (!isset($this->tables['admin_maintenance_events'])) throw new ApiException('Database maintenance setup is required.', 503);
        $query = $this->db->prepare('SELECT backup_file FROM admin_maintenance_events WHERE id = ?'); $query->execute(array($id));
        $name = $query->fetchColumn();
        if (!$name || !preg_match('/^[a-f0-9]{32}\.json$/D', $name)) throw new ApiException('Backup not found.', 404);
        $path = $this->backupDirectory() . '/' . $name;
        if (!is_file($path)) throw new ApiException('Backup file not found.', 404);
        return $path;
    }
}
