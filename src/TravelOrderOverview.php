<?php

final class TravelOrderOverview
{
    private $db;
    private $now;
    private $today;
    private $tomorrow;

    public function __construct(PDO $db, DateTimeImmutable $now = null)
    {
        $this->db = $db;
        $this->now = ($now ?: new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'));
        $this->today = $this->now->format('Y-m-d');
        $this->tomorrow = $this->now->modify('+1 day')->format('Y-m-d');
    }

    public static function isTravelOrder($name)
    {
        return is_string($name) && strtoupper(preg_replace('/\s+/u', ' ', trim($name))) === 'TRAVEL ORDER';
    }

    public function condition($window, array &$parameters)
    {
        if (!is_string($window) || !in_array($window, array('all', 'ongoing', 'today', 'tomorrow', 'incoming', 'completed'), true)) {
            throw new ApiException('Select a valid travel period.', 422);
        }
        if ($window === 'all') return '1 = 1';
        $valid = "t.departure_date >= '1000-01-01' AND t.return_date >= t.departure_date";
        if ($window === 'incoming') {
            $parameters[] = $this->tomorrow;
            return $valid . ' AND t.departure_date > ?';
        }
        if ($window === 'ongoing') {
            $parameters[] = $this->today;
            $parameters[] = $this->today;
            return $valid . ' AND t.departure_date < ? AND t.return_date >= ?';
        }
        if ($window === 'completed') {
            $parameters[] = $this->today;
            return $valid . ' AND t.return_date < ?';
        }
        $parameters[] = $window === 'today' ? $this->today : $this->tomorrow;
        return $valid . ' AND t.departure_date = ?';
    }

    public function summary($authorizedWhere, array $parameters)
    {
        $select = array('COUNT(*) AS total');
        $dateParameters = array();
        foreach (array('ongoing', 'today', 'tomorrow', 'incoming', 'completed') as $window) {
            $select[] = 'COALESCE(SUM(CASE WHEN ' . $this->condition($window, $dateParameters) . ' THEN 1 ELSE 0 END), 0) AS ' . $window;
        }
        $select[] = "COALESCE(SUM(CASE WHEN t.document_id IS NULL OR t.departure_date < '1000-01-01' OR t.return_date < t.departure_date THEN 1 ELSE 0 END), 0) AS unscheduled";
        $query = $this->db->prepare('SELECT ' . implode(', ', $select) . ' FROM documents d LEFT JOIN document_travel_details t ON t.document_id = d.id WHERE ' . $authorizedWhere);
        $query->execute(array_merge($dateParameters, $parameters));
        $counts = array_map('intval', $query->fetch());
        return array('today' => $this->today, 'tomorrow' => $this->tomorrow, 'display_timezone' => 'Asia/Manila',
            'server_time' => $this->now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'counts' => $counts);
    }

    public function details(array $documents)
    {
        if (!$documents) return $documents;
        // Only hydrate the authorized page; employee names are recorded snapshots.
        $ids = array_column($documents, 'id');
        $slots = implode(',', array_fill(0, count($ids), '?'));
        $query = $this->db->prepare('SELECT document_id, destination, purpose, order_date, departure_date, return_date, travel_type, vehicle_requirement, remarks FROM document_travel_details WHERE document_id IN (' . $slots . ')');
        $query->execute($ids);
        $travel = array();
        foreach ($query->fetchAll() as $row) { $id = $row['document_id']; unset($row['document_id']); $travel[$id] = $row; }
        $query = $this->db->prepare('SELECT document_id, personnel_id AS id, name_snapshot AS name FROM document_people WHERE document_id IN (' . $slots . ') ORDER BY name_snapshot, personnel_id');
        $query->execute($ids);
        $people = array();
        foreach ($query->fetchAll() as $row) { $id = $row['document_id']; unset($row['document_id']); $people[$id][] = $row; }
        foreach ($documents as &$document) {
            $document['travel_details'] = $travel[$document['id']] ?? null;
            $document['personnel'] = $people[$document['id']] ?? array();
        }
        unset($document);
        return $documents;
    }
}
