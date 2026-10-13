# Phase 1 findings - Central Records and PAD

Review date: **2026-10-06, Asia/Manila**. The primary database snapshot was taken at **2026-10-05 21:51:49 UTC / 2026-10-06 05:51:49 Manila**. Additional read-only checks followed during the same review.

Status: **Phase 1 complete; authoritative office mapping confirmed by the user on 2026-10-06.** Phase 2 has not started. The mapping is recorded in this document only; no office designation or assignment has been written to the database.

Plan: [Central Records and PAD implementation plan](central-records-pad-implementation-plan.md).

## 1. Evidence and scope

- Inspected account roles, office administration, registration, document access, physical receipt, routing, file upload, the Router module, dashboard counters, and the current registration/card UI.
- Queried the configured new PAD database, `pad_db`, and the local legacy reference database configured by `sam.pad/db.php`, `tnrmssks25_sam_db`.
- Both database audits used read-only transactions and were rolled back. No setup/migration scripts, login endpoints, or document action endpoints were run.
- Outputs omitted credentials, tokens, email addresses, document subjects, remarks, and personnel names. Only office/account assignments, schema metadata, aggregate counts, and document identifiers needed for adoption were recorded.
- Only Phase 1 documentation was changed. Application code, configuration, permissions, account assignments, office data, document data, and `sam.pad` remain untouched.
- A 41-file application/configuration/schema fingerprint matched before and after the documentation updates. A final read-only query confirmed the same desk records, user assignment, two document records, and two initial timeline events; no designation column was added.
- This is a code/data review, not an end-to-end execution of the future workflow. No real document was created or transferred for verification.

## 2. Existing offices and account assignments

The new system contains **80 offices, all active**. There is no workflow-designation column yet.

| New PAD office ID | Code | Current name | Campus | Provisioned users | Documents currently held | Mapping evidence |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `RECORDS` | Records Office | Not set | 0 | 0 | Not selected as Central Records. The current application still grants Records privileges to this code and sends direct external registrations here. Directory domain is the Office of the Vice President for Finance, Administration, and Resource Generation. |
| 2 | `PAD` | Presidential Action Desk - Records | ACCESS | 0 | 0 | User-confirmed Central Records office. Its retained code and description still identify a presidential signature desk, so name and coded behavior disagree. |
| 3 | `OP` | Office of the University President | Not set | 1 | 2 | User-confirmed PAD office. The current account and both new-system documents are assigned here. |
| 80 | `ACCESS-PAD` | OFFICE OF THE PRESIDENT | ACCESS | 0 | 0 | Another President/PAD candidate. There is no explicit mapping that establishes whether it is the operating PAD desk or a separate office. |

The only provisioned account is **user ID 1, ELBREN ANTONIO**, active, assigned to **office ID 3 (`OP`)**, with **Administrator and Router** roles. It registered both documents and recorded their two initial events. No provisioned account is assigned to IDs 1, 2, or 80.

The approved-account registry has **515 nonblank-email rows representing 510 distinct identities**. **509 identities do not yet have a `users` profile**. Approved registry membership and office-directory officials are not equivalent to a provisioned Router account with an office assignment. Staff assignments must not be inferred from these records.

### Authoritative mapping confirmed

The user explicitly selected this pair on 2026-10-06:

- **Central Records: ID 2, `PAD`, Presidential Action Desk - Records.**
- **PAD: ID 3, `OP`, Office of the University President.**

This confirms the target office identities for Phase 2 without changing their current database records. IDs 1 and 80 were not selected as the special desks. Keep their identities, directory data, and office availability; ordinary Routing Office is the plan's default designation for all other offices. Do not merge offices, rename codes, or move the current account to ID 2.

The Administrator currently cannot change an existing office code, and `RECORDS`, `PAD`, and `OP` are protected from deactivation. Phase 2 should add a designation independently of the retained code, rather than renaming codes or creating duplicate offices. If ID 2 is chosen as Central Records, ID 1 must not silently retain Central Records authority solely because its old code is `RECORDS`.

