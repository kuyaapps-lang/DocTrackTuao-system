# Process 10B concurrency acceptance draft

## Current acceptance and final review (Personal, 2026-09-13)

**All eight scoped MariaDB concurrency scenarios passed across two separate
authorized attempts: 8 tests / 1,310 assertions in aggregate.** This is not a
claim of one full-suite execution. This section supersedes historical pending,
failed, provisioning-next and forward-only status statements below; those
entries remain intact as the record of earlier attempts, not current directions.

Reviewed at `main` commit `8d622e2`, HEAD equal to `origin/main`, 0 ahead / 0
behind. The five harness drafts match the hashes saved with the successful
attempts. This final review changes only this sixth file, the acceptance record.

| Saved attempt | Cases | Result |
| --- | --- | --- |
| `doctrack-10b-bound-single-34a59910d03a4743829ddc1e8cdd9aeb` | #0 forward/forward | 1 test / 171 assertions; exit 0 |
| `doctrack-10b-remaining-seven-bd08dea0f63647c1a31bc233e66bc09f` | #1-7 below | 7 tests / 1,139 assertions; exit 0 |

Both directories remain under `%TEMP%`, retaining `sanitized-output.txt`,
`result.json`, `launcher-result.json`, preflight/postflight JSON and draft hashes.
The second runner completed before the usage-limit interruption. Its TestDox
output identifies all seven data sets; it did not stop partway through them.
No retry or acceptance rerun was performed during resumption or final review.

| Data set | Controlled order | Result | Asserted HTTP outcomes |
| --- | --- | --- | --- |
| #0 | Forward / forward | PASS | 201 / 403 |
| #1 | Receive / receive | PASS | 200 / 409 |
| #2 | Identical processing / processing | PASS | 200 / 200; second request no-op |
| #3 | Void / void | PASS | 200 / 409 |
| #4 | Edit / forward | PASS | 200 / 201 |
| #5 | Forward / edit | PASS | 201 / 403 |
| #6 | Login / password update | PASS | 200 / 200; issued token subsequently rejected with 401 |
| #7 | Password update / login | PASS | 200 / 401; no token issued |

Environment: MariaDB 10.4.32, REPEATABLE-READ, PHP 8.2.12, PHPUnit 11.5.55.
All eight passed same-row driver-1205 calibration, actual bound-target proof,
distinct worker/observer connections, transaction level 1, contention before
A release, completed overlap ordering, response/state/history/audit checks and
cleanup. Placeholder acceptance requires both actual query-hook proofs and the
exact server-visible Execute template; lexical shape hints alone cannot pass.
Barrier absence is sampled, not continuous or atomic observation. No claim
depends on the short observation duration alone.

Both saved preflights and postflights verified the exact
`doctrack10b_runner@127.0.0.1` account and `doctrack_10b_disposable` database,
DML-only grants, all 19 tables empty, no other runner sessions and an unowned
harness advisory lock. Child exits and private worker-directory removal passed;
post-process evidence recorded zero remaining harness processes. Resumption
also confirmed no remaining harness process. Disposable schema/account and
prior attempt evidence were retained. Cleanup is evidence from those completed
attempts, not a fresh database-state inspection during this offline review.

The successful attempts used the reviewed temporary SecureString launchers and
explicit disposable preflight/postflight wrappers. The seven-case launcher
filtered #1-7 and used `--stop-on-defect`. The repository's general Run entry
point is not that filtered wrapper and does not itself supply its external
session/process preflight or stop-on-defect option. Future runs still require
the same explicit disposable preflight and scoped launch authorization; do not
interpret this record as authorization to invoke Run, Provision or Cleanup.

Final review covered isolation before bootstrap/PDO, exact identity/grants,
schema provenance, private credential transport and sanitized failure output,
owned-worker termination, deadlines, placeholder target/connection proof,
independent effect assertions, snapshot normalization and guarded DML cleanup.
Ordinary discovery lists 416 tests with no Concurrency/Process10B cases.
Three PHP syntax checks, PowerShell syntax and XML parsing passed. All six
files passed whitespace and strict UTF-8/no-BOM checks; complete-scope review
found no embedded credentials or unintended production changes. The source
backup hash and all 19 CREATE TABLE definitions were rechecked offline without
printing or executing backup rows. Unchanged full-suite evidence remains
Process 10A's 416 tests / 14,343 assertions; it was not rerun.

