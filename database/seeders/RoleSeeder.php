<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::updateOrCreate(
            ['name' => 'Administrator'],
            [
                'description' => 'Full access to system administration, users, documents, routing, reports, and system settings.',
            ]
        );

        Role::updateOrCreate(
            ['name' => 'Records Officer'],
            [
                'description' => 'Registers, manages, routes, receives, and monitors documents and their movement.',
            ]
        );

        Role::updateOrCreate(
            ['name' => 'Office User'],
            [
                'description' => 'Handles documents routed to the assigned office, including receiving, forwarding, and adding remarks.',
            ]
        );

        Role::updateOrCreate(
            ['name' => 'Viewer'],
            [
                'description' => 'View-only access for document tracking and permitted document information.',
            ]
        );
    }
}