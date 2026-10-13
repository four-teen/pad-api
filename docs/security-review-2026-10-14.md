# PAD API and PAD Web security / GitHub push review

Reviewed: 14 October 2026 (Asia/Manila).

## Verdict

The source repositories pass the secret checks performed in this review, after the Git ignore changes below. Neither application has complete production security sign-off. Resolve the findings and run the backend checks before a push that will deploy the applications.

Scope: `pad-api` and `pad-web`, their current tracked and nonignored files, local Git objects, deployment rules, authentication/authorization, attachment handling, frontend rendering, and the frontend dependency audit. Remote GitHub settings and the production server were not inspected. `sam.pad` was outside scope.

## Changes made during this review

- API `.gitignore`: exclude runtime storage by default while retaining `storage/documents/.gitignore`; exclude local backups, logs, private key formats, and `.rnd`. The import inspection output and PHP configuration backup now stay out of normal Git staging.
- Web `.gitignore`: exclude `tests/artifacts/`, which includes live browser captures, plus local backups, logs, private key formats, and `.rnd`. Files remain on disk.
- API `.cpanel.yml`: copy the root `.htaccess` into the deployment directory, alongside the existing public and source copies. Previously, the recipe omitted this protection for a deployment with the project directory under the web root.

Existing application changes were preserved. No `.env` values were changed, no production data was changed, and no commit or push was performed.

## Remaining findings

| Priority | Finding and evidence | Required action |
| --- | --- | --- |
| High | Frontend tooling has known vulnerabilities. The registry audit reports **3 vulnerable packages: 2 high, 1 moderate**. Installed versions are Vite 5.4.21, esbuild 0.21.5, and source-map-js 1.2.1. Vite includes Windows file-access bypass advisories; source-map-js includes a denial-of-service advisory. | Upgrade Vite to a currently supported, patched release and refresh the lockfile, including source-map-js 1.2.2 or later. Re-run the full frontend suite, build, and audit. These are development/build dependencies; the static Apache build does not run a Vite server. Existing dev and preview commands bind to localhost, which reduces network exposure. |
| High if used for deployment | The local Apache configuration on disk loads `C:/VertrigoServ/Php`, whose executable is **PHP 7.1.26**. Its PHP configuration enables `display_errors` and `expose_php`. The default test CLI is separately **PHP 8.2.12**, an old patch release. Apache was unreachable, so its running runtime could not be confirmed. | Deploy with a current patched supported PHP release. Disable displayed PHP errors in production, enable private error logging, and confirm the web server uses the intended PHP installation. The README's PHP 7.4 compatibility statement is not a secure deployment baseline. |
| Medium | PAD bearer tokens persist in `localStorage` (`../pad-web/src/app.js:7,767`). Any JavaScript running in the frontend origin can read them. `signOut()` only removes the local copy; a copied signed token remains usable until its eight-hour expiry, unless the account is disabled or removed from the allow-list. | Use a server-managed session or a carefully designed Secure/HttpOnly cookie flow with appropriate CSRF protection, and server-side logout/revocation. A cookie migration requires coordinated API/frontend changes and regression checks. |
| Medium | No application rate limit was found on `POST /api/auth/google` (`public/index.php`). `request_json()` reads the complete input without an application body-size bound (`src/bootstrap.php`). | Apply login/request throttling and explicit request-body limits at the API or reverse proxy. Verify rejection behavior and appropriate HTTP 429/413 responses. |
| Medium | Frontend `.htaccess` files contain the Google popup opener policy but no Content-Security-Policy or framing restriction. Several checked-in vendor bundles execute `eval()`; for example Bootstrap 5.1.3 has 60 eval calls. | Replace development vendor bundles with production distributions, then implement and validate a CSP compatible with Google Identity Services, fonts, and the API. Include a framing restriction such as `frame-ancestors` and appropriate response security headers. |
| Deployment requirement | Current API `.env` is a local configuration: HTTP/local origins and a privileged database account. `APP_DEBUG` is false, its database password is nonempty, and APP_KEY is 64 bytes. Values were not printed. | Create a separate production configuration with HTTPS, only the deployed frontend origin, a dedicated database account with the required application privileges, and a securely generated application key. Keep local configuration local. |
| Verification blocker | Apache on localhost port 80 and MySQL at the configured address refused connections. Authentication database, administration, routing/upload, and hosting tests could not complete. | Start the local services or provide the correct endpoints, then run the existing smoke suites. Confirm `.env`, `.git`, source, uploads, and backups return HTTP 403; verify authorization and upload/download tests against the target hosting configuration. |

