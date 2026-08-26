# DocTrackTuao System — Codex Working Guide

## Purpose and source of truth

DocTrackTuao is a document tracking system for the Municipality of Tuao. It registers documents, generates and uses QR records, routes documents between offices, records receipt and processing, stores attachments, and exposes history for accountability and reporting.

This file summarizes the verified project history. The repository's current code, migrations, seeders, routes, tests, and database schema remain the implementation source of truth. Before changing behavior, inspect them. If this guide and the code differ, stop and report the difference; do not silently rewrite working behavior or invent a replacement.

## Stack and architecture

- Backend: Laravel/PHP, Eloquent models, migrations/seeders, controllers, validation, and authenticated API routes.
- Authentication: Laravel token authentication (Bearer tokens; the existing project has used Sanctum-style API tokens).
- Frontend: Vue with Vite, consuming the Laravel API.
- Database: MySQL under XAMPP; the development database is `doctrack_tuao`.
- Local development: Windows/PowerShell, normally from `C:\xampp\htdocs\DocTrackTuao-system`.
- Testing/reference: browser-based role testing plus API testing; `api-tests/API-ENDPOINTS.md` is a tracked reference when present.
- Operational scripts already established in history: `scripts/dev-start.ps1`, `scripts/backup-dev-db.ps1`, and `scripts/restore-dev-db.ps1`.

Keep the existing separation: Vue handles presentation and usability; Laravel routes/controllers/policies or equivalent server-side checks enforce authorization, office scope, validation, and state transitions. Hiding a button is never sufficient authorization.

## Critical workflow

The main workflow to preserve is:

```text
Register document
  -> generate/use QR
  -> forward to another office
  -> document is in transit (pending route)
  -> destination office receives it
  -> destination office records Current Action / Processing Note
  -> optionally attach supporting files
  -> forward again as needed
  -> view routing and processing history
  -> audit trail
  -> dashboard/reports
```

Do not weaken the state rules to make the UI easier. In particular, a processing update requires the document to be held by the user's office and not have a pending route.

## Current completion status

Treat these as historical planning estimates, not computed metrics:

- Project foundation and login: complete.
- Master data: approximately 95%.
- Document registration: complete.
- QR generation/tracking workflow: complete.
- Routing/receive/forward: approximately 95%.
- Current Action, Processing Note, automated processing history: complete.
- Attachments: complete.
- RBAC and office restrictions (Process 4): complete and tested, including a server-side wrong-office `403`.
- User management: approximately 90%.
- Incoming/outgoing UI: approximately 70%.
- Audit/activity logging (Process 5): current; the `audit_logs` table exists, but history showed an empty `AuditLog` model shell and no audit route/application wiring.
- Dashboard/reports, hardening, full-system testing, deployment preparation, and final polish remain.

The prior estimate was about 86% for the core workflow and 72–75% for a fully tested, deployment-ready system. Do not update those percentages without re-auditing the repository.

## Roles, permissions, and office scope

The established roles are:

1. **Administrator** (`role_id` observed as `1`): system-wide administration, including user management and editable master data. Preserve the current code's exact authorization checks for document operations.
2. **Records Officer** (`role_id` observed as `2`): may access QR functions, register documents, and view master data without Add/Edit/Delete controls; must not access User Management. May perform document workflow actions only when the document is currently held by the officer's assigned office and no conflicting in-transit rule applies.
3. **Office User** (`role_id` observed as `3`): office-scoped operational user. Preserve the existing permission checks; do not infer Records Officer or Administrator privileges merely from office membership.
4. **Viewer** (`role_id` observed as `4`): read-only. Do not expose or permit create, edit, delete, receive, forward, processing, attachment mutation, QR mutation, user-management, or master-data mutation unless current code explicitly proves a narrower exception.

Exact rule: **role answers “what may this user do?”; office assignment answers “on which currently held documents may this user do it?” Both must pass.** A user with an allowed role cannot mutate a document belonging to another office. A user without an `office_id` cannot perform office-scoped actions. The backend must return `403` for forbidden mutations even if a client manually calls the API.

