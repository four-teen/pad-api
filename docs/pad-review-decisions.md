# PAD review decisions and returned results

Implemented 2026-10-07 following the user's approval of the sender-identification / decision / queue proposal.

This increment uses the four account roles and each account's saved routing-office assignments. It does not replace them with the older office-designation proposal. The Phase 1 findings remain a dated audit of the implementation at that time.

## User flow

1. Central Records sends an existing document to an active office assigned to OVP PAD. This opens a review cycle on the original document and file code.
2. PAD sees the submission in **Awaiting Receipt**, with a **For approval** badge and the Central Records sender identified.
3. After the hard copy is confirmed, PAD sees it in **For Approval**. Generic send, return, action, completion and delivery cannot bypass the pending PAD decision.
4. In **Record decision & return**, PAD chooses **Approved**, **Disapproved**, or **Returned for correction**. The latter two require a reason/instructions. Saving records the outcome and dispatches back to the submitting Central Records office in one transaction. No freely selected return destination is accepted.
5. Central Records sees the outcome in **Returned from PAD** (`pad_results`), including before physical receipt. Its list shows View and Act. Act explicitly confirms the hard-copy receipt, records Records' action, and dispatches the attached result to the selected owner in one transaction.
6. Act removes the record from Returned from PAD and adds it to Records' Acted archive. The owner's actual receipt records Delivered, while the Records action stays in Acted. A correction result can be resubmitted through Central Records to PAD on the same file code, preserving earlier decisions.

PAD approval does not finish routing. **Act & send to owner** requires the attached document, notes on the latest PAD route, final action remarks, an active owner office, and an affirmative hard-copy confirmation. Acted is the Records action milestone; Delivered is the owner handoff milestone. Generic Complete/Deliver cannot bypass Act on a PAD result. Earlier closed Acted records remain unchanged. See [the current queue workflow](office-document-workflow.md) for the updated 2026-10-09 release and receipt flow.

## Data and permissions

- `document_pad_reviews` stores a submission route, the exact PAD / Central Records IDs, decision, remarks, recorder, timestamp and return route for each cycle.
- `document_routes.actor_role` records the active module for new history events. `pad_decided` is an explicit timeline event.
- Only the active OVP PAD role holding the received submission can record its decision. An account having all four roles still must select PAD mode. Global monitoring does not grant decision authority.
- For Approval belongs to the active PAD office. PAD Results belongs to the active Central Records office, even when its general monitoring filter is set to All offices. These workload cards overlap physical queues; do not add them to obtain unique document totals.
- Names and office codes are not used to assign workflow authority. Active account office assignments identify eligible PAD destinations. Each OVP unit should consistently use the same exact office ID across its accounts; conflicting cross-account module assignments remain an administration configuration concern.
- Setup is additive and repeatable: `php scripts/setup-pad-reviews.php`. It checks that existing accounts, offices, documents and route history remain unchanged. It creates no historical decisions and does not move documents.
- Older actual Central Records-to-PAD handoffs are identified from the last dispatch and current exact office assignments. Their cycle is opened on a real receipt or decision, not by migration. Unrelated older records are not labelled approved or disapproved.
- Older PAD-to-Central Records returns without structured decisions also appear in PAD Results, explicitly marked **Decision not recorded**. Their existing history is preserved; no approval result is inferred. PAD-2026-000003 was verified read-only as one such received return at Central Records office ID 2 from PAD office ID 80.

## API

Both `/api/documents/{reference}/decide` and `/api/doc_router/documents/{reference}/decide` use the shared office/role checks. JSON contains `decision` (`approved`, `disapproved`, `returned_for_correction`) and `notes`. The server derives the Central Records destination from the review submission.

Workspace queues add `for_approval` for OVP PAD and `pad_results` for OVP Central Records. The existing API queue key `incoming` remains compatible; the interface labels it Awaiting Receipt.

## Scope and validation

This is a working increment, not completion of every phase of the original plan. General office routing outside an active PAD review remains available. Full university dashboard statistics, owner/final-recipient rules, external-release forms and mandatory routing policy for every document remain later work.

Validation uses generated databases and sample browser records: PAD review outcomes, real HTTP endpoints, role denial, receipt requirements, duplicate rejection, transaction rollback, exact-office returns, correction cycles, historical compatibility, and existing registration/file/numbering behavior. Browser sample preview: `node tests/pad-review-preview-server.js` in pad-web. No real approval or document transfer is submitted during verification.

Results: 93 PAD review checks, 127 existing office-routing checks (including concurrent registrations and HTTP uploads), 78 administration checks, 41 frontend checks, and 71 Apache hosting checks passed. The production frontend build passed. Schema setup was repeated on the local database with matching existing-data fingerprints. Browser QA confirmed the decision form and the Central Records result cards using sample records only.
