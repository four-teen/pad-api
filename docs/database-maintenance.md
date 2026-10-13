# Administrator database management

The Administrator role opens **Database management** from the Overview card or the Administration sidebar (`#admin/database`). Office module roles cannot access table counts, previews, clearing, or recovery downloads, including accounts that also have Administrator assigned.

## Setup

For an existing installation, run:

```powershell
php scripts/setup-database-maintenance.php
```

This adds `admin_maintenance_events` without changing existing records. New installations include it in `database/schema.sql`. Keep `APP_KEY` at least 32 characters long and `pad-api/storage` writable by PHP. The frontend needs a fresh `npm run build`.

## Available operations

The page lists every base table in the configured PAD database with its actual record count. Empty tables, tables outside the managed PAD catalog, nontransactional tables, and referenced parent tables have no enabled clear action. Blocked tables show the reason and the number of referencing rows.

Tables are grouped by usage, with table and stored-row totals for each group:

| Group | Tables |
| --- | --- |
| Preparation | `offices`, `office_officials`, `document_types`, `document_sources`, `document_personnel` |
| Operations | `documents`, `document_routes`, `document_pad_reviews`, `document_people`, `document_travel_details` |
| System & Access | `users`, the configured approval table, `document_number_series`, `admin_maintenance_events`, and other protected tables |

New, received, returned, acted, and completed documents are workflow states of the same document registry; they are not separate tables or separate table-clear targets.

**Clear all document records** clears these tables together in one transaction:

- `document_pad_reviews`
- `document_people`
- `document_travel_details`
- `document_routes`
- `documents`

Individual clears are available for unreferenced document types, sources, personnel, office officials, and offices, as well as document personnel links and travel details. Routing history and PAD decisions cannot be cleared separately while documents exist. New or unknown tables are displayed but remain protected until explicitly supported by the application.

The `users` table, configured Google approval table (normally `tblaccount`), document-number counters, and administrative operation log remain protected. Use User accounts to manage login access. Clearing document records does not reset annual numbering or reuse existing reference codes. Uploaded attachments remain in their existing private directory for recovery.

## Review and clearing

Selecting a clear action first opens a read-only review showing every affected table and row count. The administrator must enter a reason, type the exact phrase such as `CLEAR documents`, and acknowledge the operation. Reviews expire after 10 minutes, belong to the reviewing administrator, and cannot be reused after success.

Before deletion, the API rechecks current administrator access, locks the affected rows, compares their complete contents with the preview, and checks current linked records. Changed records or new dependencies reject the operation and require a new review. Foreign-key checks remain enabled. The operation uses transactional `DELETE`; it does not drop tables, truncate them, or reset auto-increment values.

A JSON backup containing the complete rows is saved before any deletion. All table deletes and the success audit entry commit together. A failed operation rolls back its database changes and removes its own backup when rollback is confirmed. If the connection cannot confirm the outcome, the backup is retained; review the operation log before retrying.

The latest 20 successful operations show administrator email, date, target, reason, row counts, and an authenticated recovery download. Backups are stored under `storage/maintenance-backups`, outside `public` and excluded from Git. Retain the API root `.htaccess` so direct browser access remains blocked.

## Recovery

Backups are recovery data, not a one-click Undo or an automatically executable SQL dump. Recovery requires a separate database operation. Stop writes, inspect the backup, retain a current database backup, and restore the required rows with their original IDs and references in dependency order. Restore parent catalogs and offices before documents, then routes before PAD review decisions. Check conflicts with records created since clearing and keep document-number counters at or above their current values. Uploaded attachments are retained separately and must remain available at their original paths. The application provides no automated restore endpoint.

## API and checks

Every endpoint requires an approved, active account in Administrator mode (`X-PAD-Role: admin`) and a bearer token:

- `GET /api/admin/database` — table counts, eligibility, and recent audit entries.
- `POST /api/admin/database/preview` — JSON `{ "table": "documents" }`, returning affected counts, confirmation phrase, and a signed review token.
- `POST /api/admin/database/clear` — JSON containing `token`, exact `confirmation`, `acknowledged: true`, and `reason`.
- `GET /api/admin/database/backups/{event_id}` — authenticated JSON attachment.

Run `php tests/database-maintenance-smoke.php` for deletion, rollback, stale reviews, concurrent references, backups, and HTTP role checks in a generated empty test database. To exercise the HTTP routes with another PHP runtime, supply its executable path as the first argument. Test backups and the generated database are removed afterward. `php tests/hosting-smoke.php` verifies actual Apache inventory and read-only previews without clearing live records. Frontend confirmation and role checks are included in `npm test` in `pad-web`.
