<?php

// Explicit CLI setup only. Existing documents and historical decisions are never inferred.
function install_pad_reviews(PDO $db)
{
    $columns = array_column($db->query('SHOW COLUMNS FROM document_routes')->fetchAll(), 'Type', 'Field');
    if (!isset($columns['actor_role'])) $db->exec('ALTER TABLE document_routes ADD COLUMN actor_role VARCHAR(32) DEFAULT NULL');
    if (strpos($columns['action'], "'pad_decided'") === false) {
        // Extend the existing enum, retaining every previously installed action.
        $enum = substr($columns['action'], 0, -1) . ",'pad_decided')";
        $db->exec('ALTER TABLE document_routes MODIFY action ' . $enum . ' NOT NULL');
    }
    $db->exec("CREATE TABLE IF NOT EXISTS document_pad_reviews (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        document_id INT UNSIGNED NOT NULL,
        submission_route_id INT UNSIGNED NOT NULL,
        pad_office_id INT UNSIGNED NOT NULL,
        central_records_office_id INT UNSIGNED NOT NULL,
        decision ENUM('approved', 'disapproved', 'returned_for_correction') DEFAULT NULL,
        decision_notes TEXT DEFAULT NULL,
        decided_by_user_id INT UNSIGNED DEFAULT NULL,
        decided_at DATETIME DEFAULT NULL,
        result_route_id INT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY pad_review_submission_unique (submission_route_id),
        UNIQUE KEY pad_review_result_unique (result_route_id),
        KEY pad_review_document_index (document_id, id),
        KEY pad_review_office_index (pad_office_id, decision),
        CONSTRAINT pad_review_document_foreign FOREIGN KEY (document_id) REFERENCES documents (id),
        CONSTRAINT pad_review_submission_foreign FOREIGN KEY (submission_route_id) REFERENCES document_routes (id),
        CONSTRAINT pad_review_result_foreign FOREIGN KEY (result_route_id) REFERENCES document_routes (id),
        CONSTRAINT pad_review_pad_foreign FOREIGN KEY (pad_office_id) REFERENCES offices (id),
        CONSTRAINT pad_review_records_foreign FOREIGN KEY (central_records_office_id) REFERENCES offices (id),
        CONSTRAINT pad_review_actor_foreign FOREIGN KEY (decided_by_user_id) REFERENCES users (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
