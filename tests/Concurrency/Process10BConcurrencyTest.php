<?php

namespace Tests\Concurrency;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Concurrency\Support\FixtureSchema;
use Tests\Concurrency\Support\Worker;
use Throwable;

class Process10BConcurrencyTest extends TestCase
{
    private static array $input;
    private $app;
    private mixed $clock = null;
    private array $children = [];
    private array $directories = [];
    private array $secrets = [];
    private bool $ownsLock = false;
    private bool $mayClean = false;
    private float $caseDeadline = 0;
    private ?string $failedPhase = null;
    private string $diagnosticId = 'unclassified';
    private string $diagnosticScope = 'unclassified';
    private ?string $firstFailureDiagnostic = null;

    // Stable source markers: append new IDs rather than renumbering existing checks.
    private const DIAGNOSTIC_IDS = [
        'scenario.step-01',
        'scenario.step-02',
        'scenario.step-03',
        'scenario.step-04',
        'oracle.baseline-snapshot',
        'oracle.first-request',
        'oracle.first-response',
        'oracle.winner-snapshot',
        'oracle.second-request',
        'oracle.second-response',
        'oracle.final-snapshot',
        'scenario.step-05',
        'oracle.loser-unchanged',
        'scenario.step-06',
        'scenario.step-07',
        'scenario.check-01',
        'scenario.check-02',
        'scenario.check-03',
        'scenario.step-08',
        'scenario.check-04',
        'scenario.step-09',
        'scenario.check-05',
        'scenario.check-06',
        'scenario.check-07',
        'pair.step-01',
        'pair.check-01',
        'pair.step-02',
        'pair.step-03',
        'pair.check-02',
        'pair.step-04',
        'pair.check-03',
        'pair.step-05',
        'pair.step-06',
        'pair.step-07',
        'pair.distinct-workers',
        'pair.check-04',
        'pair.check-05',
        'pair.step-08',
        'pair.step-09',
        'pair.check-06',
        'pair.step-10',
        'pair.step-11',
        'pair.check-07',
        'pair.step-12',
        'pair.calibration-timeout',
        'pair.step-13',
        'pair.calibration-response',
        'pair.check-08',
        'pair.observe-processlist',
        'pair.contention-observed',
        'pair.check-09',
        'pair.check-10',
        'pair.step-14',
        'pair.step-15',
        'pair.step-16',
        'pair.check-11',
        'pair.step-17',
        'pair.step-18',
        'pair.step-19',
        'pair.step-20',
        'pair.step-21',
        'pair.check-12',
        'pair.deadline',
        'pair.step-22',
        'pair.step-23',
        'effects.audit-actions',
        'effects.check-01',
        'effects.check-02',
        'effects.check-03',
        'effects.check-04',
        'effects.check-05',
        'effects.check-06',
        'effects.check-07',
        'effects.check-08',
        'effects.check-09',
        'effects.check-10',
        'effects.check-11',
        'effects.check-12',
        'effects.check-13',
        'effects.check-14',
        'effects.check-15',
        'effects.check-16',
        'effects.check-17',
        'effects.check-18',
        'effects.check-19',
        'effects.check-20',
        'effects.check-21',
        'effects.check-22',
        'effects.check-23',
        'effects.check-24',
        'effects.step-01',
        'effects.check-25',
        'effects.check-26',
        'effects.check-27',
        'effects.check-28',
        'effects.check-29',
    ];

    // Only identifiers declared by the harness may reach PHPUnit output.
    protected function diagnostic(string $id): void
    {
        $this->diagnosticId = in_array($id, self::DIAGNOSTIC_IDS, true) ? $id : 'unclassified';
    }

    public static function setUpBeforeClass(): void
    {
        if (getenv('PROCESS10B_OPT_IN') !== 'PROCESS10B') {
            self::markTestSkipped('10B requires the explicit PowerShell Run entry point.');
        }
        try {
            self::$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
            Worker::guard(self::$input['config']);
        } catch (Throwable) {
            throw new \RuntimeException('10B class setup failed.');
        }
    }

