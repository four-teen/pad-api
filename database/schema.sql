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
  name VARCHAR(160) NOT NULL,
  email VARCHAR(191) NOT NULL,
  avatar_url VARCHAR(500) DEFAULT NULL,
  google_subject VARCHAR(191) DEFAULT NULL,
  role ENUM('staff', 'records', 'pad', 'admin') NOT NULL DEFAULT 'staff',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY users_email_unique (email),
  UNIQUE KEY users_google_subject_unique (google_subject),
  KEY users_office_id_index (office_id),
  CONSTRAINT users_office_id_foreign FOREIGN KEY (office_id) REFERENCES offices (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE documents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference_no VARCHAR(40) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  origin_type ENUM('office', 'external') NOT NULL,
  external_sender VARCHAR(255) DEFAULT NULL,
  source_office_id INT UNSIGNED DEFAULT NULL,
  recipient_office_id INT UNSIGNED DEFAULT NULL,
  current_office_id INT UNSIGNED DEFAULT NULL,
  status ENUM('created', 'in_transit', 'received', 'completed') NOT NULL DEFAULT 'created',
  file_name VARCHAR(255) NOT NULL,
  stored_file_name VARCHAR(255) NOT NULL,
  file_mime VARCHAR(150) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  created_by_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY documents_reference_no_unique (reference_no),
  UNIQUE KEY documents_stored_file_name_unique (stored_file_name),
  KEY documents_current_office_status_index (current_office_id, status),
  KEY documents_source_office_id_index (source_office_id),
  KEY documents_recipient_office_id_index (recipient_office_id),
  CONSTRAINT documents_source_office_id_foreign FOREIGN KEY (source_office_id) REFERENCES offices (id) ON DELETE SET NULL,
  CONSTRAINT documents_recipient_office_id_foreign FOREIGN KEY (recipient_office_id) REFERENCES offices (id) ON DELETE SET NULL,
  CONSTRAINT documents_current_office_id_foreign FOREIGN KEY (current_office_id) REFERENCES offices (id) ON DELETE SET NULL,
  CONSTRAINT documents_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_routes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id INT UNSIGNED NOT NULL,
  from_office_id INT UNSIGNED DEFAULT NULL,
  to_office_id INT UNSIGNED DEFAULT NULL,
  action ENUM('created', 'externally_received', 'routed', 'received') NOT NULL,
  notes TEXT DEFAULT NULL,
  dispatched_at DATETIME DEFAULT NULL,
  received_at DATETIME DEFAULT NULL,
  acted_by_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY document_routes_document_id_created_at_index (document_id, created_at),
  CONSTRAINT document_routes_document_id_foreign FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  CONSTRAINT document_routes_from_office_id_foreign FOREIGN KEY (from_office_id) REFERENCES offices (id) ON DELETE SET NULL,
  CONSTRAINT document_routes_to_office_id_foreign FOREIGN KEY (to_office_id) REFERENCES offices (id) ON DELETE SET NULL,
  CONSTRAINT document_routes_acted_by_user_id_foreign FOREIGN KEY (acted_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Core routing offices. Add all operational offices in phpMyAdmin or through an admin screen later.
INSERT INTO offices (code, name, description, is_active, created_at, updated_at) VALUES
('RECORDS', 'Records Office', 'Central receiving and release office', 1, NOW(), NOW()),
('PAD', 'Presidential Action Desk', 'Presidential signature routing desk', 1, NOW(), NOW());
