<?php

class DocumentRouting
{
    private $db;
    private $actor;
    private $officeRoles = array();
    private $recordedAt = null;

    private function timestampSql()
    {
        if ($this->recordedAt === null) $this->recordedAt = $this->db->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
        return $this->db->quote($this->recordedAt);
    }

    public function __construct(PDO $db, array $actor)
    {
        $this->db = $db;
        $this->actor = $actor;
    }

    private function fail($message, $status = 422)
    {
        throw new ApiException($message, $status);
    }

    private function text($input, $key, $limit, $required = false)
    {
        $value = $input[$key] ?? '';
        if (!is_string($value)) $this->fail('Invalid ' . str_replace('_', ' ', $key) . '.');
        $value = trim($value);
        if ($required && $value === '') $this->fail(ucfirst(str_replace('_', ' ', $key)) . ' is required.');
        if (preg_match_all('/./us', $value, $characters) === false || count($characters[0]) > $limit) $this->fail(ucfirst(str_replace('_', ' ', $key)) . ' is too long or invalid.');
        return $value;
    }

    private function office($id)
    {
        $statement = $this->db->prepare('SELECT id, name, code, campus FROM offices WHERE id = ? AND is_active = 1');
        $statement->execute(array($id));
        $office = $statement->fetch();
        if (!$office) $this->fail('Select an active routing office.');
        return $office;
    }

    private function actingOffice()
    {
        $this->assertWritable();
        if (empty($this->actor['office_id'])) $this->fail('An administrator must assign your account to an office before you can send or receive documents.', 403);
        $office = $this->office($this->actor['office_id']);
        if (is_presidential_office($office)) $this->fail('OP is monitoring only and cannot register, send, or receive documents.', 403);
        return (int) $this->actor['office_id'];
    }

    private function canMonitor()
    {
        return is_records_user($this->actor) || is_presidential_view($this->actor);
    }

    private function receivingOffice($id)
    {
        $office = $this->office($id);
        if (is_presidential_office($office)) {
            $this->fail('The Office of the University President is monitoring only. Select the OVP PAD receiving office instead.');
        }
        return $office;
    }

    private function assertWritable()
    {
        assert_monitoring_method($this->actor, 'POST');
    }

