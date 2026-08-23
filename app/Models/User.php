<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Permissions used by the application.
     *
     * Keep permission names here so controllers/routes do not depend on
     * hard-coded role IDs.
     */
    public const PERMISSIONS = [
        'documents.view',
        'documents.create',
        'documents.edit',
        'documents.delete',
        'documents.process',
        'documents.route',
        'attachments.view',
        'attachments.manage',
        'qr.view',
        'qr.manage',
        'master_data.view',
        'master_data.manage',
        'users.manage',
        'reports.view',
        'audit.view',
    ];

    /**
     * Central role-to-permission map.
     *
     * Office-level restrictions remain in the document processing/routing
     * controllers. A permission answers WHAT a user may do; office scope
     * answers WHICH documents the user may act on.
     */
    public const ROLE_PERMISSIONS = [
        'Administrator' => ['*'],

        'Records Officer' => [
            'documents.view',
            'documents.create',
            'documents.edit',
            'documents.process',
            'documents.route',
            'attachments.view',
            'attachments.manage',
            'qr.view',
            'qr.manage',
            'master_data.view',
            'reports.view',
            'audit.view',
        ],

        'Office User' => [
            'documents.view',
            'documents.process',
            'documents.route',
            'attachments.view',
            'attachments.manage',
            'master_data.view',
            'reports.view',
        ],

        'Viewer' => [
            'documents.view',
            'attachments.view',
            'master_data.view',
            'reports.view',
        ],
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'department_id',
        'office_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /*
    |--------------------------------------------------------------------------
    | RBAC Helpers
    |--------------------------------------------------------------------------
    */

    public function hasRole(string $roleName): bool
    {
        return $this->role?->name === $roleName;
    }

    public function hasPermission(string $permission): bool
    {
        $roleName = $this->role?->name;

        if (!$roleName) {
            return false;
        }

        $permissions = self::ROLE_PERMISSIONS[$roleName] ?? [];

        return in_array('*', $permissions, true)
            || in_array($permission, $permissions, true);
    }

    public function permissionNames(): array
    {
        $roleName = $this->role?->name;

        if (!$roleName) {
            return [];
        }

        $permissions = self::ROLE_PERMISSIONS[$roleName] ?? [];

        if (in_array('*', $permissions, true)) {
            return self::PERMISSIONS;
        }

        return array_values(
            array_intersect(
                self::PERMISSIONS,
                $permissions
            )
        );
    }
}