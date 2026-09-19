# Process 11: deployment and operations checklist

This checklist captures the current demo-ready operating path for DocTrackTuao.
It is not a final production runbook. Do not store passwords, bearer tokens, QR
secrets, SQL row contents or `.env` values in this document.

## Office demo startup

- Start from `C:\xampp\htdocs\DocTrackTuao-system`.
- Verify Git is on `main`, clean, and synced before the demo.
- Use the LAN fallback server for client devices:

```powershell
php artisan serve --host=192.168.100.107 --port=8000
```

- Device 2 and Device 3 should use `http://192.168.100.107:8000`.
- Confirm `public/hot` is absent and compiled assets exist under `public/build`.
- Log out demo devices when testing is complete.

## Apache public-root status

- A local Apache virtual host has been proven on Device 1 with document root:

```text
C:/xampp/htdocs/DocTrackTuao-system/public
```

- Device 1 can serve `/login`, `/build/manifest.json`, built JS/CSS assets and
  `/api/user` correctly through Apache.
- LAN clients currently cannot reach Device 1 on port 80 because of environment
  or network policy. Do not keep adding firewall rules blindly.
- For the office demo, prefer the known-working `php artisan serve` LAN fallback.

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

## Scheduler plan

- Laravel currently schedules:

```text
sanctum:prune-expired --hours=24
```

- For a real deployment, create one Windows Scheduled Task that runs every minute:

```text
Program:   C:\xampp\php\php.exe
Arguments: artisan schedule:run
Start in:  C:\xampp\htdocs\DocTrackTuao-system
```

- Demo can run without this task because the current scheduled work is housekeeping
  for expired Sanctum tokens.
- For real deployment, prefer a dedicated service account with read access to the
  project, execute access to PHP, write access to `storage` and `bootstrap/cache`,
  and database access through the configured Laravel connection.
- Log scheduler failures through Task Scheduler history and/or a protected
  `storage/logs/scheduler.log` wrapper.

## Printer and QR scanner acceptance

- LAN and physical hard-copy QR acceptance passed.
- Device 1 served DocTrack on `http://192.168.100.107:8000` and printed the QR
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
- If temporary DocTrack Apache LAN firewall rules are recreated, remove only those
  DocTrack-specific rules during cleanup.
- Scheduler rollback, if the task is created later:

```powershell
schtasks /Delete /TN "DocTrack Laravel Scheduler" /F
```

- Before any restore to the live development DB, use the restore script's safety
  backup and explicit `RESTORE` confirmation workflow.
