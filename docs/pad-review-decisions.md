# PAD review decisions and returned results

Implemented 2026-10-07 following the user's approval of the sender-identification / decision / queue proposal.

This increment uses the four account roles and each account's saved routing-office assignments. It does not replace them with the older office-designation proposal. The Phase 1 findings remain a dated audit of the implementation at that time.

## User flow

1. Central Records sends an existing document to an active office assigned to OVP PAD. This opens a review cycle on the original document and file code.
2. PAD sees the submission in **Awaiting Receipt**, with a **For approval** badge and the Central Records sender identified.
3. After the hard copy is confirmed, PAD sees it in **For Approval**. Generic send, return, action, completion and delivery cannot bypass the pending PAD decision.
4. In **Record decision & return**, PAD chooses **Approved**, **Disapproved**, or **Returned for correction**. The latter two require a reason/instructions. Saving records the outcome and dispatches back to the submitting Central Records office in one transaction. No freely selected return destination is accepted.
5. Central Records sees the decision in **Awaiting Receipt**, with View and Receive. Receive opens the standard hard-copy receipt modal with predefined and custom remarks. Its successful confirmation moves the document to **For Action** and enables Act. Approved, Disapproved, and Returned for correction outcomes all require Records processing. They are excluded from ordinary Received and its badge; PAD decision handoffs do not increase the issue-related Returned badge.
6. Act records Records' action and dispatches the attached result to the selected owner, adding it to Records' Acted archive. The owner's actual receipt records Delivered, while the Records action stays in Acted. A correction result can be resubmitted through Central Records to PAD on the same file code, preserving earlier decisions.

PAD approval does not finish routing. **Act & send to owner/offices** requires an already confirmed Records receipt, the attached document, notes on the latest PAD route, and one or more active owner offices. The final note comes from the saved PAD decision and is not entered by Records. Receipt is a separate persisted event and is not repeated in Act. Each selected office has an independently tracked Acted dispatch and receipt; the document becomes globally Delivered after all confirm. Generic Complete/Deliver cannot bypass Act on a PAD result. Earlier closed Acted records remain unchanged. See [the current queue workflow](office-document-workflow.md) for the updated release and receipt flow.

## Data and permissions

- `document_pad_reviews` stores a submission route, the exact PAD / Central Records IDs, decision, remarks, recorder, timestamp and return route for each cycle.
- `document_routes.actor_role` records the active module for new history events. `pad_decided` is an explicit timeline event.
- Only the active OVP PAD role holding the received submission can record its decision. An account having all four roles still must select PAD mode. Global monitoring does not grant decision authority.
- For Approval belongs to the active PAD office. Central Records uses Awaiting Receipt before receipt and For Action afterward. For Action and its dashboard/sidebar counts are local to the active Records office, including when monitoring All offices. Ordinary Received excludes these items. Returned and its count track explicit Return-button handoffs because of issues; PAD's routine decision dispatch is excluded. The decision badge and all review cycles remain available in lists and timelines.
- Names and office codes are not used to assign workflow authority. Active account office assignments identify eligible PAD destinations. Each OVP unit should consistently use the same exact office ID across its accounts; conflicting cross-account module assignments remain an administration configuration concern.
- Setup is additive and repeatable: `php scripts/setup-pad-reviews.php`. It checks that existing accounts, offices, documents and route history remain unchanged. It creates no historical decisions and does not move documents.
- Older actual Central Records-to-PAD handoffs are identified from the last dispatch and current exact office assignments. Their cycle is opened on a real receipt or decision, not by migration. Unrelated older records are not labelled approved or disapproved.
- Older PAD-to-Central Records handoffs without structured decisions appear in Awaiting Receipt or For Action according to their actual custody, explicitly marked **Decision not recorded**. Their existing history is preserved; no approval result is inferred. A historical unclassified Return remains identifiable as a return unless a recorded decision/result-route relationship establishes its purpose. Ordinary PAD handoffs to other offices remain in Received.

## API

Both `/api/documents/{reference}/decide` and `/api/doc_router/documents/{reference}/decide` use the shared office/role checks. JSON contains `decision` (`approved`, `disapproved`, `returned_for_correction`) and `notes`. The server derives the Central Records destination from the review submission.

Workspace queues add `for_approval` for OVP PAD and `for_action` for OVP Central Records. The interface labels `incoming` as Awaiting Receipt. The old `pad_results` API queue remains for compatibility but has no sidebar menu or dashboard card; old frontend links open Awaiting Receipt. Pending decisions expose Receive only; Act becomes authorized in For Action after a separate receipt, without the former `hard_copy_received` field.

## Scope and validation

This is a working increment, not completion of every phase of the original plan. General office routing outside an active PAD review remains available. Full university dashboard statistics, owner/final-recipient rules, external-release forms and mandatory routing policy for every document remain later work.

Validation uses generated databases and sample browser records: PAD review outcomes, real HTTP endpoints, role denial, receipt requirements, duplicate rejection, transaction rollback, exact-office returns, correction cycles, historical compatibility, and existing registration/file/numbering behavior. Browser sample preview: `node tests/pad-review-preview-server.js` in pad-web. No real approval or document transfer is submitted during verification.

Current validation: 253 PAD review checks, 201 office-routing checks (including concurrent registrations and HTTP uploads), 69 presidential monitoring checks, 99 frontend tests, and the production frontend build passed. Browser QA confirmed receipt into For Action, all three PAD outcomes, role-specific All Timeline visibility, and the retained issue-return list and badge using sample records only. This update requires no schema migration or live-document rewrite.
