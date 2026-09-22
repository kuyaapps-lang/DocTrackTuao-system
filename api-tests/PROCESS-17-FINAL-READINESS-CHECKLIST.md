# Process 17A: final readiness checklist and feature freeze

Status date: 2026-09-21, Personal / Device 1.

This document is the v1.0 readiness checkpoint after the successful controlled
Archive live smoke. It is documentation only; feature development is frozen
unless a true blocker appears.

## Feature freeze rule

- Archive live smoke passed using disposable document `DOC-20260921095049526`.
- Protected reference document `DOC-20260823024024684` was not touched.
- Final read-only station smoke passed on Device 1, Device 2, and Device 3.
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
- Process 17B final station smoke passed on all three devices: login page opened,
  login succeeded, dashboard loaded, documents list loaded, target document
  `DOC-20260823024024684` opened, public tracking loaded, and logout succeeded.
- Laravel `php artisan serve` on port `8000` is not part of the handoff path and
  should remain stopped during station operation.

Read-only smoke warning:

- During read-only station checks, do not click workflow mutation controls such
  as Complete Process, Archive, Forward, Receive, Save Current Processing, Edit,
  or attachment upload/delete.
- Complete Process changes the document to `Completed`, writes completion
  history and audit rows, blocks normal workflow mutations, and requires a new
  SQL backup if performed on live data.

## Backup status

Newest verified local SQL backup for the v1.0 handoff:

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
- Google Drive upload: user-confirmed complete for this backup during Process
  17C. Local metadata was rechecked before recording the upload confirmation.

Required handoff action:

- Keep the Google Drive copy as the shared source of truth for this v1.0
  handoff backup.
- If a future backup is uploaded, verify the uploaded file name, size,
  completion marker, and SHA-256 before treating it as the new shared source of
  truth.
- Do not place SQL backups in Git or public web paths.

## Process 18F v1.1 closeout: admin temporary password reset

Admin-assisted temporary password reset is live after Process 18E migration and
controlled smoke.

Operational flow:

- A user who cannot log in contacts an administrator through an approved channel.
- An authorized administrator opens User Management and uses the separate Reset
  Password action, not the normal Edit User form.
- The administrator sets a temporary password and gives it to the user through a
  secure private channel. Do not post, print, screenshot, email broadly, chat in
  public rooms, or store the temporary password in handoff notes.
- The reset marks the user as requiring a password change and revokes existing
  tokens for that user.
- When the user logs in with the temporary password, the API returns
  `must_change_password: true`, normal protected APIs are blocked, and the
  frontend forces the user to `/change-password`.
- The user must enter the current temporary password, choose a different new
  password, and submit the change.
- After the password change succeeds, the user's tokens are revoked, local auth
  is cleared, and the user must log in again with the new password.
- After successful new-password login, `must_change_password` is false and normal
  access resumes according to the user's role and office permissions.

Audit and safety notes:

- Admin reset writes a `password_reset` audit event.
- User password change writes a `password_changed` audit event.
- Audit descriptions must not contain temporary passwords, new passwords,
  password confirmation values, bearer tokens, or password hashes.
- Process 18E verified no users were left with `must_change_password = true`
  after the controlled smoke.

Newest trusted local SQL backup after Process 18E:

```text
storage/dev-db-backups/doctrack_tuao_20260921_184904.sql
```

Backup metadata:

- Size: `92,459` bytes.
- SHA-256:
  `D1E5BFE9ED09365CAC5FB75E1CB4B41A67DE4593DDD85448B040E2C0E12B818F`.
- Header confirms database `doctrack_tuao` through host `127.0.0.1`.
- Completion marker: `Dump completed on 2026-09-21 18:49:05`.
- Git ignored: yes.
- Google Drive upload: required and not yet confirmed for this newer Process 18E
  backup as of Process 18F.

Required handoff action:

- Upload `storage/dev-db-backups/doctrack_tuao_20260921_184904.sql` to Google
  Drive.
- Confirm the uploaded file name, size, SHA-256, and completion marker before
  treating it as the shared v1.1 backup source of truth.

## v1.0 handoff summary

Deployable v1.0 status: ready for controlled station use.

Deployment URL:

