# PAD API hosting deployment

PAD Web is a separate Vercel frontend. The production API base is `https://padapi.sksu-orms.net`, with health at `/api/health`.

## Verify the cPanel folder

In **cPanel → Domains → Manage padapi.sksu-orms.net**, find the domain's **Document Root**. The application deployment folder and document root are related as follows:

| Setting | Prepared value |
| --- | --- |
| Application folder / `.cpanel.yml` `DEPLOYPATH` | `/home/tnrmssks25/padapi.sksu-orms.net` |
| Preferred domain document root | `/home/tnrmssks25/padapi.sksu-orms.net/public` |
| Supported alternative document root | `/home/tnrmssks25/padapi.sksu-orms.net` |
| Frontend API base / API token issuer | `https://padapi.sksu-orms.net` |

These folder paths are prepared defaults based on the account name in the existing deployment file. They have not been verified in your cPanel account. Adjust `DEPLOYPATH` to the actual application folder before running deployment. If the document root ends in `/public`, `DEPLOYPATH` must be its parent folder. Keep the cPanel-managed Git checkout separate from `DEPLOYPATH` so deployment does not copy files onto themselves.

The public-root layout keeps private files outside the web root. For an application-root layout, retain both `.htaccess` files and enable Apache `mod_rewrite` and `AllowOverride All`. The root file permits `/api/...` and `/public/...` while blocking source, setup scripts, schemas, environment files, and storage. Both layouts expose the same frontend API base; no `/public` suffix is required.

## Deploy source while retaining runtime data

Use cPanel **Git Version Control** to manage `four-teen/pad-api`. In **Pull or Deploy**, first **Update from Remote**, then **Deploy HEAD Commit** after confirming the branch and deployment path. A GitHub push by itself does not run cPanel pull deployment. [cPanel deployment documentation](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/).

The tasks copy `.htaccess`, `public`, `src`, `scripts`, `database`, and `.env.example`. They create storage directories with private permissions. They do not copy or replace `.env`, uploaded files, certificate cache, or recovery backups, and they do not run database migrations automatically.

For a manual deployment, copy the same files and directories. Create `storage/documents` and `storage/maintenance-backups` outside `public` and make them writable by the PHP account. Keep the application folder and Git checkout private except for the public routes.

## Configure the hosting environment

Use the host's File Manager or terminal to create the application folder's private `.env` from `.env.example` only when `.env` does not already exist. Set:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://padapi.sksu-orms.net
APP_ALLOWED_ORIGINS=https://YOUR-ACTUAL-FRONTEND-DOMAIN
```

Find the actual frontend domain in **Vercel → PAD Web project → Settings → Domains**. Do not guess it from the repository name. Use the complete HTTPS origin, without a trailing slash or path. The example CORS setting stays blank until that value is known. Add multiple verified origins with commas. Do not use a wildcard for Vercel previews.

Enter the hosting database host/name/user/password and the existing Google Web application client ID. Retain an existing secure `APP_KEY` when keeping the same installation; use a long random secret for a new installation. Keep the key and database password out of Git and Vercel. Changing `APP_URL` requires users to sign in again because it is the session token issuer.

Enable PHP PDO MySQL, OpenSSL, and Fileinfo, plus cURL or HTTPS streams. Allow outbound verified HTTPS to `www.googleapis.com`. Set the web PHP `upload_max_filesize` to at least `MAX_UPLOAD_BYTES` and `post_max_size` above that limit to accommodate multipart overhead. The CLI PHP configuration may differ from the web PHP configuration.

## Use the correct database setup

For an existing PAD database, back up its schema/data and uploaded files first. Retain all documents, number counters, users, offices, Google account approvals, catalog entries, and file paths. Do not import the fresh-install schema over that database. Run `check-production.php` to identify missing tables or columns, then use only the relevant setup scripts:

| Feature | Existing-database setup |
| --- | --- |
| Office document routing | `php scripts/setup-office-routing.php` |
| Administration and reviewed official directory | `php scripts/setup-administration.php` |
| Role and separate OVP office assignments | `php scripts/setup-account-roles.php` |
| Later document attachments | `php scripts/setup-document-files.php` |
| PAD review decisions | `php scripts/setup-pad-reviews.php` |
| Database-management audit | `php scripts/setup-database-maintenance.php` |

Run office-routing setup before dependent feature setup when upgrading an older installation. Review the administration setup separately because it also imports the reviewed official directory. No setup script needs to run merely because the API hostname changes.

For a fresh database, import `database/schema.sql` once. This does not create the legacy approved-account roster; retain or transfer the real `tblaccount` schema and approvals, including the account fields required by administration. Also transfer the real document-source/type/personnel catalogs from the existing PAD database, or use `setup-document-types.php` and `setup-document-sources.php` with an available `sam.pad/db.php` configuration. Those import scripts require PHP MySQLi and access to that legacy database. Do not invent approvals or catalog entries to make deployment checks pass.

When moving an existing installation to a new hosting account, restore its reviewed database and documents together and retain the signing key privately. The current source schema provides a reference for the readiness check, not an instruction to erase or re-import existing tables.

## Verify before publishing the frontend

From the deployed application folder, run:

```sh
php scripts/check-production.php
php scripts/check-hosting.php https://padapi.sksu-orms.net https://YOUR-ACTUAL-FRONTEND-DOMAIN
```

The first command checks configuration, PHP extensions, private writable directories, database connectivity, expected tables/columns, and the existing account roster without modifying data. The second checks verified HTTPS, PAD health JSON, exact CORS origins, preflight headers/methods, Google public configuration, and rejection of unauthenticated requests. Both return a nonzero exit status when a required check fails. The HTTP check does not exercise an approved Google login, real upload, or authenticated document action.

Confirm `/api/health` returns HTTP 200, `/api/auth/google/config` reports enabled, and private paths such as `/.env`, `/src/bootstrap.php`, `/database/schema.sql`, `/scripts/check-production.php`, and `/storage/google-certificates.json` are inaccessible. Then register the frontend origin in Google Cloud and publish the verified frontend from GitHub to Vercel. Complete the browser checks in the frontend's `DEPLOYMENT.md` using approved accounts and suitable test records.
