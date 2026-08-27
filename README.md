# PAD API

Backend API for **PAD — Presidential Action Desk**, the **Electronic Document Routing, Tracking, and Executive Action System**.

The API is framework-free and designed for PHP 7.4+ with MySQL 5.7+/MariaDB hosting on cPanel. It requires no Composer packages.

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
- `AUTH_ACCOUNT_EMAIL_COLUMN=email` — change this only when the existing email column uses another name, such as `username`.
- `FIRST_ADMIN_EMAIL=` — optionally set this to one approved email to create the first PAD administrator.

The first approved sign-in creates a separate PAD profile in `users`; it never creates or changes a `tblaccount` record. Assign offices and the `records`, `pad`, or `admin` roles in `users` after login. Import `database/schema.sql` once to create the PAD application tables without altering `tblaccount`.
