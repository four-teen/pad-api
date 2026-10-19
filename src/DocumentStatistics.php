<?php

// Read-only reporting. Global aggregate access never grants document or routing access.
class DocumentStatistics
{
    private $db;
    private $actor;

    public function __construct(PDO $db, array $actor)
    {
        $this->db = $db;
        $this->actor = $actor;
    }

    private function date($input, $key, $fallback)
    {
        $value = $input[$key] ?? $fallback;
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila')) : false;
        if (!$date || $date->format('Y-m-d') !== $value || $value < '1000-01-01') {
            throw new ApiException('Enter a valid ' . str_replace('_', ' ', $key) . '.', 422);
        }
        return $date;
    }

    public function report(array $input)
    {
        $role = $this->actor['active_role'] ?? null;
        if (!$role || !in_array($role, account_roles($this->actor), true)) {
            throw new ApiException('Select an assigned account role to view statistics.', 403);
        }
        $global = in_array($role, array('admin', 'ovp_pad', 'ovp_central_records'), true) || is_presidential_view($this->actor);
        $office = (int) ($this->actor['office_id'] ?? 0);
        if (!$global && !$office) throw new ApiException('An office assignment is required to view office statistics.', 403);
        // This endpoint has only two scopes: shared global or the selected role's exact office.
        if (isset($input['office_id']) && $input['office_id'] !== '') {
            if (!$global && (!is_scalar($input['office_id']) || (string) $input['office_id'] !== (string) $office)) {
                throw new ApiException('Statistics are limited to your assigned office.', 403);
            }
            if ($global && $input['office_id'] !== 'all') throw new ApiException('OVP statistics use the shared all-office scope.', 422);
        }
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $result = $this->build($input, $global, $office);
            if ($ownsTransaction) $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function build(array $input, $global, $office)
    {
        $utc = new DateTimeZone('UTC');
        $manila = new DateTimeZone('Asia/Manila');
        $now = new DateTimeImmutable($this->db->query('SELECT UTC_TIMESTAMP()')->fetchColumn(), $utc);
        $localNow = $now->setTimezone($manila);
        $start = $this->date($input, 'start_date', $localNow->format('Y-01-01'));
        $end = $this->date($input, 'end_date', $localNow->format('Y-m-d'));
        if ($start > $end) throw new ApiException('Start date must be on or before end date.', 422);
        if ($end->diff($start)->days > 3660) throw new ApiException('Select a reporting period of ten years or less.', 422);
        $from = $start->setTimezone($utc)->format('Y-m-d H:i:s');
        $until = $end->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $type = $input['document_type'] ?? '';
        if (!is_string($type) || strlen($type) > 480) throw new ApiException('Select a valid document type.', 422);
        $where = '1 = 1';
        if (!$global) {
            $where = '(d.source_office_id = ' . $office . ' OR d.current_office_id = ' . $office
                . ' OR d.pending_office_id = ' . $office . ' OR d.recipient_office_id = ' . $office
                . ' OR EXISTS (SELECT 1 FROM document_routes access_route WHERE access_route.document_id = d.id'
                . ' AND (access_route.from_office_id = ' . $office . ' OR access_route.to_office_id = ' . $office . ')))';
        }
        $catalog = $this->db->query('SELECT DISTINCT d.document_type FROM documents d WHERE ' . $where . ' ORDER BY d.document_type')->fetchAll(PDO::FETCH_COLUMN);
        $parameters = array();
        if ($type !== '') { $where .= ' AND d.document_type = ?'; $parameters[] = $type; }
        $officeRows = array();
        foreach ($this->db->query('SELECT id, name, code, campus FROM offices ORDER BY campus, name')->fetchAll() as $row) {
            $officeRows[(int) $row['id']] = $row + array('awaiting_receipt' => 0, 'pending_action' => 0, 'oldest_days' => 0);
        }
        $snapshot = array('total_documents' => 0, 'awaiting_receipt' => 0, 'pending_action' => 0, 'returned_pending' => 0,
            'correction_pending' => 0, 'oldest_days' => 0);
        $statuses = array_fill_keys(array('created', 'in_transit', 'received', 'acted', 'completed', 'delivered'), 0);
        $buckets = array('0–3 days' => 0, '4–7 days' => 0, '8–15 days' => 0, '16+ days' => 0);
        $types = array(); $months = array(); $oldest = array();
        for ($month = $start->modify('first day of this month'); $month <= $end; $month = $month->modify('+1 month')) {
            $key = $month->format('Y-m');
            $months[$key] = array('month' => $key, 'registered' => 0, 'received' => 0, 'acted' => 0, 'delivered' => 0, 'handoffs' => 0);
        }
        $registered = 0;
        $query = $this->db->prepare("SELECT d.id, d.reference_no, d.subject, d.document_type, d.source_office_id, d.current_office_id,
            d.pending_office_id, d.status, d.movement_type, d.created_at,
            movement.action AS movement_action, movement.dispatched_at,
            receipt.received_at, review.decision AS latest_decision
            FROM documents d
            LEFT JOIN document_routes movement ON movement.id = (SELECT m.id FROM document_routes m WHERE m.document_id = d.id AND m.dispatched_at IS NOT NULL ORDER BY m.document_id DESC, m.id DESC LIMIT 1)
            LEFT JOIN document_routes receipt ON receipt.id = (SELECT r.id FROM document_routes r WHERE r.document_id = d.id
                AND r.action IN ('created', 'externally_received', 'received') AND r.to_office_id = d.current_office_id ORDER BY r.document_id DESC, r.id DESC LIMIT 1)
            LEFT JOIN document_pad_reviews review ON review.id = (SELECT MAX(p.id) FROM document_pad_reviews p WHERE p.document_id = d.id)
            WHERE " . $where);
        $query->execute($parameters);
        while ($d = $query->fetch()) {
            $snapshot['total_documents']++;
            if (isset($statuses[$d['status']])) $statuses[$d['status']]++;
            $created = new DateTimeImmutable($d['created_at'], $utc);
            if ($d['created_at'] >= $from && $d['created_at'] < $until) {
                $registered++;
                $name = $d['document_type'];
                $types[$name] = ($types[$name] ?? 0) + 1;
                $months[$created->setTimezone($manila)->format('Y-m')]['registered']++;
            }
            // A correction result delivered to its owner is still work, not final closure.
            $correction = $d['status'] === 'delivered' && $d['movement_action'] === 'acted' && $d['latest_decision'] === 'returned_for_correction';
            $incoming = $d['status'] === 'in_transit' && !empty($d['pending_office_id']);
            $held = !$d['pending_office_id'] && (in_array($d['status'], array('created', 'received'), true) || $correction);
            $target = (int) ($incoming ? $d['pending_office_id'] : $d['current_office_id']);
            if (!$incoming && !$held) continue;
            $began = $incoming ? ($d['dispatched_at'] ?: $d['created_at']) : ($d['received_at'] ?: $d['created_at']);
            $beganDate = (new DateTimeImmutable($began, $utc))->setTimezone($manila)->setTime(0, 0);
            $days = max(0, (int) $beganDate->diff($localNow->setTime(0, 0))->format('%r%a'));
            if ($global || $target === $office) {
                $kind = $incoming ? 'awaiting_receipt' : 'pending_action';
                $snapshot[$kind]++;
                $snapshot['oldest_days'] = max($snapshot['oldest_days'], $days);
                if ($d['movement_type'] === 'return') $snapshot['returned_pending']++;
                if ($correction) $snapshot['correction_pending']++;
                $buckets[$days <= 3 ? '0–3 days' : ($days <= 7 ? '4–7 days' : ($days <= 15 ? '8–15 days' : '16+ days'))]++;
                if (isset($officeRows[$target])) {
                    $officeRows[$target][$kind]++;
                    $officeRows[$target]['oldest_days'] = max($officeRows[$target]['oldest_days'], $days);
                }
            }
            // Presidential monitoring includes all offices; other shared reports
            // continue to expose only their own office's document references.
            if ($target === $office || is_presidential_view($this->actor)) {
                $oldest[] = array('reference_no' => $d['reference_no'], 'subject' => $d['subject'], 'document_type' => $d['document_type'],
                    'queue' => $incoming ? 'Awaiting receipt' : 'Pending action', 'since' => $began, 'days' => $days,
                    'office_id' => $target, 'office_name' => $officeRows[$target]['name'] ?? null, 'office_campus' => $officeRows[$target]['campus'] ?? null);
                usort($oldest, function ($a, $b) { return $b['days'] <=> $a['days'] ?: strcmp($a['since'], $b['since']) ?: strcmp($a['reference_no'], $b['reference_no']); });
                if (count($oldest) > 10) array_pop($oldest);
            }
        }
        // Attribute events to their performing office, not every office in the document history.
        $eventScope = $global ? '' : " AND (CASE WHEN r.action IN ('created', 'externally_received', 'received') THEN r.to_office_id ELSE r.from_office_id END) = " . $office;
        $eventWhere = ' FROM document_routes r INNER JOIN documents d ON d.id = r.document_id WHERE ' . $where . $eventScope;
        $metricSql = "COUNT(DISTINCT CASE WHEN r.action = 'received' THEN r.document_id END) AS received,
            COUNT(DISTINCT CASE WHEN r.action = 'acted' THEN r.document_id END) AS acted,
            COUNT(DISTINCT CASE WHEN r.action = 'delivered' THEN r.document_id END) AS delivered,
            COUNT(DISTINCT CASE WHEN r.action = 'completed' THEN r.document_id END) AS completed,
            COUNT(DISTINCT CASE WHEN r.dispatched_at IS NOT NULL THEN r.document_id END) AS sent,
            SUM(CASE WHEN r.dispatched_at IS NOT NULL THEN 1 ELSE 0 END) AS handoffs,
            COUNT(DISTINCT CASE WHEN r.action = 'returned' THEN r.document_id END) AS returned";
        $events = $this->db->prepare('SELECT ' . $metricSql . $eventWhere . ' AND r.created_at >= ? AND r.created_at < ?');
        $events->execute(array_merge($parameters, array($from, $until)));
        $period = array_map('intval', $events->fetch());
        $period['registered'] = $registered;
        $officeActivity = $this->db->prepare("SELECT CASE WHEN r.action IN ('created', 'externally_received', 'received') THEN r.to_office_id ELSE r.from_office_id END AS office_id, "
            . $metricSql . $eventWhere . ' AND r.created_at >= ? AND r.created_at < ? GROUP BY office_id');
        $officeActivity->execute(array_merge($parameters, array($from, $until)));
        foreach ($officeActivity->fetchAll() as $row) {
            $id = (int) $row['office_id'];
            if (!isset($officeRows[$id])) continue;
            foreach (array('received', 'acted', 'sent', 'handoffs') as $key) $officeRows[$id]['period_' . $key] = (int) $row[$key];
        }
        $ever = $this->db->prepare("SELECT COUNT(DISTINCT CASE WHEN r.action = 'acted' THEN r.document_id END) AS acted,
            COUNT(DISTINCT CASE WHEN r.action = 'delivered' THEN r.document_id END) AS delivered" . $eventWhere);
        $ever->execute($parameters);
        $milestones = array_map('intval', $ever->fetch());
        $monthly = $this->db->prepare("SELECT DATE_FORMAT(DATE_ADD(r.created_at, INTERVAL 8 HOUR), '%Y-%m') AS month, " . $metricSql . $eventWhere
            . ' AND r.created_at >= ? AND r.created_at < ? GROUP BY month ORDER BY month');
        $monthly->execute(array_merge($parameters, array($from, $until)));
        foreach ($monthly->fetchAll() as $row) foreach (array('received', 'acted', 'delivered', 'handoffs') as $key) $months[$row['month']][$key] = (int) $row[$key];
        // Registration-to-first-Act uses the document's registration cohort; a later delivery keeps its Act milestone.
        $turnaround = $this->db->prepare("SELECT AVG(TIMESTAMPDIFF(SECOND, d.created_at, a.acted_at)) AS seconds, COUNT(*) AS samples
            FROM documents d INNER JOIN (SELECT document_id, MIN(created_at) AS acted_at FROM document_routes WHERE action = 'acted'"
            . ($global ? '' : ' AND from_office_id = ' . $office) . " GROUP BY document_id) a ON a.document_id = d.id
            WHERE " . $where . ' AND d.created_at >= ? AND d.created_at < ? AND a.acted_at >= d.created_at');
        $turnaround->execute(array_merge($parameters, array($from, $until)));
        $registrationTime = $turnaround->fetch();
        // Pair each dispatch/completion with its latest preceding arrival at the same office.
        $handling = $this->db->prepare("SELECT AVG(TIMESTAMPDIFF(SECOND, arrival.created_at, finish.created_at)) AS seconds, COUNT(*) AS samples
            FROM document_routes finish INNER JOIN documents d ON d.id = finish.document_id
            INNER JOIN document_routes arrival ON arrival.id = (SELECT a.id FROM document_routes a WHERE a.document_id = finish.document_id
                AND a.to_office_id = finish.from_office_id AND a.action IN ('created', 'externally_received', 'received')
                AND a.id < finish.id AND a.created_at <= finish.created_at ORDER BY a.created_at DESC, a.id DESC LIMIT 1)
            WHERE " . $where . ($global ? '' : ' AND finish.from_office_id = ' . $office)
            . " AND (finish.dispatched_at IS NOT NULL OR finish.action IN ('acted', 'completed'))
            AND NOT EXISTS (SELECT 1 FROM document_routes prior WHERE prior.document_id = finish.document_id AND prior.from_office_id = finish.from_office_id
                AND prior.id > arrival.id AND prior.id < finish.id AND (prior.dispatched_at IS NOT NULL OR prior.action IN ('acted', 'completed')))
            AND finish.created_at >= ? AND finish.created_at < ?");
        $handling->execute(array_merge($parameters, array($from, $until)));
        $handlingTime = $handling->fetch();
        $delivery = $this->db->prepare("SELECT AVG(TIMESTAMPDIFF(SECOND, release_route.dispatched_at, release_route.received_at)) AS seconds, COUNT(*) AS samples
            FROM document_routes release_route INNER JOIN documents d ON d.id = release_route.document_id WHERE " . $where
            . ($global ? '' : ' AND release_route.from_office_id = ' . $office)
            . " AND release_route.action = 'acted' AND release_route.actor_role = 'ovp_central_records'
            AND release_route.dispatched_at IS NOT NULL AND release_route.received_at >= release_route.dispatched_at
            AND release_route.received_at >= ? AND release_route.received_at < ?");
        $delivery->execute(array_merge($parameters, array($from, $until)));
        $deliveryTime = $delivery->fetch();
        arsort($types);
        $typeRows = array();
        foreach ($types as $name => $count) $typeRows[] = array('name' => $name, 'documents' => $count, 'share' => $registered ? round($count * 100 / $registered, 1) : 0);
        $actedTypeRows = array(); $recordedTypeRows = array();
        if (is_presidential_view($this->actor)) {
            // Keep configured and historical types visible. Registry totals use
            // all registration dates; Acts use their recorded dates separately.
            $typeCatalog = $this->db->query('SELECT name FROM document_types WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
            $actedCounts = array_fill_keys($type === '' ? $typeCatalog : array($type), 0);
            $recordedCounts = $actedCounts;
            $actedTypes = $this->db->prepare("SELECT COALESCE(t.name, d.document_type) AS name, COUNT(DISTINCT r.document_id) AS documents,
                COUNT(DISTINCT d.id) AS recorded_documents
                FROM documents d LEFT JOIN document_types t ON t.name = d.document_type
                LEFT JOIN document_routes r ON r.document_id = d.id AND r.action = 'acted' AND r.created_at >= ? AND r.created_at < ?
                WHERE " . $where . ' GROUP BY COALESCE(t.name, d.document_type) ORDER BY name');
            $actedTypes->execute(array_merge(array($from, $until), $parameters));
            foreach ($actedTypes->fetchAll() as $row) {
                $actedCounts[$row['name']] = (int) $row['documents'];
                // All registry rows count once, including imported history and
                // earlier registration years, regardless of an Act milestone.
                $recordedCounts[$row['name']] = (int) $row['recorded_documents'];
            }
            ksort($actedCounts, SORT_NATURAL | SORT_FLAG_CASE);
            ksort($recordedCounts, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($actedCounts as $name => $count) $actedTypeRows[] = array('name' => $name, 'documents' => $count);
            foreach ($recordedCounts as $name => $count) $recordedTypeRows[] = array('name' => $name, 'documents' => $count);
        }
        $workload = array_values(array_filter($officeRows, function ($row) use ($global, $office) {
            return ($global || (int) $row['id'] === $office) && ($row['awaiting_receipt'] || $row['pending_action']
                || ($row['period_received'] ?? 0) || ($row['period_acted'] ?? 0) || ($row['period_handoffs'] ?? 0));
        }));
        usort($workload, function ($a, $b) { return ($b['pending_action'] + $b['awaiting_receipt']) <=> ($a['pending_action'] + $a['awaiting_receipt']) ?: strcmp($a['name'], $b['name']); });
        return array('scope' => $global ? 'global' : 'office', 'office' => $officeRows[$office] ?? null,
            'server_time' => $now->format('Y-m-d\TH:i:s\Z'), 'display_timezone' => 'Asia/Manila',
            'filters' => array('start_date' => $start->format('Y-m-d'), 'end_date' => $end->format('Y-m-d'), 'document_type' => $type),
            'document_types' => $catalog, 'snapshot' => $snapshot, 'current_statuses' => $statuses, 'milestones' => $milestones,
            'period' => $period, 'monthly' => array_values($months), 'type_breakdown' => $typeRows, 'acted_type_breakdown' => $actedTypeRows,
            'recorded_type_breakdown' => $recordedTypeRows, 'pending_age' => $buckets,
            'office_workload' => $workload, 'oldest_pending' => $oldest,
            'processing_time' => array('registration_to_act' => array('seconds' => $registrationTime['seconds'] === null ? null : (float) $registrationTime['seconds'], 'samples' => (int) $registrationTime['samples']),
                'act_to_delivery' => array('seconds' => $deliveryTime['seconds'] === null ? null : (float) $deliveryTime['seconds'], 'samples' => (int) $deliveryTime['samples']),
                'office_handling' => array('seconds' => $handlingTime['seconds'] === null ? null : (float) $handlingTime['seconds'], 'samples' => (int) $handlingTime['samples'])));
    }
}