```text
http://192.168.100.107/login
```

Devices tested:

- Device 1: Apache station host and local browser smoke passed.
- Device 2: station login/workflow read-only smoke passed.
- Device 3: station login/workflow read-only smoke passed.

Workflows complete for v1.0:

- Login/logout and authenticated API access.
- Role-aware navigation and server-enforced permissions.
- User and master-data administration needed for deployment.
- Document registration, QR linking/resolution, public tracking, forwarding,
  receiving, processing notes/actions, route history, completion, archive, audit
  trail, incoming/outgoing views, attachments, dashboard, and essential reports.
- Apache port 80 station serving path with Laravel `:8000` stopped.

Backup status:

- Local verified SQL backup exists under `storage/dev-db-backups/`.
- Matching Google Drive backup upload was user-confirmed in Process 17C.
- Git source is pushed to `origin/main` through the Process 17B closeout commit.

Do-not-touch warnings:

- Do not click Complete Process, Archive, Forward, Receive, Save Current
  Processing, Edit, or attachment mutation controls during read-only checks.
- Do not change `.env`, Apache/PHP config, firewall, scheduler, services,
  database provider, or database data without an explicit approved task.
- Do not run `php artisan migrate:fresh`, drop the live database, or restore over
  `doctrack_tuao` without the restore script's safety backup and explicit
  confirmation.
- Do not commit `.env`, SQL backups, uploaded files, credentials, tokens, or
  sensitive row contents.
- Keep feature freeze active unless a true v1.0 blocker appears.

Next optional v1.1 items:

- HTTPS/domain/certificate decision and HSTS only after stable host/domain.
- Scheduler service account or Windows service-wrapper implementation.
- Full forgot-password email workflow. Admin-assisted temporary password reset is
  already live as the v1.1 manual recovery flow.
- App icon, favicon, and optional PWA polish.
- Advanced reports/export polish.
- Final UI polish.
- Database service consolidation or production topology cleanup.

## Process 22E closeout: QR request workflow

QR request and approval is live after Process 22D migration and controlled
smoke.

Operational flow:

- Records Officer/requestor users submit QR requests from the QR Codes page.
- Administrators approve or reject pending requests.
- Approved requests generate QR rows assigned to the requestor's office.
- Requestors see their own office's approved/requested QR rows.
- Other offices cannot see another office's request or use another office's
  assigned QR for registration.
- Direct QR issuance is Admin-only.
- QR void is Admin-only.
- Do not delete unused QR rows directly. Retire QR only through Admin void after
  a verified backup and approval.

Process 22D smoke evidence:

- Request `1` was submitted by `recordsofficer@test.com` and approved by
  `admin@test.com`.
- QR `225` was created as `unused`, assigned to office `2`, and linked to
  request `1`.
- Other-office visibility/use checks were blocked.
- Records Officer direct issue and void checks returned `403`.

Newest trusted local SQL backup after Process 22D:

```text
storage/dev-db-backups/doctrack_tuao_20260923_060523.sql
```

Backup metadata:

- Size: `111,718` bytes.
- SHA-256:
  `B4A9174D98AAE13DAEFF46A6B268A59746E59006F256893D5159782594208EEC`.
- Header confirms database `doctrack_tuao` through host `127.0.0.1`.
- Completion marker: `Dump completed on 2026-09-23 06:05:24`.
- Git ignored: yes.
- Google Drive upload: required and not yet confirmed as of Process 22E.

Required handoff action:

- Upload `storage/dev-db-backups/doctrack_tuao_20260923_060523.sql` to Google
  Drive.
- Confirm the uploaded file name, size, SHA-256, and completion marker before
  treating it as the shared backup source of truth.

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

1. Preserve the Google Drive backup as the v1.0 shared source of truth.
2. Reconfirm Device 1, Device 2, and Device 3 can open
   `http://192.168.100.107/login`.
3. If a final handoff rehearsal is requested, keep it read-only: login,
   dashboard load, document list load, public tracking for
   `DOC-20260823024024684`, and logout.
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

Remaining risk is mostly operational, not feature completeness: the newer
Process 18E SQL backup must still be uploaded to Google Drive and the final
station/device smoke should be repeated immediately before handoff.