Verified processing rule:

- `user.office_id` must equal `document.current_office_id`.
- The document must not have a pending route/in-transit state.
- The selected processing action must exist and be active.
- Processing notes are optional strings with the existing maximum (historically 2,000 characters).

Do not broaden the matrix based only on this summary. Before adding a menu item, endpoint, or action, inspect the existing route middleware, controller checks, frontend route guards, and role/permission helpers. Preserve stricter behavior when found.

## Database and migration quirks

- Git synchronizes code, not MySQL records. Users, documents, routes, processing logs, QR data, and test data move through SQL backup/restore.
- One machine was found with migrated structure but missing users and master data. Do not interpret a successful migration as proof that seed/reference data exists.
- Do not use `php artisan migrate:fresh`, drop the development database manually, or recreate users/master data as a shortcut. These actions can destroy the working cross-machine state.
- Inspect `php artisan migrate:status`, the live schema, and existing data before creating or modifying a migration. Prefer additive, reversible migrations that tolerate the project's existing state.
- Do not duplicate tables/columns merely because a model/controller is incomplete. Confirm the live schema first. In particular, `audit_logs` already exists with: `id`, nullable `user_id`, `module`, `action`, nullable `record_id`, nullable `description`, nullable `ip_address`, nullable `user_agent`, and timestamps.
- Preserve current foreign keys, nullable fields, lookup IDs, and seed ordering. Do not hard-code observed development IDs as universal production facts.
- A restore replaces the target database. The restore helper must create a safety backup first and require explicit `RESTORE` confirmation.
- SQL backups belong in `storage/dev-db-backups/` and must remain ignored by Git; they may contain hashes and sensitive document/history data.

## QR, processing, routing, and attachments

- QR records are part of registration/tracking and must remain associated with their document. Preserve existing generation, lookup/scanning, and any existing voiding behavior; do not introduce a new QR lifecycle without code evidence and approval.
- A forwarded document creates/preserves routing history and becomes pending/in transit. It must not be processed as though still available at the sender or destination before receipt.
- Receipt transfers current custody to the destination office according to the existing route behavior. Forward/receive must not erase earlier routes.
- Current Action and Processing Note updates create/preserve processing history, including who changed the action and when. Never replace the history with only the current value.
- Office scope applies to receive, forward, and processing mutations. Re-test both the correct-office path and a wrong-office API call.
- Attachments are part of the document record. Preserve existing upload/download/delete behavior, ownership/authorization, storage paths, metadata, and history. Validate file type, size, name/path, and access server-side; never commit uploaded files or expose arbitrary filesystem paths.
- Audit work should record attachment and QR actions, but audit failure handling must not corrupt the primary document transaction.

## Coding conventions

- Follow the repository's existing Laravel and Vue patterns before introducing abstractions.
- Keep controllers/routes/components focused; reuse existing document list/detail components for Incoming/Outgoing views.
- Validate every API mutation server-side and return consistent HTTP statuses (`403` for authorization/office-scope denial, validation responses for invalid input).
- Use Eloquent relationships and existing naming conventions. Avoid raw SQL unless the repository already requires it for a specific reason.
- Use database transactions for related custody/routing/history mutations when consistent with existing code.
- Keep API payloads backward compatible unless a deliberate migration is requested.
- Do not hard-code localhost URLs, credentials, tokens, role IDs, office IDs, or secrets in application code.
- Never place passwords or active bearer tokens in tracked API documentation.
- Keep comments useful and concise; explain business constraints, not obvious syntax.
- Do not perform unrelated formatting, dependency upgrades, architectural rewrites, or UI redesigns while closing a process substep.

## Security is cross-cutting

Security is required in every process, not postponed entirely to Process 9. Every change must preserve authentication, role permission, office scope, validation, safe error handling, and auditability. UI restrictions must be backed by API restrictions. Treat uploaded files, QR input, route IDs, document IDs, filters, and report parameters as untrusted.

