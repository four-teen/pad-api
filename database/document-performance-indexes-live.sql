-- Document query indexes for an existing PAD installation.
-- In phpMyAdmin, select the intended PAD database first, then run this entire file.
-- This script only adds the two indexes below. It does not change rows or columns.
-- It skips matching indexes (including equivalent indexes with another name).
-- It skips changes if the database/schema preflight fails and skips a conflicting
-- index name for manual review. Unsupported online DDL fails closed.

SELECT DATABASE() AS selected_database, VERSION() AS server_version;

SET SESSION lock_wait_timeout = 10;
SET @pad_docperf_schema = DATABASE();

-- Require the expected InnoDB tables and every indexed column before changing either table.
SELECT
    @pad_docperf_schema IS NOT NULL
    AND (SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = @pad_docperf_schema AND table_name = 'documents'
           AND table_type = 'BASE TABLE' AND engine = 'InnoDB') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = @pad_docperf_schema AND table_name = 'documents'
           AND column_name IN ('updated_at', 'id')) = 2
    AND (SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = @pad_docperf_schema AND table_name = 'document_routes'
           AND table_type = 'BASE TABLE' AND engine = 'InnoDB') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = @pad_docperf_schema AND table_name = 'document_routes'
           AND column_name IN ('document_id', 'id', 'dispatched_at', 'received_at', 'action', 'actor_role')) = 6
INTO @pad_docperf_schema_ready;

SELECT @pad_docperf_schema AS selected_database,
       CASE WHEN @pad_docperf_schema IS NULL THEN 'STOP: select the PAD database first.'
            WHEN @pad_docperf_schema_ready = 0 THEN 'STOP: expected InnoDB tables or columns are missing; no index changes will run.'
            ELSE 'Preflight passed; index checks may proceed.' END AS preflight;

-- An existing full ascending index with these columns as its leading columns is sufficient.
SELECT COUNT(*) INTO @pad_docperf_documents_index_present
FROM (
    SELECT index_name,
           GROUP_CONCAT(CASE WHEN seq_in_index <= 2 THEN column_name END
                        ORDER BY seq_in_index SEPARATOR ',') AS indexed_prefix,
           SUM(CASE WHEN seq_in_index <= 2
                         AND (COALESCE(collation, '') <> 'A' OR sub_part IS NOT NULL)
                    THEN 1 ELSE 0 END) AS unsupported_prefix_parts
    FROM information_schema.statistics
    WHERE table_schema = @pad_docperf_schema AND table_name = 'documents'
    GROUP BY index_name
) AS existing_indexes
WHERE indexed_prefix = 'updated_at,id' AND unsupported_prefix_parts = 0;

SELECT COUNT(*) INTO @pad_docperf_documents_name_conflict
FROM (
    SELECT index_name,
           GROUP_CONCAT(CASE WHEN seq_in_index <= 2 THEN column_name END
                        ORDER BY seq_in_index SEPARATOR ',') AS indexed_prefix,
           SUM(CASE WHEN seq_in_index <= 2
                         AND (COALESCE(collation, '') <> 'A' OR sub_part IS NOT NULL)
                    THEN 1 ELSE 0 END) AS unsupported_prefix_parts
    FROM information_schema.statistics
    WHERE table_schema = @pad_docperf_schema AND table_name = 'documents'
    GROUP BY index_name
) AS existing_indexes
WHERE index_name = 'documents_updated_id_index'
  AND NOT (indexed_prefix = 'updated_at,id' AND unsupported_prefix_parts = 0);

SET @pad_docperf_documents_sql = CASE
    WHEN COALESCE(@pad_docperf_schema_ready, 0) = 0
        THEN 'SELECT ''SKIPPED: database/schema preflight failed; no document index change.'' AS result'
    WHEN COALESCE(@pad_docperf_documents_name_conflict, 0) > 0
        THEN 'SELECT ''SKIPPED: documents_updated_id_index exists with different columns; review it first.'' AS result'
    WHEN COALESCE(@pad_docperf_documents_index_present, 0) > 0
        THEN 'SELECT ''OK: documents already has an equivalent updated_at, id index; no change.'' AS result'
    ELSE 'ALTER TABLE `documents` ADD INDEX `documents_updated_id_index` (`updated_at`, `id`), ALGORITHM=INPLACE, LOCK=NONE'
END;
PREPARE pad_docperf_documents_stmt FROM @pad_docperf_documents_sql;
EXECUTE pad_docperf_documents_stmt;
DEALLOCATE PREPARE pad_docperf_documents_stmt;

