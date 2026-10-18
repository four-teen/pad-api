# Local legacy import rollback — 18 October 2026

Completed at **20:31:22 Asia/Manila** in **local `pad_db` on localhost**, following the user's instruction to remove the old-data import and its files while retaining the current designs. Production and the original legacy system were not changed.

## Removed from the active PAD database

| Imported item | Removed |
|---|---:|
| Documents | 2,199 |
| History events | 11,142 |
| Document/personnel links | 374 |
| Source catalog additions | 248 |
| Personnel catalog additions | 12 |
| Document type addition | 1 |
| Inactive migration identity | 1 |

Only exact rows identified by the saved import map were removed. Preflight checks confirmed that the imported document fields, history, and links had not been edited and that no newer reviews or retained records depended on the removed rows. The catalogs that existed before this import were preserved.

## Files

**651 imported attachments** were removed from the active folder `storage/documents/`, totaling 128,272,678 bytes (about 122.3 MiB). Each removal checked the exact filename, resolved folder, original import mapping, and SHA-256 hash. Three unrelated files in that folder, including the original PAD attachment, were preserved byte-for-byte. The document folder itself remains intact.

Recoverable copies remain in the private, Git-ignored backup. The supplied SQL dump, the original `sam.pad/uploads` folder, and the private import archive were retained. Backup copies are outside the active attachment folder and do not appear as active PAD documents.

## Preserved and verified

- **Two original PAD documents**, 16 history events, one PAD review, one travel record, and one personnel association remain.
- Existing users, accounts/Google allowlist, offices, officials, and document numbering remain as they were immediately before this rollback. Number counters were not reset.
- Database table structure is unchanged. All retained rows match the prepared expected state exactly, and foreign-key checks passed.
- Hashes of **30 application source/configuration files** match their pre-rollback values. The responsive login, attachment preview, President's dashboard redesign, statistics behavior, and all other existing designs/features remain intact.
- A temporary database rehearsal verified both committing the exact removals and rolling back the transaction to restore every row. The actual local operation passed the same row/schema checks and verified all remaining live files.

## Recovery and audit files

`storage/legacy-import/sam-20261018/revert-20261018/`

| File/folder | Purpose |
|---|---|
| `before-revert.json` | Complete local database schema and row backup immediately before removal |
| `files/` | Verified copies of all 654 files present in the active document folder before rollback |
| `plan.json` | Exact removed rows, attachment hashes, expected retained state, and dependency review |
| `rehearsal.json` | Successful isolated commit and transaction-rollback checks |
| `applied.json` | Completed local removal counts and validation |
| `verification.json` | Independent post-operation row/schema/file verification |
| `design-verification.json` | Application source/configuration preservation result |

The parent import folder also contains `reverted.json` and `design-preservation-before-revert.json`. These are private recovery artifacts, not deployable application assets. Restoring the database from a backup is a separate operation; do not replay the original import automatically.

The local rollback tool is `scripts/revert-legacy-records.php`. It rejects non-local configuration and any database other than `pad_db`; requires the exact saved plan, current-data hashes, and rehearsal; deletes only mapped rows with foreign keys enabled; and restores removed file copies if its database transaction fails.
