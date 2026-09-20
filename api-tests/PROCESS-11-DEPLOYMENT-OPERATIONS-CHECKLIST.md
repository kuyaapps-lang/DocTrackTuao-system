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
  Device 1 during station operation. Re-enable it only after replacing the
  interactive task with a non-popup scheduler approach, such as a hidden wrapper
  or dedicated service account setup.
- Current task principal is the local interactive user. For real deployment,
  prefer a dedicated service account with read access to the project, execute
  access to PHP, write access to `storage` and `bootstrap/cache`, and database
  access through the configured Laravel connection.
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