    private function officeModuleRole($id)
    {
        if (!$id) return null;
        if (!array_key_exists($id, $this->officeRoles)) {
            $query = $this->db->prepare("SELECT
                EXISTS (SELECT 1 FROM users WHERE is_active = 1 AND ovp_pad_office_id = ? AND FIND_IN_SET('ovp_pad', roles)) AS pad,
                EXISTS (SELECT 1 FROM users WHERE is_active = 1 AND ovp_central_records_office_id = ? AND FIND_IN_SET('ovp_central_records', roles)) AS records");
            $query->execute(array($id, $id));
            $roles = $query->fetch();
            // An office configured for both modules is ambiguous; never identify it by name/code.
            $this->officeRoles[$id] = $roles['pad'] && !$roles['records'] ? 'ovp_pad'
                : ($roles['records'] && !$roles['pad'] ? 'ovp_central_records' : null);
        }
        return $this->officeRoles[$id];
    }

    private function reviewContext(array $document)
    {
        // An Act dispatch releases the result to its owner, rather than opening another PAD review.
        if (($document['last_movement_action'] ?? null) === 'acted') return null;
        if (!empty($document['pad_review_id'])) {
            if ($document['pad_decision'] === null && (int) $document['pad_submission_route_id'] === (int) $document['last_movement_id']) {
                return array('pad' => (int) $document['pad_office_id'], 'records' => (int) $document['pad_central_records_office_id'],
                    'submission' => (int) $document['pad_submission_route_id']);
            }
            return null;
        }
        // Older handoffs can enter review on their real receipt/decision, without rewriting history during setup.
        if (!empty($document['last_movement_id'])
            && $this->officeModuleRole($document['last_from_office_id']) === 'ovp_central_records'
            && $this->officeModuleRole($document['last_to_office_id']) === 'ovp_pad') {
            return array('pad' => (int) $document['last_to_office_id'], 'records' => (int) $document['last_from_office_id'],
                'submission' => (int) $document['last_movement_id']);
        }
        return null;
    }

    private function createReview($documentId, array $context)
    {
        $insert = $this->db->prepare('INSERT INTO document_pad_reviews (document_id, submission_route_id, pad_office_id, central_records_office_id, created_at)
            VALUES (?, ?, ?, ?, ' . $this->timestampSql() . ')');
        $insert->execute(array($documentId, $context['submission'], $context['pad'], $context['records']));
        return (int) $this->db->lastInsertId();
    }

    private function joins()
    {
        return ' FROM documents d
            LEFT JOIN offices source ON source.id = d.source_office_id
            LEFT JOIN offices current ON current.id = d.current_office_id
            LEFT JOIN offices pending ON pending.id = d.pending_office_id
            LEFT JOIN offices recipient ON recipient.id = d.recipient_office_id
            LEFT JOIN document_routes movement ON movement.id = (SELECT MAX(m.id) FROM document_routes m
                WHERE m.document_id = d.id AND m.dispatched_at IS NOT NULL)
            LEFT JOIN document_routes receipt ON receipt.id = (SELECT MAX(r.id) FROM document_routes r
                WHERE r.document_id = d.id AND r.received_at IS NOT NULL)
            LEFT JOIN offices previous ON previous.id = movement.from_office_id
            LEFT JOIN users receipt_actor ON receipt_actor.id = receipt.acted_by_user_id
            LEFT JOIN document_pad_reviews review ON review.id = (SELECT MAX(pr.id) FROM document_pad_reviews pr WHERE pr.document_id = d.id)
            LEFT JOIN users decision_actor ON decision_actor.id = review.decided_by_user_id
            LEFT JOIN offices review_records ON review_records.id = review.central_records_office_id
            LEFT JOIN document_routes final_release ON final_release.id = (SELECT MAX(fr.id) FROM document_routes fr
                WHERE fr.document_id = d.id AND fr.action = \'acted\' AND fr.dispatched_at IS NOT NULL AND fr.actor_role = \'ovp_central_records\')';
    }

    private function select()
    {
        return 'SELECT d.*, source.name AS source_office_name, source.campus AS source_office_campus,
            current.name AS current_office_name, current.campus AS current_office_campus, current.code AS current_office_code,
            pending.name AS pending_office_name, pending.campus AS pending_office_campus,
            recipient.name AS recipient_office_name, movement.from_office_id AS last_from_office_id,
            previous.name AS last_from_office_name, previous.code AS last_from_office_code, previous.campus AS last_from_office_campus,
            movement.dispatched_at AS last_dispatched_at, movement.notes AS last_route_notes, receipt.received_at AS last_received_at,
            receipt.action AS last_receipt_action, receipt_actor.name AS last_received_by_name,
            movement.id AS last_movement_id, movement.to_office_id AS last_to_office_id, movement.actor_role AS last_sender_role,
            movement.action AS last_movement_action, final_release.id AS release_route_id,
            final_release.from_office_id AS release_records_office_id, final_release.to_office_id AS release_owner_office_id,
            final_release.dispatched_at AS release_acted_at, final_release.received_at AS release_delivered_at,
            review.id AS pad_review_id, review.submission_route_id AS pad_submission_route_id, review.pad_office_id,
            review.central_records_office_id AS pad_central_records_office_id, review_records.name AS pad_central_records_office_name,
            review.decision AS pad_decision, review.decision_notes AS pad_decision_notes, review.decided_at AS pad_decided_at,
            decision_actor.name AS pad_decided_by_name, review.result_route_id AS pad_result_route_id' . $this->joins();
    }

    private function participation($office, &$parameters)
    {
        for ($i = 0; $i < 6; $i++) $parameters[] = $office;
        return '(d.source_office_id = ? OR d.current_office_id = ? OR d.pending_office_id = ? OR d.recipient_office_id = ?
            OR EXISTS (SELECT 1 FROM document_routes access_route WHERE access_route.document_id = d.id
                AND (access_route.from_office_id = ? OR access_route.to_office_id = ?)))';
    }

    private function decorate($document)
    {
        $office = (int) ($this->actor['office_id'] ?? 0);
        $active = false;
        if ($office) {
            $query = $this->db->prepare('SELECT is_active, code FROM offices WHERE id = ?');
            $query->execute(array($office));
            $officeState = $query->fetch();
            $active = $officeState && (bool) $officeState['is_active'] && !is_presidential_office($officeState);
        }
        $held = $active && $office === (int) $document['current_office_id'] && !$document['pending_office_id']
            && in_array($document['status'], array('created', 'received'), true);
        $incoming = $active && $office === (int) $document['pending_office_id'] && $document['status'] === 'in_transit';
        if (!$document['last_sender_role']) $document['last_sender_role'] = $this->officeModuleRole($document['last_from_office_id']);
        $document['is_my_document'] = $document['origin_type'] === 'office' && !$document['last_movement_id']
            && (int) $document['source_office_id'] === (int) $document['current_office_id']
            && !$document['pending_office_id'] && in_array($document['status'], array('created', 'received'), true);
        $padResult = $document['last_sender_role'] === 'ovp_pad'
            && ($this->actor['active_role'] ?? '') === 'ovp_central_records'
            && in_array('ovp_central_records', account_roles($this->actor), true);
        $document['is_pad_return'] = $document['last_sender_role'] === 'ovp_pad' && ($held || $incoming)
            && $office === (int) $document['last_to_office_id']
            && ($padResult || $this->officeModuleRole($office) === 'ovp_central_records'
                || $office === (int) $document['pad_central_records_office_id']);
        $document['is_owner_release'] = !empty($document['release_route_id'])
            && (int) $document['last_movement_id'] === (int) $document['release_route_id'];
        $correctionHeld = $active && $document['is_owner_release'] && $document['status'] === 'delivered'
            && $document['pad_decision'] === 'returned_for_correction'
            && $office === (int) $document['release_owner_office_id'] && !$document['pending_office_id'];
        $document['act_blockers'] = array();
        if ($document['is_pad_return']) {
            if (!$document['stored_file_name']) $document['act_blockers'][] = 'Open View and attach the document before marking it Acted.';
            if (trim((string) $document['last_route_notes']) === '') $document['act_blockers'][] = 'The latest route from PAD must include notes. Return the document to PAD for the missing routing notes.';
        }
        $document['permissions'] = array(
            'receive' => $incoming,
            'route' => ($held || $correctionHeld) && !$document['is_pad_return'],
            'return' => ($held || $incoming || $correctionHeld) && !$document['is_pad_return']
                && !empty($document['last_from_office_id']) && $office !== (int) $document['last_from_office_id']
                && !is_presidential_office(array('code' => $document['last_from_office_code'])),
            'act' => $document['is_pad_return'] && $padResult,
            'delete' => $held && $document['is_my_document'],
            'complete' => $held && !$document['is_my_document'] && !$document['is_pad_return'],
            'deliver' => $held && !$document['is_my_document'] && !$document['is_pad_return'],
            'upload_file' => $active && $document['status'] !== 'acted' && (!$document['is_owner_release'] || $correctionHeld)
                && ($office === (int) $document['source_office_id'] || $office === (int) $document['current_office_id'] || $document['is_pad_return'])
        );
        $context = $this->reviewContext($document);
        $reviewHeld = $held && $context && $office === $context['pad'];
        $document['permissions']['decide'] = $reviewHeld && ($this->actor['active_role'] ?? '') === 'ovp_pad'
            && in_array('ovp_pad', account_roles($this->actor), true);
        if ($reviewHeld) foreach (array('route', 'return', 'act', 'complete', 'deliver') as $action) $document['permissions'][$action] = false;
        $document['awaiting_pad_decision'] = (bool) ($context && (($document['pending_office_id'] && (int) $document['pending_office_id'] === $context['pad'])
            || (!$document['pending_office_id'] && (int) $document['current_office_id'] === $context['pad'] && in_array($document['status'], array('created', 'received', 'acted'), true))));
        $document['pad_return_office_id'] = $context ? $context['records'] : null;
        $document['pad_return_office_name'] = $context ? ($document['pad_central_records_office_name'] ?: $document['last_from_office_name']) : null;
        $document['pad_result_unrecorded'] = !$document['pad_review_id'] && $document['last_sender_role'] === 'ovp_pad'
            && $this->officeModuleRole($document['last_to_office_id']) === 'ovp_central_records'
            && (int) ($document['pending_office_id'] ?: $document['current_office_id']) === (int) $document['last_to_office_id']
            && in_array($document['status'], array('in_transit', 'received', 'acted'), true);
        if (is_presidential_view($this->actor)) {
            $document['permissions'] = array_fill_keys(array_keys($document['permissions']), false);
        }
        return $document;
    }

    public function record($reference, $withHistory = false)
    {
        $parameters = array($reference);
        $sql = $this->select() . ' WHERE d.reference_no = ?';
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        $document = $statement->fetch();
        if (!$document) $this->fail('Document not found.', 404);
        if (!$this->canMonitor()) {
            $parameters = array($document['id']);
            $query = $this->db->prepare('SELECT d.id FROM documents d WHERE d.id = ? AND ' . $this->participation((int) ($this->actor['office_id'] ?? 0), $parameters));
            $query->execute($parameters);
            if (!$query->fetch()) $this->fail('You are not allowed to access this document.', 403);
        }
        $document = $this->decorate($document);
        if ($withHistory) {
            $document['history'] = document_history($document['id']);
            // DATETIME values in the routing ledger are UTC. Include an explicit
            // server instant so elapsed office stays do not depend on a client clock.
            $document['server_time'] = str_replace(' ', 'T', $this->db->query('SELECT UTC_TIMESTAMP()')->fetchColumn()) . 'Z';
            $document['timestamp_timezone'] = 'UTC';
            $document['display_timezone'] = 'Asia/Manila';
            $reviews = $this->db->prepare('SELECT pr.*, actor.name AS decided_by_name FROM document_pad_reviews pr
                LEFT JOIN users actor ON actor.id = pr.decided_by_user_id WHERE pr.document_id = ? ORDER BY pr.id');
            $reviews->execute(array($document['id']));
            $document['pad_reviews'] = $reviews->fetchAll();
            $people = $this->db->prepare('SELECT personnel_id AS id, name_snapshot AS name FROM document_people WHERE document_id = ? ORDER BY name_snapshot, personnel_id');
            $people->execute(array($document['id']));
            $document['personnel'] = $people->fetchAll();
            $travel = $this->db->prepare('SELECT destination, purpose, order_date, departure_date, return_date, travel_type, vehicle_requirement, remarks FROM document_travel_details WHERE document_id = ?');
            $travel->execute(array($document['id']));
            $document['travel_details'] = $travel->fetch() ?: null;
        }
        return $document;
    }

    public function registrationOptions()
    {
        return array(
            'document_types' => $this->db->query('SELECT id, name, details_kind FROM document_types WHERE is_active = 1 ORDER BY name')->fetchAll(),
            'personnel' => $this->db->query('SELECT id, name FROM document_personnel WHERE is_active = 1 ORDER BY name, id')->fetchAll(),
            'source_divisions' => $this->db->query('SELECT id, name FROM document_sources WHERE is_active = 1 ORDER BY name, id')->fetchAll()
        );
    }

    private function registrationDetails(array $input)
    {
        if (isset($input['document_type_id']) && $input['document_type_id'] !== '') {
            $id = filter_var($input['document_type_id'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
            if ($id === false) $this->fail('Select a valid type of document.');
            $query = $this->db->prepare('SELECT id, name, details_kind FROM document_types WHERE id = ? AND is_active = 1');
            $query->execute(array($id));
        } else {
            // Accept a known catalog name for older clients, never arbitrary free text.
            $query = $this->db->prepare('SELECT id, name, details_kind FROM document_types WHERE name = ? AND is_active = 1');
            $query->execute(array($this->text($input, 'document_type', 120)));
        }
        $type = $query->fetch();
        if (!$type) $this->fail('Select a type of document from the list.');
        $ids = $input['personnel_ids'] ?? array();
        if (!is_array($ids) || count($ids) > 100) $this->fail('Select up to 100 faculty / personnel from the list.');
        $unique = array();
        foreach ($ids as $id) {
            $id = filter_var($id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
            if ($id === false) $this->fail('Select valid faculty / personnel from the list.');
            $unique[$id] = $id;
        }
        $people = array();
        if ($unique) {
            $query = $this->db->prepare('SELECT id, name FROM document_personnel WHERE is_active = 1 AND id IN (' . implode(',', array_fill(0, count($unique), '?')) . ') ORDER BY id');
            $query->execute(array_values($unique));
            $people = $query->fetchAll();
            if (count($people) !== count($unique)) $this->fail('One or more selected personnel are unavailable. Select names from the current list.');
        }
        $travel = null;
        if ($type['details_kind'] === 'travel_order') {
            if (!$people) $this->fail('Select at least one faculty / personnel for the travel order.');
            $travel = array('destination' => $this->text($input, 'travel_destination', 2000, true),
                'purpose' => $this->text($input, 'travel_purpose', 2000, true));
            foreach (array('order_date', 'departure_date', 'return_date') as $key) {
                $value = $this->text($input, 'travel_' . $key, 10, true);
                if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) $this->fail('Enter a valid ' . str_replace('_', ' ', $key) . '.');
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value || $value < '1000-01-01') $this->fail('Enter a valid ' . str_replace('_', ' ', $key) . '.');
                $travel[$key] = $value;
            }
            if ($travel['return_date'] < $travel['departure_date']) $this->fail('Return date must be on or after departure date.');
            $travel['travel_type'] = $this->text($input, 'travel_type', 30, true);
            if (!in_array($travel['travel_type'], array('Official Time', 'Official Business'), true)) $this->fail('Select Official Time or Official Business for the travel type.');
            $travel['vehicle_requirement'] = $this->text($input, 'travel_vehicle_requirement', 30, true);
            if (!in_array($travel['vehicle_requirement'], array('Yes', 'Not Necessary'), true)) $this->fail('Select a valid vehicle requirement.');
            $travel['remarks'] = $this->text($input, 'travel_remarks', 10000);
        } else {
            foreach (array('destination', 'purpose', 'order_date', 'departure_date', 'return_date', 'type', 'vehicle_requirement', 'remarks') as $key) {
                if ($this->text($input, 'travel_' . $key, 10000) !== '') $this->fail('Travel details apply only to Travel Order documents.');
            }
        }
        return array('type_id' => (int) $type['id'], 'type' => $type['name'], 'personnel' => $people, 'travel' => $travel);
    }

    private function queueCondition($queue, $office, &$parameters)
    {
        $holder = $office ? 'd.current_office_id = ' . (int) $office : '1 = 1';
        $recipient = $office ? 'd.pending_office_id = ' . (int) $office : 'd.pending_office_id IS NOT NULL';
        switch ($queue) {
            case 'attention':
                if (!$office) return '1 = 0';
                $received = $this->queueCondition('received', $office, $parameters);
                return '(' . $received . ") OR (" . $holder . " AND d.pending_office_id IS NULL AND d.status = 'delivered'
                    AND EXISTS (SELECT 1 FROM document_pad_reviews pr WHERE pr.document_id = d.id
                        AND pr.id = (SELECT MAX(lp.id) FROM document_pad_reviews lp WHERE lp.document_id = d.id)
                        AND pr.decision = 'returned_for_correction')
                    AND EXISTS (SELECT 1 FROM document_routes ar WHERE ar.document_id = d.id
                        AND ar.id = (SELECT MAX(lm.id) FROM document_routes lm WHERE lm.document_id = d.id AND lm.dispatched_at IS NOT NULL)
                        AND ar.action = 'acted' AND ar.actor_role = 'ovp_central_records' AND ar.to_office_id = " . (int) $office . '))';
            case 'for_approval':
                $latestMovement = '(SELECT MAX(pm.id) FROM document_routes pm WHERE pm.document_id = d.id AND pm.dispatched_at IS NOT NULL)';
                return $holder . " AND d.pending_office_id IS NULL AND d.status IN ('created', 'received', 'acted') AND (
                    EXISTS (SELECT 1 FROM document_pad_reviews pr WHERE pr.document_id = d.id
                        AND pr.id = (SELECT MAX(lp.id) FROM document_pad_reviews lp WHERE lp.document_id = d.id)
                        AND pr.pad_office_id = " . (int) $office . ' AND pr.decision IS NULL AND pr.submission_route_id = ' . $latestMovement . ")
                    OR (NOT EXISTS (SELECT 1 FROM document_pad_reviews pr WHERE pr.document_id = d.id)
                        AND EXISTS (SELECT 1 FROM document_routes pm WHERE pm.id = " . $latestMovement . ' AND pm.to_office_id = ' . (int) $office . "
                            AND EXISTS (SELECT 1 FROM users ru WHERE ru.is_active = 1 AND ru.ovp_central_records_office_id = pm.from_office_id
                                AND FIND_IN_SET('ovp_central_records', ru.roles)))))";
            case 'pad_results':
                return "d.status IN ('in_transit', 'received') AND ((d.pending_office_id IS NOT NULL AND " . $recipient . ') OR (d.pending_office_id IS NULL AND ' . $holder . '))
                    AND (EXISTS (SELECT 1 FROM document_pad_reviews pr WHERE pr.document_id = d.id
                        AND pr.id = (SELECT MAX(lp.id) FROM document_pad_reviews lp WHERE lp.document_id = d.id)
                        AND pr.central_records_office_id = ' . (int) $office . ' AND pr.decision IS NOT NULL
                        AND pr.result_route_id = (SELECT MAX(pm.id) FROM document_routes pm WHERE pm.document_id = d.id AND pm.dispatched_at IS NOT NULL))
                    OR (NOT EXISTS (SELECT 1 FROM document_pad_reviews pr WHERE pr.document_id = d.id)
                        AND EXISTS (SELECT 1 FROM document_routes pm WHERE pm.id = (SELECT MAX(lm.id) FROM document_routes lm WHERE lm.document_id = d.id AND lm.dispatched_at IS NOT NULL)
                            AND pm.to_office_id = ' . (int) $office . " AND (pm.actor_role = 'ovp_pad' OR (pm.actor_role IS NULL
                                AND EXISTS (SELECT 1 FROM users pu WHERE pu.is_active = 1 AND pu.ovp_pad_office_id = pm.from_office_id AND FIND_IN_SET('ovp_pad', pu.roles)))))))";
            case 'incoming': return $recipient . " AND d.status = 'in_transit'";
            case 'my_documents': return $holder . " AND d.pending_office_id IS NULL AND d.status IN ('created', 'received')
                AND d.origin_type = 'office' AND d.source_office_id = d.current_office_id
                AND NOT EXISTS (SELECT 1 FROM document_routes sent WHERE sent.document_id = d.id AND sent.dispatched_at IS NOT NULL)";
            case 'received': return $holder . " AND d.pending_office_id IS NULL AND d.status IN ('created', 'received')
                AND (d.origin_type <> 'office' OR d.source_office_id IS NULL OR d.source_office_id <> d.current_office_id
                    OR EXISTS (SELECT 1 FROM document_routes sent WHERE sent.document_id = d.id AND sent.dispatched_at IS NOT NULL))";
            case 'outgoing':
                if (!$office) return "d.status = 'in_transit'";
                $parameters[] = $office;
                return '(d.current_office_id <> ' . (int) $office . ' OR d.pending_office_id IS NOT NULL)
                    AND EXISTS (SELECT 1 FROM document_routes sent WHERE sent.document_id = d.id AND sent.from_office_id = ? AND sent.dispatched_at IS NOT NULL)';
            case 'returned': return "d.movement_type = 'return' AND d.status IN ('in_transit', 'received', 'acted') AND ((d.pending_office_id IS NOT NULL AND " . $recipient . ') OR (d.pending_office_id IS NULL AND ' . $holder . '))';
            case 'acted': return '(' . $holder . " AND d.pending_office_id IS NULL AND d.status = 'acted') OR EXISTS (
                SELECT 1 FROM document_routes ar WHERE ar.document_id = d.id AND ar.action = 'acted'
                    AND ar.dispatched_at IS NOT NULL AND ar.actor_role = 'ovp_central_records'"
                    . ($office ? ' AND ar.from_office_id = ' . (int) $office : '') . ')';
            case 'delivered': return "d.status IN ('completed', 'delivered')";
            default: return '1 = 1';
        }
    }

    public function workspace(array $input)
    {
        $scope = !empty($this->actor['office_id']) ? (int) $this->actor['office_id'] : null;
        if (isset($input['office_id']) && $input['office_id'] !== '') {
            if (!$this->canMonitor()) $this->fail('Office monitoring access is required.', 403);
            $scope = $input['office_id'] === 'all' ? null : filter_var($input['office_id'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
            if ($scope === false) $this->fail('Select a valid office.');
        }
        if (($input['queue'] ?? '') === 'attention') $scope = !empty($this->actor['office_id']) ? (int) $this->actor['office_id'] : null;
        $office = null;
        if ($scope) {
            $find = $this->db->prepare('SELECT id, name, campus, is_active FROM offices WHERE id = ?');
            $find->execute(array($scope));
            $office = $find->fetch();
            if (!$office) $this->fail('Office not found.', 404);
        }
        $parameters = array();
        $where = array();
        if ($scope) $where[] = $this->participation($scope, $parameters);
        elseif (!$this->canMonitor()) $where[] = '1 = 0';
        $base = $where ? implode(' AND ', $where) : '1 = 1';
        $queues = array('my_documents', 'incoming', 'received', 'outgoing', 'returned', 'acted', 'delivered', 'all', 'attention');
        if (($this->actor['active_role'] ?? '') === 'ovp_pad') $queues[] = 'for_approval';
        if (($this->actor['active_role'] ?? '') === 'ovp_central_records') $queues[] = 'pad_results';
        $localQueues = array('for_approval', 'pad_results', 'attention');
        $counts = array();
        foreach ($queues as $queue) {
            $local = in_array($queue, $localQueues, true);
            $args = $local ? array() : $parameters;
            $condition = $this->queueCondition($queue, $local ? (int) $this->actor['office_id'] : $scope, $args);
            $count = $this->db->prepare('SELECT COUNT(*) FROM documents d WHERE ' . ($local ? '1 = 1' : $base) . ' AND (' . $condition . ')');
            $count->execute($args);
            $counts[$queue] = (int) $count->fetchColumn();
        }
        $queue = $input['queue'] ?? 'all';
        if (!in_array($queue, $queues, true)) $this->fail('Unknown document queue.');
        if (in_array($queue, $localQueues, true) && !empty($this->actor['office_id']) && $scope !== (int) $this->actor['office_id']) {
            $scope = (int) $this->actor['office_id'];
            $office = $this->office($scope);
            $parameters = array();
            $where = array($this->participation($scope, $parameters));
        }
        $where[] = $this->queueCondition($queue, $scope, $parameters);
        $search = $this->text($input, 'search', 255);
        if ($search !== '') {
            $where[] = '(d.reference_no LIKE ? OR d.subject LIKE ? OR d.document_type LIKE ? OR d.external_sender LIKE ? OR source.name LIKE ? OR current.name LIKE ? OR pending.name LIKE ?)';
            for ($i = 0; $i < 7; $i++) $parameters[] = '%' . $search . '%';
        }
        $status = $input['status'] ?? '';
        if ($status !== '') {
            if (!in_array($status, array('created', 'in_transit', 'received', 'acted', 'completed', 'delivered'), true)) $this->fail('Unknown document status.');
            $where[] = $status === 'acted' ? "(d.status = ? OR EXISTS (SELECT 1 FROM document_routes ar
                WHERE ar.document_id = d.id AND ar.action = 'acted' AND ar.dispatched_at IS NOT NULL AND ar.actor_role = 'ovp_central_records'))" : 'd.status = ?';
            $parameters[] = $status;
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = $this->db->prepare('SELECT COUNT(*)' . $this->joins() . $sqlWhere);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / 20));
        $page = min($pages, max(1, (int) ($input['page'] ?? 1)));
        $ordering = $queue === 'attention' ? 'COALESCE(receipt.received_at, d.created_at) ASC, d.id ASC' : 'd.updated_at DESC, d.id DESC';
        $query = $this->db->prepare($this->select() . $sqlWhere . ' ORDER BY ' . $ordering . ' LIMIT 20 OFFSET ' . (($page - 1) * 20));
        $query->execute($parameters);
        $documents = array();
        foreach ($query->fetchAll() as $document) $documents[] = $this->decorate($document);
        $deskCounts = $counts;
        if ($scope !== (int) ($this->actor['office_id'] ?? 0)) {
            $deskCounts = array();
            foreach ($queues as $deskQueue) {
                $args = array();
                $deskOffice = (int) ($this->actor['office_id'] ?? 0);
                $deskBase = $deskOffice ? $this->participation($deskOffice, $args) : '1 = 0';
                $deskCondition = $this->queueCondition($deskQueue, $deskOffice, $args);
                $deskQuery = $this->db->prepare('SELECT COUNT(*) FROM documents d WHERE ' . $deskBase . ' AND (' . $deskCondition . ')');
                $deskQuery->execute($args);
                $deskCounts[$deskQueue] = (int) $deskQuery->fetchColumn();
            }
        }
        return array('data' => $documents, 'counts' => $counts, 'desk_counts' => $deskCounts, 'office' => $office,
            'can_monitor' => $this->canMonitor(), 'can_register' => !is_presidential_view($this->actor) && (!empty($this->actor['office_id']) || is_administrator($this->actor)),
            'can_register_external' => !is_presidential_view($this->actor) && $this->canMonitor(), 'pagination' => array('page' => $page, 'pages' => $pages, 'total' => $total, 'per_page' => 20));
    }

    private function transaction(callable $callback)
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->db->beginTransaction();
            $this->recordedAt = null;
            try {
                $result = $callback();
                $this->db->commit();
                return $result;
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                $retry = $error instanceof PDOException && in_array((int) ($error->errorInfo[1] ?? 0), array(1205, 1213, 1062), true);
                if (!$retry || $attempt === 3) throw $error;
                usleep(20000 * ($attempt + 1));
            } finally {
                $this->recordedAt = null;
            }
        }
    }

    private function registeredSubmission($key, $hash)
    {
        $query = $this->db->prepare('SELECT id, reference_no, submission_hash FROM documents WHERE created_by_user_id = ? AND submission_key = ?');
        $query->execute(array($this->actor['id'], $key));
        $document = $query->fetch();
        if ($document && !hash_equals($document['submission_hash'], $hash)) $this->fail('This registration was already saved with different details. Open a new registration.', 409);
        return $document;
    }

    private function uploadedFile(array $file, $optional = false)
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE && $optional) return null;
        $max = (int) env_value('MAX_UPLOAD_BYTES', '10485760');
        if (in_array($error, array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) $this->fail('The document file must be smaller than ' . floor($max / 1048576) . ' MB.');
        if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) $this->fail('Select a document file that uploaded successfully.');
        $size = filesize($file['tmp_name']);
        if ($size <= 0 || $size > $max) $this->fail('The document file must be smaller than ' . floor($max / 1048576) . ' MB.');
        if (!class_exists('finfo')) $this->fail('Document uploads require the PHP Fileinfo extension. Ask the server administrator to enable it and restart PHP.', 503);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = array('application/pdf' => 'pdf', 'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx', 'image/jpeg' => 'jpg', 'image/png' => 'png');
        if (!isset($allowed[$mime])) $this->fail('Only PDF, Word, Excel, JPG, and PNG files are allowed.');
        if (!isset($file['name']) || !is_string($file['name'])) $this->fail('Invalid document filename.');
        $name = basename(str_replace('\\', '/', $file['name']));
        if ($name === '' || strlen($name) > 255) $this->fail('The document filename must be between 1 and 255 bytes.');
        return array('name' => $name, 'mime' => $mime, 'size' => $size, 'extension' => $allowed[$mime], 'hash' => hash_file('sha256', $file['tmp_name']));
    }

    private function storeFile(array $file, array $attachment)
    {
        $stored = bin2hex(random_bytes(20)) . '.' . $attachment['extension'];
        $path = safe_upload_directory() . DIRECTORY_SEPARATOR . $stored;
        if (!move_uploaded_file($file['tmp_name'], $path)) $this->fail('The uploaded file could not be stored.', 500);
        return array('stored' => $stored, 'path' => $path);
    }

    public function register(array $input, array $file)
    {
        $this->assertWritable();
        $subject = $this->text($input, 'subject', 255);
        $description = $this->text($input, 'description', 10000);
        $origin = $input['origin_type'] ?? 'office';
        if (!in_array($origin, array('office', 'external'), true)) $this->fail('Select a valid document source.');
        $external = '';
        $divisionId = null;
        if ($origin === 'external') {
            if (!$this->canMonitor()) $this->fail('Only Central Records or an administrator may register outside documents.', 403);
            $centralRecordsId = account_role_office_ids($this->actor)['ovp_central_records'] ?? module_office_ids()['ovp_central_records'];
            $records = $this->db->prepare('SELECT id FROM offices WHERE id = ? AND is_active = 1');
            $records->execute(array($centralRecordsId));
            $holder = $records->fetchColumn();
            if (!$holder) $this->fail('The configured Central Records office must be active.');
            $holder = (int) $holder;
            $this->receivingOffice($holder);
            if (isset($input['source_division_id']) && $input['source_division_id'] !== '') {
                $divisionId = filter_var($input['source_division_id'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
                if ($divisionId === false) $this->fail('Select a valid source agency / division.');
                $division = $this->db->prepare('SELECT id, name FROM document_sources WHERE id = ? AND is_active = 1');
                $division->execute(array($divisionId));
            } else {
                // Older clients may send a known source name instead of its catalog ID.
                $division = $this->db->prepare('SELECT id, name FROM document_sources WHERE name = ? AND is_active = 1 ORDER BY id LIMIT 1');
                $division->execute(array($this->text($input, 'external_sender', 255)));
            }
            $source = $division->fetch();
            if (!$source) $this->fail('Select the source agency / division from the document source list.');
            $divisionId = (int) $source['id'];
            $external = $source['name'];
        } else {
            $holder = $this->actingOffice();
            // The source catalog identifies the document owner independently of the registering office.
            if (isset($input['source_division_id']) && $input['source_division_id'] !== '') {
                $divisionId = filter_var($input['source_division_id'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
                if ($divisionId === false) $this->fail('Select a valid source office / agency.');
                $division = $this->db->prepare('SELECT id, name FROM document_sources WHERE id = ? AND is_active = 1');
                $division->execute(array($divisionId));
                $source = $division->fetch();
                if (!$source) $this->fail('Select the source office / agency from the document source list.');
                $external = $source['name'];
            }
        }
        $recipient = empty($input['recipient_office_id']) ? null : filter_var($input['recipient_office_id'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if ($recipient === false) $this->fail('Select a valid intended recipient.');
        if ($recipient) $this->receivingOffice($recipient);
        $details = $this->registrationDetails($input);
        $type = $details['type'];
        // The registration form uses the particulars field instead of a separate subject.
        // Keep a searchable heading for lists and older integrations.
        if ($subject === '') {
            $heading = $description !== '' ? $description : $type;
            preg_match('/^(.{1,255})/us', $heading, $headingMatch);
            $subject = $headingMatch[1] ?? $type;
        }
        $key = $this->text($input, 'submission_key', 80) ?: bin2hex(random_bytes(16));
        if (!preg_match('/^[A-Za-z0-9-]{16,80}$/', $key)) $this->fail('Invalid registration identifier. Reopen the registration form.');
        $attachment = $this->uploadedFile($file, true);
        $hashInput = array($subject, $description, $type, $origin, $divisionId === null ? $external : '', $holder, $recipient, $attachment['name'] ?? null, $attachment['hash'] ?? null,
            $details['type_id'], array_column($details['personnel'], 'id'), $details['travel']);
        if ($divisionId !== null) $hashInput[] = $divisionId;
        $hash = hash('sha256', json_encode($hashInput));
        $existing = $this->registeredSubmission($key, $hash);
        if ($existing) return array('reference_no' => $existing['reference_no'], 'replayed' => true);
        $storage = $attachment ? $this->storeFile($file, $attachment) : null;
        try {
            $result = $this->saveRegistration(array('key' => $key, 'hash' => $hash, 'subject' => $subject, 'description' => $description,
                'type' => $type, 'origin' => $origin, 'external' => $external, 'division_id' => $divisionId, 'holder' => $holder, 'recipient' => $recipient,
                'stored' => $storage['stored'] ?? null, 'mime' => $attachment['mime'] ?? null, 'size' => $attachment['size'] ?? null, 'name' => $attachment['name'] ?? null,
                'type_id' => $details['type_id'], 'personnel' => $details['personnel'], 'travel' => $details['travel']));
            if ($result['replayed'] && $storage) @unlink($storage['path']);
            return $result;
        } catch (Throwable $error) {
            if ($storage) @unlink($storage['path']);
            throw $error;
        }
    }

    // Called only after authorization, validation, and any optional file storage have succeeded.
    private function saveRegistration(array $registration)
    {
        return $this->transaction(function () use ($registration) {
                $key = $registration['key']; $hash = $registration['hash']; $subject = $registration['subject'];
                $description = $registration['description']; $type = $registration['type']; $origin = $registration['origin'];
                $external = $registration['external']; $holder = $registration['holder']; $recipient = $registration['recipient'];
                $stored = $registration['stored']; $mime = $registration['mime']; $size = $registration['size']; $name = $registration['name'];
                $year = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y');
                $seed = $this->db->prepare('INSERT INTO document_number_series (series_year, last_number) VALUES (?, 0) ON DUPLICATE KEY UPDATE series_year = series_year');
                $seed->execute(array($year));
                $counter = $this->db->prepare('SELECT last_number FROM document_number_series WHERE series_year = ? FOR UPDATE');
                $counter->execute(array($year));
                $next = (int) $counter->fetchColumn() + 1;
                // The counter lock also serializes retries of the same registration.
                $existing = $this->registeredSubmission($key, $hash);
                if ($existing) return array('reference_no' => $existing['reference_no'], 'replayed' => true);
                if ($next > 4294967295) $this->fail('The annual document number series is exhausted.', 409);
                $reference = 'PAD-' . $year . '-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
                $update = $this->db->prepare('UPDATE document_number_series SET last_number = ? WHERE series_year = ?');
                $update->execute(array($next, $year));
                // Registration and its first receipt must describe the same instant.
                $this->timestampSql();
                $registeredAt = $this->recordedAt;
                $save = $this->db->prepare('INSERT INTO documents (reference_no, number_year, number_sequence, submission_key, submission_hash, subject, description, document_type, document_type_id,
                    origin_type, external_sender, source_division_id, source_office_id, recipient_office_id, current_office_id, status, file_name, stored_file_name, file_mime, file_size, created_by_user_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $save->execute(array($reference, $year, $next, $key, $hash, $subject, $description, $type, $registration['type_id'] ?? null, $origin, $origin === 'external' || !empty($registration['division_id']) ? $external : null,
                    $registration['division_id'] ?? null, $origin === 'office' ? $holder : null, $recipient, $holder, $origin === 'external' ? 'received' : 'created', $name, $stored, $mime, $size, $this->actor['id'], $registeredAt, $registeredAt));
                $id = (int) $this->db->lastInsertId();
                $savePerson = $this->db->prepare('INSERT INTO document_people (document_id, personnel_id, name_snapshot) VALUES (?, ?, ?)');
                foreach ($registration['personnel'] ?? array() as $person) $savePerson->execute(array($id, $person['id'], $person['name']));
                if (!empty($registration['travel'])) {
                    $travel = $registration['travel'];
                    $saveTravel = $this->db->prepare('INSERT INTO document_travel_details (document_id, destination, purpose, order_date, departure_date, return_date, travel_type, vehicle_requirement, remarks)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $saveTravel->execute(array($id, $travel['destination'], $travel['purpose'], $travel['order_date'], $travel['departure_date'], $travel['return_date'],
                        $travel['travel_type'], $travel['vehicle_requirement'], $travel['remarks'] ?: null));
                }
                $this->event($id, null, $holder, $origin === 'external' ? 'externally_received' : 'created', $description, false, true, $registeredAt);
                return array('reference_no' => $reference, 'replayed' => false);
        });
    }

    private function event($id, $from, $to, $action, $notes, $dispatch = false, $receipt = false, $recordedAt = null)
    {
        $timestamp = $recordedAt ? $this->db->quote($recordedAt) : $this->timestampSql();
        $query = $this->db->prepare('INSERT INTO document_routes (document_id, from_office_id, to_office_id, action, notes, dispatched_at, received_at, acted_by_user_id, actor_role, created_at)
            VALUES (?, ?, ?, ?, ?, ' . ($dispatch ? $timestamp : 'NULL') . ', ' . ($receipt ? $timestamp : 'NULL') . ', ?, ?, ' . $timestamp . ')');
        $query->execute(array($id, $from, $to, $action, $notes ?: null, $this->actor['id'], $this->actor['active_role'] ?? null));
        return (int) $this->db->lastInsertId();
    }

    public function uploadFile($reference, array $file)
    {
        $this->assertWritable();
        $visible = $this->record($reference);
        $office = $this->actingOffice();
        if (!$visible['permissions']['upload_file']) $this->fail('Only the originating or current holding office can upload this document file.', 403);
        $attachment = $this->uploadedFile($file);
        $storage = $this->storeFile($file, $attachment);
        try {
            $previous = $this->transaction(function () use ($reference, $visible, $office, $attachment, $storage) {
                $lock = $this->db->prepare('SELECT id FROM documents WHERE id = ? FOR UPDATE');
                $lock->execute(array($visible['id']));
                $document = $this->record($reference);
                if (!$document['permissions']['upload_file']) $this->fail('The holding office changed. Refresh the document before uploading.', 409);
                $update = $this->db->prepare('UPDATE documents SET file_name = ?, stored_file_name = ?, file_mime = ?, file_size = ?, updated_at = ' . $this->timestampSql() . ' WHERE id = ?');
                $update->execute(array($attachment['name'], $storage['stored'], $attachment['mime'], $attachment['size'], $document['id']));
                $notes = ($document['stored_file_name'] ? 'Updated document file: ' : 'Uploaded document file: ') . $attachment['name'];
                $this->event($document['id'], $office, $office, 'file_uploaded', $notes);
                return $document['stored_file_name'];
            });
        } catch (Throwable $error) {
            @unlink($storage['path']);
            throw $error;
        }
        // Remove the previous file only after the replacement and history are committed.
        if ($previous) @unlink(safe_upload_directory() . DIRECTORY_SEPARATOR . basename($previous));
        return array('reference_no' => $reference, 'message' => 'Document file uploaded.');
    }

    public function deleteDocument($reference)
    {
        $this->assertWritable();
        $office = $this->actingOffice();
        $visible = $this->record($reference);
        $previous = $this->transaction(function () use ($reference, $visible) {
            $lock = $this->db->prepare('SELECT id FROM documents WHERE id = ? FOR UPDATE');
            $lock->execute(array($visible['id']));
            $document = $this->record($reference);
            if (!$document['permissions']['delete'] || $document['pad_review_id']) $this->fail('Only an unsent document encoded by your office can be deleted.', 409);
            // Delete registration details explicitly, including test schemas without cascading foreign keys.
            foreach (array('document_people', 'document_travel_details', 'document_routes') as $table) {
                $this->db->prepare('DELETE FROM ' . $table . ' WHERE document_id = ?')->execute(array($document['id']));
            }
            $this->db->prepare('DELETE FROM documents WHERE id = ?')->execute(array($document['id']));
            // Its annual number remains consumed and cannot be assigned to another document.
            return $document['stored_file_name'];
        });
        if ($previous) @unlink(safe_upload_directory() . DIRECTORY_SEPARATOR . basename($previous));
        return array('reference_no' => $reference, 'message' => 'Unsent document deleted.');
    }

    public function action($reference, $action, array $input)
    {
        $this->assertWritable();
        if (!in_array($action, array('route', 'receive', 'return', 'act', 'complete', 'deliver', 'decide'), true)) $this->fail('Unknown document action.');
        $notes = $this->text($input, 'notes', 10000, in_array($action, array('return', 'act', 'deliver'), true));
        $decision = $action === 'decide' ? $this->text($input, 'decision', 32, true) : null;
        if ($decision !== null && !in_array($decision, array('approved', 'disapproved', 'returned_for_correction'), true)) $this->fail('Select Approved, Disapproved, or Returned for correction.');
        if ($decision !== null && $decision !== 'approved' && $notes === '') $this->fail('A reason or correction instructions are required for this decision.');
        $office = $this->actingOffice();
        $visible = $this->record($reference);
        return $this->transaction(function () use ($visible, $reference, $action, $input, $office, $notes, $decision) {
            $lock = $this->db->prepare('SELECT id FROM documents WHERE id = ? FOR UPDATE');
            $lock->execute(array($visible['id']));
            $document = $this->record($reference);
            if (!$document['permissions'][$action]) $this->fail('This action is no longer available for your office. Refresh the document.', 409);
            if ($action === 'act') {
                if ($document['act_blockers']) $this->fail(implode(' ', $document['act_blockers']));
                if (!is_file(safe_upload_directory() . DIRECTORY_SEPARATOR . basename($document['stored_file_name']))) $this->fail('The attached document file is missing. Open View and upload it before marking the document Acted.');
                if (($input['hard_copy_received'] ?? false) !== true) $this->fail('Confirm that Records has received the hard copy from PAD.');
                $owner = filter_var($input['owner_office_id'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
                if (!$owner || $owner === $office) $this->fail('Select the owner office to receive this document.');
                $destination = $this->receivingOffice($owner);
                if ($document['pending_office_id']) $this->confirmReceipt($document, $office, 'Hard copy from PAD confirmed during Act.');
                $update = $this->db->prepare("UPDATE documents SET current_office_id = ?, recipient_office_id = ?, pending_office_id = ?,
                    movement_type = 'forward', status = 'in_transit', updated_at = " . $this->timestampSql() . ' WHERE id = ?');
                $update->execute(array($office, $owner, $owner, $document['id']));
                $this->event($document['id'], $office, $owner, 'acted', $notes, true);
                return array('reference_no' => $reference, 'message' => 'Acted and sent to ' . $destination['name'] . '. Delivery is confirmed when the owner office receives the hard copy.');
            }
            $context = $this->reviewContext($document);
            if ($action === 'decide') {
                $destination = $context['records'];
                $this->receivingOffice($destination);
                $reviewId = $document['pad_review_id'] ?: $this->createReview($document['id'], $context);
                $update = $this->db->prepare('UPDATE document_pad_reviews SET decision = ?, decision_notes = ?, decided_by_user_id = ?, decided_at = ' . $this->timestampSql() . ' WHERE id = ? AND decision IS NULL');
                $update->execute(array($decision, $notes ?: null, $this->actor['id'], $reviewId));
                $labels = array('approved' => 'Approved', 'disapproved' => 'Disapproved', 'returned_for_correction' => 'Returned for correction');
                $this->event($document['id'], $office, $office, 'pad_decided', $labels[$decision] . ($notes ? ': ' . $notes : ''));
                $routeId = $this->event($document['id'], $office, $destination, 'returned', $labels[$decision] . ($notes ? ': ' . $notes : ''), true);
                $update = $this->db->prepare('UPDATE document_pad_reviews SET result_route_id = ? WHERE id = ?');
                $update->execute(array($routeId, $reviewId));
                $update = $this->db->prepare("UPDATE documents SET pending_office_id = ?, movement_type = 'return', status = 'in_transit', updated_at = " . $this->timestampSql() . ' WHERE id = ?');
                $update->execute(array($destination, $document['id']));
                return array('reference_no' => $reference, 'message' => $labels[$decision] . ' recorded. Sent back to Central Records; awaiting hard-copy receipt.');
            }
            $destination = null;
            if ($action === 'route') {
                $destination = filter_var($input['to_office_id'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
                if (!$destination || $destination === $office) $this->fail('Choose a different destination office.');
                $this->receivingOffice($destination);
            } elseif ($action === 'return') {
                $destination = (int) $document['last_from_office_id'];
                $this->receivingOffice($destination);
            }
            if ($destination) {
                $update = $this->db->prepare("UPDATE documents SET pending_office_id = ?, movement_type = ?, status = 'in_transit', updated_at = " . $this->timestampSql() . ' WHERE id = ?');
                $update->execute(array($destination, $action === 'return' ? 'return' : 'forward', $document['id']));
                $routeId = $this->event($document['id'], $office, $destination, $action === 'return' ? 'returned' : 'routed', $notes, true);
                if (($this->actor['active_role'] ?? '') === 'ovp_central_records' && $this->officeModuleRole($destination) === 'ovp_pad') {
                    $this->createReview($document['id'], array('pad' => $destination, 'records' => $office, 'submission' => $routeId));
                }
            } elseif ($action === 'receive') {
                if ($context && !$document['pad_review_id'] && $office === $context['pad']) $this->createReview($document['id'], $context);
                $this->confirmReceipt($document, $office, $notes);
                if ($document['is_owner_release'] && $office === (int) $document['release_owner_office_id']) {
                    $update = $this->db->prepare("UPDATE documents SET status = 'delivered' WHERE id = ?");
                    $update->execute(array($document['id']));
                    $this->event($document['id'], $office, $office, 'delivered', 'Owner office confirmed hard-copy receipt.' . ($notes ? ' ' . $notes : ''));
                    return array('reference_no' => $reference, 'delivery_confirmed' => true,
                        'message' => 'Owner hard-copy receipt confirmed. Delivery is recorded; the Records action remains in Acted.');
                }
            } else {
                $status = array('act' => 'acted', 'complete' => 'completed', 'deliver' => 'delivered')[$action];
                $update = $this->db->prepare('UPDATE documents SET status = ?, updated_at = ' . $this->timestampSql() . ' WHERE id = ?');
                $update->execute(array($status, $document['id']));
                $this->event($document['id'], $office, $office, $status, $notes);
            }
            return array('reference_no' => $reference, 'message' => array('route' => 'Document sent. Awaiting destination receipt.', 'return' => 'Document returned. Awaiting receipt.',
                'receive' => 'Physical receipt confirmed on the existing PAD document.', 'act' => 'Document marked Acted. Central Records processing is finished.', 'complete' => 'Document marked completed.', 'deliver' => 'Document delivery recorded.')[$action]);
        });
    }

    private function confirmReceipt(array $document, $office, $notes)
    {
        $update = $this->db->prepare("UPDATE documents SET current_office_id = ?, pending_office_id = NULL, status = 'received', updated_at = " . $this->timestampSql() . ' WHERE id = ?');
        $update->execute(array($office, $document['id']));
        $close = $this->db->prepare('UPDATE document_routes SET received_at = ' . $this->timestampSql() . ' WHERE document_id = ? AND dispatched_at IS NOT NULL AND received_at IS NULL ORDER BY id DESC LIMIT 1');
        $close->execute(array($document['id']));
        $this->event($document['id'], $document['current_office_id'], $office, 'received', $notes, false, true);
    }
}
