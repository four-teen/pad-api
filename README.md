# PAD API

Backend API for **PAD — Presidential Action Desk**, the **Electronic Document Routing, Tracking, and Executive Action System**.

The API is framework-free and designed for Apache with PHP 7.4+ and MySQL 5.7+/MariaDB. It requires PHP PDO MySQL and OpenSSL, plus cURL or HTTPS streams, with no Composer packages.

## Local development

The existing allow-list is `pad_db.tblaccount.acc_email`. Set these values in `.env`, retaining your local MySQL credentials, `APP_KEY`, and Google web-client ID:

```dotenv
APP_ENV=local
APP_URL=http://localhost/pad-api/public
APP_ALLOWED_ORIGINS=http://localhost,http://localhost:5174
DB_DATABASE=pad_db
AUTH_ACCOUNT_TABLE=tblaccount
AUTH_ACCOUNT_EMAIL_COLUMN=acc_email
```

Import `database/schema.sql` once for a new database. For an existing PAD database, run `php scripts/setup-office-routing.php` to add Office Document Routing. The installer preserves existing references, adopts any existing `PAD-YYYY-NNNNNN` series, and separates the sender and pending destination for legacy documents in transit. It can be run again safely and does not alter Google account approvals.

Start Apache and MySQL, run `npm run build` in `pad-web`, then open `http://localhost/pad-web/`. No running Vite server is needed. The API health endpoint is `http://localhost/pad-api/public/api/health`. The optional `npm run dev` workflow still uses `http://localhost:5174`.

## First deployment

