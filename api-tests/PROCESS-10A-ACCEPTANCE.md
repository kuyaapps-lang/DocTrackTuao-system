# Process 10A: isolated sequential hard-copy acceptance

Personal, 2026-09-09. Base: `main` at `279828d`, matching `origin/main`,
ahead/behind 0/0, with the explicitly authorized untracked acceptance draft.
Scope is one backend acceptance test and this record; no production files changed.

`tests/Feature/Process10ASequentialAcceptanceTest.php` preserves and extends the
draft's schema/lookups adapted from `DocumentWorkflowAuditTest`. It creates four
synthetic offices and seven users: Administrator/A, Records Officer/A, Office
User/B, Office User/C, Office User/D, Viewer/B, and Office User without an office.
Fixture creation precedes seven real HTTP logins. Authentication adds seven
tokens and seven login audits, preserving the other fixture rows. Protected
requests prime token usage before the separate workflow baseline.

The test uses actual Laravel HTTP routes, Sanctum bearer authentication, gates,
validation, controllers and audit logging. It issues and resolves one QR,
registers one document, forwards A to B, receives at B, records UNDER_REVIEW with
a synthetic note, forwards B to C, and receives at C.

| Addition after authentication baseline | Exact result |
| --- | ---: |
| Documents | 1 |
| QR records | 1 |
| Received routes | 2 |
| Processing-history rows | 6 |
| Workflow/QR audits | 8 |
| Attachments | 0 |

Processing events are `registered`, `forwarded`, `received`, `action_updated`,
`forwarded`, `received`. Audit events are QR `generated`, QR `registered`, document
`created`, routing `forwarded`, routing `received`, processing
`processing_updated`, routing `forwarded`, routing `received`. These match the
established event contract; registration with an issued QR produces two audits.
The final audit count including authentication is 15.

Forwarding sets the destination as `current_office_id` and assigns its
AWAITING_RECEIPT history row to that destination, attributed to the sender.
The pending route blocks processing until receipt. Final custody is C with
FOR_ACTION, no pending route, and no current note; B's note remains in history.
Assertions cover route/document/QR links, actors, history actions and offices,
audit modules/record IDs/IP/user-agent, baseline preservation, and HTTP history.

Each rejected request has immediately surrounding normalized snapshots of all
17 explicit fixture/business/auth tables, ordered by ID with sorted columns,
normalized abilities and hashed secret columns. Status failures omit response
bodies and token-bearing URLs. No credentials or tokens are written to this record.

| Rejection | Existing status |
| --- | --- |
| Unauthenticated protected requests | 401 |
| Wrong role, wrong office, missing office, Viewer mutations | 403 |
| Invalid/same-office destination; invalid, inactive, malformed or system-controlled processing action | 422 |
| Invalid registration QR or reuse of registered QR | 422 |
| Invalid public QR resolution | 404 |
| Forward or process at destination while receipt is pending | 409 |
| Repeated forward by the former holder | 403 |
| Duplicate receipt | 409 |

Viewer attempts include registration, QR issuance/voiding, forwarding, receipt,
processing, document edit/delete and attachment upload; holding-office checks
also exercise Viewer denial independently of office mismatch. Administrator and
Records Officer cannot forward/process from the wrong office. Reusing a QR is
rejected; ordinary registration is not asserted to be idempotent.

Administrator and Records Officer can read detail/history and audits system-wide.
B, C and Viewer/B retain historical document read access after final receipt;
unrelated D and the user without an office cannot read detail/history. Office
users and Viewer cannot read audits. All Documents remains the existing global
metadata list; Incoming/Outgoing remain office-scoped even for administrators.
Dashboard totals are system-wide for Administrator/Records Officer: one document,
two incoming and two outgoing movements, zero in transit, one received document.
B and Viewer/B see one incoming/one outgoing; C sees one incoming/zero outgoing;
D sees zeroes; missing office receives 403.

Verification commands (PHP 8.2.12, PHPUnit 11.5.55):

```powershell
php vendor/bin/phpunit --filter Process10ASequentialAcceptanceTest --do-not-cache-result
php vendor/bin/phpunit --do-not-cache-result
php -l tests/Feature/Process10ASequentialAcceptanceTest.php
git diff --check
git diff --no-index --check -- NUL tests/Feature/Process10ASequentialAcceptanceTest.php
git diff --no-index --check -- NUL api-tests/PROCESS-10A-ACCEPTANCE.md
```

Focused result: 1 test, 495 assertions, passing. Full PHP suite run once:
416 tests, 14,343 assertions, passing. PHP syntax and whitespace checks pass,
including both untracked files. The complete additions were reviewed.

Isolation is SQLite `:memory:` with hand-built disposable schema, no migrations.
The frozen clock is restored in teardown; Laravel disposes the application,
configuration, guards, session/cache, listeners and connection. No custom
listeners are installed. The draft's shared-request guard carryover was corrected
in its HTTP helper; no production authentication change was needed.

SQLite establishes sequential behavior only, not simultaneous MySQL lock
correctness or production schema parity. Physical scanner, browser printing and
LAN acceptance remain outstanding. No live MySQL, services, browser activity,
physical printing, dependencies, frontend/build runs or backups were used.
Stop before 10B, ready for final read-only review. No staging, commit or push;
Git preservation awaits the user's workflow, and no MySQL backup is needed.
