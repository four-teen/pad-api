<?php

// The annual counter is shared by every registration module and administrator edit.
final class DocumentFileCodes
{
    const MAX_NUMBER = 4294967295;
    private $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public static function currentYear()
    {
        return (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y');
    }

    public static function format($year, $number)
    {
        return substr((string) $year, -2) . '-' . str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }

    private function integer($value, $minimum, $maximum, $label)
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]+$/', (string) $value)
            || (float) $value < $minimum || (float) $value > $maximum) {
            throw new ApiException($label . ' must be a whole number from ' . $minimum . ' to ' . $maximum . '.', 422);
        }
        return (int) $value;
    }

    private function year($value) { return $this->integer($value, 2000, 2099, 'Year'); }

    private function documentMaximum($year)
    {
        $query = $this->db->prepare('SELECT MAX(number_sequence) FROM documents WHERE number_year = ?');
        $query->execute(array($year));
        $maximum = (int) $query->fetchColumn();
        // Imported references may have no numbering metadata. Reserve their numeric
        // portion too, including legacy revision suffixes such as 26-06549_1.
        foreach (array(substr((string) $year, -2) . '-', 'PAD-' . $year . '-') as $prefix) {
            $query = $this->db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING(reference_no, ?), '_', 1) AS UNSIGNED))
                FROM documents WHERE reference_no LIKE ? AND reference_no REGEXP ?");
            $query->execute(array(strlen($prefix) + 1, $prefix . '%', '^' . $prefix . '[0-9]{1,10}(_[0-9]+)?$'));
            $maximum = max($maximum, (int) $query->fetchColumn());
        }
        return $maximum;
    }

    // Call inside the registration/save transaction, before reading document maxima.
    public function lockYear($year)
    {
        if (!$this->db->inTransaction()) throw new LogicException('File code allocation requires a transaction.');
        $seed = $this->db->prepare('INSERT INTO document_number_series (series_year, last_number) VALUES (?, 0) ON DUPLICATE KEY UPDATE series_year = series_year');
        $seed->execute(array($year));
        $query = $this->db->prepare('SELECT last_number FROM document_number_series WHERE series_year = ? FOR UPDATE');
        $query->execute(array($year));
        return max((int) $query->fetchColumn(), $this->documentMaximum($year));
    }

    public function setLastNumber($year, $number)
    {
        $query = $this->db->prepare('UPDATE document_number_series SET last_number = ? WHERE series_year = ?');
        $query->execute(array($number, $year));
    }

    private function row($year, $last)
    {
        $next = $last < self::MAX_NUMBER ? $last + 1 : null;
        return array('year' => (int) $year, 'last_number' => $last, 'next_number' => $next,
            'preview' => $next === null ? null : self::format($year, $next));
    }

    public function settings($selectedYear = null)
    {
        $current = self::currentYear();
        $year = $this->year($selectedYear === null ? $current : $selectedYear);
        $query = $this->db->prepare('SELECT last_number FROM document_number_series WHERE series_year = ?');
        $query->execute(array($year));
        $selected = $this->row($year, max((int) $query->fetchColumn(), $this->documentMaximum($year)));
        $series = array();
        foreach ($this->db->query('SELECT series_year, last_number FROM document_number_series ORDER BY series_year DESC')->fetchAll() as $row) {
            $series[(int) $row['series_year']] = $this->row((int) $row['series_year'], max((int) $row['last_number'], $this->documentMaximum((int) $row['series_year'])));
        }
        $series[$year] = $selected;
        krsort($series);
        return array('current_year' => $current, 'format' => 'YY-NNNNN', 'selected' => $selected, 'series' => array_values($series));
    }

    public function save(array $input)
    {
        $year = $this->year($input['year'] ?? null);
        $next = $this->integer($input['next_number'] ?? null, 1, self::MAX_NUMBER, 'Next number');
        $expected = $this->integer($input['expected_next_number'] ?? null, 1, self::MAX_NUMBER, 'Previously displayed next number');
        $this->db->beginTransaction();
        try {
            $last = $this->lockYear($year);
            if ($last + 1 !== $expected) throw new ApiException('The file code series changed. Refresh the settings before saving again.', 409);
            if ($next <= $last) throw new ApiException('The next number must be at least ' . ($last + 1) . '. Issued or reserved numbers cannot be reused.', 422);
            $this->setLastNumber($year, $next - 1);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        return $this->settings($year);
    }
}