### Legacy reference evidence

The legacy `tbl_office_heads` identifies:

| Legacy office ID | Legacy office name | Meaning for this review |
| --- | --- | --- |
| 1 | University President | A legacy President's Office identity, not new PAD office ID 1. |
| 38 | University Records and Archives | Distinct from the legacy Central Records Unit. |
| 67 | CENTRAL RECORDS UNIT | Explicit legacy Central Records identity; 1,699 legacy registry documents have this initial receiving office. |
| 68 | PAD OFFICE | Explicit legacy PAD identity. |

There is **no explicit legacy-office-ID mapping on the new `offices` table**. Legacy IDs 67 and 68 cannot be substituted into new document routing. The legacy names support the existence of separate desks but do not resolve the new office-ID choice.

## 3. Current application behavior

### Accounts and permissions

- Effective account roles are `admin` and `router`. The older `role` enum still includes `staff`, `records`, and `pad`, but the current role picker and roles set use Administrator/Router.
- Administrator access depends on the active Administrator mode. Switching to Router keeps actions tied to the account's assigned office.
- `is_records_user()` currently recognizes active Administrator access, office code `RECORDS`, or the legacy single-role value `records`.
- That one Records permission is reused for all-office monitoring, cross-office document access, and direct external registration. These capabilities need separation before PAD is granted global statistics.
- Existing global access does not let an Administrator receive/route a document held by a different office: action permissions still require the assigned, active holding or pending office.

### Registration and document fields

| Concept | Current representation | Limitation |
| --- | --- | --- |
| Agency/internal source | `source_division_id` points to `document_sources`; `external_sender` stores the selected name snapshot | Catalog entries are not classified as agencies versus university offices. |
| Registration path | `origin_type` is `office` or `external` | The frontend chooses this from registration privilege, not source classification; it is not reliable evidence of internal/external authorship. |
| First receiving office | First `created`/`externally_received` event's `to_office_id` | No independent first-receiving-office field. Registration records its initial receipt at save time. |
| Office origin | `source_office_id` is the initial holder for ordinary office registration; null for external registration | This records the registering office, not a separately reviewed responsible/owner office. |
| Intended office | Optional `recipient_office_id` | Not required, not enforced as the distribution destination, and intentionally absent from the simplified registration UI. |
| Current custody | `current_office_id` | Sender remains current holder during an unconfirmed transfer. |
| Awaiting receipt | `pending_office_id` | Destination becomes holder only when it confirms receipt. |
| Processing/custody status | `created`, `in_transit`, `received`, `acted`, `completed`, `delivered` | No workflow stage for Central intake, PAD review, return, correction, or distribution. |
| Direction | `movement_type`: `forward`/`return` | Direction is not an approval outcome or review-cycle identifier. |
| PAD decision | Generic action notes/history only | No structured Approved/Not approved/Needs correction decision, decision authority, or cycle. |
| External release | Generic `deliver` notes; event records the holding office to itself | No structured agency recipient/release record and no decision-stage restriction. |
| Actor/history | `created_by_user_id` and `document_routes.acted_by_user_id` | Actor and timestamps are retained; this foundation should be preserved. |

Direct `origin_type=external` registration requires the current Records/Administrator privilege and sets the holder to the office with **code `RECORDS` (currently ID 1)**, regardless of the account's assigned office. Ordinary registration starts at the actual assigned office and may select any active source-catalog entry.

The registration form preserves the agreed simplified fields. The server derives its searchable heading from particulars when no subject is supplied. The heading is limited to 255 characters; the full description can be longer. Preserve full particulars in detail/review views.

### Routing and receipt

- A current active holding office can route a `created`, `received`, or `acted` document to any different active office. No designation or workflow-stage restriction is enforced.
- Return targets the last sending office, not necessarily Central Records or the original owner.
- The pending destination alone can confirm receipt. Confirmation updates the existing document, clears its pending destination, closes the open dispatch's receipt timestamp, and appends a received event.
- Transactions and row locking protect movement actions from stale custody changes. Existing registration uses submission keys and the locked number series to prevent duplicate registration on retries.
- Any eligible holding office can act, complete, or deliver. Completion can therefore currently occur before the diagram's final distribution.
- Files remain optional. Originating/current holding offices can upload later, including after completion. Updating an attachment replaces the current binary; the old binary is removed after commit, so existing file history is not a full versioned-file archive.