Never weaken a guard to make a test pass. Never log secrets, passwords, full bearer tokens, or unnecessarily sensitive attachment contents. Use environment configuration for secrets. Process 9 is the focused security review, but security regressions block completion of Processes 5–8 as well.

## Personal / Office synchronization workflow

At the beginning of every session, ask or establish the active machine:

- `Personal` = laptop.
- `Office` = office PC.

Then:

1. Check `git status`, current branch, and latest commit before edits.
2. On the same machine as the last meaningful work, normally run the existing startup/environment checks; do not restore needlessly.
3. When switching machines, sync code first using the established Git workflow, then identify the newest trusted SQL backup.
4. Run `scripts/dev-start.ps1` as established by the project.
5. Restore only when the other machine has newer required database/test state. Use `scripts/restore-dev-db.ps1 <backup-file>` and verify counts/key records afterward.
6. Never decide “newest” from filename alone when there is doubt; compare timestamps and the expected users/documents/routes/processing logs.

Git being clean and at the same commit proves code synchronization, not database synchronization. Raw working-copy hashes may differ because of CRLF/LF; use Git status, commit SHA, and Git diff as the code authority.

## Backup and restore routine

At the end of every coding/testing session, state which actions are required:

- Code changed: inspect the diff, run relevant tests, then commit and push only with the user's intended Git workflow.
- Database or meaningful test data changed: run `scripts/backup-dev-db.ps1`, verify the SQL file exists and is non-empty, and upload the newest trusted backup to Google Drive.
- Both changed: do both Git and MySQL backup routines.
- Read-only testing with no code or database change: no duplicate commit or SQL backup is needed; confirm that explicitly.

Before restoring, protect the target database with the restore helper's safety backup. After restoring, clear Laravel caches as the script does and verify at least users, documents, document routes, processing logs, and QR records as applicable. Never add SQL backups to Git.

## Optimized remaining plan (Processes 5–12)

Work sequentially in small closure targets. Complete and test each substep before starting the next.

### Process 5 — Audit / Activity Logging (current)

- **5A Foundation:** configure the existing `AuditLog` model and a reusable logging service/helper around the existing table; define stable module/action names.
- **5B Document workflow:** log create/update/delete where deletion exists, forward, receive, Current Action/Processing Note changes.
- **5C Auth/admin:** log login/logout and user-management changes without recording credentials/tokens.
- **5D Files/QR:** log attachment upload/delete and existing QR actions.
- **5E Read API/UI:** add a basic paginated audit endpoint and page for authorized roles. History suggested Administrator and Records Officer access; confirm that against current authorization code before implementing.
- **5F Verification:** test critical events, actor/IP/user-agent fields, forbidden access, and confirm logging does not alter the primary workflow. Defer advanced filters/export.

### Process 6 — Sidebar / Main Navigation

- Add one role-aware navigation structure for existing and planned modules.
- Hide unauthorized modules, but retain server-side enforcement.
- Include Documents, QR, Users, Master Data, Audit, Incoming/Outgoing, Reports/Dashboard as each route exists.
- Add active-page state and basic responsive/collapsible behavior.
- Keep this functional; defer dark mode and visual perfection to Process 12.

### Process 7 — Incoming / Outgoing Document UI

- Reuse existing document endpoints and list/detail components.
- Add dedicated Incoming and Outgoing lists/views.
- Add essential filters/status display and links to document details.
- Verify role and office scope, empty/loading/error states, and correct custody/routing interpretation.

### Process 8 — Dashboard and Essential Reports

- Total, incoming, outgoing, pending/in-transit, and received counts.
- Office-based counts, date/month filter, and basic routing/activity summary.
- Confirm every query respects the intended role/office scope.
- Defer advanced PDF/Excel/export unless explicitly required for the thesis/demo.

### Process 9 — Focused Security Hardening

- Review authentication/token/session behavior and logout/revocation.
- Audit route permissions and office checks for every mutation and sensitive read.
- Review validation, upload restrictions, rate limiting, CORS/CSRF as applicable, headers, secrets/environment handling, error leakage, and dependencies.
- Validate backup/recovery and document deployment/server-hardening decisions.
- Make targeted fixes; do not rewrite the architecture without evidence.