    final protected function setUp(): void
    {
        $this->safePhase('setup', fn () => $this->setUpIsolated());
    }

    protected function setUpIsolated(): void
    {
        $this->clock = Carbon::getTestNow();
        $this->app = Worker::boot(self::$input['config']);
        Worker::$setupStage = 'advisory-lock-acquire';
        $this->ownsLock = (int) DB::selectOne("SELECT GET_LOCK('doctrack_10b_disposable_acceptance', 0) AS acquired")->acquired === 1;
        Worker::$setupStage = 'advisory-lock-check';
        self::assertTrue($this->ownsLock, 'Another 10B run owns this disposable database.');
        Worker::$setupStage = 'schema-verification';
        FixtureSchema::verify();
        Worker::$setupStage = 'empty-table-check';
        foreach (array_keys(FixtureSchema::definitions()) as $table) {
            self::assertSame(0, DB::table($table)->count(), 'Refusing to overwrite pre-existing fixture rows: '.$table);
        }
        $this->mayClean = true;
        $password = bin2hex(random_bytes(24));
        $this->secrets = [
            'password' => $password, 'replacement' => bin2hex(random_bytes(24)),
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
            'tokens' => array_combine([1, 2, 3, 4], array_map(fn () => bin2hex(random_bytes(32)), range(1, 4))),
            'qr' => bin2hex(random_bytes(16)),
        ];
        $metadata = DB::selectOne('SELECT VERSION() AS version, @@version_comment AS vendor, @@tx_isolation AS isolation_level');
        fwrite(STDOUT, '\n10B engine: '.json_encode($metadata)."\n");
    }

    final protected function tearDown(): void
    {
        $this->safePhase('teardown', function (): void {
            try {
                $this->tearDownIsolated();
            } finally {
                Worker::restoreHandlers($this->app);
            }
        });
    }

    protected function tearDownIsolated(): void
    {
        $failed = false;
        $attempt = static function (callable $operation) use (&$failed): void {
            try {
                $operation();
            } catch (Throwable) {
                $failed = true; // Never let a cleanup exception skip remaining cleanup.
            }
        };
        $attempt(fn () => $this->stopChildren());
        if ($this->children === [] && $this->mayClean) {
            $attempt(fn () => FixtureSchema::clear());
        }
        if ($this->ownsLock) {
            $attempt(fn () => DB::select("SELECT RELEASE_LOCK('doctrack_10b_disposable_acceptance')"));
        }
        foreach ($this->children === [] ? $this->directories : [] as $directory) {
            $attempt(function () use ($directory): void {
                // Only files created by this test in its exact private run directory.
                foreach (glob($directory.'/*') ?: [] as $file) {
                    if (is_file($file) && !is_link($file)) {
                        unlink($file);
                    }
                }
                rmdir($directory);
            });
        }
        if ($this->app) {
            $attempt(fn () => DB::purge());
            $attempt(fn () => $this->app->flush());
        }
        $this->secrets = [];
        $attempt(fn () => Carbon::setTestNow($this->clock));
        if ($failed) {
            throw new \RuntimeException('10B teardown failed.');
        }
    }

    private function safePhase(string $phase, callable $operation): void
    {
        $this->diagnosticScope = $phase;
        $this->diagnosticId = 'unclassified';
        try {
            $operation();
        } catch (Throwable $failure) {
            $this->failedPhase ??= $phase;
            $category = $failure instanceof \PHPUnit\Framework\AssertionFailedError ? 'assertion' : 'exception';
            $this->firstFailureDiagnostic ??= ' [scope='.$this->diagnosticScope
                .'; id='.$this->diagnosticId.'; category='.$category.']';
            $message = '10B '.$this->failedPhase.' failed.';
            $message .= $this->firstFailureDiagnostic;
            if ($phase === 'teardown' && $this->failedPhase !== $phase) {
                $message .= ' 10B teardown also failed.';
            }
            // Even assertion messages/comparison objects may contain sensitive values.
            // Sanitize BEFORE PHPUnit emits events, without chaining the original.
            if ($failure instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw new \PHPUnit\Framework\AssertionFailedError($message);
            }
            throw new \RuntimeException($message);
        }
    }