Both `/api/documents/...` and `/api/doc_router/documents/...` use the same `DocumentRouting` service. The Router prefix additionally checks selected Router access and then normalizes the path. Future workflow restrictions must apply in this shared service to cover both paths.

### UI and dashboards

- One shared Router dashboard currently renders Incoming, Received, Outgoing, Returned, Acted, Delivered/completed, and All Timeline counts.
- Its default scope is the account's assigned office. Records/Administrator users can select All offices or another office; PAD/President's Office Router users do not receive this privilege just because their office is a PAD/President candidate.
- Office scope includes source/current/pending/recipient-office participation and route-history participation. It is broader than documents created or sent by that office.
- Ordinary users cannot request another office's statistics using the `office_id` override.
- Queue counts are calculated before search/status list filters. List filters do not currently redefine those counter totals.
- Global Incoming and Outgoing both describe documents in transit and can count the same documents. Returned overlaps other custody queues. These are not disjoint categories and should not be summed.
- Current Outgoing means a document sent by that office that is now away or has a pending transfer. It is not a cumulative lifetime Sent documents or Handoffs metric.
- Current Acted is a status/holding queue, not a history of decisions. No structured PAD approval metrics are available yet.
- Received uses the agreed cards, including received date, encoder/receiver, source division, document type, status, and existing actions. Other queues retain tables. Searchable source and personnel selections and Travel Order details are present.

## 4. Read-only document/catalog baseline

| Item | Observed value |
| --- | --- |
| New-system documents | 2 |
| New-system timeline events | 2, both initial `created` events |
| Current holder/source office | ID 3 (`OP`) for both documents |
| Documents in transit | 0 |
| Recorded route/receipt/return/decision/release actions after registration | 0 |
| Uploaded files | 0; optional-file behavior is in use |
| Documents without intended office | 1 |
| Active source entries | 648, all with legacy IDs |
| Active document types | 8, including Travel Order |
| Active personnel entries | 634 |
| Current number series | Year 2026, last number 2 |

| Document ID / file code | Current status | Source office / initial receipt / holder | Intended office | Adoption observation |
| --- | --- | --- | --- | --- |
| 1 / `PAD-2026-000001` | `created`, office-origin registration | ID 3 / ID 3 / ID 3 | ID 3 | One initial event; no central intake or PAD-review evidence. |
| 2 / `PAD-2026-000002` | `created`, office-origin registration | ID 3 / ID 3 / ID 3 | Not set | One initial event; one linked person; 255-character heading and 397-character particulars. |

No duplicate file code, missing creator, missing type ID, invalid source ID, missing initial event, or difference between the two source snapshots and their current catalog names was found in these records. Source IDs are populated, but that does not establish source classification or responsible ownership.

New PAD source names/legacy IDs match all **648** local `sam.pad.tbldivisions` entries. Both databases have **8** document types. The legacy division table contains only ID and description, with no internal/external classification.

The local legacy registry contains **2,175 documents** and **10,024 action events**. Its inspected receipt dates range from 2025-11-03 through 2026-02-18. This is the configured local reference snapshot; it does not establish parity with October-dated records in the user's earlier screenshots.

The new API's statistics query new `documents`/`document_routes`, not the legacy registry/action tables. The source/type catalogs have been copied, but legacy document history is not included in the two-document dashboard baseline. Importing legacy document history would require separately agreed scope, office mapping, timestamps, and file-code reconciliation; it is not silently included in this plan.

## 5. Target routing contract for later implementation

This is the agreed diagram expressed as a proposed transition matrix. It is documentation only; none of these new restrictions have been activated.

