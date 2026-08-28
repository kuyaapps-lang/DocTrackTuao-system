<?php

namespace Database\Seeders;

use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException(
                'DevelopmentSeeder may only be used in the local development environment.'
            );
        }

        $accountConfiguration = [
            'Administrator' => config('development.seeded_accounts.administrator'),
            'Records Officer' => config('development.seeded_accounts.records_officer'),
            'Office User' => config('development.seeded_accounts.office_user'),
            'Viewer' => config('development.seeded_accounts.viewer'),
        ];

        foreach ($accountConfiguration as $category => $configuration) {
            $password = $configuration['password'] ?? null;

            if (! is_string($password) || mb_strlen($password) < 8) {
                throw new RuntimeException(
                    "Required development password configuration is missing or invalid for the {$category} account."
                );
            }
        }

        $requiredRoles = array_keys($accountConfiguration);

        $roles = Role::whereIn('name', $requiredRoles)
            ->pluck('id', 'name');

        foreach ($requiredRoles as $roleName) {
            if (! $roles->has($roleName)) {
                throw new RuntimeException(
                    "Missing role: {$roleName}. Run RoleSeeder first."
                );
            }
        }

        $budgetOffice = Office::updateOrCreate(
            ['office_code' => 'MBO'],
            [
                'office_name' => 'Municipal Budget Office',
                'department_id' => null,
                'description' => 'Municipal Budget Office',
            ]
        );

        $accountingOffice = Office::updateOrCreate(
            ['office_name' => 'Municipal Accounting Office'],
            [
                'office_code' => 'MAO',
                'department_id' => null,
                'description' => 'Municipal Accounting Office',
            ]
        );

        $users = [
            [
                'name' => 'Admin',
                'email' => 'admin@test.com',
                'role' => 'Administrator',
                'office' => $budgetOffice,
                'password' => $accountConfiguration['Administrator']['password'],
            ],
            [
                'name' => 'Records Officer Test',
                'email' => 'recordsofficer@test.com',
                'role' => 'Records Officer',
                'office' => $accountingOffice,
                'password' => $accountConfiguration['Records Officer']['password'],
            ],
            [
                'name' => 'Office User Test',
                'email' => 'officeuser@test.com',
                'role' => 'Office User',
                'office' => $budgetOffice,
                'password' => $accountConfiguration['Office User']['password'],
            ],
            [
                'name' => 'Viewer Test',
                'email' => 'viewer@test.com',
                'role' => 'Viewer',
                'office' => $budgetOffice,
                'password' => $accountConfiguration['Viewer']['password'],
            ],
        ];

        foreach ($users as $item) {
            User::updateOrCreate(
                ['email' => $item['email']],
                [
                    'name' => $item['name'],
                    'password' => Hash::make($item['password']),
                    'role_id' => $roles[$item['role']],
                    'office_id' => $item['office']->id,
                    'department_id' => $item['office']->department_id,
                ]
            );
        }

        $this->command?->info(
            'Development offices and test users are ready.'
        );
    }
}