    public static function scenarios(): array
    {
        return array_map(fn ($name) => [$name], [
            'forward', 'receive', 'processing', 'void',
            'edit-first', 'forward-first', 'login-first', 'password-first',
        ]);
    }

    #[DataProvider('scenarios')]
    final public function test_overlapping_requests(string $case): void
    {
        $this->safePhase('test', fn () => $this->executeScenario($case));
    }

    protected function executeScenario(string $case): void
    {
        $this->diagnosticScope = 'calibration';
        [$first, $second, $statuses, $table] = $this->specs($case);
        // Calibration uses the SAME application requests and blocking row, in a
        // separate fixture lifecycle. HTTP 500 plus driver 1205 is required here only.
        $this->diagnostic('scenario.step-01');
        FixtureSchema::seed($case, $this->secrets);
        $this->diagnostic('scenario.step-02');
        $this->pair($first, $second, $table, true);
        $this->diagnostic('scenario.step-03');
        $this->stopChildren();

        $this->diagnosticScope = 'oracle';
        // Establish an independently inspected sequential oracle at each commit
        // boundary. The acceptance below still MUST show overlapping requests.
        $this->diagnostic('scenario.step-04');
        FixtureSchema::seed($case, $this->secrets);
        $this->diagnostic('oracle.baseline-snapshot');
        $baseline = FixtureSchema::snapshot();
        $this->diagnostic('oracle.first-request');
        $r1 = Worker::request($this->app, $first, $this->secrets);
        $this->diagnostic('oracle.first-response');
        self::assertSame($statuses[0], $r1['status']);
        $this->diagnostic('oracle.winner-snapshot');
        $winner = FixtureSchema::snapshot();
        $this->diagnostic('oracle.second-request');
        $r2 = Worker::request($this->app, $second, $this->secrets);
        $this->diagnostic('oracle.second-response');
        self::assertSame($statuses[1], $r2['status']);
        $this->diagnostic('oracle.final-snapshot');
        $expected = FixtureSchema::snapshot();
        Worker::$issuedToken = null;
        $this->diagnostic('scenario.step-05');
        $this->effects($case, $baseline, $expected);
        if ($statuses[1] >= 400 || $case === 'processing') {
            $this->diagnostic('oracle.loser-unchanged');
            self::assertTrue($winner === $expected, 'Losing/no-op request changed normalized state.');
        }

        $this->diagnosticScope = 'overlap';
        $this->diagnostic('scenario.step-06');
        FixtureSchema::seed($case, $this->secrets);
        $this->diagnostic('scenario.step-07');
        [$actualFirst, $actualSecond, $boundary] = $this->pair($first, $second, $table, false);
        $this->diagnostic('scenario.check-01');
        self::assertSame($statuses, [$actualFirst['status'], $actualSecond['status']]);
        if ($case === 'password-first') {
            $this->diagnostic('scenario.check-02');
            self::assertFalse($actualSecond['issued'], 'Stale login issued a token.');
        }
        $this->diagnostic('scenario.check-03');
        self::assertTrue($this->canonical($winner) === $this->canonical($boundary), 'Winner commit differs from its complete state oracle.');
        $this->diagnostic('scenario.step-08');
        $actual = FixtureSchema::snapshot();
        $this->diagnostic('scenario.check-04');
        self::assertTrue($this->canonical($expected) === $this->canonical($actual), 'Overlapping outcome differs from complete serial state.');
        $this->diagnostic('scenario.step-09');
        $this->effects($case, $baseline, $actual);
        if ($statuses[1] >= 400 || $case === 'processing') {
            $this->diagnostic('scenario.check-05');
            self::assertTrue($boundary === $actual, 'Contending loser/no-op changed any normalized row.');
        }
        if ($case === 'processing') {
            $this->diagnostic('scenario.check-06');
            self::assertFalse($actualFirst['noop']);
            $this->diagnostic('scenario.check-07');
            self::assertTrue($actualSecond['noop']);
        }
    }