| Step / starting condition | Actor office | Permitted action/destination | Required evidence and resulting state |
| --- | --- | --- | --- |
| New hard copy first arrives | Routing Office or Central Records | Register once at the actual receiving office | One file code; source distinct from receiving office; initial receipt/encoder retained; attachment optional. PAD's new-document origination policy is an open detail below. |
| Office has a new or corrected submission | Routing Office | Send to designated Central Records only | Existing file code; current office holds until Central Records confirms; pending destination is Central Records. |
| Submission physically arrives | Central Records | Confirm receipt of the existing record | Custody moves to Central Records; append receipt; do not re-encode. |
| Central intake is ready for review | Central Records | Send to designated PAD only | Confirmed custody, no outstanding handoff; required source/responsibility review completed; pending destination PAD. No routine bypass to final distribution. |
| Hard copy physically arrives at PAD | PAD | Confirm receipt | Custody moves to PAD; active review cycle becomes available. |
| Review decision is available | PAD | Record Approved, Not approved, or Needs correction with remarks | Append decision with actor/time/cycle; preserve prior decisions. This does not complete the document. |
| PAD has recorded its result | PAD | Send back to designated Central Records only | Existing number and recorded result travel together; Central Records is the only pending destination. |
| Result/hard copy arrives back | Central Records | Confirm receipt | Custody moves to Central Records; branch by the recorded outcome. |
| Latest outcome is final: Approved or Not approved | Central Records | Send to reviewed intended SKSU office, or record actual outside-agency release | Intended office must be active and explicitly selected; agency release has recipient/time/remarks and does not use a fake office account. |
| Final internal distribution physically arrives | Intended SKSU office | Confirm final receipt | Finish the PAD routing cycle, retaining history and permitted late attachment uploads. Further office implementation work is separate unless the user extends the agreed completion rule. |
| Latest outcome is Needs correction | Central Records | Return to the responsible office for correction | Record reason, preserve outcome/history, and keep the workflow open. Direct outside-submission correction handling needs the policy choice below. |
| Correction arrives/is prepared | Responsible Routing Office | Confirm receipt, record correction, resubmit to Central Records | Same number; new review cycle is created without overwriting earlier results. Central Records then forwards to PAD again. |

Unauthorized cross-office actions, receipt without an assigned pending handoff, office-to-PAD direct submission, PAD-to-another-office dispatch, and final completion merely on PAD approval must be rejected by the shared service.

Administrative global reporting does not bypass custody or the transition matrix. No hidden general administrative routing bypass is part of this contract.

## 6. Dashboard definitions for later implementation

| Dashboard | Default reporting scope | Operational workload scope |
| --- | --- | --- |
| PAD | All new-system documents across all offices | Documents pending at/held by PAD; review backlog; recorded outcomes; awaiting return to Central Records. |
| Central Records | All new-system documents across all offices | Direct intake, office submissions, ready to forward, sent to PAD, PAD results returned, correction returns, distribution/release. |
| Ordinary office | Only documents submitted/sent by or otherwise involving the assigned office | Incoming to that office, currently held there, and its submissions' progress after leaving. No arbitrary foreign-office scope. |

Definitions:

- **System document total:** distinct new-system document IDs/file codes, regardless of number of offices/hand-offs. PAD and Central Records totals must agree for the same reporting filters.
- **Registered/submitted by my office:** distinct documents initially registered at, or explicitly owned/submitted by, that office. Keep registration-at-office and reviewed ownership separate when they differ.
- **Sent documents:** distinct documents dispatched by that office; continue tracking them after they move elsewhere or later return. Repeated correction cycles do not create extra documents.
- **Handoffs/routing activity:** count qualifying dispatch events, separately labelled; repeated sends do increase activity.
- **Current office workload:** a snapshot of pending inbound handoffs and physically held, unfinished work. An unconfirmed dispatch does not prove the destination physically holds the document.
- **System in transit:** unique documents with an outstanding handoff. Do not add global Incoming and Outgoing as if they were different documents.
- **PAD outcomes:** latest recorded review-cycle result per document for current-outcome cards. Historical decisions/cycles are a separately labelled activity measure.
- **Needs correction:** documents whose current correction cycle has not finished/resubmitted, rather than all documents ever returned.
- **Finished:** final office receipt or recorded final agency release following Central Records distribution; Approved alone is not Finished.
- **Filters:** default totals are all-time registry/current-workload values. If date filtering is introduced, document registration totals, dispatch activity, and decision activity must use their respective timestamps and clear labels. Current search/status list filters do not define dashboard totals.

