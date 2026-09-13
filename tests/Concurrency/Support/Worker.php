<?php

namespace Tests\Concurrency\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

final class Worker
{
    public const TIME = '2026-09-09 02:00:00';
    public const ROOT = __DIR__.'/../../..';
    public static ?string $issuedToken = null;
    private static ?\WeakMap $handlerStates = null;
    public static string $setupStage = 'not-started';
    public static string $setupFailure = 'none';

    public static function handlerState(): array
    {
        $error = set_error_handler(static fn () => false);
        restore_error_handler();
        $exception = set_exception_handler(static function (Throwable $e): void {});
        restore_exception_handler();
        return [$error, $exception, error_reporting()];
    }

    public static function restoreHandlerState(array $state): void
    {
        // Pop only handlers installed above the saved handlers, including PHPUnit's.
        foreach ([0, 1] as $index) {
            for ($depth = 0; self::handlerState()[$index] !== $state[$index]; $depth++) {
                if ($depth >= 32 || self::handlerState()[$index] === null) {
                    throw new RuntimeException('10B handler restoration failed.');
                }
                $index === 0 ? restore_error_handler() : restore_exception_handler();
            }
        }
        error_reporting($state[2]);
    }

    public static function restoreHandlers($app): void
    {
        if ($app && self::$handlerStates !== null && isset(self::$handlerStates[$app])) {
            $state = self::$handlerStates[$app];
            self::restoreHandlerState($state);
            unset(self::$handlerStates[$app]);
        }
    }

    public static function rememberHandlers($app): void
    {
        self::$handlerStates ??= new \WeakMap();
        self::$handlerStates[$app] = self::handlerState();
    }

    public static function guard(array $c): void
    {
        foreach (['opt_in' => 'PROCESS10B', 'environment' => 'testing', 'host' => '127.0.0.1',
            'database' => FixtureSchema::DATABASE, 'username' => FixtureSchema::ACCOUNT] as $key => $value) {
            if (($c[$key] ?? null) !== $value) {
                throw new RuntimeException('10B guard rejected '.$key);
            }
        }
        if (($c['port'] ?? null) !== 3306 || !is_string($c['password'] ?? null) || $c['password'] === ''
            || !is_string($c['app_key'] ?? null) || !str_starts_with($c['app_key'], 'base64:')
            || strlen(base64_decode(substr($c['app_key'], 7), true) ?: '') !== 32) {
            throw new RuntimeException('10B connection input rejected.');
        }
        foreach (['url', 'read', 'write', 'unix_socket'] as $key) {
            if (!empty($c[$key])) {
                throw new RuntimeException('10B connection override rejected.');
            }
        }
        foreach (['DB_URL', 'DATABASE_URL', 'MYSQL_URL', 'APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE'] as $key) {
            if (getenv($key) !== false && getenv($key) !== '') {
                throw new RuntimeException('10B inherited override rejected: '.$key);
            }
        }
        if (getenv('APP_ENV') !== false && getenv('APP_ENV') !== '' && getenv('APP_ENV') !== 'testing') {
            throw new RuntimeException('10B requires a testing process environment.');
        }
        if (is_file(self::ROOT.'/bootstrap/cache/config.php') || glob(self::ROOT.'/bootstrap/cache/routes*.php')) {
            throw new RuntimeException('10B refuses cached application configuration/routes.');
        }
    }