    private function specs(string $case): array
    {
        $make = fn ($method, $url, $actor, $payload = []) => compact('method', 'url', 'actor', 'payload');
        $forward = $make('POST', '/api/documents/1/forward', 1, ['to_office_id' => 2]);
        $edit = $make('PATCH', '/api/documents/1', 2, ['title' => 'Synthetic revised']);
        $login = ['method' => 'POST', 'url' => '/api/login', 'payload' => [
            'email' => 'synthetic4@example.test', 'password' => $this->secrets['password'],
        ]];
        $password = $make('PUT', '/api/users/4', 3, [
            'name' => 'Synthetic user 4', 'email' => 'synthetic4@example.test', 'role_id' => 2, 'office_id' => 1,
            'password' => $this->secrets['replacement'], 'password_confirmation' => $this->secrets['replacement'],
        ]);
        return match ($case) {
            'forward' => [$forward, array_replace($forward, ['actor' => 2]), [201, 403], 'documents'],
            'receive' => [$make('POST', '/api/documents/1/receive', 1), $make('POST', '/api/documents/1/receive', 2), [200, 409], 'documents'],
            'processing' => [$make('PUT', '/api/documents/1/processing', 1, ['current_action_id' => 4, 'processing_note' => 'Synthetic note']),
                $make('PUT', '/api/documents/1/processing', 2, ['current_action_id' => 4, 'processing_note' => 'Synthetic note']), [200, 200], 'documents'],
            'void' => [$make('POST', '/api/qr-codes/1/void', 1), $make('POST', '/api/qr-codes/1/void', 2), [200, 409], 'document_qr_codes'],
            'edit-first' => [$edit, $forward, [200, 201], 'documents'],
            'forward-first' => [$forward, $edit, [201, 403], 'documents'],
            'login-first' => [$login, $password, [200, 200], 'users'],
            'password-first' => [$password, $login, [200, 401], 'users'],
        };
    }

