<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    /**
     * LOGIN
     */
    public function login(Request $request, AuditLogger $auditLogger)
    {
        $policy = $this->authenticationPolicy();

        if ($policy === null) {
            return response()->json([
                'message' => 'Authentication is temporarily unavailable.',
            ], 500);
        }

        $limiterKey = $this->loginLimiterKey($request);

        if (RateLimiter::tooManyAttempts(
            $limiterKey,
            $policy['login_max_attempts']
        )) {
            return response()->json([
                'message' => 'Too many login attempts. Please try again later.',
            ], 429);
        }

        // Count every accepted login request, including malformed, failed, and
        // successful attempts. This prevents rapid successful re-authentication
        // from bypassing the request boundary.
        RateLimiter::hit(
            $limiterKey,
            $policy['login_decay_seconds']
        );

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
        $expiresAt = CarbonImmutable::now()->addMinutes(
            $policy['token_lifetime_minutes']
        );

        $token = DB::transaction(function () use (
            $user,
            $credentials,
            $expiresAt,
            $policy,
            $auditLogger
        ): ?array {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->first();

            if (
                !$lockedUser ||
                !Hash::check($credentials['password'], $lockedUser->password)
            ) {
                return null;
            }

            $lockedUser->tokens()->delete();

            $plainTextToken = $lockedUser->createToken(
                $policy['token_name'],
                ['*'],
                $expiresAt
            )->plainTextToken;

            $auditLogger->log(
                module: AuditLog::MODULE_AUTHENTICATION,
                action: AuditLog::ACTION_LOGIN,
                recordId: $lockedUser->id,
                description: 'User logged in successfully.',
                userId: $lockedUser->id
            );

            return [
                'user' => $lockedUser,
                'token' => $plainTextToken,
            ];
        });

        if ($token === null) {
            Auth::guard()->logout();

            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        return response()->json([
            'message' => 'Login successful',
            'user' => $token['user'],
            'token' => $token['token'],
            'token_type' => 'Bearer',
        ]);
    }

    private function loginLimiterKey(Request $request): string
    {
        $email = $request->input('email');
        $normalizedEmail = is_string($email)
            ? mb_strtolower(trim($email))
            : '[invalid-email-input]';

        return 'login:'.hash(
            'sha256',
            $normalizedEmail."\0".$request->ip()
        );
    }

    private function authenticationPolicy(): ?array
    {
        $maxAttempts = $this->configuredInteger(
            'authentication.login_max_attempts',
            1,
            100
        );
        $decaySeconds = $this->configuredInteger(
            'authentication.login_decay_seconds',
            1,
            3600
        );
        $lifetimeMinutes = $this->configuredInteger(
            'authentication.token_lifetime_minutes',
            1,
            43200
        );
        $tokenName = config('authentication.token_name');

        if (
            $maxAttempts === null ||
            $decaySeconds === null ||
            $lifetimeMinutes === null ||
            !is_string($tokenName) ||
            trim($tokenName) === '' ||
            mb_strlen($tokenName) > 255 ||
            preg_match('/[\x00-\x1F\x7F]/', $tokenName)
        ) {
            return null;
        }

        return [
            'login_max_attempts' => $maxAttempts,
            'login_decay_seconds' => $decaySeconds,
            'token_lifetime_minutes' => $lifetimeMinutes,
            'token_name' => trim($tokenName),
        ];
    }

    private function configuredInteger(
        string $key,
        int $minimum,
        int $maximum
    ): ?int {
        $value = config($key);

        if (is_string($value) && preg_match('/\A\d+\z/', $value)) {
            $value = (int) $value;
        }

        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            return null;
        }

        return $value;
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
