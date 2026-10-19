# Document query tuning

Existing installations:

```powershell
php scripts/setup-document-performance.php --check
php scripts/setup-document-performance.php
php scripts/setup-document-performance.php --check
```

Run this in the API project using its existing database configuration. The office-routing and PAD-review schema must already be installed. The script preflights both tables, reuses equivalent existing indexes, and only adds missing indexes. Repeating it is safe. It requests `ALGORITHM=INPLACE, LOCK=NONE` and bounds metadata-lock waiting to 10 seconds; unsupported operations fail instead of silently falling back to a table-copy migration. No document fields, counters, files, decisions, or history are rewritten. No schema checks or DDL run inside HTTP requests.

For a live database accessed through phpMyAdmin, run [document-performance-indexes-live.sql](../database/document-performance-indexes-live.sql) after selecting the PAD database. It reports the selected database and server version, checks both tables and all required columns before making either change, reuses equivalent leading-column indexes, and skips an index name already used for different columns. Run the complete file in one SQL session so its preflight and prepared statements share the selected database/session. Confirm the final result lists both required indexes. If the preflight says `STOP` or the final result is missing an index, stop and share the exact message rather than changing the `ALGORITHM` or `LOCK` clauses.

| Table | Index columns | Purpose |
| --- | --- | --- |
| `documents` | `updated_at, id` | Read timeline pages in their existing stable descending order. |
| `document_routes` | `document_id, id, dispatched_at, received_at, action, actor_role` | Cover latest handoff, receipt, and Records-release lookups without reading large route notes. |

These are additive secondary indexes; existing unique keys, foreign keys, office indexes, and chronological-history indexes remain in place. Each new route/document update also maintains its new index, so the change is limited to these two measured indexes. New installations receive them through `database/schema.sql`.

The document service now:

- Reads the newest matching route using descending document/route IDs and `LIMIT 1`, preserving the existing ID-based definition of latest. Including `document_id` in the ordering avoids a correlated-subquery filesort observed on MySQL 5.7.25.
- Shares one latest dispatch and review across the sidebar count expressions. Boolean expressions preserve `EXISTS` semantics for nullable legacy fields, including reviews without a result route.
- Uses indexed, office-specific participant sets for visibility instead of repeatedly scanning each document's history. Active role and office restrictions continue to apply on the server.
- Selects the authorized 20-document page before loading detailed metadata; filtered totals only join office names needed by search.
- Uses the same latest-route ordering in the statistics snapshot. All Timeline scope, receipt requirements, For Action, Returned, and Acted/archive behavior stay consistent with the existing workflow.

Free-text search retains substring matching, including office names. These indexes improve routing lookups and ordering; a broad substring search can still scan candidate documents. MySQL documents the index conditions for [ORDER BY](https://dev.mysql.com/doc/refman/8.0/en/order-by-optimization.html) and [range/LIKE matching](https://dev.mysql.com/doc/refman/8.4/en/range-optimization.html). See also [online InnoDB DDL](https://dev.mysql.com/doc/refman/8.3/en/innodb-online-ddl-operations.html) and [MariaDB INPLACE operations](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-online-ddl/innodb-online-ddl-operations-with-the-inplace-alter-algorithm).

## Verification

```powershell
php tests/document-performance-smoke.php
php tests/document-loading-benchmark.php --indexes --history=20
php tests/document-loading-benchmark.php --indexes --documents=5000 --history=20 --profile
php tests/office-routing-smoke.php
php tests/pad-review-smoke.php
php tests/statistics-smoke.php
php tests/president-smoke.php
```

The tests create empty databases with random names, populate sample rows, and remove only those generated databases afterward. They require database-creation privileges. The performance suite checks repeatability, equivalent-index detection, row preservation, MySQL query plans, and count/list predicate agreement across roles, scopes, and legacy states. The benchmark reports the median of three runs after a warm-up, verifies page totals and query budgets, and hashes the complete response so comparisons include counts, office scope, ordering, and permissions. It measures the workspace service, excluding HTTP authentication and network/browser time.

`--without-indexes` removes only these two indexes in the generated benchmark database for comparison. Developers can use `--baseline-file=path` to load an explicit local snapshot named `DocumentRoutingBaseline`; its file-code dependency must resolve to the API source. It never changes which class HTTP requests use.

## Local measurements, 19 October 2026

MySQL 5.7.25, 1,000 documents, 23,200 route events, including 20 historical uploads per document. Baseline and tuned runs were sequential. The baseline used the document service immediately before this tuning and omitted the two new indexes in its generated fixture. The complete response hashes matched in all six scenarios, including permissions, scope, counts, and ordering. These are local service timings, not a hosted latency guarantee.

| Workspace | Before (ms) | After (ms) | Faster |
| --- | ---: | ---: | ---: |
| Records, global All Timeline | 347.4 | 171.1 | 51% |
| Records, For Action | 373.6 | 184.6 | 51% |
| Records, filtered office timeline search | 441.8 | 196.4 | 56% |
| PAD, For Approval | 336.1 | 176.3 | 48% |
| Ordinary office timeline, page 3 | 230.5 | 186.3 | 19% |
| Ordinary office, empty incoming queue | 158.4 | 121.0 | 24% |

See [machine-readable benchmark results](document-performance-benchmark.json) for inputs and before/after response hashes. `EXPLAIN` confirmed `documents_updated_id_index` serves the timeline ordering, and the correlated latest-route lookup uses `routes_document_latest_index` with `Using index` and no `Using filesort`. Other filtered pages may still sort their matching candidate IDs; their detailed metadata joins remain limited to 20 documents.