Limits: these are eight deterministic two-worker orders over small synthetic
fixtures using the Laravel HTTP kernel, not browser/network, load/stress,
exhaustive interleaving, deadlock-recovery or all-role/office coverage. Identical
processing tests duplicate/no-op behavior, not competing different actions.
QR coverage here is voiding an unused, unlinked QR. Schema acceptance uses the
verified embedded snapshot and does not prove clean migration replay. The
existing migration duplication, live demonstration-session reconciliation and
backup/restore rehearsal remain deferred as described below; this acceptance
does not establish deployment readiness or complete all of Process 10.

Ready for the user's focused six-file commit workflow. Nothing was staged,
committed or pushed. No database connection, resource removal or next process
was started during final review. Personal code/documentation preservation is
pending; no new MySQL backup is needed for this offline review or the already
cleaned disposable fixtures.

## 2026-09-12 offline failure-reporting update

The latest authorized full run exited 1: eight tests, 841 assertions, eight
test-phase assertion failures, eight calibration markers and no completed-overlap
markers. No setup/teardown failure or handler warning was reported. Its saved
evidence is preserved separately in the temporary acceptance attempt directory.
The prior successful connection and actual-PHPUnit setup-only evidence remains
valid for those attempts; neither establishes concurrency acceptance.

Shared unchecked candidates after calibration include child cleanup, oracle
reseeding/snapshots, sequential requests and response assertions, state/audit
effects, and overlap coordination/observation. Generic failure messages do not
identify the failing assertion or prove an application defect.

The harness now retains a fixed scope, allowlisted source identifier and
assertion/exception category before sanitization. Numbered identifiers map to
the immediately following source operation; they must not be renumbered.
The first failure identifier survives later teardown failure. Original exception
messages, comparison data and chained exceptions are still discarded.

Focused offline verification passed six installed-PHPUnit synthetic reporting
probes: assertion, exception, contention, audit effects, unknown identifier and
combined test/teardown failure. All retained nonzero status and cleanup evidence,
without sentinel leakage or handler warnings. Affected PHP syntax and whitespace
checks passed. Existing seven handler-cleanup probes and other unchanged checks
were reused, not rerun. No database connection or acceptance rerun occurred.

MariaDB acceptance remains unpassed. Final database emptiness and advisory-lock
cleanup remain explicitly unverified. Before another separately authorized
database run, verify disposable cleanup; then one bounded failing case with these
identifiers is sufficient to locate the next failure. No production behavior,
guards, assertion expectations or fixture semantics changed. No Git writes or
database backup occurred; preserving these draft edits through Git remains pending.

## 2026-09-11 diagnostic update (supersedes historical readiness below)

Personal. The saved runner-password reset verification passed: exact loopback
account, fresh disposable-database connection and unchanged four DML grants.
The subsequent single acceptance attempt exited 1: eight setup errors, zero
assertions, eight handler-related risky tests, no calibration or overlap markers.
The original sanitized result and empty retained barrier directory are preserved.
Read-only process inspection after that attempt found no running PHP processes;
this did not establish database cleanup.

The scoped harness correction now snapshots pre-bootstrap error/exception
handlers and error-reporting level. Bootstrap failure and final test teardown
restore that state by removing only handlers above the saved handlers. Worker
normal cleanup also restores its saved state. PHPUnit's pre-existing handlers
are retained. Fixed setup-stage labels and an allowlisted failure category avoid
printing exception messages, traces, SQL, bindings or secret values.

Offline verification passed: affected PHP syntax, 15 existing guard/grant checks,
independent-child barrier/deadline checks, and seven installed-PHPUnit exception
regressions. The two added probes cover a failure after handler installation,
before providers/PDO, and restoration through the real final teardown. All seven
preserved the expected nonzero outcomes, emitted cleanup evidence, kept synthetic
secrets out of stdout/stderr, and produced no handler-restoration risky warnings.
Unchanged ordinary-suite, schema and discovery evidence is reused.

