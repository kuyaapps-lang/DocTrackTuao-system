<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountPasswordController extends Controller
{
    public function update(
        Request $request,
        AuditLogger $auditLogger
    ): JsonResponse {
        $validated = $request->validate([
            'current_password' => [
                'required',
                'string',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        $user = $request->user();

        DB::transaction(function () use (
            $user,
            $validated,
            $auditLogger
        ): void {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!Hash::check(
                $validated['current_password'],
                $lockedUser->password
            )) {
                throw ValidationException::withMessages([
                    'current_password' => [
                        'The current password is incorrect.',
                    ],
                ]);
            }

            if (Hash::check(
                $validated['password'],
                $lockedUser->password
            )) {
                throw ValidationException::withMessages([
                    'password' => [
                        'The new password must be different from the current password.',
                    ],
                ]);
            }

            $lockedUser->password = Hash::make($validated['password']);
            $lockedUser->must_change_password = false;
            $lockedUser->save();

            $auditLogger->log(
                module: AuditLog::MODULE_AUTHENTICATION,
                action: AuditLog::ACTION_PASSWORD_CHANGED,
                recordId: $lockedUser->id,
                description: 'User changed password successfully.',
                userId: $lockedUser->id
            );

            $lockedUser->tokens()->delete();
        });

        return response()->json([
            'message' => 'Password changed successfully. Please log in again.',
        ]);
    }
}
