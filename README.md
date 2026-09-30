# PAD API

Backend API for **PAD — Presidential Action Desk**, the **Electronic Document Routing, Tracking, and Executive Action System**.

The API is framework-free and designed for PHP 7.4+ with MySQL 5.7+/MariaDB hosting on cPanel. It requires PHP PDO MySQL and OpenSSL, plus cURL or HTTPS streams, with no Composer packages.

## Local development

The existing allow-list is `pad_db.tblaccount.acc_email`. Set these values in `.env`, retaining your local MySQL credentials, `APP_KEY`, and Google web-client ID:

```dotenv
APP_ENV=local
APP_URL=http://localhost/pad-api/public
APP_ALLOWED_ORIGINS=http://localhost:5174
DB_DATABASE=pad_db
AUTH_ACCOUNT_TABLE=tblaccount
AUTH_ACCOUNT_EMAIL_COLUMN=acc_email
```

Import `database/schema.sql` once to create `users`, `offices`, `documents`, and `document_routes`. It does not create or modify `tblaccount`. These tables are needed by the existing PAD workspace after login.

Start `pad-web` with `npm run dev`, then open `http://localhost:5174`. The API health endpoint is `http://localhost/pad-api/public/api/health`.

## First deployment

1. Create a MySQL database and user in cPanel, then import `database/schema.sql` with phpMyAdmin.
2. Copy `.env.example` to `.env`, then enter the database details, API URL, PAD web URL, a long random `APP_KEY`, and the Google web-client ID.
3. Point the API subdomain document root to `pad-api/public`.
4. Put `UPLOAD_PATH` outside `public` if the cPanel account permits it, and make that directory writable by PHP.
5. In Google Cloud Console, add the PAD web production domain to **Authorized JavaScript origins**. The frontend does not need a Google client secret.

## API surface

Public endpoints:

- `GET /api/health`
- `GET /api/auth/google/config`
- `POST /api/auth/google` with a Google Identity Services `credential`

Authenticated endpoints use `Authorization: Bearer <access token>`:

- `GET /api/me`, `GET /api/offices`
- `GET /api/documents`, `POST /api/documents` (multipart file upload)
- `GET /api/documents/{reference}`, `GET /api/documents/{reference}/download`
- `POST /api/documents/{reference}/route`, `POST /api/documents/{reference}/receive`
- Administrator-only: `GET /api/admin/overview`, `GET /api/admin/users`, `POST /api/admin/users/{id}`
- Administrator-only: `GET /api/admin/offices`, `POST /api/admin/offices`, `POST /api/admin/offices/{id}`
- Administrator-only: `GET /api/admin/access-list` (read-only existing `tblaccount` email list)

External documents begin with Records. The intended signature path is Records → Presidential Action Desk → Records → destination office. The route history preserves each handoff and receipt.

## Google allow-list and user administration

`tblaccount` remains the access authority and is never modified by PAD. Google sign-in is accepted only when the verified Google email already exists in the configured table and column:

- `AUTH_ACCOUNT_TABLE=tblaccount`
- `AUTH_ACCOUNT_EMAIL_COLUMN=acc_email` — the email column in PAD's existing account table.
- `FIRST_ADMIN_EMAIL=` — optionally set this to one approved email to create the first PAD administrator.

The first approved sign-in creates a separate PAD profile in `users`; it never creates or changes a `tblaccount` record. Assign offices and the `records`, `pad`, or `admin` roles in `users` after login. Import `database/schema.sql` once to create the PAD application tables without altering `tblaccount`.

Email matching ignores case and surrounding spaces. Any Google email domain is accepted when the verified email is in the account list. Existing sessions are checked against that list on each authenticated request. Inactive PAD profiles cannot sign in. `FIRST_ADMIN_EMAIL` only sets the role when a profile is first created; later role changes remain in effect.

Google ID tokens are verified with Google's public signing certificates, checking the RS256 signature, audience, issuer, expiry, subject, and verified email. Certificates are cached in `storage/google-certificates.json` according to Google's cache lifetime; allow outbound HTTPS to `www.googleapis.com`. The cache contains only public certificates. PAD profiles are linked by Google's stable `sub` claim, with the allow-list checked again if an email changes. [Google token verification requirements](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token).

In Google Cloud, choose a **Web application** client. Add `http://localhost`, `http://localhost:5174`, and your actual deployed PAD frontend origin to **Authorized JavaScript origins**. Leave **Authorized redirect URIs** empty: PAD uses the Google Identity Services popup and JavaScript callback. Put the client ID in `GOOGLE_CLIENT_ID`; no client secret is used. For access across Google account domains, configure the Google OAuth audience as External. [Google setup guide](https://developers.google.com/identity/gsi/web/guides/get-google-api-clientid).

## Authentication checks

Run `php tests/auth-smoke.php` after importing the schema. It uses generated signing keys and connection-local temporary tables, leaving existing accounts and application data unchanged. The database user must be allowed to create temporary tables. On XAMPP for Windows, set `$env:OPENSSL_CONF='C:/xampp/php/extras/ssl/openssl.cnf'` in PowerShell first if OpenSSL cannot generate the test key.
