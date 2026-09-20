# Process 11: deployment and operations checklist

This checklist captures the current demo-ready operating path for DocTrackTuao.
It is not a final production runbook. Do not store passwords, bearer tokens, QR
secrets, SQL row contents or `.env` values in this document.

## Office demo startup

- Start from `C:\xampp\htdocs\DocTrackTuao-system`.
- Verify Git is on `main`, clean, and synced before the demo.
- Apache public-root on port 80 is the deployment target on Device 1.
- Final station URL for Device 1, Device 2, and Device 3:
  `http://192.168.100.107/login`.
- Keep Laravel's `php artisan serve` stopped during station rollout.
- After Device 1 restarts, Apache and MySQL/MariaDB should come back
  automatically, `http://192.168.100.107/login` should remain available through
  Apache port 80, and Laravel `:8000` should remain unused.
- Confirm `public/hot` is absent and compiled assets exist under `public/build`.
- Log out demo devices when testing is complete.
- Future station rollout item, not a blocker: add a DocTrack app icon and desktop
  shortcut on client stations after the serving path is stable.

## Serving decision

- Apache public-root is the selected deployment serving model. The local virtual
  host on Device 1 uses document root:

```text
C:/xampp/htdocs/DocTrackTuao-system/public
```

- Device 1 can serve `/login`, `/build/manifest.json`, built JS/CSS assets and
  `/api/user` correctly through Apache.
- Process 15F verified local Apache health after restart:
  `/login` returns `200` HTML, `/api/user` returns `401` JSON, and public
  tracking for `DOC-20260823024024684` returns `200`.
- Process 15I closed the LAN port 80 remediation on Device 1. Device 1 network
  profile is Private; Device 2 and Device 3 can connect to
  `192.168.100.107:80` and open `http://192.168.100.107/login`.
- Process 15L passed the restart/startup test after Device 1 reboot. Apache and
  MySQL/MariaDB were running automatically, Device 1 remained on
  `192.168.100.107` with a Private network profile, `/login` returned `200`
  HTML, `/api/user` returned expected unauthenticated `401` JSON,
  `/build/manifest.json` returned `200` JSON, Device 2 and Device 3 could still
  open the login page through `192.168.100.107:80`, Laravel `:8000` had no
  listener, and no scheduler pop-up was observed.
- Laravel's `php artisan serve` on `:8000` is not part of the final station path
  and should remain stopped unless a separately approved troubleshooting step
  explicitly starts it.
- HTTPS, certificate selection, and HSTS are deferred until the final host/domain
  decision. Do not enable HSTS while serving plain HTTP.

## Environment checklist

- `APP_DEBUG=false` for demo/deployment-style checks.
- `APP_URL` should match the active serving URL.
- `TRUSTED_HOSTS` should include only the intended hostnames/IPs for the run.
- Keep MySQL bound to loopback unless a reviewed deployment requires otherwise.
- Keep SPA and API same-origin; frontend API calls should use `/api`.
- Do not commit `.env`, credentials, SQL backups, `vendor`, `node_modules`,
  uploaded files or generated runtime data.

## Security reminders

- Web root must point to `public`, not the project root.
- Verify these paths do not expose raw contents: `/.env`, `/vendor/`, `/app/`,
  `/routes/`, `/database/`, and `/storage/dev-db-backups/`.
- Process 15F PHP/Apache hardening is active locally: `X-Powered-By` is absent,
  `Server` is reduced to `Apache`, PHP `expose_php` is off, PHP display errors
  are off, PHP timezone is `Asia/Manila`, Apache `ServerTokens` is `Prod`, and
  `ServerSignature` is off.
- MySQL is currently listening on loopback (`127.0.0.1:3306`) for local-only DB
  access.
- Keep SQL backups outside Git and out of any served public path.
- Keep `storage` and `bootstrap/cache` writable only for the service account that
  needs them.
- Do not print passwords, bearer tokens, QR token values or sensitive SQL rows in
  logs, screenshots or handoff notes.

## Backup procedure

- Run a backup after meaningful database-changing testing:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\backup-dev-db.ps1
```

- The script verifies the dump exists, is nonempty, has the expected dump header,
  has a dump completion marker, prints SHA-256, and confirms Git ignore coverage.
- Backups are written to `storage/dev-db-backups/` and must remain Git-ignored.
- Upload the newest trusted verified backup to Google Drive when closing a DB
  changing session.
- The newest local backup as of Process 15J is
  `storage/dev-db-backups/doctrack_tuao_20260919_173656.sql`. It is 87,512
  bytes, has SHA-256
  `6223844A6A478FB7F358212FA3C8CBD9DBC7DAADAD2FE1F3997EC144B044250C`, and
  ends with `Dump completed on 2026-09-19 17:36:59`.

## Daily operations routine

- Start check:
  verify Device 1 is on the intended Private network, Apache is running, Laravel
  `:8000` is stopped, `http://192.168.100.107/login` opens locally and from
  client stations, and Git is clean on the expected branch/commit.
- Closeout backup rule:
  create a new SQL backup only after meaningful database-changing activity, such
  as document registration, routing, receiving, processing, attachment changes,
  user/master-data changes, or test data that must be preserved. Do not create a
  duplicate backup after read-only checks, firewall changes, scheduler changes,
  or documentation-only Git commits.
