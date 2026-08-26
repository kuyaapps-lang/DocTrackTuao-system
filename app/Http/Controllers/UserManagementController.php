<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with([
                'role',
                'office',
                'department',
            ])
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    public function formOptions(): JsonResponse
    {
        return response()->json([
            'roles' => Role::query()
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'description',
                ]),

            'offices' => Office::query()
                ->orderBy('office_name')
                ->get([
                    'id',
                    'office_name',
                    'office_code',
                    'department_id',
                ]),
        ]);
    }

    public function store(
        Request $request,
        AuditLogger $auditLogger
    ): JsonResponse
    {
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
            'user' => $user->load([
                'role',
                'office',
                'department',
            ]),
        ], 201);
    }

    public function update(
        Request $request,
        User $user,
        AuditLogger $auditLogger
    ): JsonResponse {
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

        foreach ($newValues as $field => $value) {
            $currentValue = $user->getAttribute($field);

            if (in_array($field, ['role_id', 'office_id', 'department_id'], true)) {
                $currentValue = $currentValue === null
                    ? null
                    : (int) $currentValue;
            }

            if ($currentValue !== $value) {
                $changedFields[] = $field;
            }
        }

        $passwordChanged = !empty($validated['password']);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role_id = $validated['role_id'];
        $user->department_id = $office->department_id;
        $user->office_id = $office->id;

        if ($passwordChanged) {
            $user->password = Hash::make(
                $validated['password']
            );
        }

        $user->save();

        $auditLogger->log(
            module: AuditLog::MODULE_USERS,
            action: AuditLog::ACTION_UPDATED,
            recordId: $user->id,
            description: sprintf(
                'Changed fields: %s; password changed: %s.',
                $changedFields === []
                    ? 'none'
                    : implode(', ', $changedFields),
                $passwordChanged ? 'yes' : 'no'
            ),
            userId: $request->user()->id
        );

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $user->load([
                'role',
                'office',
                'department',
            ]),
        ]);
    }
}