One authorized masked-password diagnostic was then attempted from a separate
private temporary directory accessible to both the task identity and BongXD.
It exited 1 at `read-only-connect`, before a PDO handle was returned and before
identity/grant checks or Laravel setup. Its fixed output cannot distinguish
authentication/connection failure from read-only connection initialization
failure. No retry was performed. It reports no seeding, race workers or harness
lock acquisition, and unchanged pre-bootstrap handlers. This is NOT evidence that
the disposable tables are empty or that no other database sessions remain: table
counts, same-account sessions and advisory-lock ownership remain unverified.
The original acceptance setup failure likewise remains unresolved.

Next scope requires approval for another diagnostic attempt: split connection
and read-only initialization stages and report only allowlisted driver failure
categories, then perform the already scoped state inspection and setup-only
probe if connection succeeds. Do not reset credentials, alter grants, seed, run
acceptance, or remove evidence as a recovery shortcut. No production code,
schema, grants or live database were changed. No staging, commit or push occurred.
Code preservation through the user's Git workflow remains pending; no application
SQL backup is needed for these offline/read-only attempts.

Personal throughout. Reviewed offline on 2026-09-10 against `main` at `8d622e2`,
HEAD equal to `origin/main`, 0 ahead / 0 behind. The five existing untracked
harness files were resumed and preserved. This record is the sixth draft file.
**Ready for harness review and provisioning approval; MariaDB concurrency
acceptance has NOT run or passed.** No production source or ordinary bootstrap
was changed. Processes 11 and 12 have not started.

Files:

- `phpunit.concurrency.xml`: separate suite, vendor autoload only.
- `scripts/process-10b.ps1`: explicit Offline / Provision / Run / Cleanup modes.
- `tests/Concurrency/Process10BConcurrencyTest.php`: eight controlled race orders.
- `tests/Concurrency/Support/FixtureSchema.php`: verified schema, synthetic seed,
  complete snapshots and DML cleanup.
- `tests/Concurrency/Support/Worker.php`: private stdin, isolated bootstrap,
  HTTP kernel requests, coordination, provisioning and offline checks.
- `api-tests/PROCESS-10B-ACCEPTANCE.md`: this review and approval boundary.

## Isolation and schema authority

Only TCP `127.0.0.1:3306`, database `doctrack_10b_disposable`, and account
`doctrack10b_runner`@`127.0.0.1` are accepted for test connections. The account
must have exactly SELECT, INSERT, UPDATE and DELETE on the escaped literal
database name; global USAGE is allowed, other grants and GRANT OPTION fail.
No PROCESS privilege, root fallback, URL, socket, or read/write connection
fallback is permitted. Guards run before application bootstrap or PDO creation.
The live `.env` is not loaded; cached config/routes and inherited connection URL
or bootstrap-cache overrides are rejected. Configuration replaces all database
connections before providers boot. Identity and grants are checked again after
connection. Configuration and ordinary `phpunit.xml` remain unchanged.

Schema source is exclusively CREATE TABLE sections from
`storage/dev-db-backups/doctrack_tuao_20260908_174402.sql`.
Verified SHA-256:
`800B8AD2BEA7D9B18EDEADC0378E39EFBBEA9D389DE4985446E457FEA4E6C7A0`.
All 19 embedded definitions were compared offline and match after whitespace,
terminal semicolon and historical AUTO_INCREMENT counter normalization. No dump
rows were printed, restored or executed. The harness does not replay migrations.
Every run requires the exact table/definition inventory and initially empty
tables before it may seed or delete anything. Fixtures are synthetic only.

The runner receives credentials through masked prompts and private stdin pipes,
never arguments or credential files. The administrator identity is also masked.
Windows temp barriers have an owner-only ACL. They contain phase metadata,
synthetic IDs, connection IDs and status codes, never passwords, bearer tokens,
QR values, raw SQL, bindings or HTTP bodies. Logging uses a null handler and
unexpected exceptions are replaced with fixed messages inside class setup,
setup, test and teardown boundaries before PHPUnit can emit them. The former
`onNotSuccessfulTest()` sanitizer was too late and has been removed. Assertions
also receive fixed phase messages without comparison objects or original
exception chaining. Snapshot comparisons
retain every column; secret fields are hashed in memory and comparisons use
boolean assertions so failures cannot dump those rows.

