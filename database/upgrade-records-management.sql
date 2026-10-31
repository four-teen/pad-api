-- Select the live PAD API database in phpMyAdmin before running this script.
-- Adds missing schema only; existing records and saved values are retained.

CREATE TABLE IF NOT EXISTS `record_changes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `entity_type` VARCHAR(20) NOT NULL,
    `entity_id` INT UNSIGNED NOT NULL,
    `entity_reference` VARCHAR(255) NOT NULL,
    `action` VARCHAR(40) NOT NULL,
    `before_data` MEDIUMTEXT NOT NULL,
    `after_data` MEDIUMTEXT NOT NULL,
    `reason` VARCHAR(2000) NOT NULL,
    `actor_user_id` INT UNSIGNED NOT NULL,
    `actor_name` VARCHAR(255) NOT NULL,
    `actor_role` VARCHAR(40) NOT NULL,
    `actor_office_id` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    KEY `record_changes_entity_index` (`entity_type`, `entity_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conditional ALTER statements support both MySQL and MariaDB.
SET @pad_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_personnel' AND COLUMN_NAME = 'email'),
    'SELECT ''email already exists'' AS status',
    'ALTER TABLE `document_personnel` ADD COLUMN `email` VARCHAR(191) DEFAULT NULL'
);
PREPARE pad_upgrade FROM @pad_sql;
EXECUTE pad_upgrade;
DEALLOCATE PREPARE pad_upgrade;

SET @pad_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_personnel' AND COLUMN_NAME = 'position'),
    'SELECT ''position already exists'' AS status',
    'ALTER TABLE `document_personnel` ADD COLUMN `position` VARCHAR(255) DEFAULT NULL'
);
PREPARE pad_upgrade FROM @pad_sql;
EXECUTE pad_upgrade;
DEALLOCATE PREPARE pad_upgrade;

SET @pad_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_personnel' AND COLUMN_NAME = 'campus'),
    'SELECT ''campus already exists'' AS status',
    'ALTER TABLE `document_personnel` ADD COLUMN `campus` VARCHAR(80) DEFAULT NULL'
);
PREPARE pad_upgrade FROM @pad_sql;
EXECUTE pad_upgrade;
DEALLOCATE PREPARE pad_upgrade;

-- Verify the required table and contact columns.
SHOW COLUMNS FROM `record_changes`;
SHOW COLUMNS FROM `document_personnel`;
