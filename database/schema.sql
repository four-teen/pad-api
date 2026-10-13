-- PAD — Presidential Action Desk
-- Electronic Document Routing, Tracking, and Executive Action System
-- Compatible with MySQL 5.7+ and MariaDB 10.3+.
-- Import this once from cPanel phpMyAdmin after creating the database/user.

CREATE TABLE offices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(1000) DEFAULT NULL,
  domain VARCHAR(160) NOT NULL DEFAULT '',
  campus VARCHAR(80) NOT NULL DEFAULT '',
  contact_email VARCHAR(191) NOT NULL DEFAULT '',
  source_url VARCHAR(500) DEFAULT NULL,
  source_checked_at DATE DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY offices_code_unique (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE office_officials (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  office_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  position VARCHAR(255) NOT NULL,
  email VARCHAR(191) NOT NULL DEFAULT '',
  alternate_email VARCHAR(191) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY office_officials_office_index (office_id),
  CONSTRAINT office_officials_office_foreign FOREIGN KEY (office_id) REFERENCES offices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  office_id INT UNSIGNED DEFAULT NULL,
  ovp_pad_office_id INT UNSIGNED DEFAULT NULL,
  ovp_central_records_office_id INT UNSIGNED DEFAULT NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(191) NOT NULL,
  avatar_url VARCHAR(500) DEFAULT NULL,
  google_subject VARCHAR(191) DEFAULT NULL,
  role ENUM('staff', 'records', 'pad', 'admin', 'router') NOT NULL DEFAULT 'router',
  roles SET('admin', 'router', 'ovp_pad', 'ovp_central_records', 'sksu_officials') NOT NULL DEFAULT 'router',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY users_email_unique (email),
  UNIQUE KEY users_google_subject_unique (google_subject),
  KEY users_office_id_index (office_id),
  CONSTRAINT users_office_id_foreign FOREIGN KEY (office_id) REFERENCES offices (id) ON DELETE SET NULL,
  CONSTRAINT users_ovp_pad_office_id_foreign FOREIGN KEY (ovp_pad_office_id) REFERENCES offices (id) ON DELETE RESTRICT,
  CONSTRAINT users_ovp_central_records_office_id_foreign FOREIGN KEY (ovp_central_records_office_id) REFERENCES offices (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  legacy_type_id INT UNSIGNED DEFAULT NULL,
  name VARCHAR(120) NOT NULL,
  details_kind ENUM('personnel', 'travel_order') NOT NULL DEFAULT 'personnel',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY document_types_legacy_unique (legacy_type_id),
  UNIQUE KEY document_types_name_unique (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_personnel (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  legacy_profile_id INT UNSIGNED DEFAULT NULL,
  name VARCHAR(160) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY document_personnel_legacy_unique (legacy_profile_id),
  KEY document_personnel_name_index (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_sources (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  legacy_division_id INT UNSIGNED DEFAULT NULL,
  name VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY document_sources_legacy_unique (legacy_division_id),
  KEY document_sources_name_index (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE documents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference_no VARCHAR(40) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  document_type VARCHAR(120) NOT NULL DEFAULT 'General document',
  document_type_id INT UNSIGNED DEFAULT NULL,
  origin_type ENUM('office', 'external') NOT NULL,
  external_sender VARCHAR(255) DEFAULT NULL,
  source_division_id INT UNSIGNED DEFAULT NULL,
  source_office_id INT UNSIGNED DEFAULT NULL,
  recipient_office_id INT UNSIGNED DEFAULT NULL,
  current_office_id INT UNSIGNED DEFAULT NULL,
  pending_office_id INT UNSIGNED DEFAULT NULL,
  movement_type ENUM('forward', 'return') NOT NULL DEFAULT 'forward',
  number_year SMALLINT UNSIGNED DEFAULT NULL,
  number_sequence INT UNSIGNED DEFAULT NULL,
  submission_key VARCHAR(80) DEFAULT NULL,
  submission_hash CHAR(64) DEFAULT NULL,
  status ENUM('created', 'in_transit', 'received', 'acted', 'completed', 'delivered') NOT NULL DEFAULT 'created',
  file_name VARCHAR(255) DEFAULT NULL,
  stored_file_name VARCHAR(255) DEFAULT NULL,
  file_mime VARCHAR(150) DEFAULT NULL,
  file_size INT UNSIGNED DEFAULT NULL,
  created_by_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY documents_reference_no_unique (reference_no),
  UNIQUE KEY documents_number_unique (number_year, number_sequence),
  UNIQUE KEY documents_submission_unique (created_by_user_id, submission_key),
  UNIQUE KEY documents_stored_file_name_unique (stored_file_name),
  KEY documents_current_office_status_index (current_office_id, status),
  KEY documents_pending_office_index (pending_office_id, status),
  KEY documents_source_office_id_index (source_office_id),
  KEY documents_recipient_office_id_index (recipient_office_id),
  CONSTRAINT documents_type_foreign FOREIGN KEY (document_type_id) REFERENCES document_types (id),
  CONSTRAINT documents_division_foreign FOREIGN KEY (source_division_id) REFERENCES document_sources (id),
  CONSTRAINT documents_source_office_id_foreign FOREIGN KEY (source_office_id) REFERENCES offices (id) ON DELETE SET NULL,
  CONSTRAINT documents_recipient_office_id_foreign FOREIGN KEY (recipient_office_id) REFERENCES offices (id) ON DELETE SET NULL,
  CONSTRAINT documents_current_office_id_foreign FOREIGN KEY (current_office_id) REFERENCES offices (id) ON DELETE SET NULL,
  CONSTRAINT documents_pending_office_foreign FOREIGN KEY (pending_office_id) REFERENCES offices (id) ON DELETE SET NULL,
  CONSTRAINT documents_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_people (
  document_id INT UNSIGNED NOT NULL,
  personnel_id INT UNSIGNED NOT NULL,
  name_snapshot VARCHAR(160) NOT NULL,
  PRIMARY KEY (document_id, personnel_id),
  CONSTRAINT document_people_document_foreign FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  CONSTRAINT document_people_personnel_foreign FOREIGN KEY (personnel_id) REFERENCES document_personnel (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_travel_details (
  document_id INT UNSIGNED NOT NULL,
  destination TEXT NOT NULL,
  purpose TEXT NOT NULL,
  order_date DATE NOT NULL,
  departure_date DATE NOT NULL,
  return_date DATE NOT NULL,
  travel_type ENUM('Official Time', 'Official Business') NOT NULL,
  vehicle_requirement ENUM('Yes', 'Not Necessary') NOT NULL,
  remarks TEXT DEFAULT NULL,
  PRIMARY KEY (document_id),
  CONSTRAINT document_travel_document_foreign FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_routes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id INT UNSIGNED NOT NULL,
  from_office_id INT UNSIGNED DEFAULT NULL,
  to_office_id INT UNSIGNED DEFAULT NULL,
  action ENUM('created', 'externally_received', 'routed', 'received', 'returned', 'acted', 'completed', 'delivered', 'file_uploaded', 'pad_decided') NOT NULL,
  notes TEXT DEFAULT NULL,
  dispatched_at DATETIME DEFAULT NULL,
  received_at DATETIME DEFAULT NULL,
  acted_by_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  actor_role VARCHAR(32) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY document_routes_document_id_created_at_index (document_id, created_at),
  KEY routes_from_office_document_index (from_office_id, document_id),
  KEY routes_to_office_document_index (to_office_id, document_id),
  CONSTRAINT document_routes_document_id_foreign FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  CONSTRAINT document_routes_from_office_id_foreign FOREIGN KEY (from_office_id) REFERENCES offices (id) ON DELETE SET NULL,
  CONSTRAINT document_routes_to_office_id_foreign FOREIGN KEY (to_office_id) REFERENCES offices (id) ON DELETE SET NULL,
  CONSTRAINT document_routes_acted_by_user_id_foreign FOREIGN KEY (acted_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_pad_reviews (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_number_series (
  series_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
  last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Core routing offices. Add all operational offices in phpMyAdmin or through an admin screen later.
CREATE TABLE admin_maintenance_events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO offices (code, name, description, is_active, created_at, updated_at) VALUES
('RECORDS', 'Records Office', 'Central receiving and release office', 1, NOW(), NOW()),
('PAD', 'Presidential Action Desk', 'Presidential signature routing desk', 1, NOW(), NOW());
