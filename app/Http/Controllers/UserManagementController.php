<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserManagementController extends Controller
{
    private const SUPPORTED_ROLE_NAMES = [
        'Administrator',
        'Records Officer',
        'Office User',
        'Viewer',
    ];

    private const MUTATION_FIELDS = [
        'name',
        'email',
        'role_id',
        'office_id',
        'password',
        'password_confirmation',
    ];

    public function index(): JsonResponse
    {
        $users = User::query()
            ->with([
                'role',
                'office',
            ])
            ->orderBy('name')
            ->get();

        return response()->json(
            $users->map(fn (User $user): array => $this->userShape($user))->values()
        );
    }

    public function formOptions(): JsonResponse
    {
        return response()->json([
            'roles' => Role::query()
                ->whereIn('name', self::SUPPORTED_ROLE_NAMES)
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                ])
                ->filter(fn (Role $role): bool => in_array(
                    $role->name,
                    self::SUPPORTED_ROLE_NAMES,
                    true
                ))
                ->map(fn (Role $role): array => $this->roleShape($role))
                ->values(),

            'offices' => Office::query()
                ->orderBy('office_name')
                ->get([
                    'id',
                    'office_name',
                    'office_code',
                    'department_id',
                ])
                ->map(fn (Office $office): array => $this->officeShape($office))
                ->values(),
        ]);
    }

    public function store(
        Request $request,
        AuditLogger $auditLogger
    ): JsonResponse
    {
        $this->rejectUnknownMutationFields($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'role_id' => [
                'required',
                'integer',
                'exists:roles,id',
            ],
            'office_id' => [
                'required',
                'integer',
                'exists:offices,id',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        $this->supportedRole((int) $validated['role_id']);

        $office = Office::query()->findOrFail(
            $validated['office_id']
        );

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make(
                $validated['password']
            ),
            'role_id' => $validated['role_id'],
            'department_id' => $office->department_id,
            'office_id' => $office->id,
        ]);

        $auditLogger->log(
            module: AuditLog::MODULE_USERS,
            action: AuditLog::ACTION_CREATED,
            recordId: $user->id,
            description: 'Changed fields: name, email, role_id, office_id, department_id; password changed: yes.',
            userId: $request->user()->id
        );

        return response()->json([
            'message' => 'User created successfully.',
            'user' => $this->userShape($user->load([
                'role',
                'office',
            ])),
        ], 201);
    }

    public function update(
        Request $request,
        User $user,
        AuditLogger $auditLogger
    ): JsonResponse {
        $this->rejectUnknownMutationFields($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->ignore($user->id),
            ],
            'role_id' => [
                'required',
                'integer',
                'exists:roles,id',
            ],
            'office_id' => [
                'required',
                'integer',
                'exists:offices,id',
            ],
            'password' => [
                'nullable',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        $this->supportedRole((int) $validated['role_id']);

        if (
            $request->user()->is($user) &&
            (int) $validated['role_id'] !==
                (int) $user->role_id
        ) {
            return response()->json([
                'message' => 'You cannot change your own role.',
            ], 422);
        }

        $office = Office::query()->findOrFail(
            $validated['office_id']
        );

        $changedFields = [];
        $newValues = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role_id' => (int) $validated['role_id'],
            'office_id' => (int) $office->id,
            'department_id' => $office->department_id === null
                ? null
                : (int) $office->department_id,
        ];

        $passwordChanged = !empty($validated['password']);

        DB::transaction(function () use (
            $user,
            $validated,
            $office,
            $passwordChanged,
            $newValues,
            &$changedFields,
            $auditLogger,
            $request
        ): void {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            foreach ($newValues as $field => $value) {
                $currentValue = $lockedUser->getAttribute($field);

                if (in_array($field, ['role_id', 'office_id', 'department_id'], true)) {
                    $currentValue = $currentValue === null
                        ? null
                        : (int) $currentValue;
                }

                if ($currentValue !== $value) {
                    $changedFields[] = $field;
                }
            }

            $lockedUser->name = $validated['name'];
            $lockedUser->email = $validated['email'];
            $lockedUser->role_id = $validated['role_id'];
            $lockedUser->department_id = $office->department_id;
            $lockedUser->office_id = $office->id;

            if ($passwordChanged) {
                $lockedUser->password = Hash::make(
                    $validated['password']
                );
            }

            $lockedUser->save();

            $securitySensitiveChange = $passwordChanged ||
                array_intersect(
                    $changedFields,
                    ['email', 'role_id', 'office_id']
                ) !== [];

            if ($securitySensitiveChange) {
                $lockedUser->tokens()->delete();
            }

            $auditLogger->log(
                module: AuditLog::MODULE_USERS,
                action: AuditLog::ACTION_UPDATED,
                recordId: $lockedUser->id,
                description: sprintf(
                    'Changed fields: %s; password changed: %s.',
                    $changedFields === []
                        ? 'none'
                        : implode(', ', $changedFields),
                    $passwordChanged ? 'yes' : 'no'
                ),
                userId: $request->user()->id
            );

            $user->setRawAttributes($lockedUser->getAttributes(), true);
        });

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $this->userShape($user->load([
                'role',
                'office',
            ])),
        ]);
    }

    private function rejectUnknownMutationFields(Request $request): void
    {
        if (array_diff(array_keys($request->all()), self::MUTATION_FIELDS) !== []) {
            throw ValidationException::withMessages([
                'request' => ['The request contains unsupported fields.'],
            ]);
        }
    }

    private function supportedRole(int $id): Role
    {
        $role = Role::query()->find($id);

        if (!$role || !in_array($role->name, self::SUPPORTED_ROLE_NAMES, true)) {
            throw ValidationException::withMessages([
                'role_id' => ['The selected role is invalid.'],
            ]);
        }

        return $role;
    }

    private function userShape(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role_id' => $user->role_id === null ? null : (int) $user->role_id,
            'department_id' => $user->department_id === null
                ? null
                : (int) $user->department_id,
            'office_id' => $user->office_id === null ? null : (int) $user->office_id,
            'role' => $user->role ? $this->roleShape($user->role) : null,
            'office' => $user->office ? $this->officeShape($user->office) : null,
        ];
    }

    private function roleShape(Role $role): array
    {
        return [
            'id' => (int) $role->id,
            'name' => (string) $role->name,
        ];
    }

    private function officeShape(Office $office): array
    {
        return [
            'id' => (int) $office->id,
            'office_name' => (string) $office->office_name,
            'office_code' => (string) $office->office_code,
            'department_id' => $office->department_id === null
                ? null
                : (int) $office->department_id,
        ];
    }
}