Dependency evidence: [Vite Windows access-control advisory](https://github.com/vitejs/vite/security/advisories/GHSA-fx2h-pf6j-xcff), [source-map-js advisory](https://github.com/advisories/GHSA-68fv-2mgg-jv7q), [esbuild maintainer advisory](https://github.com/evanw/esbuild/security/advisories/GHSA-67mh-4wv8-2f99).

Security guidance: [PHP supported versions](https://www.php.net/supported-versions.php), [PHP unsupported branches](https://www.php.net/eol.php), [OWASP browser storage guidance](https://cheatsheetseries.owasp.org/cheatsheets/HTML5_Security_Cheat_Sheet.html), [OWASP REST assessment guidance](https://cheatsheetseries.owasp.org/cheatsheets/REST_Assessment_Cheat_Sheet.html).

## Checks that passed

- Both actual `.env` files are ignored and untracked. Normal branch/tag/remote history contains `.env.example`, not the actual environment files. Example credentials are placeholders; the Google OAuth client ID is public configuration.
- Pattern scans across current nonignored text files and local Git blobs found no recognizable private keys, GitHub tokens, AWS access keys, Google API keys, or credentials embedded in HTTP URLs. The API scan also found no copies of configured nonplaceholder local secret values in those files/blobs. This is a pattern-based result, not a guarantee that every possible secret format was detected.
- Local runtime files appeared in private Codex capture/checkpoint objects, but not ordinary branch/tag/remote history. They are now excluded from normal staging; no Git history rewrite was needed for the checked branches.
- Google verification requires RS256 and validates signature, fixed server-side signing keys, audience, issuer, expiry, issuance time, subject, and verified email. The **12 offline Google signature/claim checks passed**, using the existing authentication test's database-independent portion and generated test keys.
- Static review found server-side administrator checks, current role/allow-list checks on authenticated requests, parameterized user-input SQL, and office participation checks for document access.
- Uploads require an actual PHP upload, enforce byte limits and a MIME allow-list, use random stored filenames outside `public`, and require document authorization for download. Downloads use attachment disposition and `nosniff`. Live upload/access regression checks remain pending.
- Frontend rendering uses escaping for reviewed dynamic HTML values; frontend tests include escaping and role-scope coverage. No exploitable XSS was established by this review.
- All **79 frontend tests passed** and `npm.cmd run build` succeeded.
- **36 PHP files passed syntax checks** with PHP 8.2.12.
- Git ignore checks confirm environment files, API runtime data, backups, frontend browser captures, dependencies, and compiled output stay excluded. `.env.example` and required `.htaccess` files remain eligible for tracking. `git diff --check` passed in both repositories.

## Before committing and pushing

Include all required source files, tests, and hosting rules together. Several required files are currently untracked, including both root `.htaccess` files, frontend `public/.htaccess`, API routing/schema services, and frontend modules. Committing only previously tracked files would omit required behavior or hosting protection.

Review the staged file list in each repository and retain `.env.example`; keep `.env`, runtime data, private backups, browser captures, and private key material excluded. Resolve the dependency findings and production requirements before an automated deployment. The root API deny rules must be deployed and honored by Apache, or the virtual host must expose only the `public` directory.