## Required outcomes (expected, not observed on MariaDB)

All requests traverse existing Laravel HTTP-kernel routes, middleware and
controllers. No mocked mutation or production coordination hook is installed.

| Controlled order | Shared locked row | Expected HTTP outcomes | Required effects |
| --- | --- | --- | --- |
| Forward / forward | documents 1 | 201 / 403 | One route, one forwarded history and audit; loser unchanged |
| Receive / receive | documents 1 | 200 / 409 | One receipt, one received history and audit; loser unchanged |
| Identical processing / processing | documents 1 | 200 / 200 | Second response explicitly no-op; one processing history and audit |
| Void / void | document_qr_codes 1 | 200 / 409 | One void audit; QR remains unlinked; loser unchanged |
| Edit / forward | documents 1 | 200 / 201 | Revised title retained through transfer; both audits |
| Forward / edit | documents 1 | 201 / 403 | Stale editor denied after custody changes; original title retained |
| Login / password update | users 4 | 200 / 200 | Issued login token revoked; HTTP token probe returns 401 |
| Password update / login | users 4 | 200 / 401 | Login passes initial old-password check before waiting, then post-lock recheck rejects it; no issued token or login audit |

The last two mixed pairs run in both orders, not a random scheduler order.
In password-first, reaching AuthController's SELECT FOR UPDATE proves that
Auth::attempt already accepted the old password while the updater still holds
the original row. In forward-first, the editor likewise reaches its locked
reload after the initial office check. Neither can pass through an early
sequential rejection in place of the stale-read race.

Each case first calibrates the same requests against the same row: A holds its
transaction open after the locking SELECT returns; B must report driver 1205
and HTTP 500 with a one-second InnoDB timeout, without acquiring the lock.
These deliberately failed requests are isolated from the acceptance fixture.
Next, a sequential oracle records the first commit and final state. Finally,
fresh independent PHP workers run overlapping requests. A, B and the observer
must have three distinct connection IDs. Test-local before/after query hooks
prove the identical table and primary-key target and transaction level 1.

Before A is released, the restricted observer must see B executing the exact
same-row SELECT FOR UPDATE in SHOW FULL PROCESSLIST, with no B lock-acquired
or response marker. Combined with the successful same-row timeout calibration
and A's held incompatible lock, this is the required contention evidence.
The process list is inspected in memory, never printed. Timing or serial replay
alone cannot pass. If this server hides same-account statements, returns a
different statement shape, or fails calibration, the case fails/blocks; do not
grant PROCESS, weaken observation, or call the result concurrency acceptance.

B pauses immediately after acquiring the released row, allowing the observer
to snapshot A's commit before B mutates. Complete snapshots cover all 19 tables,
including unrelated tables, audit, routes, processing history and tokens. Exact
row deltas, actor/module/record/IP/user-agent, custody/status/action, title and
receipt fields are asserted separately. Rejected/no-op requests must preserve
the entire same-run state. Cross-lifecycle comparisons normalize generated IDs
and route references, salted password hashes and issued token randomness only;
winner-boundary and final password validity are verified separately, and
unrelated user/token fields must remain identical.

## Failure handling and limits

Barrier waits are at most 10 seconds and overlap cases have a 30-second budget.
Connections use a three-second PDO timeout; MariaDB sessions use five-second
InnoDB/metadata lock waits and an eight-second statement limit. Unsupported
MariaDB session settings block the run. Worker failure markers cancel pending
waits promptly. Worker finally blocks roll back, purge connections and restore
the clock. Bootstrap failures purge the isolated connection and sanitize output.

Teardown attempts termination of every owned proc_open handle, allowing one
second for normal exit and two seconds after termination. Closed handles are
removed immediately so repeated cleanup is safe. If any child remains alive,
fixture deletion and barrier removal are withheld. Normal teardown deletes
synthetic rows in reverse dependency order under the run's named advisory lock;
it does not drop tables or reset AUTO_INCREMENT. Cleanup errors are collected
without retaining their exceptions; remaining cleanup stages are still attempted.
The first failing phase is retained if teardown also fails. App, secrets and
clock cleanup is attempted even after an earlier cleanup failure.