Current all-office and OP-office baseline: **All Timeline 2; Received 2; Incoming/Outgoing/Returned/Acted/Delivered 0**. Offices 1, 2, and 80 have no participating documents. Future decision/intake-cycle metrics cannot be backfilled from the two generic registration events.

Global statistics permission must be distinct from full cross-office record-detail access and outside-intake permission. Preserve ordinary office participation access. PAD's requirement establishes all-office statistics; unrestricted detail/download access has not been requested or inferred.

## 7. Data gaps and adoption requirements

1. **Desk identity mismatch:** the user confirmed Central Records ID 2 and PAD ID 3, but current coded Records behavior still points to ID 1. Phase 2 must represent the confirmed mapping independently of immutable office codes and remove unintended authority from the old coded assumption.
2. **No desk staff provisioned:** the new database has no user at the candidate Central Records offices. Designating an office must not automatically move the current user or create staff assignments.
3. **Missing source classification:** 648 imported sources need reviewed agency/internal classification or an explicit Unclassified state; no reliable classification column exists in the reference table.
4. **Source versus registration path:** `origin_type` and `source_office_id` must not be used to infer outside authorship, final responsibility, or a receipt at Central Records that did not occur.
5. **Ownership/destination gap:** no independent responsible office exists, and one document has no intended office. Existing recipient values must be preserved but reviewed as evidence, not treated as confirmed ownership.
6. **No stage/decision cycles:** initial registrations do not prove that a Central Records intake or PAD decision occurred. Add review/adoption state rather than manufacturing history.
7. **Current OP records if OP becomes PAD:** both existing documents were registered directly there. They need a valid legacy/adoption route back through Central Records review without being assigned a fabricated PAD decision or receipt.
8. **Attachment history:** optional/later upload is supported; binary revisions are not retained by current replacement behavior. Correction/version-retention requirements need deliberate storage/history treatment in later phases.
9. **Long particulars:** the second heading is truncated to 255 characters while complete particulars remain in description. Preserve both, and do not infer that the heading contains the whole document's particulars.
10. **Historical dataset scope:** the new system has two documents; the local legacy reference has 2,175 with 10,024 events and different office identities. A historical import requires separate scope and factual mapping; global reporting currently covers the new system only.
11. **Timestamp semantics:** new events are saved using UTC; legacy receipt dates are entered in the legacy application with Manila defaults and no timezone column. Any future import must establish conversion rules before moving timestamps.

## 8. Unresolved choices and recommended defaults

| Choice | Recommendation / current boundary | Needed by |
| --- | --- | --- |
| Which new office IDs are Central Records and PAD? | **Resolved by the user:** Central Records ID 2 / PAD ID 3. This review recorded the target mapping without assigning it in the database. | Apply the confirmed designations in Phase 2. |
| Which staff accounts will operate Central Records? | Administrator selects actual authorized accounts. No current assignment is changed and none is inferred from directory officials. | Operational readiness before activation; account provisioning is separately explicit. |
| Does PAD need to view/download all record details? | Global statistics required; maintain office-participation detail access unless broader monitoring is explicitly requested. Central Records' existing oversight can be separately represented. | Permission specification in Phase 2 / reporting drill-down in Phase 6. |
| Who may record a PAD decision? | An authorized PAD staff account records the actual decision with actor/time and remarks; this does not imply that every clerk personally has signatory/approval authority. | Phase 4 decision authorization. |
| May PAD/President's Office originate new internal documents? | Diagram defines PAD primarily as reviewer and outside intake at Central/ordinary offices. Any additional PAD-originating submission path needs explicit rules through Central Records. | Phase 4 registration/routing policy. |
| Who handles corrections for a direct outside-agency submission without an internal responsible office? | Identify a concerned SKSU office during Central intake where appropriate; otherwise define an agency-correction/resubmission path that stays open and keeps the number. Never invent an agency office account. | Phase 3 responsibility data / Phase 4 correction flow. |
| Should old legacy documents be included in new dashboard totals? | Current plan covers new PAD data; historical import is additional scope requiring a separate mapping/import plan. | Before extending Phase 7 adoption to a legacy import. |
| Is an administrative workflow override required? | None implicit. If required, define specific authority, allowed transitions, and audit evidence before implementation. | Phase 4 exception policy, if requested. |