    public static function boot(array $c, ?callable $stageObserver = null): \Illuminate\Foundation\Application
    {
        self::$setupFailure = 'none';
        self::$setupStage = 'guard';
        self::guard($c); // Must precede application bootstrap AND PDO construction.
        self::$setupStage = 'application-create';
        $app = require self::ROOT.'/bootstrap/app.php';
        self::rememberHandlers($app);
        $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app) use ($c) {
            $connection = [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
                'database' => FixtureSchema::DATABASE, 'username' => FixtureSchema::ACCOUNT,
                'password' => $c['password'], 'url' => null, 'unix_socket' => '',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
                'options' => [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_PERSISTENT => false],
            ];
            $app['config']->set([
                'app.env' => 'testing', 'app.debug' => false, 'app.key' => $c['app_key'],
                'app.url' => 'http://localhost', 'app.timezone' => 'UTC',
                'database.default' => 'mysql', 'database.connections' => ['mysql' => $connection],
                'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
                'mail.default' => 'array', 'broadcasting.default' => 'null',
                'logging.default' => '10b-null', 'logging.channels.10b-null' => [
                    'driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class,
                ],
                'auth.defaults.guard' => 'web', 'authentication.login_max_attempts' => 5,
                'authentication.login_decay_seconds' => 60, 'authentication.token_lifetime_minutes' => 480,
                'authentication.token_name' => 'synthetic-10b', 'sanctum.expiration' => 480,
                'hashing.bcrypt.rounds' => 4,
            ]);
            $app->instance('env', 'testing');
        });
        // Deliberately omit LoadEnvironmentVariables. Never read the live .env.
        $clock = Carbon::getTestNow();
        try {
            foreach ([
                \Illuminate\Foundation\Bootstrap\LoadConfiguration::class => 'load-configuration',
                \Illuminate\Foundation\Bootstrap\HandleExceptions::class => 'install-handlers',
                \Illuminate\Foundation\Bootstrap\RegisterFacades::class => 'register-facades',
                \Illuminate\Foundation\Bootstrap\RegisterProviders::class => 'register-providers',
                \Illuminate\Foundation\Bootstrap\BootProviders::class => 'boot-providers',
            ] as $bootstrapper => $stage) {
                $app->beforeBootstrapping($bootstrapper, static function ($app) use ($stage, $stageObserver): void {
                    self::$setupStage = $stage;
                    if ($stageObserver !== null) {
                        $stageObserver($stage, $app);
                    }
                });
            }
            $app->bootstrapWith([
                \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
                \Illuminate\Foundation\Bootstrap\HandleExceptions::class,
                \Illuminate\Foundation\Bootstrap\RegisterFacades::class,
                \Illuminate\Foundation\Bootstrap\RegisterProviders::class,
                \Illuminate\Foundation\Bootstrap\BootProviders::class,
            ]);
            self::$setupStage = 'clock';
            Carbon::setTestNow(Carbon::parse(self::TIME, 'UTC'));
            self::$setupStage = 'database-identity';
            $identity = DB::selectOne('SELECT DATABASE() AS db, CURRENT_USER() AS account');
            if ($identity->db !== FixtureSchema::DATABASE || $identity->account !== FixtureSchema::ACCOUNT.'@127.0.0.1') {
                throw new RuntimeException('10B server identity rejected.');
            }
            self::$setupStage = 'database-grants';
            self::verifyGrants(DB::select('SHOW GRANTS'));
            self::$setupStage = 'session-innodb-timeout';
            DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
            self::$setupStage = 'session-metadata-timeout';
            DB::statement('SET SESSION lock_wait_timeout = 5');
            self::$setupStage = 'session-statement-timeout';
            DB::statement('SET SESSION max_statement_time = 8');
            self::$setupStage = 'session-time-zone';
            DB::statement("SET SESSION time_zone = '+00:00'");
            self::$setupStage = 'bootstrap-complete';
            return $app;
        } catch (Throwable $failure) {
            self::$setupFailure = 'unclassified';
            if ($failure instanceof \TypeError && !$app->bound('request')) {
                foreach ($failure->getTrace() as $frame) {
                    if (($frame['class'] ?? '') === \Illuminate\Routing\UrlGenerator::class
                        && ($frame['function'] ?? '') === '__construct') {
                        self::$setupFailure = 'url-generator-request-unbound';
                        break;
                    }
                }
            }
            try {
                if ($app->bound('db')) {
                    $app['db']->purge();
                }
            } catch (Throwable) {
                // Preserve secret-free failure output even if disconnect fails.
            }
            try {
                $app->flush();
                Carbon::setTestNow($clock);
            } finally {
                self::restoreHandlers($app);
            }
            throw new RuntimeException('10B isolated bootstrap failed; raw connection details suppressed.');
        }
    }

    public static function verifyGrants(array $rows): void
    {
        $dml = false;
        foreach ($rows as $row) {
            $grant = (string) array_values((array) $row)[0];
            if (preg_match('/^GRANT USAGE ON \*\.\* TO /', $grant) && !str_contains($grant, 'WITH GRANT OPTION')) {
                continue;
            }
            $pattern = '/^GRANT (.+) ON `doctrack\\\\_10b\\\\_disposable`\.\* TO /';
            if (!preg_match($pattern, $grant, $matches) || str_contains($grant, 'WITH GRANT OPTION')) {
                throw new RuntimeException('10B grants exceed the exact database DML allowlist.');
            }
            $permissions = explode(', ', $matches[1]);
            sort($permissions);
            if ($permissions !== ['DELETE', 'INSERT', 'SELECT', 'UPDATE']) {
                throw new RuntimeException('10B requires exactly four DML grants.');
            }
            $dml = true;
        }
        if (!$dml) {
            throw new RuntimeException('10B restricted DML grant missing.');
        }
    }

    public static function request($app, array $spec, array $secrets): array
    {
        self::$issuedToken = null;
        \Illuminate\Support\Facades\Auth::forgetGuards();
        \Illuminate\Support\Facades\Auth::shouldUse('web');
        $app['session']->flush();
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '192.0.2.10', 'HTTP_USER_AGENT' => 'Synthetic-10B/1.0'];
        if (isset($spec['actor'])) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$spec['actor'].'|'.$secrets['tokens'][$spec['actor']];
        }
        $request = Request::create('http://localhost'.$spec['url'], $spec['method'], [], [], [], $server,
            json_encode($spec['payload'] ?? [], JSON_THROW_ON_ERROR));
        $kernel = $app->make(Kernel::class);
        $response = $kernel->handle($request);
        $body = json_decode($response->getContent(), true);
        if ($spec['url'] === '/api/login') {
            self::$issuedToken = $body['token'] ?? null;
        }
        $result = ['status' => $response->getStatusCode(), 'issued' => self::$issuedToken !== null,
            'noop' => ($body['message'] ?? '') === 'Current processing action is already up to date.'];
        $kernel->terminate($request, $response);
        return $result;
    }

    public static function signal(string $dir, string $name, array $data = []): void
    {
        $temp = $dir.'/'.$name.'.tmp';
        file_put_contents($temp, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);
        if (!rename($temp, $dir.'/'.$name.'.json')) {
            throw new RuntimeException('10B barrier publication failed.');
        }
    }

    public static function await(string $dir, string $name, float $seconds = 10): array
    {
        $deadline = microtime(true) + $seconds;
        do {
            foreach (['a-failure', 'b-failure'] as $failure) {
                if (is_file($dir.'/'.$failure.'.json')) {
                    throw new RuntimeException('10B worker failed; barrier wait cancelled.');
                }
            }
            if (is_file($dir.'/'.$name.'.json')) {
                return json_decode(file_get_contents($dir.'/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('10B barrier deadline: '.$name);
    }

    // Pure classification only: never return process-list text, SQL or bindings.
    public static function contentionSample(array $threads, int $a, int $b, string $table, bool $boundTarget = false): array
    {
        $flags = array_fill_keys(['a_visible', 'b_visible', 'b_database_matches',
            'b_command_query', 'b_command_execute', 'b_info_visible', 'b_sql_matches'], false);
        foreach ($threads as $thread) {
            $flags['a_visible'] = $flags['a_visible'] || (int) $thread->Id === $a;
            if ((int) $thread->Id !== $b) {
                continue;
            }
            $flags['b_visible'] = true;
            $flags['b_database_matches'] = $thread->db === FixtureSchema::DATABASE;
            $flags['b_command_query'] = $thread->Command === 'Query';
            $flags['b_command_execute'] = $thread->Command === 'Execute';
            $flags['b_info_visible'] = is_string($thread->Info);
            $flags['b_sql_matches'] = is_string($thread->Info)
                && preg_match('/^select \\* from `'.preg_quote($table, '/').'` where `'
                    .preg_quote($table, '/').'`\\.`id` = [\'\"]?'
                    .($table === 'users' ? 4 : 1).'[\'\"]? limit 1 for update$/i', $thread->Info) === 1;
            if ($boundTarget && $flags['b_command_execute'] && is_string($thread->Info)
                && strcasecmp($thread->Info, self::lockingTemplate($table)) === 0) {
                $flags['b_sql_matches'] = true;
            }
        }
        return $flags;
    }

    private static function lockingTemplate(string $table): string
    {
        if (!in_array($table, ['documents', 'users', 'document_qr_codes'], true)) {
            throw new RuntimeException('Unsupported synthetic lock target.');
        }
        return 'select * from `'.$table.'` where `'.$table.'`.`id` = ? limit 1 for update';
    }

    // Called with actual query-hook arguments, never the intended HTTP payload.
    // Compare bindings in memory; publish only the already-known synthetic target.
    public static function lockingEvidence(string $sql, array $bindings, string $table, int $target, int $connection, int $transaction): ?array
    {
        if ($connection <= 0 || $transaction !== 1 || $target !== ($table === 'users' ? 4 : 1)
            || strcasecmp($sql, self::lockingTemplate($table)) !== 0
            || count($bindings) !== 1 || !array_key_exists(0, $bindings)
            || !in_array($bindings[0], [$target, (string) $target], true)) {
            return null;
        }
        return ['table' => $table, 'synthetic_id' => $target, 'transaction' => 1,
            'connection' => $connection, 'exact_operation' => true];
    }

    public static function boundTargetVerified(array $lock, array $attempt, int $a, int $b, string $table): bool
    {
        if ($a <= 0 || $b <= 0 || $a === $b) {
            return false;
        }
        foreach ([[$lock, $a], [$attempt, $b]] as [$evidence, $connection]) {
            if (($evidence['connection'] ?? null) !== $connection
                || ($evidence['transaction'] ?? null) !== 1
                || ($evidence['exact_operation'] ?? null) !== true
                || ($evidence['table'] ?? null) !== $table
                || ($evidence['synthetic_id'] ?? null) !== ($table === 'users' ? 4 : 1)) {
                return false;
            }
        }
        return true;
    }

    // Diagnostic lexical hints only, never an alternative acceptance predicate.
    public static function sqlShape(array $threads, int $b, string $table): array
    {
        $shape = ['text' => 'unobserved', 'truncation' => 'unknown', 'statement' => 'unknown',
            'expected_table' => false, 'for_update' => false, 'identifier_quoting' => 'unknown',
            'key_form' => 'unknown', 'ends_for_update' => false, 'ends_ellipsis' => false];
        foreach ($threads as $thread) {
            if ((int) $thread->Id !== $b) {
                continue;
            }
            $sql = $thread->Info ?? null;
            $shape['text'] = !is_string($sql) ? 'unavailable' : ($sql === '' ? 'empty' : 'present');
            if (!is_string($sql) || $sql === '') {
                return $shape;
            }
            // SHOW FULL PROCESSLIST supplies no truncation metadata: unknown stays unknown.
            if (preg_match('/^\s*(select|update|insert|delete)\b/i', $sql, $match)) {
                $shape['statement'] = strtolower($match[1]);
            } else {
                $shape['statement'] = 'other';
            }
            if (preg_match('/\bfrom\s+(`[^`]+`|"[^"]+"|[a-z_][a-z0-9_]*)/i', $sql, $match)) {
                $identifier = $match[1];
                $shape['identifier_quoting'] = $identifier[0] === '`' ? 'backtick'
                    : ($identifier[0] === '"' ? 'double' : 'unquoted');
                $shape['expected_table'] = trim($identifier, '`"') === $table;
            }
            $shape['for_update'] = preg_match('/\bfor\s+update\b/i', $sql) === 1;
            $shape['ends_for_update'] = preg_match('/\bfor\s+update\s*$/i', $sql) === 1;
            $shape['ends_ellipsis'] = preg_match('/(?:\.{3}|\x{2026})\s*$/u', $sql) === 1;
            if (preg_match('/(?:`id`|"id"|\bid)\s*=\s*(\?|:[a-z_][a-z0-9_]*|\x27[^\x27]*\x27|"[^"]*"|[0-9]+)(?=\s|$)/i', $sql, $match)) {
                $value = $match[1];
                $shape['key_form'] = $value[0] === '?' || $value[0] === ':' ? 'placeholder'
                    : ($value[0] === "'" || $value[0] === '"' ? 'quoted_literal' : 'numeric_literal');
            }
            return $shape;
        }
        return $shape;
    }

    public static function observationBarriers(string $dir): array
    {
        $flags = ['observed' => is_dir($dir)];
        foreach (['a-release', 'a-result', 'a-failure', 'b-locked', 'b-result', 'b-failure', 'b-sql-error'] as $marker) {
            $path = $dir.'/'.$marker.'.json';
            clearstatcache(true, $path);
            $flags[$marker] = file_exists($path);
        }
        return $flags;
    }

    // Called only after calibration, distinct connections, A-locked then B-attempt
    // on the same row. Execute alone is never evidence of a blocked request.
    public static function contentionObserved(array $sample, string $dir, int $transaction): bool
    {
        if ($transaction !== 1 || !is_dir($dir)
            || !$sample['a_visible'] || !$sample['b_visible'] || !$sample['b_database_matches']
            || !$sample['b_info_visible'] || !$sample['b_sql_matches']
            || !($sample['b_command_query'] || $sample['b_command_execute'])) {
            return false;
        }
        foreach (['a-release', 'a-result', 'a-failure', 'b-locked', 'b-result', 'b-failure', 'b-sql-error'] as $marker) {
            $path = $dir.'/'.$marker.'.json';
            clearstatcache(true, $path);
            if (file_exists($path)) {
                return false;
            }
        }
        return true;
    }

    public static function run(array $input): void
    {
        $app = null;
        $dir = $input['dir'];
        $name = $input['name'];
        $clock = Carbon::getTestNow();
        try {
            $app = self::boot($input['config']);
            $id = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $pdo = DB::connection()->getPdo();
            self::signal($dir, $name.'-ready', ['connection' => $id]);
            $app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function (Throwable $e) use ($dir, $name) {
                if ($e instanceof QueryException) {
                    self::signal($dir, $name.'-sql-error', ['driver_code' => (int) ($e->errorInfo[1] ?? 0)]);
                }
                return false; // Never render SQL bindings, credentials, or exception text.
            });
            if ($input['calibration'] ?? false) {
                DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
            }
            $locked = false;
            $evidence = fn ($sql, $bindings, $connection) => self::lockingEvidence($sql, $bindings,
                $input['lock_table'], $input['lock_id'], $id, $connection->transactionLevel());
            DB::connection()->beforeExecuting(function ($sql, $bindings, $connection) use ($evidence, $pdo, $dir, $name) {
                if ($connection->getPdo() !== $pdo) {
                    throw new RuntimeException('Worker connection changed.');
                }
                if ($proof = $evidence($sql, $bindings, $connection)) {
                    self::signal($dir, $name.'-attempt', $proof);
                }
            });
            DB::listen(function ($query) use (&$locked, $evidence, $pdo, $input, $dir, $name) {
                if ($query->connection->getPdo() !== $pdo) {
                    throw new RuntimeException('Worker connection changed.');
                }
                if (!$locked && ($proof = $evidence($query->sql, $query->bindings, $query->connection))) {
                    $locked = true;
                    if (DB::connection()->transactionLevel() !== 1) {
                        throw new RuntimeException('Expected an active primary transaction.');
                    }
                    self::signal($dir, $name.'-locked', $proof);
                    if ($input['hold'] ?? false) {
                        self::await($dir, $name.'-release');
                    }
                }
            });
            self::await($dir, $name.'-start');
            $result = self::request($app, $input['spec'], $input['secrets']);
            self::signal($dir, $name.'-result', $result);
            if (self::$issuedToken !== null) {
                self::await($dir, $name.'-probe');
                \Illuminate\Support\Facades\Auth::forgetGuards();
                \Illuminate\Support\Facades\Auth::shouldUse('web');
                $app['session']->flush();
                $request = Request::create('http://localhost/api/user', 'GET', [], [], [], [
                    'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.self::$issuedToken,
                ]);
                $kernel = $app->make(Kernel::class);
                $response = $kernel->handle($request);
                self::signal($dir, $name.'-probe-result', ['status' => $response->getStatusCode()]);
                $kernel->terminate($request, $response);
            }
        } catch (Throwable $e) {
            self::signal($dir, $name.'-failure', ['type' => 'Worker failed; inspect sanitized phase evidence.']);
        } finally {
            self::$issuedToken = null;
            if ($app) {
                try {
                    while (DB::connection()->transactionLevel() > 0) {
                        DB::rollBack();
                    }
                    DB::purge();
                } catch (Throwable) {
                    // Process exit closes its remaining nonpersistent PDO connection.
                }
                try {
                    $app->flush();
                } finally {
                    self::restoreHandlers($app);
                }
            }
            Carbon::setTestNow($clock);
        }
    }

    public static function provision(array $input): void
    {
        self::guard($input['config']);
        if (($input['confirmation'] ?? '') !== 'PROVISION EMPTY PROCESS10B'
            || empty($input['admin_user']) || !is_string($input['admin_password'] ?? null)) {
            throw new RuntimeException('Provisioning confirmation/input rejected.');
        }
        // Privileged identity is used only by this explicit provisioning command.
        // No default database, environment file, existing schema or dump is opened.
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $input['admin_user'], $input['admin_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
        $pdo->exec('SET SESSION lock_wait_timeout = 5');
        $pdo->exec('SET SESSION max_statement_time = 8');
        $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $check->execute([FixtureSchema::DATABASE]);
        if ((int) $check->fetchColumn() !== 0) {
            throw new RuntimeException('Refusing an existing disposable database.');
        }
        $check = $pdo->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ?');
        $check->execute([FixtureSchema::ACCOUNT]);
        if ((int) $check->fetchColumn() !== 0) {
            throw new RuntimeException('Refusing an existing runner account at any host.');
        }
        // No IF NOT EXISTS: a concurrent provisioning attempt also fails closed.
        $pdo->exec('CREATE DATABASE `doctrack_10b_disposable` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `doctrack_10b_disposable`');
        foreach (FixtureSchema::definitions() as $ddl) {
            $pdo->exec($ddl);
        }
        $pdo->exec("CREATE USER 'doctrack10b_runner'@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($input['config']['password']));
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `doctrack\\_10b\\_disposable`.* TO 'doctrack10b_runner'@'127.0.0.1'");
        self::verifyGrants($pdo->query("SHOW GRANTS FOR 'doctrack10b_runner'@'127.0.0.1'")->fetchAll(PDO::FETCH_NUM));
        fwrite(STDOUT, "Empty schema and restricted account provisioned. No acceptance executed.\n");
    }

    public static function cleanup(array $input): void
    {
        self::guard($input['config']);
        if (($input['confirmation'] ?? '') !== 'DROP DISPOSABLE PROCESS10B') {
            throw new RuntimeException('Cleanup confirmation rejected.');
        }
        // Validate ownership contract and exact schema before the privileged drop.
        $app = self::boot($input['config']);
        FixtureSchema::verify();
        if ((int) DB::selectOne("SELECT GET_LOCK('doctrack_10b_disposable_acceptance', 0) AS acquired")->acquired !== 1) {
            throw new RuntimeException('Refusing cleanup while acceptance owns the database.');
        }
        try {
            $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $input['admin_user'], $input['admin_password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
            foreach (DB::select('SHOW FULL PROCESSLIST') as $thread) {
                if ($thread->db === FixtureSchema::DATABASE && (int) $thread->Id !== (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id) {
                    throw new RuntimeException('Refusing cleanup while other runner connections exist.');
                }
            }
            $pdo->exec('DROP DATABASE `doctrack_10b_disposable`');
            $pdo->exec("DROP USER 'doctrack10b_runner'@'127.0.0.1'");
        } finally {
            DB::purge();
            $app->flush();
            Carbon::setTestNow();
        }
        fwrite(STDOUT, "Named disposable schema/account removed.\n");
    }

    public static function exceptionRegression(string $dir, bool $diagnosticsOnly = false): void
    {
        // Subclass only the isolated operations: exercise the real, final PHPUnit
        // boundaries without invoking Laravel bootstrap, fixtures, or PDO.
        $file = $dir.'/Process10BExceptionProbeTest.php';
        file_put_contents($file, <<<'PHP'
<?php
namespace Tests\Concurrency;

final class Process10BExceptionProbeTest extends Process10BConcurrencyTest
{
    private array $originalHandlers = [];
    public static function setUpBeforeClass(): void {}
    public static function scenarios(): array { return [['probe']]; }
    protected function setUpIsolated(): void
    {
        $phase = $GLOBALS['10b_probe']['phase'];
        if ($phase === 'handler-failure') {
            $this->originalHandlers = Support\Worker::handlerState();
            $config = ['opt_in'=>'PROCESS10B', 'environment'=>'testing', 'host'=>'127.0.0.1',
                'port'=>3306, 'database'=>Support\FixtureSchema::DATABASE,
                'username'=>Support\FixtureSchema::ACCOUNT, 'password'=>'offline-placeholder',
                'app_key'=>'base64:'.base64_encode(random_bytes(32))];
            try {
                Support\Worker::boot($config, static function (string $stage): void {
                    if ($stage === 'register-facades') {
                        throw new \RuntimeException($GLOBALS['10b_probe']['sentinel']);
                    }
                });
            } finally {
                if (Support\Worker::handlerState() !== $this->originalHandlers) {
                    echo "HANDLER-REGRESSION\n";
                }
            }
        }
        if ($phase === 'handler-teardown') {
            $this->originalHandlers = Support\Worker::handlerState();
            $app = new \Illuminate\Foundation\Application(Support\Worker::ROOT);
            Support\Worker::rememberHandlers($app);
            set_error_handler(static fn () => false);
            set_exception_handler(static function (\Throwable $e): void {});
            // Exercise the real final teardown's app-specific restoration.
            (new \ReflectionProperty(Process10BConcurrencyTest::class, 'app'))->setValue($this, $app);
        }
        if ($GLOBALS['10b_probe']['phase'] === 'setup') {
            throw new \RuntimeException($GLOBALS['10b_probe']['sentinel']);
        }
    }
    protected function executeScenario(string $case): void
    {
        $phase = $GLOBALS['10b_probe']['phase'];
        if (str_starts_with($phase, 'diagnostic-')) {
            $this->diagnostic(match ($phase) {
                'diagnostic-overlap' => 'pair.contention-observed',
                'diagnostic-effects' => 'effects.audit-actions',
                'diagnostic-unknown' => $GLOBALS['10b_probe']['sentinel'],
                default => 'oracle.first-response',
            });
            if ($phase === 'diagnostic-exception') {
                throw new \RuntimeException($GLOBALS['10b_probe']['sentinel'], 0,
                    new \RuntimeException($GLOBALS['10b_probe']['sentinel']));
            }
            self::assertSame(['safe'], ['response' => $GLOBALS['10b_probe']['sentinel']],
                $GLOBALS['10b_probe']['sentinel']);
        }
        if ($phase === 'assertion') {
            self::assertSame('safe', $GLOBALS['10b_probe']['sentinel'], $GLOBALS['10b_probe']['sentinel']);
        }
        if (in_array($phase, ['test', 'combined', 'handler-teardown'], true)) {
            throw new \RuntimeException('SQL bindings: '.$GLOBALS['10b_probe']['sentinel'], 0,
                new \RuntimeException($GLOBALS['10b_probe']['sentinel']));
        }
        self::assertTrue(true);
    }
    protected function tearDownIsolated(): void
    {
        try {
            if (in_array($GLOBALS['10b_probe']['phase'], ['teardown', 'combined', 'diagnostic-combined'], true)) {
                throw new \RuntimeException($GLOBALS['10b_probe']['sentinel']);
            }
        } finally {
            echo "10B probe cleanup executed.\n";
            if ($GLOBALS['10b_probe']['phase'] === 'diagnostic-combined') {
                echo "10B diagnostic teardown failure injected.\n";
            }
        }
    }
}
PHP);
        $diagnosticPhases = ['diagnostic-assertion', 'diagnostic-exception', 'diagnostic-overlap',
            'diagnostic-effects', 'diagnostic-unknown', 'diagnostic-combined'];
        $phases = $diagnosticsOnly ? $diagnosticPhases : array_merge(
            ['setup', 'test', 'teardown', 'combined', 'assertion', 'handler-failure', 'handler-teardown'],
            $diagnosticPhases,
        );
        foreach ($phases as $phase) {
            $sentinel = 'SYNTHETIC-SECRET-'.bin2hex(random_bytes(24));
            $stdout = $dir.'/probe-stdout';
            $stderr = $dir.'/probe-stderr';
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', __FILE__],
                [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
                $pipes, self::ROOT, null, ['bypass_shell' => true, 'create_no_window' => true]);
            if (!is_resource($process)) {
                throw new RuntimeException('10B exception regression launch failed.');
            }
            try {
                fwrite($pipes[0], json_encode(['mode' => 'offline-phpunit', 'file' => $file,
                    'phase' => $phase, 'sentinel' => $sentinel], JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
                $deadline = microtime(true) + 15;
                do {
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                if ($status['running']) {
                    throw new RuntimeException('10B exception regression deadline exceeded.');
                }
                $out = file_get_contents($stdout);
                $err = file_get_contents($stderr);
                $expectedPhase = str_starts_with($phase, 'diagnostic-') ? 'test' : match ($phase) {
                    'combined', 'assertion', 'handler-teardown' => 'test',
                    'handler-failure' => 'setup',
                    default => $phase,
                };
                if (!in_array($status['exitcode'], [1, 2], true)
                    || !str_contains($out, '10B '.$expectedPhase.' failed.')
                    || !str_contains($out, '10B probe cleanup executed.')
                    || str_contains($out, $sentinel) || str_contains($err, $sentinel)
                    || str_contains($out, 'HANDLER-REGRESSION')
                    || str_contains($out, 'did not remove its own') || str_contains($out, 'removed error handlers')
                    || str_contains($out, 'removed exception handlers')) {
                    throw new RuntimeException('10B exception-output regression failed: '.$phase);
                }
                if (str_starts_with($phase, 'diagnostic-')) {
                    $id = match ($phase) {
                        'diagnostic-overlap' => 'pair.contention-observed',
                        'diagnostic-effects' => 'effects.audit-actions',
                        'diagnostic-unknown' => 'unclassified',
                        default => 'oracle.first-response',
                    };
                    $category = $phase === 'diagnostic-exception' ? 'exception' : 'assertion';
                    if (!str_contains($out, '[scope=test; id='.$id.'; category='.$category.']')
                        // PHPUnit may retain only the primary assertion when teardown also throws.
                        || ($phase === 'diagnostic-combined' && !str_contains($out, '10B diagnostic teardown failure injected.'))) {
                        throw new RuntimeException('10B fixed diagnostic regression failed.');
                    }
                }
            } finally {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                $until = microtime(true) + 2;
                while (proc_get_status($process)['running'] && microtime(true) < $until) {
                    usleep(10000);
                }
                if (proc_get_status($process)['running']) {
                    throw new RuntimeException('10B regression worker termination failed.');
                }
                proc_close($process);
                // Synthetic exception output is private, checked, never forwarded.
                unlink($stdout);
                unlink($stderr);
            }
        }
        unlink($file);
        fwrite(STDOUT, 'Offline: '.count($phases)." installed-PHPUnit reporting regressions passed; cleanup observed, handlers preserved, nonzero exits, no sentinel on stdout/stderr.\n");
    }

    public static function observationRegression(string $dir): void
    {
        $checks = 0;
        $check = static function (bool $passed) use (&$checks): void {
            if (!$passed) {
                throw new RuntimeException('Offline observation regression failed.');
            }
            $checks++;
        };
        $barriers = $dir.'/observation-'.bin2hex(random_bytes(12));
        mkdir($barriers, 0700);
        $secret = bin2hex(random_bytes(32));
        $sql = 'select * from `documents` where `documents`.`id` = 1 limit 1 for update';
        $base = ['Id' => 22, 'db' => FixtureSchema::DATABASE, 'Command' => 'Execute', 'Info' => $sql];
        $sample = static fn (array $row) => self::contentionSample([(object) ['Id' => 11], (object) $row], 11, 22, 'documents');
        try {
            $prepared = str_replace('= 1', '= ?', $sql);
            $lockProof = self::lockingEvidence($prepared, [1], 'documents', 1, 11, 1);
            $attemptProof = self::lockingEvidence($prepared, ['1'], 'documents', 1, 22, 1);
            $check($lockProof !== null && $attemptProof !== null);
            $verified = self::boundTargetVerified($lockProof, $attemptProof, 11, 22, 'documents');
            $check($verified);
            $threads = [(object) ['Id' => 11], (object) array_replace($base, ['Info' => $prepared])];
            $check(self::contentionObserved(self::contentionSample($threads, 11, 22, 'documents', $verified), $barriers, 1));
            $check(!self::contentionObserved(self::contentionSample($threads, 11, 22, 'documents'), $barriers, 1));
            foreach ([[2], [], [1, 2], ['1junk'], [true], [1.0], [$secret]] as $bindings) {
                $different = self::lockingEvidence($prepared, $bindings, 'documents', 1, 22, 1);
                $check($different === null);
                $bound = self::boundTargetVerified($lockProof, $different ?? [], 11, 22, 'documents');
                $check(!self::contentionObserved(self::contentionSample($threads, 11, 22, 'documents', $bound), $barriers, 1));
            }
            foreach ([['connection' => 23], ['transaction' => 0], ['exact_operation' => false],
                ['synthetic_id' => 2], ['table' => 'users']] as $change) {
                $check(!self::boundTargetVerified($lockProof, array_replace($attemptProof, $change), 11, 22, 'documents'));
                $check(!self::boundTargetVerified(array_replace($lockProof, $change), $attemptProof, 11, 22, 'documents'));
            }
            $check(!self::boundTargetVerified($lockProof, $attemptProof, 11, 11, 'documents'));
            foreach ([str_replace('`id`', '`current_office_id`', $prepared), $prepared.';',
                str_replace(' for update', '', $prepared), str_replace('documents', 'users', $prepared)] as $near) {
                $check(self::lockingEvidence($near, [1], 'documents', 1, 22, 1) === null);
                $nearThreads = [(object) ['Id' => 11], (object) array_replace($base, ['Info' => $near])];
                $check(!self::contentionObserved(self::contentionSample($nearThreads, 11, 22, 'documents', true), $barriers, 1));
            }
            $queryThreads = [(object) ['Id' => 11], (object) array_replace($base, ['Info' => $prepared, 'Command' => 'Query'])];
            $check(!self::contentionObserved(self::contentionSample($queryThreads, 11, 22, 'documents', true), $barriers, 1));
            $check(self::lockingEvidence($prepared, [1], 'documents', 1, 22, 0) === null);
            $check(!str_contains(json_encode($attemptProof), $secret) && !str_contains(json_encode($attemptProof), $prepared));
            self::signal($barriers, 'a-locked', $lockProof);
            self::signal($barriers, 'b-attempt', $attemptProof);
            $check(self::await($barriers, 'a-locked') === $lockProof && self::await($barriers, 'b-attempt') === $attemptProof);
            $check(count(array_filter(self::observationBarriers($barriers))) === 1);
            $shapeOf = static fn ($text) => self::sqlShape([(object) ['Id' => 22, 'Info' => $text]], 22, 'documents');
            foreach ([[$sql, 'statement', 'select'], [$sql, 'expected_table', true],
                [$sql, 'identifier_quoting', 'backtick'], [$sql, 'key_form', 'numeric_literal'],
                [$sql, 'for_update', true], [$sql, 'ends_for_update', true],
                [$sql, 'truncation', 'unknown'], [null, 'text', 'unavailable'], ['', 'text', 'empty'],
                [str_replace('`', '"', $sql), 'identifier_quoting', 'double'],
                [str_replace('`', '', $sql), 'identifier_quoting', 'unquoted'],
                [str_replace('= 1', '= ?', $sql), 'key_form', 'placeholder'],
                [str_replace('= 1', '= :value', $sql), 'key_form', 'placeholder'],
                [str_replace('= 1', "= '".$secret."'", $sql), 'key_form', 'quoted_literal'],
                [str_replace('documents', 'users', $sql), 'expected_table', false],
                [str_replace(' for update', '', $sql), 'for_update', false],
                [substr($sql, 0, -5).'...', 'ends_ellipsis', true],
                ['update `documents` set value = 1', 'statement', 'update'],
                [$secret, 'statement', 'other']] as [$text, $flag, $expected]) {
                $shape = $shapeOf($text);
                $check($shape[$flag] === $expected);
                $check(!str_contains(json_encode($shape), $secret) && !str_contains(json_encode($shape), $sql));
            }
            $check(self::sqlShape([(object) ['Id' => 23, 'Info' => $sql]], 22, 'documents')['text'] === 'unobserved');
            $check(self::observationBarriers($barriers)['observed']);
            $check(!self::observationBarriers($barriers.'/missing')['observed']);
            foreach (['Query', 'Execute'] as $command) {
                $flags = $sample(array_replace($base, ['Command' => $command]));
                $check($flags['b_sql_matches']); // SQL classification is independent of Command.
                $check(self::contentionObserved($flags, $barriers, 1));
            }
            foreach ([['Command' => 'Sleep'], ['Command' => 'Prepare'], ['Command' => $secret],
                ['Id' => 23], ['db' => $secret], ['Info' => null], ['Info' => $secret],
                ['Info' => str_replace('= 1', '= 2', $sql)],
                ['Info' => str_replace('= 1', '= ?', $sql)],
                ['Info' => str_replace('documents', 'users', $sql)],
                ['Info' => str_replace(' for update', '', $sql)],
                ['Info' => $sql.'; select 1'], ['Info' => $sql.' /* unrelated */']] as $change) {
                $flags = $sample(array_replace($base, $change));
                $check(!self::contentionObserved($flags, $barriers, 1));
                $check(!str_contains(json_encode($flags), $secret));
            }
            $flags = $sample($base);
            $check(!$flags['b_command_query'] && $flags['b_command_execute'] && $flags['b_sql_matches']);
            $check(!self::contentionObserved(array_replace($flags, ['b_sql_matches' => false]), $barriers, 1));
            $check(!self::contentionObserved(array_replace($flags, ['a_visible' => false]), $barriers, 1));
            foreach ([0, 2] as $transaction) {
                $check(!self::contentionObserved($flags, $barriers, $transaction));
            }
            $check(!self::contentionObserved($flags, $barriers.'/missing', 1));
            foreach (['a-release', 'a-result', 'a-failure', 'b-locked', 'b-result', 'b-failure', 'b-sql-error'] as $marker) {
                $path = $barriers.'/'.$marker.'.json';
                file_put_contents($path, $secret);
                $check(self::observationBarriers($barriers)[$marker]);
                $check(!self::contentionObserved($flags, $barriers, 1));
                unlink($path);
                $check(!self::observationBarriers($barriers)[$marker]);
                $check(self::contentionObserved($flags, $barriers, 1));
            }
        } finally {
            foreach (glob($barriers.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($barriers);
        }
        fwrite(STDOUT, 'Offline: '.$checks." observation checks passed. No PDO opened.\n");
    }

    public static function offline(array $input): void
    {
        $c = $input['config'];
        $c['password'] = 'offline-placeholder';
        self::guard($c);
        $checks = 1;
        foreach (['database' => 'doctrack_tuao', 'username' => 'root', 'host' => 'localhost',
            'environment' => 'production', 'opt_in' => '', 'url' => 'mysql://invalid', 'port' => 3307,
            'read' => ['host' => '127.0.0.1'], 'write' => ['host' => '127.0.0.1'], 'password' => ''] as $key => $value) {
            $rejected = false;
            try {
                self::guard(array_replace($c, [$key => $value]));
            } catch (RuntimeException) {
                $rejected = true;
            }
            if (!$rejected) {
                throw new RuntimeException('Offline guard check failed.');
            }
            $checks++;
        }
        $grant = "GRANT SELECT, INSERT, UPDATE, DELETE ON `doctrack\\_10b\\_disposable`.* TO `doctrack10b_runner`@`127.0.0.1`";
        self::verifyGrants([[$grant], ["GRANT USAGE ON *.* TO `doctrack10b_runner`@`127.0.0.1`"]]);
        foreach ([str_replace('SELECT, INSERT, UPDATE, DELETE', 'ALL PRIVILEGES', $grant),
            $grant.' WITH GRANT OPTION', 'GRANT PROCESS ON *.* TO runner',
            str_replace('doctrack\\_10b\\_disposable', 'doctrack_tuao', $grant)] as $bad) {
            $rejected = false;
            try {
                self::verifyGrants([[$bad]]);
            } catch (RuntimeException) {
                $rejected = true;
            }
            if (!$rejected) {
                throw new RuntimeException('Offline grants check failed.');
            }
            $checks++;
        }
        $dir = $input['private_directory'];
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', __FILE__],
            [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, self::ROOT,
            null, ['bypass_shell' => true, 'create_no_window' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Offline child launch failed.');
        }
        try {
            fwrite($pipes[0], json_encode(['mode' => 'offline-child', 'dir' => $dir], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            self::await($dir, 'offline-ready');
            if (is_file($dir.'/offline-done.json')) {
                throw new RuntimeException('Offline barrier did not hold.');
            }
            self::signal($dir, 'offline-release');
            self::await($dir, 'offline-done');
            $expired = false;
            try {
                self::await($dir, 'never-created', 0.03);
            } catch (RuntimeException) {
                $expired = true;
            }
            if (!$expired) {
                throw new RuntimeException('Offline deadline did not fire.');
            }
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
        fwrite(STDOUT, 'Offline: '.$checks." guard/grant checks plus independent-process barrier/deadline checks passed. No PDO opened.\n");
        self::observationRegression($dir);
        self::exceptionRegression($dir);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require Worker::ROOT.'/vendor/autoload.php';
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    ini_set('zend.exception_ignore_args', '1');
    try {
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        switch ($input['mode'] ?? 'worker') {
            case 'offline': Worker::offline($input); break;
            case 'offline-phpunit':
                $GLOBALS['10b_probe'] = $input;
                exit((new \PHPUnit\TextUI\Application)->run([
                    'phpunit', '--no-configuration', '--no-coverage', '--do-not-cache-result',
                    '--colors=never', $input['file'],
                ]));
            case 'offline-child':
                Worker::signal($input['dir'], 'offline-ready');
                Worker::await($input['dir'], 'offline-release');
                Worker::signal($input['dir'], 'offline-done');
                break;
            case 'provision': Worker::provision($input); break;
            case 'cleanup': Worker::cleanup($input); break;
            case 'worker': Worker::run($input); break;
            default: throw new RuntimeException('Unknown 10B command.');
        }
    } catch (Throwable) {
        fwrite(STDERR, "10B operation failed; raw exceptions suppressed. Provisioning DDL is not transactional; inspect only the named disposable resources before retrying.\n");
        exit(1);
    }
}
