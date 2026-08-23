<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Models\Role;
use App\Models\User;
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

    public function store(Request $request): JsonResponse
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
        User $user
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

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role_id = $validated['role_id'];
        $user->department_id = $office->department_id;
        $user->office_id = $office->id;

        if (!empty($validated['password'])) {
            $user->password = Hash::make(
                $validated['password']
            );
        }

        $user->save();

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