The PowerShell parent has a five-minute deadline, polled once per second. An
abnormal parent stop can prevent PHP teardown; only its owned process is killed,
and barriers are retained for review while children have their own bounded
waits. This is a failed run, not guaranteed database cleanup. Before reuse,
verify worker exit and inspect only the disposable resources. Existing rows
cause the next run to refuse execution; do not silently erase them to retry.
Provisioning DDL is nontransactional: partial failure also requires review and
must never be retried with overwrite or IF NOT EXISTS shortcuts.

## Offline evidence and remaining blockers

Prior reported PHP/PowerShell/XML syntax, 15 guard/grant checks and independent
PHP-child barrier/timeout evidence were carried forward. After helper changes,
direct PHP validation again passed all 15 checks and child barriers/deadlines
without PDO. Additional direct checks passed failure-marker cancellation,
termination of two owned PHP children, and safe repeated cleanup. PHP syntax for
all three PHP files and PowerShell/XML parsing pass. Ordinary `--list-tests`
lists 416 cases and excludes Concurrency/Process10B. Complete draft additions
and whitespace were reviewed, including untracked files.

The earlier launcher attempt was blocked by script execution policy. Following
the exception-output correction, the actual launcher passed using the explicitly
authorized process-local bypass (exit 0):

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\DocTrackTuao-system\scripts\process-10b.ps1 -Mode Offline -Php C:\xampp\php\php.exe
```

No persistent execution policy was changed. This exercised the launcher's private
directory/ACL setup, stdin dispatch, child launch and normal temporary cleanup.
All 15 guard/grant checks and independent-child barrier/deadline checks passed.
Offline now additionally runs five synthetic exception probes through the
installed PHPUnit TextUI runner: setup, test body, teardown, combined test plus
teardown, and assertion comparison/message failures. Every probe must exit 1 or 2,
contain its fixed primary phase message and cleanup marker, and omit its random
sentinel from both stdout and stderr. All five passed. Private captured synthetic
output is checked and discarded, never forwarded to the launcher. The temporary
probe subclass exercises the real final boundary methods but replaces the isolated
operations, so no Laravel bootstrap, PDO, fixtures or concurrency tests run.
Affected PHP syntax and whitespace checks pass; the complete correction diff was
reviewed. Unchanged schema/discovery/application-suite evidence is reused.

The user's live Administrator demonstration login has no confirmed logout.
Its live audit/token counts are unknown and must be reconciled later. This task
did not query that session/database or perform live-session cleanup.

The existing Process 10A acceptance record supplies ordinary-suite compatibility
evidence: 416 tests / 14,343 assertions passed. Ordinary discovery/bootstrap and
production code are unchanged, so the full suite was not rerun. No frontend or
build rerun was needed. No services were started, database connections opened,
accounts/schema provisioned, database concurrency tests executed, backups
created, or changes staged/committed/pushed in this review.

## Concrete next approval: provisioning only

1. Review these six draft files, including the exception-output correction and
   passing Offline launcher evidence above. Do not change persistent script policy.
2. After explicit provisioning approval, use
   `scripts/process-10b.ps1 -Mode Provision`. Enter the dedicated runner password
   and administrative credentials only in its masked prompts, then type
   `PROVISION EMPTY PROCESS10B`. Never put credentials into the command line.
3. The helper checks for an existing database and any same-name runner account
   at any host, refusing either. It creates only the named empty database, the
   19 embedded tables and the loopback runner with four DML grants, then checks
   those grants. It neither reads live application data nor runs acceptance.
4. Stop after provisioning to review its result. A later, separate authorization
   may run `scripts/process-10b.ps1 -Mode Run`, supplying only runner credentials.
   Record engine/version/isolation, all eight orders, calibration/overlap markers,
   HTTP outcomes and cleanup outcome. Any failed observation remains blocked.
5. Optional removal is a separately confirmed
   `scripts/process-10b.ps1 -Mode Cleanup`, with `DROP DISPOSABLE PROCESS10B`.
   It verifies runner identity/schema and absence of an active run/other visible
   runner connections before dropping only the disposable database/account.
   It is not a recovery mechanism for partially provisioned resources.

Process 11 must resolve clean migration replay: both
`2026_05_10_081551_create_documents_table.php` and
`2026_08_12_112734_add_tracking_no_to_documents_table.php` add `tracking_no`.
The verified schema bypass here does not fix that clean-deployment defect.
Also carry forward verification of backup/restore scripts and a recovery rehearsal.

The future Administrator Backup/Restore module remains deferred: implement
backup creation/list/download first. Restore requires validated backups,
automatic safety backup, write maintenance, explicit confirmation, restricted
access, and recovery logging outside the restored database. No part of that
module is implemented during 10B.

Personal stopping routine: code/documentation preservation through Git remains
pending the user's authorized workflow. No MySQL backup is needed because no
database or meaningful database test state changed.

## Personal offline observer review (2026-09-13)

The saved single forward case failed with 109 assertions at
`scope=overlap; id=pair.contention-observed; category=assertion`.
Its diagnostic records 129 samples / 2,015 ms as aggregate `ever` and final
`last` flags, not 129 individual process-list records. In both summaries B was
visible in the disposable database with Command Execute and visible Info;
`b_sql_matches` was false. Calibration passed with driver 1205. This remains a
failed concurrency case, despite passing sequential oracle checks and cleanup.

The old acceptance predicate short-circuited SQL matching on Command Query.
However, `Worker::contentionSample()` evaluates the same SQL regex independently
of Command. Therefore the saved SQL mismatch is real classifier evidence, not
an unexecuted comparison caused by Execute. No statement text was retained, so
the mismatching syntax cannot be reconstructed. In particular, neither a
placeholder nor different quoting/spacing is established by this record.

Local framework code confirms native prepared statements: Connector defaults
`PDO::ATTR_EMULATE_PREPARES` to false, and the isolated Worker connection does
not override it. Connection::select prepares, binds and executes the SELECT.
The forward controller locks Document::whereKey inside its transaction. The
worker's beforeExecuting callback publishes B-attempt before execution; its
query listener publishes locked only after the SELECT returns, then waits for
release. The parent waits for A-locked before starting B, verifies the same row
and distinct worker/observer connections, and releases A only after observation.

The seven saved barrier flags were populated by seven actual `is_file()` calls
after the unsuccessful observation loop, before its assertion and teardown:
`a-release`, `a-result`, `a-failure`, `b-locked`, `b-result`, `b-failure`,
`b-sql-error`. False means no regular marker file detected at that individual
check. These are not unobserved default flags, a per-sample series, an atomic
snapshot, or proof of absence after capture. Successful observations previously
did not emit this failure-only barrier diagnostic. Markers publish by renaming
a temporary file to JSON; their contents are not read by this diagnostic.

The narrow correction shares the existing SQL classifier with acceptance and
allows Query or Execute only with matching worker/database/locking SQL, A
visible, B transaction level 1 and no release, completion, acquisition or
failure marker. Marker checks clear the stat cache; a missing case directory
is rejected. Existing calibration, connection/row/order assertions, post-check
barrier assertions, response/state/audit assertions, waits and cleanup remain.
Execute by itself cannot pass, and the saved `b_sql_matches=false` still fails.
The SQL regex and secret-free diagnostic fields are unchanged.

Offline evidence: 50 new observation checks, 56 existing diagnostic checks,
six installed-PHPUnit diagnostic reporting regressions, and both affected PHP
syntax checks passed. Coverage includes Query versus Execute, wrong worker,
database and row, hidden/unrelated/placeholder SQL, missing FOR UPDATE, extra
statements/comments, inactive commands, invalid transaction levels, absent
directories and each of the seven disqualifying markers. No PDO, application
bootstrap, acceptance case or production change was needed.

Next required evidence is a separately authorized disposable diagnostic case
with bounded, secret-free SQL-shape classification to identify which exact
target-statement condition fails (table/key, literal versus placeholder, and
locking-clause/statement shape). Review that diagnostic instrumentation before
running; do not broaden SQL acceptance based on guesses or print raw SQL.
Use fresh preflight and the corrected SecureString launcher for forward only.
No run is authorized or performed by this offline review. Full MariaDB
acceptance remains pending. Three of the existing six drafts changed; Git
preservation awaits the user's workflow. No SQL backup is required.

## SQL-shape diagnostic and one forward case (2026-09-13)

Added diagnostic-only fixed categories/booleans for statement type, expected
table, FOR UPDATE, table-identifier quoting, key placeholder/literal form and
text availability. Truncation is explicitly unknown because the observer has
no truncation metadata; terminal FOR UPDATE and ellipsis are separate hints.
These lexical hints never replace the unchanged exact-target predicate.
No raw statement, literal, binding or secret is emitted. The observation loop
captures barrier flags every iteration, keeping the first eight shape/barrier
samples, final sample and aggregate barrier flags. `observed` distinguishes a
present case directory from an unavailable one. Sampling is not atomic and
does not prove continuous absence between checks.

Before execution: 105 offline observation/classifier checks, six installed-
PHPUnit reporting regressions, 12 actual-loop formatter checks, PHP syntax and
diff whitespace checks passed. Formatter coverage verified bounded retention,
an observed barrier transition and absence of synthetic secret/SQL text. The
Query/Execute predicate, exact SQL regex, calibration, ordering, assertions,
waits and reviewed cleanup were preserved.

One authorized run used the unchanged corrected SecureString launcher and
`phpunit.concurrency.xml`, filtered to `test_overlapping_requests#0`.
Fresh preflight verified `doctrack10b_runner@127.0.0.1` against
`doctrack_10b_disposable`, DML-only grants, 19 empty tables, no conflicting
harness processes or runner sessions, and an unowned advisory lock.

