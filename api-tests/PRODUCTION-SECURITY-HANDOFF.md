# Process 9D2H: production security handoff

This is a deployment requirements handoff, not a production deployment. HTTPS,
domain names, proxy addresses, server paths and service accounts remain Process 11
decisions. Local `.env` and its development defaults are unchanged.

## Token expiry and housekeeping

- Keep `AUTH_TOKEN_LIFETIME_MINUTES=480`. Sanctum uses the existing authentication
  configuration; pruning does not extend or otherwise change authentication.
- `routes/console.php` schedules the established Sanctum command
  `sanctum:prune-expired --hours=24` daily at 00:00 in the application timezone
  (currently UTC). The fixed retention grace is 24 hours after expiry.
- Sanctum deletes rows whose `expires_at` is strictly older than 24 hours, or whose
  `created_at` is strictly older than configured expiration plus 24 hours (32 hours
  with the 480-minute setting). Either expiry condition suffices. Equality at the
  retention boundary remains until a later run. Active and recently expired tokens
  remain; daily timing can retain eligible rows until the next successful run.
- `withoutOverlapping()` uses Laravel's cache mutex, with its default 1,440-minute
  lock expiry. Configure a working persistent cache accessible to the scheduler
  account. The array cache is suitable only for isolated tests. This prevents
  concurrent scheduled runs sharing that cache; it is not a distributed singleton
  guarantee or a lock on manual command invocation. Use one designated scheduler.
- Configure the server's scheduler to execute `php artisan schedule:run` **every
  minute** from the release directory, using the correct PHP executable, service
  identity and production environment. On Windows, use the equivalent repeating
  scheduled task. Monitor scheduler failures and missed runs. Review stale locks
  before any operational lock clearing.
- The task's pruning verification runs only on isolated SQLite memory databases.
  No live pruning, database migration or data backup is part of this substep.

## Origin and authentication contract

`config/cors.php` explicitly covers `api/*`, with empty allowed origins and origin
patterns, credentials disabled and no exposed headers. It does not reflect origins
or provide an environment-configurable cross-origin allowlist. Laravel may return
204 for rejected preflights; that status does not grant browser access. Neither
preflight nor actual cross-origin responses grant CORS authorization.

Methods are GET/HEAD, POST, PUT, DELETE and OPTIONS (preflight). Request headers are
Accept, Authorization, Content-Type and the tracked Axios X-Requested-With default.
These cover bearer requests, JSON and browser-generated multipart content types;
there are no wildcard methods, headers or origins. Same-origin SPA requests and
non-browser API clients continue normally. CORS does not replace authentication,
role/office authorization, or prevent a non-browser client from sending requests.

The previously accepted residual risk remains: bearer tokens in localStorage can
be stolen by successful same-origin script execution/XSS. Storage minimization,
CSP, expiry and logout reduce exposure but do not remove that risk. This substep
does not introduce cookie authentication or a CSRF workflow.

## Production operator requirements

- Set `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL` to the correct HTTPS
  application URL. Keep credentials and keys out of Git, build assets and logs.
- Set `TRUSTED_HOSTS` to the exact deployed hostname(s), comma-separated without
  spaces, schemes, ports, paths or wildcards. Do not leave development hosts as the
  production allowlist. Configure the web server's host handling consistently.
- Enable `SECURITY_HSTS_ENABLED=true` only after HTTPS and redirects are verified.
  The current header is `max-age=31536000; includeSubDomains`: verify HTTPS for all
  affected subdomains before enabling it. Keep HSTS disabled for local HTTP.
- If a reverse proxy is deployed, configure Laravel trust only for known proxy
  addresses and required forwarded headers; never trust all proxies (`*`). Verify
  HTTPS detection and client IP handling against spoofed forwarded headers. No
  proxy trust is added here; topology and exact values need Process 11 review.
- Serve the SPA and API from the same origin. Do not enable wildcard origins,
  credentialed cross-origin access, or origin reflection at the app, proxy or server.
- Build assets with `npm run build` in the release/build environment. After final
  production environment values are installed, prepare Laravel production caches
  with `php artisan config:cache`, `php artisan route:cache` and
  `php artisan view:cache`. Rebuild caches when their inputs change. Verify the
  release has no Vite development `public/hot` marker and serves compiled assets.
- Point the web root to `public`; prevent direct access to `.env`, backups,
  private attachments and source files. Grant the service account only necessary
  write access to `storage` and `bootstrap/cache`; avoid blanket writable permissions.
  Verify attachment access remains mediated by existing authorization.
- Configure protected, rotated logs and an appropriate production log level;
  monitor application and scheduler errors without logging tokens or credentials.
- Establish protected database and attachment backups, retention and restore
  rehearsal. Follow the existing safety-backup/explicit-confirmation restore
  procedure. Keep SQL backups out of Git and publicly served directories.
- Disable PHP `expose_php` in the serving PHP configuration and remove any
  `X-Powered-By` header added by the web server/proxy. Verify externally: Laravel's
  response header removal cannot guarantee removal of headers injected afterward.

Process 9D2I, Process 10 testing and actual Process 11 deployment are outside this
handoff. Commit/push only after review approval; no MySQL backup is needed when
live data remains untouched.