    private function pair(array $first, array $second, string $table, bool $calibration): array
    {
        $this->diagnostic('pair.step-01');
        $root = realpath(self::$input['private_directory']);
        $this->diagnostic('pair.check-01');
        self::assertTrue($root !== false && is_dir($root) && !is_link($root), 'Private worker directory missing.');
        $dir = $root.'/case-'.bin2hex(random_bytes(12));
        $this->diagnostic('pair.step-02');
        mkdir($dir, 0700);
        $this->directories[] = $dir;
        $started = microtime(true);
        $this->caseDeadline = $started + 30;
        foreach (['a' => $first, 'b' => $second] as $name => $spec) {
            $pipes = [];
            $this->diagnostic('pair.step-03');
            $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0',
                __DIR__.'/Support/Worker.php'], [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
                $pipes, Worker::ROOT, null, ['bypass_shell' => true, 'create_no_window' => true]);
            $this->diagnostic('pair.check-02');
            self::assertTrue(is_resource($process), 'Unable to start owned PHP worker.');
            $this->children[] = $process;
            $input = ['config' => self::$input['config'], 'secrets' => $this->secrets, 'spec' => $spec,
                'dir' => $dir, 'name' => $name, 'lock_table' => $table, 'lock_id' => $table === 'users' ? 4 : 1, 'hold' => true,
                'calibration' => $calibration && $name === 'b'];
            $this->diagnostic('pair.step-04');
            $bytes = json_encode($input, JSON_THROW_ON_ERROR);
            $this->diagnostic('pair.check-03');
            self::assertSame(strlen($bytes), fwrite($pipes[0], $bytes), 'Private worker input incomplete.');
            $this->diagnostic('pair.step-05');
            fclose($pipes[0]);
        }
        $this->diagnostic('pair.step-06');
        $a = $this->await($dir, 'a-ready');
        $this->diagnostic('pair.step-07');
        $b = $this->await($dir, 'b-ready');
        $this->diagnostic('pair.distinct-workers');
        self::assertNotSame($a['connection'], $b['connection']);
        $this->diagnostic('pair.check-04');
        $observerConnection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        self::assertNotSame($observerConnection, $a['connection']);
        $this->diagnostic('pair.check-05');
        self::assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $b['connection']);
        $this->diagnostic('pair.step-08');
        Worker::signal($dir, 'a-start');
        $this->diagnostic('pair.step-09');
        $lock = $this->await($dir, 'a-locked');
        $this->diagnostic('pair.check-06');
        self::assertSame(1, $lock['transaction']);
        $this->diagnostic('pair.step-10');
        Worker::signal($dir, 'b-start');
        $this->diagnostic('pair.step-11');
        $attempt = $this->await($dir, 'b-attempt');
        $this->diagnostic('pair.check-07');
        self::assertSame([$lock['table'], $lock['synthetic_id']], [$attempt['table'], $attempt['synthetic_id']]);
        $boundTarget = Worker::boundTargetVerified($lock, $attempt, $a['connection'], $b['connection'], $table);
        self::assertTrue($boundTarget, 'Actual locking query target/connection proof missing.');
        if ($calibration) {
            $this->diagnostic('pair.step-12');
            $error = $this->await($dir, 'b-sql-error');
            $this->diagnostic('pair.calibration-timeout');
            self::assertSame(1205, $error['driver_code'], 'Calibration did not encounter a real InnoDB lock timeout.');
            $this->diagnostic('pair.step-13');
            $resultB = $this->await($dir, 'b-result');
            $this->diagnostic('pair.calibration-response');
            self::assertSame(500, $resultB['status']);
            $this->diagnostic('pair.check-08');
            self::assertFileDoesNotExist($dir.'/b-locked.json');
        } else {
            // Observe the server executing B's incompatible row-lock statement
            // while A's post-lock barrier proves that its transaction still holds it.
            $observed = false;
            $observationStarted = microtime(true);
            $until = $observationStarted + 2;
            $samples = 0;
            $ever = Worker::contentionSample([], $a['connection'], $b['connection'], $table);
            $sampleEvidence = [];
            $barrierEver = [];
            do {
                $this->diagnostic('pair.observe-processlist');
                $threads = DB::select('SHOW FULL PROCESSLIST');
                $last = Worker::contentionSample($threads, $a['connection'], $b['connection'], $table, $boundTarget);
                $shape = Worker::sqlShape($threads, $b['connection'], $table);
                $barriers = Worker::observationBarriers($dir);
                foreach ($barriers as $flag => $value) {
                    $barrierEver[$flag] = ($barrierEver[$flag] ?? false) || $value;
                }
                if (count($sampleEvidence) < 8) {
                    $sampleEvidence[] = ['shape' => $shape, 'barriers' => $barriers];
                }
                $samples++;
                foreach ($last as $flag => $value) {
                    $ever[$flag] = $ever[$flag] || $value;
                }
                $observed = Worker::contentionObserved($last, $dir, (int) ($attempt['transaction'] ?? -1));
                if (!$observed) {
                    usleep(10000);
                }
            } while (!$observed && microtime(true) < $until);
            $this->diagnostic('pair.contention-observed');
            {
                // First eight and final classifications; barriers sampled inside the loop.
                fwrite(STDOUT, json_encode(['phase' => 'contention-observation-diagnostic',
                    'connections' => [(int) $a['connection'], (int) $b['connection'], $observerConnection],
                    'a_transaction_at_lock' => (int) $lock['transaction'],
                    'b_transaction_at_attempt' => (int) ($attempt['transaction'] ?? -1),
                    'actual_bound_target_verified' => $boundTarget,
                    'samples' => $samples, 'elapsed_ms' => (int) ((microtime(true) - $observationStarted) * 1000),
                    'ever' => $ever, 'last' => $last, 'barriers' => $barriers,
                    'sample_evidence' => $sampleEvidence, 'last_shape' => $shape,
                    'barriers_ever' => $barrierEver, 'contention_observed' => $observed])."\n");
            }
            self::assertTrue($observed, 'BLOCKED: limited-account observation did not establish overlapping incompatible lock execution.');
            $this->diagnostic('pair.check-09');
            self::assertFileDoesNotExist($dir.'/b-locked.json');
            $this->diagnostic('pair.check-10');
            self::assertFileDoesNotExist($dir.'/b-result.json');
        }
        $this->diagnostic('pair.step-14');
        Worker::signal($dir, 'a-release');
        $this->diagnostic('pair.step-15');
        $resultA = $this->await($dir, 'a-result');
        $this->diagnostic('pair.step-16');
        $boundary = FixtureSchema::snapshot();
        if ($table === 'users') {
            $password = $first['url'] === '/api/login' ? $this->secrets['password'] : $this->secrets['replacement'];
            $this->diagnostic('pair.check-11');
            self::assertTrue(password_verify($password, DB::table('users')->where('id', 4)->value('password')),
                'Winner boundary password differs from the controlled order.');
        }
        if (!$calibration) {
            $this->diagnostic('pair.step-17');
            $this->await($dir, 'b-locked');
            $this->diagnostic('pair.step-18');
            Worker::signal($dir, 'b-release');
            $this->diagnostic('pair.step-19');
            $resultB = $this->await($dir, 'b-result');
        }
        foreach (['a' => $resultA, 'b' => $resultB] as $name => $result) {
            if ($result['issued']) {
                $this->diagnostic('pair.step-20');
                Worker::signal($dir, $name.'-probe');
                $this->diagnostic('pair.step-21');
                $probe = $this->await($dir, $name.'-probe-result');
                if (!$calibration) {
                    $this->diagnostic('pair.check-12');
                    self::assertSame(401, $probe['status'], 'Password change must revoke the concurrently issued token.');
                }
            }
        }
        $this->diagnostic('pair.deadline');
        self::assertLessThan(30, microtime(true) - $started, 'Case deadline exceeded.');
        $this->diagnostic('pair.step-22');
        fwrite(STDOUT, json_encode(['phase' => $calibration ? 'lock-timeout-calibration' : 'overlap',
            'connections' => [$a['connection'], $b['connection']], 'lock_table' => $table,
            'order' => ['a-lock', 'b-attempt', $calibration ? 'b-timeout-1205' : 'b-server-lock-query', 'a-release', 'b-completion']])."\n");
        $this->diagnostic('pair.step-23');
        $this->stopChildren();
        return [$resultA, $resultB, $boundary];
    }

    private function stopChildren(): void
    {
        $failed = false;
        foreach ($this->children as $key => $process) {
            $deadline = microtime(true) + 1;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process); // Only this proc_open handle; never taskkill by image name.
            }
            $deadline = microtime(true) + 2;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (proc_get_status($process)['running']) {
                $failed = true;
                continue; // Still attempt termination of every other owned worker.
            }
            proc_close($process);
            unset($this->children[$key]);
        }
        if ($failed) {
            throw new \RuntimeException('Owned worker did not terminate; fixture and barrier cleanup withheld.');
        }
    }

    private function await(string $dir, string $name): array
    {
        $remaining = $this->caseDeadline - microtime(true);
        self::assertGreaterThan(0, $remaining, 'Case deadline expired.');
        return Worker::await($dir, $name, min(10, $remaining));
    }

    private function effects(string $case, array $before, array $after): void
    {
        $forward = in_array($case, ['forward', 'edit-first', 'forward-first'], true);
        $auth = in_array($case, ['login-first', 'password-first'], true);
        $events = match ($case) {
            'forward', 'forward-first' => ['forwarded'], 'receive' => ['received'],
            'processing' => ['processing_updated'], 'void' => ['voided'],
            'edit-first' => ['updated', 'forwarded'], 'login-first' => ['login', 'updated'],
            'password-first' => ['updated'],
        };
        $this->diagnostic('effects.audit-actions');
        self::assertSame($events, array_column($after['audit_logs'], 'action'));
        $this->diagnostic('effects.check-01');
        self::assertSame(match ($case) {
            'edit-first' => [2, 1], 'login-first' => [4, 3], 'password-first' => [3], default => [1],
        }, array_column($after['audit_logs'], 'user_id'));
        $delta = ['document_routes' => $forward ? 1 : 0,
            'document_processing_logs' => ($forward || in_array($case, ['receive', 'processing'], true)) ? 1 : 0,
            'audit_logs' => count($events), 'personal_access_tokens' => $auth ? -1 : 0];
        foreach ($before as $table => $rows) {
            $this->diagnostic('effects.check-02');
            self::assertSame($delta[$table] ?? 0, count($after[$table]) - count($rows), $table.' exact row delta');
            $mutable = ['audit_logs', 'document_processing_logs'];
            if ($forward || in_array($case, ['receive', 'processing'], true)) {
                $mutable[] = 'documents';
                $mutable[] = 'document_routes';
            }
            if ($case === 'void') {
                $mutable[] = 'document_qr_codes';
            }
            if ($auth) {
                $mutable = array_merge($mutable, ['users', 'personal_access_tokens']);
            }
            if (!in_array($table, $mutable, true)) {
                $this->diagnostic('effects.check-03');
                self::assertTrue($rows === $after[$table], $table.' unexpected mutation');
            }
        }
        if ($forward || $case === 'receive') {
            $document = $after['documents'][0];
            $route = $after['document_routes'][0];
            $this->diagnostic('effects.check-04');
            self::assertSame(2, $document['current_office_id']);
            $this->diagnostic('effects.check-05');
            self::assertSame($forward ? 2 : 3, $document['current_action_id']);
            $this->diagnostic('effects.check-06');
            self::assertSame($forward ? 2 : 3, $document['status_id']);
            $this->diagnostic('effects.check-07');
            self::assertSame([1, 1, 2], [$route['document_id'], $route['from_office_id'], $route['to_office_id']]);
            $this->diagnostic('effects.check-08');
            self::assertSame($forward ? 1 : 4, $route['forwarded_by']);
            $this->diagnostic('effects.check-09');
            self::assertSame($forward ? 2 : 3, $route['status_id']);
            $this->diagnostic('effects.check-10');
            self::assertSame(1, $route['action_id']);
            $this->diagnostic('effects.check-11');
            self::assertSame($forward ? null : 1, $route['received_by']);
            $this->diagnostic('effects.check-12');
            self::assertSame($forward ? null : Worker::TIME, $route['received_at']);
            $this->diagnostic('effects.check-13');
            self::assertSame($case === 'edit-first' ? 'Synthetic revised' : 'Synthetic original', $document['title']);
            $this->diagnostic('effects.check-14');
            self::assertSame([$forward ? 'forwarded' : 'received'], array_column($after['document_processing_logs'], 'event_type'));
            $this->diagnostic('effects.check-15');
            self::assertSame([1], array_column($after['document_processing_logs'], 'user_id'));
            $log = $after['document_processing_logs'][0];
            $this->diagnostic('effects.check-16');
            self::assertSame([1, 2, $forward ? 2 : 3, $route['id']],
                [$log['document_id'], $log['office_id'], $log['processing_action_id'], $log['document_route_id']]);
        }
        if ($case === 'processing') {
            $this->diagnostic('effects.check-17');
            self::assertSame(4, $after['documents'][0]['current_action_id']);
            $this->diagnostic('effects.check-18');
            self::assertSame('Synthetic note', $after['documents'][0]['processing_note']);
            $this->diagnostic('effects.check-19');
            self::assertSame('Synthetic note', $after['document_processing_logs'][0]['processing_note']);
            $this->diagnostic('effects.check-20');
            self::assertSame('action_updated', $after['document_processing_logs'][0]['event_type']);
            $this->diagnostic('effects.check-21');
            self::assertSame(1, $after['document_processing_logs'][0]['user_id']);
        }
        if ($case === 'void') {
            $this->diagnostic('effects.check-22');
            self::assertSame('void', $after['document_qr_codes'][0]['status']);
            $this->diagnostic('effects.check-23');
            self::assertNull($after['document_qr_codes'][0]['document_id']);
        }
        if ($auth) {
            $this->diagnostic('effects.check-24');
            self::assertSame([1, 2, 3], array_column($after['personal_access_tokens'], 'tokenable_id'));
            $this->diagnostic('effects.step-01');
            $hash = DB::table('users')->where('id', 4)->value('password');
            $this->diagnostic('effects.check-25');
            self::assertTrue(password_verify($this->secrets['replacement'], $hash), 'Replacement password not persisted.');
            $this->diagnostic('effects.check-26');
            self::assertFalse(password_verify($this->secrets['password'], $hash), 'Stale password still valid.');
            $safeBefore = $before['users'];
            $safeAfter = $after['users'];
            unset($safeBefore[3]['password'], $safeAfter[3]['password']);
            $this->diagnostic('effects.check-27');
            self::assertTrue($safeBefore === $safeAfter, 'Security update changed unrelated user fields.');
            $this->diagnostic('effects.check-28');
            self::assertTrue(array_slice($before['personal_access_tokens'], 0, 3) === $after['personal_access_tokens'], 'Unrelated tokens changed.');
        }
        foreach ($after['audit_logs'] as $i => $audit) {
            $module = $auth ? ($events[$i] === 'login' ? 'authentication' : 'users')
                : ($case === 'void' ? 'qr_codes' : ($case === 'processing' ? 'document_processing'
                    : ($events[$i] === 'updated' ? 'documents' : 'document_routing')));
            $this->diagnostic('effects.check-29');
            self::assertSame([$module, $auth ? 4 : 1, '192.0.2.10', 'Synthetic-10B/1.0'],
                [$audit['module'], $audit['record_id'], $audit['ip_address'], $audit['user_agent']]);
        }
    }

    private function canonical(array $snapshot): array
    {
        // DML-only reset cannot reset AUTO_INCREMENT; use row identities by order
        // and normalize route references. Cross-run password salts/token randomness
        // are normalized ONLY here; same-run rejection snapshots retain their hashes.
        $routeMap = [];
        foreach ($snapshot['document_routes'] ?? [] as $i => $route) {
            $routeMap[$route['id']] = $i + 1;
        }
        foreach ($snapshot as $table => &$rows) {
            foreach ($rows as $i => &$row) {
                if (in_array($table, ['document_routes', 'document_processing_logs', 'audit_logs', 'personal_access_tokens'], true)) {
                    $row['id'] = $i + 1;
                }
                if (isset($row['document_route_id'])) {
                    $row['document_route_id'] = $routeMap[$row['document_route_id']];
                }
                if ($table === 'users' && $row['id'] === 4) {
                    $row['password'] = 'password-verified-separately';
                }
                if ($table === 'personal_access_tokens' && $row['tokenable_id'] === 4) {
                    $row['token'] = 'opaque-issued-token';
                }
            }
            unset($row);
        }
        unset($rows);
        return $snapshot;
    }
}