Result: **1 test / 109 assertions / 1 failure**, PHP child exit 1.
Exact identifier: `scope=overlap; id=pair.contention-observed; category=assertion`.
MariaDB 10.4.32 / REPEATABLE-READ; PHP 8.2.12 / PHPUnit 11.5.55.
Calibration connections 200/201 passed the same-row driver-1205 timeout.
Overlap A/B/observer connections were 203/202/199, both recorded transaction
levels 1. Sequential oracle assertions passed; overlap final response/state/
audit assertions were not reached.

The 128 samples over 2,005 ms reported B visible, disposable database matching,
Command Execute and Info visible, but exact SQL match false. All eight retained
shape samples and the final sample classified SELECT, expected table true,
backtick quoting, FOR UPDATE true and terminal, **key form placeholder**,
text present, no terminal ellipsis, truncation unknown. This identifies a
concrete reason the unchanged literal-row matcher cannot accept the observed
text. It does not expose the bound row value or independently prove its identity.

The case directory was observed, and none of `a-release`, `a-result`,
`a-failure`, `b-locked`, `b-result`, `b-failure`, `b-sql-error` was detected in
any in-loop barrier sample. Required contention was not established; Execute
and shape hints alone are insufficient. No matcher correction or retry followed.

Postflight verified all 19 tables empty, zero other runner sessions and the
advisory lock unowned. The PHP child and visible wrapper exited, no harness
process remained, and reviewed teardown removed worker barrier files. The
empty private worker directory and one-shot attempt sentinel were retained as
failure evidence. Prior evidence is preserved. Attempt artifacts are under
`%TEMP%/doctrack-10b-shape-single-15806168fdf746f3b1d4cf720a6f6cf1`.