- Google Drive upload rule:
  upload the newest trusted verified SQL backup after every DB-changing session.
  Confirm the uploaded file name, size, completion marker, and SHA-256 against
  the local file before treating it as the shared source of truth.
- Backup custody:
  the system owner or assigned administrator should keep the Google Drive copy;
  Device 1 may keep local working backups under `storage/dev-db-backups/`, but
  those files are operational data and must not be committed.
- Weekly restore rehearsal:
  rehearse the newest trusted backup at least weekly, or before major demos, into
  a disposable database such as `doctrack_tuao_restore_rehearsal`; never rehearse
  restore against the live `doctrack_tuao` database without explicit approval and
  the restore script's safety-backup workflow.
- Never commit `.env`, SQL backups, uploaded files, credentials, bearer tokens,
  QR token values, `vendor`, `node_modules`, generated runtime data, or sensitive
  row contents to Git.
- The `DocTrack Laravel Scheduler` task is disabled for now to avoid the
  recurring console pop-up. Process 15K confirmed that even a hidden PowerShell
  interactive-user action could still surface a visible window every minute.
  Process 15L confirmed the task remained disabled after reboot and no black
  scheduler pop-up was observed.
  Before depending on scheduled jobs in deployment, recreate the scheduler under
  a dedicated account configured to run whether the user is logged on or run it
  through a Windows service wrapper.

## Restore rehearsal rule

- Do not rehearse restore against live `doctrack_tuao` unless explicitly approved.
- Use a disposable database such as `doctrack_tuao_restore_rehearsal`.
- Before creating or dropping the disposable DB, check whether it already exists.
  If it exists, stop unless cleanup is explicitly approved.
- Verify restored table counts and key records without printing passwords, token
  values or sensitive row contents.
- Process 11G restored `doctrack_tuao_20260919_091319.sql` into
  `doctrack_tuao_restore_rehearsal` and verified table/document counts; the live
  database was not touched.

## Scheduler

- Laravel currently schedules:

```text
sanctum:prune-expired --hours=24
```

- Process 15E installed and verified the Windows Scheduled Task:

```text
Task:      DocTrack Laravel Scheduler
Program:   C:\xampp\php\php.exe
Arguments: artisan schedule:run
Start in:  C:\xampp\htdocs\DocTrackTuao-system
Frequency: every minute
```

- The task manual trigger returned `LastTaskResult: 0`.
- Process 15I disabled this task to stop the recurring black console pop-up on
  Device 1 during station operation.
- Process 15K tried a hidden PowerShell action that logged to
  `storage/logs/scheduler.log`; the manual run succeeded with `LastTaskResult:
  0`, but the recurring task still produced a visible pop-up every minute.
  The task was disabled again. This is acceptable temporarily because the only
  current scheduled Laravel command is daily Sanctum token pruning.
- Process 15L confirmed after Device 1 reboot that the scheduler task remained
  disabled and that no scheduler console pop-up appeared during startup checks.
- Current task principal is the local interactive user. For real deployment,
  prefer a dedicated service account task configured to run whether the user is
  logged on or use a Windows service wrapper such as NSSM or WinSW. The runtime
  account needs read access to the project, execute access to PHP, write access
  to `storage` and `bootstrap/cache`, and database access through the configured
  Laravel connection.
- Log scheduler failures through Task Scheduler history and/or a protected
  `storage/logs/scheduler.log` wrapper.

## LAN firewall

- Process 15I disabled only the enabled inbound TCP Block rule for Apache:
  `C:\xampp\apache\bin\httpd.exe`, display name `Apache HTTP Server`, Public
  profile, protocol TCP, local port Any. The Apache UDP Block rule was left
  unchanged.
- Process 15I added the narrow inbound Allow rule:

```text
Name:        DocTrack Apache HTTP Private LAN 80
Program:     C:\xampp\apache\bin\httpd.exe
Direction:   Inbound
Action:      Allow
Profile:     Private
Protocol:    TCP
Local port:  80
Remote IP:   LocalSubnet
```

- Do not loosen unrelated firewall rules for DocTrack station access.

## Printer and QR scanner acceptance

- LAN and physical hard-copy QR acceptance passed.
- Device 1 served DocTrack on `http://192.168.100.107/login` and printed the QR
  through Epson.
- Device 2 scanned the physical QR and opened the correct public tracking page.
- Device 3 participated as Mayor Office.
- Tested document: `DOC-20260823024024684`.
- Confirmed state: Office of the Mayor, status `Received`, current action
  `For Approval`, with Mayor to Accounting receive and Accounting to Mayor receive
  in route history.

## Rollback notes

- Apache vhost rollback: restore the timestamped backup of
  `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, then run Apache config test and
  restart only Apache if the config test passes.
- LAN firewall rollback:

```powershell
netsh advfirewall firewall set rule name="Apache HTTP Server" dir=in program="C:\xampp\apache\bin\httpd.exe" protocol=TCP new enable=yes
netsh advfirewall firewall delete rule name="DocTrack Apache HTTP Private LAN 80"
```

- Scheduler rollback:

```powershell
Enable-ScheduledTask -TaskName "DocTrack Laravel Scheduler"
```

- Before any restore to the live development DB, use the restore script's safety
  backup and explicit `RESTORE` confirmation workflow.