The agreed default completion boundary is final recipient-office receipt or final agency release. Needs correction remains open and returns through Central Records for another review cycle. Files remain optional at completion.

## 9. Phase 2 handoff and limits

Using the user-confirmed office pair (Central Records ID 2 / PAD ID 3), Phase 2 will:

1. Add an independent office designation and validate exactly one active Central Records/PAD office for this workflow, using the selected existing IDs.
2. Add Administrator controls for designations and Router identity labels; preserve `admin`/`router`, office IDs/codes, accounts, and existing assignments.
3. Separate global-statistics, monitoring/detail, intake, and custody-bound action capabilities. PAD global statistics must not make all PAD registrations external or send them to old Records ID 1.
4. Replace office-authority assumptions based on code/legacy role with the agreed designation, including configured direct Central Records intake lookup. Review protections for designated offices without changing unrelated office codes.
5. Verify office/role boundaries while keeping unfinished mandatory routing enforcement inactive.

Phase 2 does not implement document stage/outcome migrations, the complete workflow, new dashboard metrics, historical import, real document transfers, or inferred staff assignments. Those remain in their named later phases. Full dashboard rendering/data scopes are Phase 6.

## 10. Reviewed code references

- [bootstrap.php](../src/bootstrap.php): environment/database setup, effective account roles, current Records privilege, Google profile creation, and history reader.
- [Administration.php](../src/Administration.php): account role/office assignment validation; office-code immutability and protected core offices; directory officials versus users.
- [DocumentRouting.php](../src/DocumentRouting.php): participation access; custody-based permissions; current queue definitions and counts; registration/numbering; optional files; movement transactions.
- [document-routes.php](../src/document-routes.php) and [Router routes](../src/doc_router/routes.php): shared endpoints and selected Router-role path normalization.
- [schema.sql](../database/schema.sql), [RoutingSchema.php](../src/RoutingSchema.php), [DocumentSourceSchema.php](../src/DocumentSourceSchema.php), and [AccountRoleSchema.php](../src/AccountRoleSchema.php): present schema, compatibility fields, and migration definitions. These scripts/functions were read, not invoked.
- [web app](../../pad-web/src/app.js), [Router UI](../../pad-web/src/doc_router/index.js), and [index.html](../../pad-web/index.html): active role identity, shared dashboard, scope filter, source privilege-dependent registration, Received cards, and current action forms.
- Local legacy `sam.pad/db.php` and Records registration code were inspected for connection/source semantics; no legacy application/database changes were made.

## 11. Completion checklist

- [x] Reviewed current application code and endpoint coverage.
- [x] Identified actual office candidates and provisioned account assignments from the live local databases.
- [x] Documented the target transition contract, including outcomes, corrections, and release.
- [x] Defined dashboard scope, document totals, office sending/workload metrics, and handoff counting.
- [x] Recorded existing-data gaps and future adoption cases.
- [x] Kept application code, permissions, assignments, and document data unchanged.
- [x] Obtain authoritative Central Records/PAD office-ID selection: the user explicitly chose ID 2 / ID 3 on 2026-10-06.

Phase 1's review deliverables and office-mapping completion gate are complete. Later policy choices are recorded for their relevant phases. Do not automatically start Phase 2.
