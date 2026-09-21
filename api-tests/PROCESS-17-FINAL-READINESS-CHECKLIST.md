# Process 17A: final readiness checklist and feature freeze

Status date: 2026-09-21, Personal / Device 1.

This document is the v1.0 readiness checkpoint after the successful controlled
Archive live smoke. It is documentation only; feature development is frozen
unless a true blocker appears.

## Feature freeze rule

- Archive live smoke passed using disposable document `DOC-20260921095049526`.
- Protected reference document `DOC-20260823024024684` was not touched.
- No new features, UI redesign, full email password reset, HTTPS implementation,
  scheduler service-account implementation, app icon/PWA work, or advanced
  reports should enter v1.0 unless explicitly approved as a release blocker.
- From this point forward, changes should be limited to final readiness,
  documentation, backup/handoff, and blocker fixes with focused verification.

## Completed must-have workflows

- Authentication and logout.
- Role-aware navigation and server-side permission checks.
- User management for deployment users.
- Master data used by registration and routing.
- Document registration with tracking number creation.
- QR issuance, registration linking, public QR resolution, and public tracking.
- Document forwarding, receiving, custody transfer, and route history.
- Current Action / Processing Note updates with processing history.
- Attachments, including terminal-state mutation blocking.
- Audit logging for core document, QR, attachment, auth/admin, completion, and
  archive actions.
- Incoming/outgoing document views and document detail workflow controls.
- Dashboard and essential reports.
- Completion workflow.
- Archive workflow, including live smoke, history, audit, public tracking, and
  terminal mutation blocking.
- Apache station serving path on Device 1 with client access from Device 2 and
  Device 3.
- Backup and restore helper routines, including the TCP backup-script fix in
  commit `5d3c871`.

## Deployment URL and verified devices

Current v1.0 station URL:

```text
http://192.168.100.107/login
```

Verified station path:

- Device 1 serves DocTrack through Apache public-root on port 80.
- Device 2 can open the station URL.
- Device 3 can open the station URL.
- Laravel `php artisan serve` on port `8000` is not part of the handoff path and
  should remain stopped during station operation.

## Backup status

Newest verified local SQL backup:

```text
storage/dev-db-backups/doctrack_tuao_20260921_175243.sql
```

Backup metadata:

- Size: `88,117` bytes.
- SHA-256:
  `665A90A323800E631044FE07D721D44640CF737993F918E079AA2EE1FE1A473C`.
- Header confirms database `doctrack_tuao` through host `127.0.0.1`.
- Completion marker: `Dump completed on 2026-09-21 17:52:43`.
- Git ignored: yes.

Required handoff action:

- Upload `storage/dev-db-backups/doctrack_tuao_20260921_175243.sql` to Google
  Drive.
- After upload, verify the uploaded file name, size, completion marker, and
  SHA-256 before treating it as the shared source of truth.
- Do not place SQL backups in Git or public web paths.

## Known limitations and deferred items

- HTTPS, certificate/domain selection, and HSTS remain deferred.
- Scheduler service-account or Windows service-wrapper implementation remains
  deferred. The current scheduler task is intentionally disabled to avoid visible
  pop-ups; the known scheduled Laravel work is token pruning.
- Full forgot-password email workflow remains deferred.
- App icon, favicon, and optional PWA install polish remain deferred.
- Major UI redesign and final visual polish remain deferred unless a usability
  blocker is found.
- Advanced reports and export polish remain deferred.
- Database service consolidation remains deferred. Do not change the current
  working XAMPP-backed database path without a verified backup, restore
  rehearsal, and explicit approval.

## Emergency rollback and restore notes

- Code rollback: return to the last known-good Git commit only after preserving
  any local changes and confirming the target commit. Do not use destructive Git
  commands unless explicitly approved.
- Database rollback: use `scripts/restore-dev-db.ps1 <backup-file>`. The restore
  helper must create a safety backup and require explicit `RESTORE`
  confirmation before replacing the live database.
- Restore rehearsal: use a disposable database such as
  `doctrack_tuao_restore_rehearsal`; do not rehearse against live
  `doctrack_tuao` without explicit approval.
- Apache rollback: restore the timestamped Apache virtual-host backup, run an
  Apache config test, and restart only Apache if the config test passes.
- Firewall rollback: remove the `DocTrack Apache HTTP Private LAN 80` allow rule
  only if station LAN exposure must be withdrawn.
- Scheduler rollback: keep the task disabled if pop-ups return; re-enable only
  after the service-account or service-wrapper approach is verified.

## Next 2-3 day tasks

1. Upload the newest verified SQL backup to Google Drive and verify its metadata.
2. Reconfirm Device 1, Device 2, and Device 3 can open
   `http://192.168.100.107/login`.
3. Run one read-only station smoke: login, dashboard load, document list load,
   public tracking for `DOC-20260823024024684`, and logout.
4. Confirm `APP_DEBUG=false`, `APP_URL` matches the station URL, trusted hosts
   are limited to the deployment hosts/IPs, and the web root remains `public`.
5. Confirm `public/hot` is absent and compiled assets exist under
   `public/build`.
6. Prepare handoff notes for operators: station URL, login/logout reminder,
   backup rule, and who owns Google Drive backup custody.
7. Freeze the release candidate unless a blocker appears; blocker fixes require
   focused tests, a Git commit, and a fresh SQL backup only if database state
   changed.

## Readiness assessment

Estimated deployable v1.0 readiness: 93%.

Remaining risk is mostly operational, not feature completeness: Google Drive
backup upload must still be completed and the final station/device smoke should
be repeated immediately before handoff.