Stopped after this case. A future offline review must establish how a prepared
placeholder statement can be tied to the verified bound target and worker
without accepting unrelated or unblocked requests before considering another
authorized run. Full acceptance remains pending. No live database access,
password reset, provisioning, schema/grant changes, production edits or Git
writes occurred. Git preservation of the three changed drafts remains pending;
no SQL backup is needed for cleaned disposable fixtures.

## Bound-target observer correction and forward success (2026-09-13)

Placeholder acceptance now requires independent actual-query evidence. Both
worker hooks validate the exact primary-key locking template and exactly one
binding equal to the synthetic target (strict integer or canonical string).
Other rows, keys, binding counts/types and statement suffixes are rejected.
The beforeExecuting hook emits the attempt proof from its actual query/binding
arguments; the post-query listener emits the locked proof only after execution
returns. Both require transaction level 1 and the original PDO object whose
server connection ID was recorded at worker readiness. A replacement PDO fails
the worker. Bindings are compared in memory and never serialized or logged.

The parent verifies both proof markers against distinct A/B connection IDs,
the same known table/row, transaction level 1 and exact-operation flags. A's
locked marker still precedes B's start. Only then can the observer accept an
exact matching placeholder template under Command Execute. Query placeholders,
shape-only matches, missing proof and different-row placeholder operations
cannot pass. Existing literal matching is retained. Observer identity, A's
held barrier, B's pre-execution marker, timeout calibration, blocked-before-
release checks, response/state/audit assertions, deadlines and cleanup remain.

