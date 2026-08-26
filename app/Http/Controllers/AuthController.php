<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * LOGIN
     */
    public function login(Request $request, AuditLogger $auditLogger)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $user = Auth::user();

        $token = $user->createToken('auth-token')->plainTextToken;

        $auditLogger->log(
            module: AuditLog::MODULE_AUTHENTICATION,
            action: AuditLog::ACTION_LOGIN,
            recordId: $user->id,
            description: 'User logged in successfully.',
            userId: $user->id
        );

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * LOGOUT
     */
    public function logout(Request $request, AuditLogger $auditLogger)
    {
        $user = $request->user();

        $user->currentAccessToken()->delete();

        $auditLogger->log(
            module: AuditLog::MODULE_AUTHENTICATION,
            action: AuditLog::ACTION_LOGOUT,
            recordId: $user->id,
            description: 'User logged out successfully.',
            userId: $user->id
        );

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
