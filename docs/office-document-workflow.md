# Office document queues and final action

Updated 2026-10-09.

- **My Document**: office-origin registrations held by their encoding office with no dispatch history. The queue and action areas show Send and Delete; titles open details. Outside intake remains Received. After a dispatch, a document never regains Delete even when returned to its source.
- **Awaiting Receipt**: sent documents at their pending destination. Actions are View, Receive and Return. Return requires a reason and sends back to the exact last sender without recording a receipt at the rejecting office.
- **Received**: confirmed handoffs and outside intake held by the office. Own documents returned after a real dispatch also belong here.
- **Returned from PAD** (`pad_results`): the Records workload for the latest PAD return. List actions are **View** (timeline and attached copy) and **Act**. Direct Send and Return cannot bypass the Records action.
- **Act**: available only to the active OVP Central Records module holding, or receiving, a document whose latest dispatch came from OVP PAD. The user explicitly confirms hard-copy receipt, selects an active owner office, and records the action taken. An existing attachment and notes on the latest PAD dispatch remain required. Receipt (if pending), Records custody, the Act event, and owner dispatch commit together; failure rolls back all of them.
- **Acted**: the archive of Records actions and dispatches to owners. New releases remain physically in transit until the selected owner confirms receipt. Records retains its Acted archive after delivery. The Act dispatch ends Records' office stay and starts a separately measured transit interval. Earlier terminal Acted records remain closed without being rewritten.
- **Delivered / completed**: **Delivered** records the actual owner hard-copy receipt of a released PAD result; it is not set merely by dispatching. **Completed** closes processing without a delivery handoff. Owner receipt also closes the release route and records its delivery time. A result requiring correction remains available to its owner for correction and resubmission on the same file code, while its earlier Records action and delivered instructions remain in history.

All guards apply in the shared API for both /api/documents and /api/doc_router/documents. Deletion locks and rechecks the document, removes unsent registration details and the attached file, and leaves annual numbering unchanged. No database migration or live-document rewrite is needed. New owner releases use dispatched `acted` history events, keeping Records' action separate from physical routing status.

Validation: 151 isolated office-routing checks (including HTTP deletion and 12 concurrent registrations), 142 isolated PAD-review checks (including HTTP Act and owner receipt), 55 frontend tests, production build, and sample browser verification. No real document is transferred, acted on or deleted for verification.