### Process 10 — Full End-to-End Testing

- Test the complete register -> QR -> forward -> receive -> process -> forward -> receive -> history -> audit -> reports flow.
- Repeat by relevant role and office.
- Negative tests: wrong role, wrong office, missing office, invalid QR, unauthenticated/unauthorized request, pending-route processing, invalid/inactive action, and attachment restrictions.
- Record reproducible failures and fix them before deployment work.

### Process 11 — Deployment Preparation

- Prepare production environment values without committing secrets.
- Build the Vue frontend and verify Laravel production caches/config.
- Define safe migrate/seed strategy; never assume development data is production seed data.
- Verify LAN/server startup and client access.
- Perform and document backup/restore rehearsal and recovery checks.
- Produce a final deployment checklist.

### Process 12 — Final UI Polish

- Only after workflow stability: dark mode if still required, spacing, colors, icons, responsive cleanup, dashboard cosmetics, sidebar visual refinement, and minor consistency fixes.
- Re-run role/navigation and critical end-to-end smoke tests after polish.

## Test-before-next-step rule

Never declare a substep or process complete from code inspection alone. For every change:

1. Run the smallest relevant automated checks available.
2. Exercise the positive path.
3. Exercise at least one relevant forbidden/invalid path, especially wrong-role and wrong-office cases.
4. Confirm history/audit/database side effects and that unrelated workflow still works.
5. Inspect `git diff` and `git status` for unintended changes.
6. Report evidence, remaining risks, and whether code backup, database backup, both, or neither are needed.

Do not begin the next numbered process while a blocking test in the current one fails. Fix or clearly document and obtain a decision to defer it.

## Git workflow

- Begin with `git status`, branch, and latest commit; preserve user changes already present.
- Pull/synchronize before editing when switching Personal/Office, but do not overwrite a dirty worktree.
- Make focused commits for completed, tested closure targets (for example, Process 5A rather than all of Process 5 at once).
- Review diffs before committing. Do not commit generated builds, `.env`, secrets, tokens, SQL backups, runtime uploads, `vendor`, or `node_modules`.
- Do not use `git reset --hard`, destructive checkout, force-push, or history rewriting unless the user explicitly requests it and exact consequences are understood.
- Do not commit or push automatically unless the user's current request authorizes it. When authorized, use a clear message describing the completed substep.
- A clean working tree and matching commit SHA on both machines are the synchronization authority.

## What Codex should change

- The smallest set of files needed for the requested current substep.
- Existing implementation defects that block that substep, after explaining material scope changes.
- Tests and concise documentation needed to prove or operate the change.
- Security checks directly associated with every touched endpoint/UI action.

## What Codex should not change

- Established role or office-scope behavior without explicit approval.
- Routing, custody, receipt, processing-history, QR, or attachment semantics merely to simplify implementation.
- Database contents, seed identities, credentials, or test users unless the task requires it and a backup/safe plan exists.
- Unrelated code, formatting, dependencies, framework versions, environment files, or deployment architecture.
- Personal/Office SQL state without identifying the source of truth and taking a safety backup.
- Existing user work in a dirty tree.
- Anything based only on an assumption when repository inspection can answer it.

## How to work with Codex using this file

Open the DocTrackTuao repository as the Codex project so this root `AGENTS.md` is loaded automatically. Start each session with `Personal` or `Office`, then give one closure target, for example:

```text
Personal. Continue Process 5A. Inspect the existing audit table/model first,
implement only the reusable audit foundation, run relevant tests, and stop
before 5B. Tell me whether Git and/or MySQL backup is needed.
```

Useful follow-ups:

- `Show me the evidence that 5A passes before continuing.`
- `Continue with 5B only; preserve RBAC and office scope.`
- `Review this change without editing anything.`
- `We are stopping. Check whether code backup, database backup, both, or neither are required.`

Codex should read this guide and the actual repository, state the active process/substep, inspect before editing, implement narrowly, test before advancing, and end with the correct Personal/Office backup reminder.
