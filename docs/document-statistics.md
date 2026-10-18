# Document statistics

Implemented 2026-10-10 in the current pad-api / pad-web system, using sam.pad's reports as a reference. Legacy documents are not imported by this change.

Document Statistics has its own sidebar entry and page (`#doc_router/statistics`). Module login opens Overview (`#doc_router`) directly to **Received documents needing attention**, with search, pagination and existing role-appropriate actions. This attention list uses the assigned office, including when Central Records has global monitoring access. It contains held received/outside-intake documents and owner correction results still requiring work; it excludes unsent own-office drafts, outstanding handoffs, and finished records. Oldest current-office receipts appear first. Statistics load only when their page opens. The separate Awaiting Receipt shortcut handles unconfirmed handoffs.

## Scope and access

- Document Statistics shows **OVP Statistics** for OVP PAD and OVP Central Records. Both use identical all-office aggregates for the same dates and document type. Administrator can read the same aggregates.
- Document Statistics shows **Office Statistics** for SKSU Officials. The server derives scope from the selected role's saved office, even if the same account also has OVP roles. The scope includes originating, holding, pending, recipient and historical participating offices, so sent records remain tracked.
- Officials cannot substitute another office ID or request `all`. Type choices and workload rows are scoped as well as document totals.
- Global reporting does not grant PAD cross-office document viewing, downloads, intake or actions. The oldest-pending list contains references only for the actor's assigned office. Office workload tables expose aggregates only.
- Each desk's queue cards use its own office even when Central Records monitors a different timeline scope. Opening a desk card restores its own scope. Physical, review and milestone queues can overlap.

## Dates and counting

The API is `GET /api/routing/statistics`, also available as `/api/doc_router/routing/statistics`. It accepts `start_date`, `end_date`, and a document-type name in `document_type`. Dates are inclusive Asia/Manila calendar dates, converted to a half-open UTC interval. The default period is January 1 through today in Manila. Periods of up to ten years are supported. Responses use `Cache-Control: no-store`.

| Statistic | Definition |
| --- | --- |
| Total documents | Unique documents in the reporting scope, all registration dates. Historical movements do not duplicate documents. |
| Awaiting receipt | Current in-transit documents with a pending destination. Local counts belong to that destination. |
| Pending action | Unfinished held work without an outstanding dispatch. Includes delivered correction results still requiring work. Local counts belong to the current holder. |
| Acted / Delivered milestones | Unique documents with a corresponding recorded history action, all time. Local milestones count that office's actions. Delivery does not remove an earlier Act. |
| Current statuses | Current recorded document statuses, separately shown from historical milestones. Delivered correction results can still contribute to pending work. |
| Registered in period / type shares | Registration timestamp in the selected period. Type shares use only this registration cohort. |
| Received / Acted / Delivered / Completed / Returned in period | Unique documents with the corresponding activity in the period. Local activity belongs to the performing office: receipt uses the receiving office, other actions use the sender/actor office. |
| Sent documents / dispatch events | Unique dispatched documents versus individual dispatch events. Repeated sends add activity, not new documents. |
| Monthly trends | Unique registered/received/acted/delivered documents within each month, with zero months retained. A document can appear in multiple months; monthly counts are not a distinct-document period total. |
| Pending age | Manila calendar days since the outstanding dispatch or latest arrival at the current office. Uploads do not restart the age. Buckets are 0–3, 4–7, 8–15, and 16+ days. |
| Office workload / activity | Current pending work plus separately labelled performing-office activity in the selected period. Office totals overlap; they must not be summed for a university document total. |

The date filters apply to period activity and registration cohorts, not the current pending snapshot. Document type applies to both. Desk queue cards use all dates and types independently of reporting filters.

Presidential monitoring also returns `recorded_type_breakdown`: one all-date registry count per active configured or historically recorded document type. Its sum matches `snapshot.total_documents`, including documents from earlier years and documents without a recorded Act. The selected document type applies to both these totals. `acted_type_breakdown` remains the separate selected-period Act summary; ordinary reporting roles receive empty arrays for these presidential fields.

## Processing time

- **Office receipt to next handling action:** completed office stays ending in a dispatch, Act, or completion during the period. Match the latest preceding arrival at that office; ignore uploads and repeated actions in the same stay. Re-receipt starts another stay. Transit and unfinished stays are excluded.
- **Registration to first Act:** registered documents in the selected period with a recorded Act milestone by report time. The Act may occur after that registration period. This is distinct from the period's action activity.
- **Act dispatch to owner receipt:** Central Records Act dispatches whose hard-copy receipt was confirmed during the period. Unconfirmed dispatches are excluded.
- Averages use recorded timestamps and calendar days. Sample sizes are visible; no samples displays a message instead of a misleading zero. There is no overdue/SLA metric without a recorded deadline or agreed target.

## Implementation and validation

- `src/DocumentStatistics.php` performs read-only reporting in a consistent transaction. No schema migration or account/document rewrite is required.
- `src/document-routes.php` exposes both endpoint families without changing operational permissions.
- `DocumentRouting::workspace()` supplies independent `desk_counts` when timeline monitoring differs from the actor's office.
- `pad-web/src/doc_router/statistics.js` renders dates, type filters, totals, monthly charts/tables, type shares, aging, processing times, office comparisons and authorized pending links. Role switches reset report state; delayed responses cannot render in a different role.
- `php tests/statistics-smoke.php`: 52 isolated database/HTTP checks, including matching OVP reports, ordinary-office boundaries, repeated handoffs, retained milestones, correction work, Manila date boundaries, type filters, no-cache responses, revoked roles, assigned-office attention scope, received-work exclusions, oldest receipt ordering, search and unchanged routing authority/history.
- Regression checks: 142 PAD review checks and 151 office routing checks passed, including concurrent registrations.
- Frontend: 62 tests and the production build passed. Sample browser QA verified Overview's PAD decision buttons and Records View/Act buttons, separate Statistics navigation, desktop/mobile layouts with no page overflow, and no browser console errors.

Statistics describe records and structured events in the current system. Legacy historical imports and wider workflow-policy changes remain separate work.