SELECT COUNT(*) INTO @pad_docperf_routes_index_present
FROM (
    SELECT index_name,
           GROUP_CONCAT(CASE WHEN seq_in_index <= 6 THEN column_name END
                        ORDER BY seq_in_index SEPARATOR ',') AS indexed_prefix,
           SUM(CASE WHEN seq_in_index <= 6
                         AND (COALESCE(collation, '') <> 'A' OR sub_part IS NOT NULL)
                    THEN 1 ELSE 0 END) AS unsupported_prefix_parts
    FROM information_schema.statistics
    WHERE table_schema = @pad_docperf_schema AND table_name = 'document_routes'
    GROUP BY index_name
) AS existing_indexes
WHERE indexed_prefix = 'document_id,id,dispatched_at,received_at,action,actor_role'
  AND unsupported_prefix_parts = 0;

SELECT COUNT(*) INTO @pad_docperf_routes_name_conflict
FROM (
    SELECT index_name,
           GROUP_CONCAT(CASE WHEN seq_in_index <= 6 THEN column_name END
                        ORDER BY seq_in_index SEPARATOR ',') AS indexed_prefix,
           SUM(CASE WHEN seq_in_index <= 6
                         AND (COALESCE(collation, '') <> 'A' OR sub_part IS NOT NULL)
                    THEN 1 ELSE 0 END) AS unsupported_prefix_parts
    FROM information_schema.statistics
    WHERE table_schema = @pad_docperf_schema AND table_name = 'document_routes'
    GROUP BY index_name
) AS existing_indexes
WHERE index_name = 'routes_document_latest_index'
  AND NOT (indexed_prefix = 'document_id,id,dispatched_at,received_at,action,actor_role'
           AND unsupported_prefix_parts = 0);

SET @pad_docperf_routes_sql = CASE
    WHEN COALESCE(@pad_docperf_schema_ready, 0) = 0
        THEN 'SELECT ''SKIPPED: database/schema preflight failed; no route index change.'' AS result'
    WHEN COALESCE(@pad_docperf_routes_name_conflict, 0) > 0
        THEN 'SELECT ''SKIPPED: routes_document_latest_index exists with different columns; review it first.'' AS result'
    WHEN COALESCE(@pad_docperf_routes_index_present, 0) > 0
        THEN 'SELECT ''OK: document_routes already has an equivalent route index; no change.'' AS result'
    ELSE 'ALTER TABLE `document_routes` ADD INDEX `routes_document_latest_index` (`document_id`, `id`, `dispatched_at`, `received_at`, `action`, `actor_role`), ALGORITHM=INPLACE, LOCK=NONE'
END;
PREPARE pad_docperf_routes_stmt FROM @pad_docperf_routes_sql;
EXECUTE pad_docperf_routes_stmt;
DEALLOCATE PREPARE pad_docperf_routes_stmt;

-- Final verification: both required leading-column sequences must now exist.
SELECT table_name, index_name, indexed_prefix AS required_leading_columns
FROM (
    SELECT table_name, index_name,
           GROUP_CONCAT(CASE
                            WHEN table_name = 'documents' AND seq_in_index <= 2 THEN column_name
                            WHEN table_name = 'document_routes' AND seq_in_index <= 6 THEN column_name
                        END ORDER BY seq_in_index SEPARATOR ', ') AS indexed_prefix,
           SUM(CASE
                   WHEN table_name = 'documents' AND seq_in_index <= 2
                        AND (COALESCE(collation, '') <> 'A' OR sub_part IS NOT NULL) THEN 1
                   WHEN table_name = 'document_routes' AND seq_in_index <= 6
                        AND (COALESCE(collation, '') <> 'A' OR sub_part IS NOT NULL) THEN 1
                   ELSE 0
               END) AS unsupported_prefix_parts
    FROM information_schema.statistics
    WHERE table_schema = @pad_docperf_schema
      AND table_name IN ('documents', 'document_routes')
    GROUP BY table_name, index_name
) AS verified_indexes
WHERE (table_name = 'documents' AND indexed_prefix = 'updated_at, id' AND unsupported_prefix_parts = 0)
   OR (table_name = 'document_routes'
       AND indexed_prefix = 'document_id, id, dispatched_at, received_at, action, actor_role'
       AND unsupported_prefix_parts = 0)
ORDER BY table_name, index_name;
