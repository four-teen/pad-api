# sam.pad records import assessment

Checked 9 October 2026 against the locally configured sam.pad database, its uploads directory, the current PAD database, and both applications' routing code. This was a read-only assessment. No document, account, status, or attachment was imported or changed.

## Conclusion

Importing the old records into pad-web is feasible. The new application's database and API are the import destination; copying the old tables directly would not produce correct menus, ownership, or timelines. A migration must map offices, translate workflow events, preserve legacy identifiers and provenance, and report unresolved records before enabling current workflow actions.

The available old records have received dates from **3 November 2025 through 18 February 2026**. This establishes the coverage of the local copy inspected, not the completeness of any separate deployed legacy database.

## Inventory and destination

| Legacy table | Rows | Destination / treatment |
|---|---:|---|
| `tbl_documents_registry` | 2,175 | `documents`: original file code, particulars, source, document type, registration/intake dates |
| `tbl_document_actions` | 10,024 | `document_routes` plus preserved original legacy action metadata; pair dispatch and receipt only when the history supports it |
| `tbl_document_images` | 1,900 | Protected attachments; all referenced files exist and are readable |
| `tbl_travel_order` | 540 | `document_travel_details`; review duplicates, incomplete fields and invalid date ranges |
| `tbl_travel_order_faculty` | 775 | `document_people` / `document_personnel`, through the travel record's document |
| `tblother_information` | 3 | Additional personnel links in `document_people` |
| `tbl_office_heads` | 75 | Explicit mapping to the current `offices` catalog |
| `tbldivisions` | 648 | Already represented by legacy IDs in `document_sources` |
| `tbltypeofdocuments` | 8 | Already represented by legacy IDs in `document_types` |
| `tblprofiles` | 636 | 634 legacy IDs are already in `document_personnel`; two require catalog synchronization |
| `tblpresident_actions` | 0 | No structured presidential decisions are available in this local table |
| `tbl_acted_to_office` | 0 | Do not rely on this table for delivery destinations |

The current PAD database contains five documents and 17 route events at the time of inspection. The old file codes do not collide with their references. Importing must preserve these existing records.

## Attachment readiness

- All 1,900 referenced files are present, readable, nonempty, and valid JPEG (1,896) or PNG (4) according to Fileinfo.
- Referenced files total **311,469,152 bytes**, approximately 297 MiB. No individual file exceeds the current 10 MiB limit.
- 1,848 documents have attachments; 327 have none. The new schema already permits records without a file.
- **27 documents have multiple attachments**, with up to eight scans on a document. The new app currently stores one current attachment per document. Preserve all scans by adding multiple-attachment support or producing a combined PDF per document while retaining the original images. Do not pick one image and discard the rest.

## Workflow meaning must be preserved

Latest legacy actions across the 2,175 registered documents:

| Latest legacy action | Documents |
|---|---:|
| Delivered | 1,841 |
| Outgoing | 177 |
| Acted | 93 |
| Received | 56 |
| Returned | 6 |
| Logged | 2 |

The old PAD `send_back_with_selection` writes **Acted** when PAD sends a document to Central Records. Among documents whose latest action is Acted, **90 go from old PAD office 68 to old Records office 67**, and **three go from PAD to Accounting office 71**. In the new workflow, Records' Act means confirming receipt and dispatching to the owner. These old PAD actions cannot simply be converted into new Records Acted releases. The 90 Records-bound documents are candidates for Returned from PAD / receipt processing after office mapping and custody review; the three Accounting-bound documents require separate destination review.

All **1,843 historical Delivered events** use destination office **0**. The old delivery handler records physical handover to a person and stores the recipient information in remarks. Preserve these as legacy delivery events; do not invent an owner office, owner acknowledgment, or a new Records release route. A latest historical Delivered record can be treated as closed history without claiming that the new owner-confirmation flow occurred.

Outgoing, Forwarded, Redirected and Transmitted generally correspond to dispatch events; Received confirms arrival; Returned records a return dispatch. Original action labels and remarks should remain available even when events are translated into the new application's vocabulary. Blank or ambiguous events must be retained in the import audit rather than silently interpreted.

`tblpresident_actions` is empty. An old Acted label does not prove approval, disapproval or correction instructions. Do not create structured PAD decisions by guessing from that label.

## Mapping and data issues

- **Offices:** only nine of 75 legacy offices match current office names exactly after case/whitespace normalization. All 75 overlapping numeric IDs identify different names in the new catalog. Match institutional identities and renamed offices explicitly; copying IDs would assign documents to the wrong offices. Some old offices represent particular office heads or repeated BAC positions and need an explicit decision about their current equivalent.
- **Source versus custody:** registry `office_division` links to the source catalog. `received_by` is an office ID, not a staff member. `uni_divisionid` is updated on some dispatches; it cannot universally be treated as the original document owner. Determine custody and pending receipt from the action history.
- **Staff attribution:** action rows have no dedicated staff/user ID. Some remarks identify the receiving person, but this is incomplete. The new schema requires user attribution, so use explicit legacy provenance / an inactive import identity where necessary, and preserve original office and remarks. Do not attribute old actions to the current signed-in user.
- **File codes:** no blanks, duplicate codes, codes over the current 40-character limit, or collisions with current references were found. Six codes contain `_1`, which the current API route pattern rejects. Support those references before importing them, or introduce a separate legacy reference field without losing the original number. Keep old `25-...` / `26-...` references separate from new `PAD-YYYY-...` numbering.
- **Long particulars:** 86 exceed the new 255-character subject limit. Preserve complete particulars in description / legacy data and derive a shorter display heading.
- **Missing links:** 28 documents reference seven source-catalog IDs that no longer exist; one references an unknown receiving office. Five action rows refer to missing registry documents. No documents lack action history, and no attachment row lacks its document.
- **Action quality:** three action rows have an empty action type. Preserve and report them for review.
- **Travel data:** 540 rows cover 538 distinct document IDs, including one orphan travel row. One document has three travel rows, producing two extra versions. Four rows lack at least one field required by the new travel structure, and 19 have return dates before departure dates. Retain original values and versions; review exceptions rather than fabricating replacement dates or choosing a version silently.
- **Time:** the old PHP app uses Asia/Manila, and its database session uses the server's UTC+08 system zone. The new routing datetime values are stored as UTC. Read legacy TIMESTAMP fields in a defined zone and convert local DATETIME values deliberately. Preserve date-only travel dates without shifting them. This avoids an eight-hour error in the new timeline and office-stay calculations.

## Recommended migration sequence

1. Preserve a database and attachment backup of the old system and the existing new PAD records.
2. Prepare a repeatable preview import into an isolated copy of the new database. Store legacy document/action/image IDs and original labels so retries cannot create duplicates and exceptions remain traceable.
3. Resolve the office mapping, six underscore references, multiple-scan handling, attribution and data exceptions. Preserve full raw legacy rows alongside the translated records.
4. Validate original file codes, all retained scans, timeline ordering, timestamps, dispatch/receipt pairing and menu placement against representative old documents, including PAD returns and person deliveries.
5. Import closed historical records first. The 334 records whose latest action is not Delivered require custody/status review before activating them in current routing queues. Keep uncertain records visible as imported history until reviewed.

The inspection script is `scripts/inspect-legacy-records.php`. Its queries run in read-only transactions and emit an aggregate/schema audit. The audit snapshot is stored privately at `storage/legacy-import-audit.json`.