Barrier trace found no producer/path/name defect. Each pair gets a fresh
private `case-*` directory passed to both workers. Worker::signal publishes
`<name>.json` by renaming `<name>.tmp`; consumers use those same paths. The
markers persist until teardown stops owned workers and removes the case files.

| Marker | Producer and expected timing |
| --- | --- |
| a-release | Parent, only after contention observation succeeds |
| a-result | A, after its HTTP request returns; it is still held during observation |
| a-failure | A catch boundary, only on a worker failure |
| b-locked | B post-query listener, after the blocked locking SELECT returns |
| b-result | B, after the HTTP request returns and its post-lock hold is released |
| b-failure | B catch boundary, only on a worker failure |
| b-sql-error | B exception reporter on QueryException; expected in calibration, not healthy overlap |

Thus all seven absent markers are expected during healthy pre-release
observation, while a-locked and b-attempt have already been consumed. In-loop
diagnostics explicitly check the case directory and each marker. No barrier
fix or deadline change was necessary. Absence is sampled, not continuous proof
by itself; the verified query/row/connection and held lock supply the other
required evidence.

Offline checks passed: 147 focused observation/proof checks, including a
different-row binding with identical placeholder SQL, missing/tampered proof,
wrong connection/transaction, and near-match statements; 12 actual-loop
formatter checks; six installed-PHPUnit reporting regressions; affected PHP
syntax and whitespace checks. Synthetic marker producer/consumer checks passed.
The review opened no database connections before the authorized preflight.

One authorized attempt then used the unchanged reviewed masked SecureString
launcher and actual `phpunit.concurrency.xml`, filtered to forward data set #0.
Fresh preflight passed exact disposable identity, DML-only grants, all 19
tables empty, no runner/process conflicts and an unowned advisory lock.

**PASS: 1 test / 171 assertions, exit 0; no failure identifier.**
MariaDB 10.4.32 / REPEATABLE-READ, PHP 8.2.12, PHPUnit 11.5.55.
Calibration A/B connections 208/207 produced driver 1205 before A release.
Overlap A/B/observer IDs were 210/209/206. Both transactions were level 1;
`actual_bound_target_verified=true`. One observation sample (1 ms) showed B
Execute, exact SQL match true, expected SELECT/table/backtick identifiers,
placeholder key, terminal FOR UPDATE, text present; truncation remained
unknown. All seven disqualifying markers were checked and absent before
release. Contention proof relies on the combined evidence, not the duration.
The controlled order completed A-lock -> B-attempt -> server lock statement
observation -> A-release -> B-completion. HTTP 201/403, winner-only route,
processing history/audit effects and loser-unchanged assertions all passed.

Postflight passed: all 19 tables empty, zero other runner sessions, advisory
lock unowned, no remaining harness processes. PHP and visible wrapper exited;
reviewed cleanup removed the worker directory. The attempt evidence remains at
`%TEMP%/doctrack-10b-bound-single-34a59910d03a4743829ddc1e8cdd9aeb`.
No retry or additional case ran. Prior evidence is preserved. Full MariaDB
acceptance remains pending; this establishes only forward/forward.

Only three existing drafts changed within the six-file scope. No production,
live-database, schema/grant, provisioning or Git writes occurred. Personal Git
preservation remains pending the user's workflow. No SQL backup is required
for the cleaned disposable fixtures. Stop after this case.
