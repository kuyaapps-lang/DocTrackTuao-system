<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DevelopmentSeederCredentialSecurityTest extends TestCase
{
    private const CONFIGURATION_KEYS = [
        'administrator',
        'records_officer',
        'office_user',
        'viewer',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('env', 'local');
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach (['users', 'offices', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_tracked_development_credential_files_have_no_plaintext_values(): void
    {
        $root = base_path();
        $seeder = file_get_contents($root.'/database/seeders/DevelopmentSeeder.php');
        $configuration = file_get_contents($root.'/config/development.php');
        $example = file_get_contents($root.'/.env.example');

        $this->assertIsString($seeder);
        $this->assertIsString($configuration);
        $this->assertIsString($example);
        $this->assertDoesNotMatchRegularExpression(
            "/'password'\s*=>\s*'[^']+'/",
            $seeder
        );
        $this->assertDoesNotMatchRegularExpression(
            "/DEV_SEED_[A-Z_]+_PASSWORD',\s*[^)]+/",
            $configuration
        );

        foreach ($this->environmentVariableNames() as $variable) {
            $this->assertSame(1, preg_match(
                '/^'.preg_quote($variable, '/').'=$/m',
                $example
            ));
        }
    }

    public function test_missing_configuration_fails_before_database_writes(): void
    {
        $passwords = $this->configureGeneratedPasswords();
        config()->set('development.seeded_accounts.viewer.password', null);
        $existingUser = User::create([
            'name' => 'Existing User',
            'email' => 'existing@example.test',
            'password' => Hash::make(Str::random(24)),
        ]);
        $beforePasswordHash = $existingUser->password;

        try {
            (new DevelopmentSeeder())->run();
            $this->fail('The seeder accepted missing credential configuration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Required development password configuration is missing or invalid',
                $exception->getMessage()
            );
            $this->assertTrue(collect($passwords)->every(
                fn (string $password): bool => ! str_contains(
                    $exception->getMessage(),
                    $password
                )
            ));
        }

        $this->assertSame(1, User::count());
        $this->assertSame(0, Schema::getConnection()
            ->table('offices')->count());
        $this->assertTrue(hash_equals(
            $beforePasswordHash,
            $existingUser->fresh()->password
        ));
    }

    public function test_generated_configuration_is_hashed_without_plaintext_persistence(): void
    {
        $passwords = $this->configureGeneratedPasswords();
        $roles = collect([
            'Administrator',
            'Records Officer',
            'Office User',
            'Viewer',
        ])->mapWithKeys(function (string $name): array {
            $role = Role::create(['name' => $name]);

            return [$name => $role];
        });

        (new DevelopmentSeeder())->run();

        $users = User::orderBy('id')->get();
        $this->assertCount(4, $users);
        $this->assertTrue($users->every(function (User $user) use (
            $passwords,
            $roles
        ): bool {
            $category = $roles->first(
                fn (Role $role): bool => $role->id === $user->role_id
            )?->name;
            $configurationKey = match ($category) {
                'Administrator' => 'administrator',
                'Records Officer' => 'records_officer',
                'Office User' => 'office_user',
                'Viewer' => 'viewer',
                default => null,
            };

            return $configurationKey !== null
                && Hash::check($passwords[$configurationKey], $user->password);
        }));
        $this->assertTrue($users->every(
            fn (User $user): bool => ! in_array(
                $user->password,
                $passwords,
                true
            )
        ));
    }

    private function configureGeneratedPasswords(): array
    {
        $passwords = [];

        foreach (self::CONFIGURATION_KEYS as $key) {
            $passwords[$key] = Str::random(24);
            config()->set(
                "development.seeded_accounts.{$key}.password",
                $passwords[$key]
            );
        }

        return $passwords;
    }

    private function environmentVariableNames(): array
    {
        return [
            'DEV_SEED_ADMINISTRATOR_PASSWORD',
            'DEV_SEED_RECORDS_OFFICER_PASSWORD',
            'DEV_SEED_OFFICE_USER_PASSWORD',
            'DEV_SEED_VIEWER_PASSWORD',
        ];
    }

    private function createSchema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('office_name');
            $table->string('office_code')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }
}
