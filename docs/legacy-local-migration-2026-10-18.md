# Local legacy records import — 18 October 2026

**Current status: this local import was reverted on 18 October 2026 at 20:31 Asia/Manila, at the user's request.** The 2,199 imported documents and 651 copied live attachments were removed. The current database retains the two original documents and all application designs/features. See [the rollback report](legacy-local-rollback-2026-10-18.md). The original import results below are a historical audit, not the current database state.

The first historical-record batch was committed to **`pad_db` on localhost** at 18:10:51 Asia/Manila. Production was not accessed or changed. This batch adapts old records to the existing PAD schema; no application table, column, UI, or workflow was changed.

This report uses the attached **`tnrmssks25_sam_db (9).sql`**, containing 7,151 document registry rows. The earlier `legacy-records-import-assessment.md` inspected a smaller local legacy database; its counts do not describe this attachment.

## Imported batch

| Item | Added | Current local total |
|---|---:|---:|
| Documents | 2,199 | 2,201 |
| History events | 11,142 | 11,158 |
| Document/personnel associations | 374 | 375 |
| Source catalog entries | 248 | 896 |
| Document types | 1 | 9 |
| Personnel catalog entries | 12 | 646 |
| Document attachments | 651 | 652, including the preserved original attachment |

The attachments include 633 single images and 18 combined PDFs, covering 56 PDF pages. Original scans were retained separately. Every PDF was parsed independently, all pages rendered, and the rendered contact sheets and full-size samples visually inspected. Attachment copies were checked by SHA-256.

All selected records have **Delivered as their final action by both action ID and timestamp**. They appear as closed history. A legacy person handover is not treated as a new owner-office acknowledgment. No structured PAD approval, decision, or Central Records release was invented.

Full particulars and original legacy metadata are retained in the description and private archive. Long headings are shortened to fit the existing 255-character subject column. Four selected references containing unsupported characters use `LEGACY-SAM-<old ID>`; their original references remain in the heading, description, and reference map. New `PAD-YYYY-...` numbering and its counter were left intact.

Historical staff identity is absent from the old action rows. One clearly named **inactive** import identity was added, with no Google identity or office assignment. No old passwords, legacy login accounts, or Google allowlist entries were imported. Existing users and their permissions were preserved.

## Records and fields still requiring review

**4,952 of the 7,151 documents were preserved in the migration archive and not inserted into PAD.** The full migration is therefore not complete.

- **4,234 held documents have conflicting action order and timestamps.** In this group, 4,019 end with Delivered by action ID but Received by timestamp; 215 end with Acted by ID but Received by timestamp. No blanket time offset or guessed final status was applied.
- Other held records require current custody/status review; one has an unclassified historical action and one has an unknown document type. Reasons overlap. The review CSV lists each held document once with every applicable reason.
- **38 legacy office identities lack a confirmed equivalent** in the existing office catalog. Historical office labels remain in notes; an ambiguous numeric ID was not assigned to a different modern office.
- The selected batch has **1,533 unavailable scan references across 1,528 documents**. Their record data was imported, with missing-file notes; missing or partial scan sets were not represented as complete attachments. Across the entire dump, 4,367 image references lack a matching local file. Recovering the original server uploads is still necessary for completeness.
- **271 imported documents have travel-detail exceptions.** Original values and versions remain in descriptions and the source archive. Required travel enums and dates were not fabricated; no new normalized travel rows were inserted.
- **589 selected records contain history timestamps earlier than their registry receipt time.** Their original times were retained. These historical inconsistencies need review before using them as evidence for turnaround-time measurements.
- Orphan legacy rows are retained in `orphan-rows.json`; they were not attached to unrelated documents. Five catalog naming/label exceptions are listed in `review.json`; existing catalog entries were not overwritten.

## Verification performed

- Six migration tests passed, covering SQL literal parsing, reference/text/time preservation, chronology holds, incomplete attachments, and invalid travel data.
- A disposable database rehearsal passed. An intentional foreign-key failure rolled back all inserted rows. A second application of the stale plan was rejected to prevent duplicates.
- The real import ran in a transaction. **4,218 verification checks passed**, including every imported field and history event, all existing rows, schema identity, file hashes, and foreign-key integrity.
- Existing offices, officials, login roster, PAD reviews, and document numbering were unchanged. The two existing documents and original attachment were preserved.
- A read-only check using the actual `DocumentRouting` service and the existing Central Records role passed. Six representative records were found by reference and opened with their histories. Coverage includes PDF, missing attachment, personnel, travel provenance, and alternate references. The Delivered queue across all offices contains **2,200** records: 2,199 imported and one existing closed record. Imported samples enable no workflow actions and imply no PAD decision.

To review in the local app, use **Central Records → All offices → Delivered**, then search a reference from `imported-reference-map.csv`. An office-specific view can show fewer records because it respects office participation.

## Private backup and reports

All data-bearing artifacts remain under the Git-ignored directory:

`storage/legacy-import/sam-20261018/`

| Artifact | Purpose |
|---|---|
| `before.json` | Complete pre-import local schema and row backup |
| `preexisting-files/` and `preexisting-files.json` | Original local attachment backup and hashes |
| `source.sql` | Exact copy of the attached legacy dump |
| `original-rows.json` | Parsed original legacy rows, including held records |
| `plan.json` | Reviewed inserts and attachment hashes for this local baseline |
| `review.json` / `orphan-rows.json` | Mapping, travel, file, catalog, and orphan exceptions |
| `records-needing-review.csv` | 4,952 held records, with action-ID/time comparisons |
| `imported-reference-map.csv` | 2,199 old IDs/references mapped to actual new local IDs |
| `original-scans/` | Original scans used for complete imported attachment sets |
| `attachments/` | Prepared attachment copies |
| `pdf-validation.json` / `pdf-qa/` | PDF page/hash verification and rendered inspection images |
| `rehearsal.json` | Successful test import, rollback, and duplicate-rejection results |
| `applied.json` | Committed local import counts and generated document IDs |
| `verification.json` | Post-import data, schema, and file verification |
| `app-verification.json` | Read-only application search, history, and permission checks |

The imported application attachments are in PAD's existing configured upload directory. These private artifacts contain real records and account data and are excluded from Git.

## Rechecking this local batch

From `C:\VertrigoServ\www\pad-api`:

```powershell
C:\xampp\php\php.exe -d memory_limit=512M scripts\migrate-legacy-records.php verify storage\legacy-import\sam-20261018
C:\xampp\php\php.exe -d memory_limit=512M scripts\migrate-legacy-records.php app-verify storage\legacy-import\sam-20261018
```

The tools reject non-local application/database configuration. `apply` also checks the exact backup/plan, source hash, rehearsal, PDF validation, duplicate marker, and migration lock. This applied package must not be regenerated or replayed.

For a later hosted import, prepare a merge against a fresh backup of the hosted PAD database and transfer its matching attachment files. The current plan contains local catalog/user/document mappings and is tied to the pre-import local data hash; it is not a portable SQL file for overwriting an existing production database. The 4,952 held records still require review before migration.