1. Create a MySQL database and user on the hosting server, then import `database/schema.sql` with phpMyAdmin. For an existing PAD database, retain the existing schema and data.
2. Copy `.env.example` to `.env`, then enter the database details, API URL, PAD web URL, a long random `APP_KEY`, and the Google web-client ID.
3. For the SKSU server, put `pad-api` under the Apache document root and retain its root and public `.htaccess` files. Enable `mod_rewrite` and `AllowOverride All`. The root rules block browser access to `.env`, source, database files, and uploads. Alternatively, an API virtual host may point directly to `pad-api/public`.
4. Keep `UPLOAD_PATH` outside `public` and make it writable by PHP. Enable the PHP Fileinfo extension for document MIME validation, then restart PHP / Apache after changing `php.ini`. Set PHP `upload_max_filesize` and `post_max_size` large enough for `MAX_UPLOAD_BYTES`.
5. Provide HTTPS for the frontend and API. Google's JavaScript origin rules permit HTTP only for localhost; Google login cannot operate at `http://isulan.sksu.edu.ph:8082`. Add the final HTTPS origin to Google Cloud Console's **Authorized JavaScript origins**. The frontend does not need a Google client secret. [Google origin validation](https://developers.google.com/identity/protocols/oauth2/web-server#javascript-origin-validation).

For the intended server with TLS on port 8082, set:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://isulan.sksu.edu.ph:8082/pad-api/public
APP_ALLOWED_ORIGINS=https://isulan.sksu.edu.ph:8082
```

Retain the server's database credentials, `APP_KEY`, and Google client ID. `APP_URL` is also the PAD session-token issuer, so changing it requires users to sign in again. The frontend uses `/pad-api/public` on the same host and port, whether it is served at `/` or `/pad-web/`. The frontend README describes both layouts.

## Account roles and role selection

For an existing database, run `php scripts/setup-account-roles.php` before using the four module roles and separate OVP office assignments. New databases include these columns in `database/schema.sql`. Existing role values are retained; setup preserves each existing OVP role's previous office using `PAD_OVP_PAD_OFFICE_ID` and `PAD_CENTRAL_RECORDS_OFFICE_ID` (legacy defaults: 3 and 2). It does not move documents or change existing SKSU assignments.

Administration can assign any combination of **Administrator**, **OVP PAD**, **OVP Central Records**, and **SKSU Officials** to one approved Google email. The account form requires a separate active routing office for each selected OVP role, saved as `users.ovp_pad_office_id` and `users.ovp_central_records_office_id`. SKSU Officials keeps its independent `users.office_id`, with an active office represented in `office_officials`. Each role on an account must use a different office. Office selectors include names, campuses, and codes to distinguish similar entries. Configure all accounts serving the same OVP unit with that unit's exact office ID.

Users with multiple roles choose the role and see its assigned office at login. The API uses the account's saved office for that role on every request, including existing sessions after an administrator changes the assignment. New OVP assignments require an explicit selection; they do not silently fall back to legacy environment defaults. Missing or inactive OVP offices block entry with an explanatory error. Assigned OVP offices cannot be deactivated or deleted until their accounts are reassigned. The Google identity and bearer token remain the same when switching roles. `/api/me` and Google login return saved OVP IDs, `role_offices` details, and the active role and office. Documents appear in Incoming only when their pending destination ID matches that active office; changing an account assignment never rewrites document history. Central Records external intake uses its selected office. Administrator external intake uses its saved Central Records assignment, or the legacy central office default when none is assigned.

Module requests send the selected role in `X-PAD-Role`. The API checks that assignment against the current database profile on every request. Without a selection, a multi-role user cannot open a module. Office module roles cannot access administration, including identities that also have Administrator assigned. Removing a role takes effect for existing tokens on their next request. The office document workspace uses `/api/doc_router/routing/workspace` and `/api/doc_router/documents` (including detail, download, and all movement suffixes), backed by the existing routing service and global number series.

## API surface

Administrator **Database management** shows all PAD table counts and supports reviewed clears of documents and eligible reference data. It saves a private recovery backup and success audit entry for each completed operation. Login/access tables, document numbering, and the audit log are protected. For an existing database, run `php scripts/setup-database-maintenance.php`; it adds the audit table without changing records. Operation details, recovery limits, and isolated tests: [Database maintenance](docs/database-maintenance.md).

Module login opens Overview directly to received documents needing attention in the assigned office, with search, pagination and existing action buttons. **Document Statistics** is a separate sidebar page providing shared all-office statistics for **OVP PAD / OVP Central Records**, and server-scoped statistics for **SKSU Officials**. Reports include current backlog, unique document milestones, period activity, monthly trends, document-type shares, pending age, processing times and office workload. Current backlog remains visible outside selected registration dates. Global statistics do not grant foreign-office document access or actions. Definitions and checks: [Document statistics](docs/document-statistics.md). No schema migration is needed; run `php tests/statistics-smoke.php` for isolated reporting/access checks.

PAD review decisions and role-specific workload queues are documented in [PAD review decisions](docs/pad-review-decisions.md). On existing installations, run `php scripts/setup-pad-reviews.php` before using them. It preserves existing records and does not invent historical decisions. Fresh schema imports include the review table and history fields. Run `php tests/pad-review-smoke.php` for isolated decision, correction and HTTP checks.

Public endpoints:

- `GET /api/health`
- `GET /api/auth/google/config`
- `POST /api/auth/google` with a Google Identity Services `credential`

Authenticated endpoints use `Authorization: Bearer <access token>`:

- `GET /api/me`, `GET /api/offices`
- `GET /api/routing/statistics` with `start_date`, `end_date`, and `document_type`; module roles can use `/api/doc_router/routing/statistics`
- `GET /api/routing/workspace` with `queue`, `search`, `status`, and `page`; administrators and Records can also select `office_id` or `all`
- `GET /api/documents`, `POST /api/documents` (multipart registration, optional file)
- `POST /api/documents/{reference_no}/file` (multipart file upload or replacement)
- `GET /api/documents/{reference}`, `GET /api/documents/{reference}/download`
- `POST /api/documents/{reference}/route`, `POST /api/documents/{reference}/receive`
- `POST /api/documents/{reference}/return`, `/act`, `/complete`, `/deliver`
- Administrator-only: `GET /api/admin/overview`, `GET /api/admin/users`, `POST /api/admin/users/{id}`
- Administrator-only: `GET /api/admin/offices`, `POST /api/admin/offices`, `POST /api/admin/offices/{id}`
- Administrator-only: `GET /api/admin/access-list` (read-only existing `tblaccount` email list)
- Administrator-only: `GET /api/admin/database`, `POST /api/admin/database/preview`, `POST /api/admin/database/clear`, `GET /api/admin/database/backups/{event_id}`

## Office Document Routing

Every active office can register, send, receive, forward, return, record an action, and finish a document. Accounts must be assigned to an office to act for it. Administrators and Records can monitor other offices; monitoring does not let them confirm receipt or take action for another office.

The workspace provides Incoming, Received, Outgoing, Returned, Acted, Delivered / completed, and All Timeline queues, with server pagination and counts. A send creates a pending destination while the sender remains the holding office. Only the destination can confirm receipt, which transfers responsibility. Sending and receiving are separate timeline events with their actual staff and timestamps. Earlier participating offices retain read and download access after forwarding. Returns go to the previous sender and require a reason; actions and deliveries also require remarks. Completed or delivered documents retain their timeline and cannot be routed again.

Registration requires a source and catalog document type. Particulars are entered in Description / registration remarks; when a client omits subject, the API derives the list heading from the particulars. The file is not part of registration: the active originating or current holding office can upload or replace it from document detail, including after completion or delivery. Run `php scripts/setup-document-files.php` once on an existing database to allow empty attachment metadata and upload history; rerunning it preserves existing files. Monitoring access alone does not grant upload permission. Later uploads preserve the official number and routing status and record the actor and filename in the timeline. Failed replacements preserve the previous attachment; successful replacements update the current download. A document without a file returns HTTP 404 for download. External documents can be registered by Records or an administrator and begin at the active Records Office. Supported files remain PDF, Word, Excel, JPG, and PNG, within `MAX_UPLOAD_BYTES`.

Run `php scripts/setup-document-types.php` to create PAD's document registration tables and copy the type list from `sam.pad.tbltypeofdocuments` and personnel names from `sam.pad.tblprofiles`. The script defaults to the sibling `sam.pad/db.php`; pass its full path as the first argument for another installation. It can be rerun to refresh names without duplicating records. Blank personnel names are skipped. The API reads only PAD's own catalog tables after setup. Run this after importing `database/schema.sql` on a new installation, or directly on an existing PAD database that already has office routing installed.

`GET /api/routing/registration-options` (or `/api/doc_router/routing/registration-options` with Router selected) supplies the active catalog. New registrations select `document_type_id`; existing free-text type names remain on historical documents. Known catalog names are accepted from older API clients. Other document types can link optional `personnel_ids[]`. Travel Order requires at least one traveler, destination, purpose, order date, departure date, return date, travel type (Official Time or Official Business), and vehicle requirement (Yes or Not Necessary); travel remarks are optional. Its fields use the `travel_` prefix in multipart requests. Return date cannot precede departure date. The document, personnel snapshots, travel details, number allocation, and first event save together; document detail includes `personnel` and `travel_details`.

Run `php scripts/setup-document-sources.php` to create `document_sources` and copy all agency / division entries from `sam.pad.tbldivisions`. The script defaults to the sibling `sam.pad/db.php` and accepts another configuration path as its first argument. Rerunning it refreshes names by legacy division ID without creating duplicate records. The full list is retained, including university divisions; the legacy table has no internal/external classification. The registration options response includes `source_divisions`. Document source is one Select2 dropdown containing only that catalog; search appears inside the opened dropdown. The chosen source identifies the document owner independently of the registering office. Ordinary office registrations submit `origin_type=office` with `source_division_id` and start at the account's assigned office. Records / Administrator registrations continue to submit `origin_type=external` and begin at Records. The API validates active catalog entries and stores their canonical name in `external_sender` as a historical snapshot for either workflow. The selected ID and original source name appear in document detail. Legacy registrations without a source ID retain their office origin. Older external-registration clients can submit a known active catalog name through `external_sender`.

The API assigns a global annual number such as `PAD-2026-000001` only when registration succeeds. It uses the Asia/Manila year and locks the annual counter in the same transaction as the document and first history event. Unique database keys prevent duplicate references and annual sequence values. Clients send a stable `submission_key` for retries: matching retries return the existing document; changed details with the same key return HTTP 409. Failed database saves roll back their number and remove the staged file. Existing references remain usable.

Run `php tests/office-routing-smoke.php` for workflow, access, pagination, rollback, numbering, authenticated uploads, and download checks, including twelve concurrent PHP workers. It creates an empty database with a generated `pad_routing_test_` name and removes that test database and its temporary test uploads afterward. The configured database user needs database creation privileges for this isolated check; production records and uploads are untouched.

## Google allow-list and user administration

`tblaccount` remains the access authority. Google sign-in is accepted only when the verified Google email already exists in the configured table and column. Administrators manage those approvals through User accounts; Google sign-in itself never creates approvals:

- `AUTH_ACCOUNT_TABLE=tblaccount`
- `AUTH_ACCOUNT_EMAIL_COLUMN=acc_email` — the email column in PAD's existing account table.
- `FIRST_ADMIN_EMAIL=` — optionally set this to one approved email to create the first PAD administrator.

The first approved sign-in creates or links a PAD profile in `users`; it never creates or changes a `tblaccount` record. Assign module access and the office through Administration after login. Existing `staff`, `records`, and `pad` primary-role values remain available for compatibility. Import `database/schema.sql` once for new PAD application tables without altering `tblaccount`; use the setup scripts for existing databases.

Email matching ignores case and surrounding spaces. Any Google email domain is accepted when the verified email is in the account list. Existing sessions are checked against that list on each authenticated request. Inactive PAD profiles cannot sign in. `FIRST_ADMIN_EMAIL` only sets the role when a profile is first created; later role changes remain in effect.

Google ID tokens are verified with Google's public signing certificates, checking the RS256 signature, audience, issuer, expiry, subject, and verified email. Certificates are cached in `storage/google-certificates.json` according to Google's cache lifetime; allow outbound HTTPS to `www.googleapis.com`. The cache contains only public certificates. PAD profiles are linked by Google's stable `sub` claim, with the allow-list checked again if an email changes. [Google token verification requirements](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token).

In Google Cloud, choose a **Web application** client. Add `http://localhost`, `http://localhost:5174`, and your actual deployed PAD frontend origin to **Authorized JavaScript origins**. Leave **Authorized redirect URIs** empty: PAD uses the Google Identity Services popup and JavaScript callback. Put the client ID in `GOOGLE_CLIENT_ID`; no client secret is used. For access across Google account domains, configure the Google OAuth audience as External. [Google setup guide](https://developers.google.com/identity/gsi/web/guides/get-google-api-clientid).

## Authentication checks

With Apache and MySQL running locally, run `php tests/hosting-smoke.php`. It checks the compiled frontend, scripts, styles, icon font, blocked private files, CORS, bearer-token handling, document validation routes, and authenticated workspace reads. It signs a local PAD token for an existing approved profile and leaves the database and uploads unchanged; it does not complete a real Google login.

Run `php tests/auth-smoke.php` after importing the schema. It uses generated signing keys and connection-local temporary tables, leaving existing accounts and application data unchanged. The database user must be allowed to create temporary tables. On XAMPP for Windows, set `$env:OPENSSL_CONF='C:/xampp/php/extras/ssl/openssl.cnf'` in PowerShell first if OpenSSL cannot generate the test key.
