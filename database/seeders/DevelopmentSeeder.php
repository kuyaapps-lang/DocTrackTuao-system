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

        $requiredRoles = [
            'Administrator',
            'Records Officer',
            'Office User',
            'Viewer',
        ];

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
            ['office_code' => 'MACCO'],
            [
                'office_name' => 'Municipal Accounting Office',
                'department_id' => null,
                'description' => 'Municipal Accounting Office',
            ]
        );

        $users = [
            [
                'name' => 'Admin',
                'email' => 'admin@test.com',
                'password' => 'Admin123!',
                'role' => 'Administrator',
                'office' => $budgetOffice,
            ],
            [
                'name' => 'Records Officer Test',
                'email' => 'recordsofficer@test.com',
                'password' => 'Records123!',
                'role' => 'Records Officer',
                'office' => $accountingOffice,
            ],
            [
                'name' => 'Office User Test',
                'email' => 'officeuser@test.com',
                'password' => 'Office123!',
                'role' => 'Office User',
                'office' => $budgetOffice,
            ],
            [
                'name' => 'Viewer Test',
                'email' => 'viewer@test.com',
                'password' => 'Viewer123!',
                'role' => 'Viewer',
                'office' => $budgetOffice,
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
