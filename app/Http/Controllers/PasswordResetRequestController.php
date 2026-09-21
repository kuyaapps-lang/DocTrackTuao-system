<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PasswordResetRequestController extends Controller
{
    private const GENERIC_PUBLIC_MESSAGE =
        'If the account exists, an administrator will review the password reset request.';

    public function store(
        Request $request,
        AuditLogger $auditLogger
    ): JsonResponse {
        if (RateLimiter::tooManyAttempts($this->limiterKey($request), 5)) {
            return response()->json([
                'message' => 'Too many requests. Please try again later.',
            ], 429);
        }

        RateLimiter::hit($this->limiterKey($request), 600);

        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
            ],
            'name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'message' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $email = $this->normalizeEmail($validated['email']);
        $name = $this->nullableTrimmed($validated['name'] ?? null);
        $message = $this->nullableTrimmed($validated['message'] ?? null);

        $resetRequest = DB::transaction(function () use (
            $request,
            $email,
            $name,
            $message
        ): PasswordResetRequest {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            $resetRequest = PasswordResetRequest::query()
                ->where('email', $email)
                ->where('status', PasswordResetRequest::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'user_id' => $user?->id,
                'email' => $email,
                'name' => $name,
                'message' => $message,
                'status' => PasswordResetRequest::STATUS_PENDING,
                'requested_ip' => $request->ip(),
                'requested_user_agent' => $this->boundedUserAgent($request),
            ];

            if ($resetRequest) {
                $resetRequest->fill($attributes);
                $resetRequest->save();

                return $resetRequest;
            }

            return PasswordResetRequest::query()->create($attributes);
        });

        $auditLogger->log(
            module: AuditLog::MODULE_PASSWORD_RESET_REQUESTS,
            action: AuditLog::ACTION_PASSWORD_RESET_REQUESTED,
            recordId: $resetRequest->id,
            description: 'Password reset request submitted.',
            userId: null
        );

        return response()->json([
            'message' => self::GENERIC_PUBLIC_MESSAGE,
        ], 202);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => [
                'sometimes',
                'string',
                Rule::in(PasswordResetRequest::STATUSES),
            ],
            'per_page' => [
                'sometimes',
                'integer',
                Rule::in([10, 25, 50]),
            ],
        ]);

        $requests = PasswordResetRequest::query()
            ->with([
                'user:id,name,email',
                'resolvedBy:id,name',
            ])
            ->when(
                isset($validated['status']),
                fn ($query) => $query->where('status', $validated['status'])
            )
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        $requests->through(
            fn (PasswordResetRequest $resetRequest): array =>
                $this->resetRequestShape($resetRequest)
        );

        return response()->json($requests);
    }

    public function resolve(
        Request $request,
        PasswordResetRequest $passwordResetRequest,
        AuditLogger $auditLogger
    ): JsonResponse {
        $validated = $request->validate([
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
            'resolution_note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $updatedRequest = DB::transaction(function () use (
            $passwordResetRequest,
            $validated,
            $request
        ): PasswordResetRequest {
            $lockedRequest = PasswordResetRequest::query()
                ->whereKey($passwordResetRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== PasswordResetRequest::STATUS_PENDING) {
                abort(response()->json([
                    'message' => 'This password reset request is already closed.',
                ], 409));
            }

            if ($lockedRequest->user_id === null) {
                abort(response()->json([
                    'message' => 'This password reset request is not linked to an active user.',
                ], 422));
            }

            $user = User::query()
                ->whereKey($lockedRequest->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $user->password = Hash::make($validated['password']);
            $user->must_change_password = true;
            $user->save();
            $user->tokens()->delete();

            $lockedRequest->status = PasswordResetRequest::STATUS_RESOLVED;
            $lockedRequest->resolved_by_user_id = $request->user()->id;
            $lockedRequest->resolved_at = Carbon::now();
            $lockedRequest->resolution_note =
                $this->nullableTrimmed($validated['resolution_note'] ?? null);
            $lockedRequest->save();

            return $lockedRequest;
        });

        $auditLogger->log(
            module: AuditLog::MODULE_PASSWORD_RESET_REQUESTS,
            action: AuditLog::ACTION_PASSWORD_RESET_REQUEST_RESOLVED,
            recordId: $updatedRequest->id,
            description: 'Password reset request resolved with a temporary password.',
            userId: $request->user()->id
        );

        return response()->json([
            'message' => 'Password reset request resolved successfully.',
            'password_reset_request' => $this->resetRequestShape(
                $updatedRequest->load(['user:id,name,email', 'resolvedBy:id,name'])
            ),
        ]);
    }

    public function reject(
        Request $request,
        PasswordResetRequest $passwordResetRequest,
        AuditLogger $auditLogger
    ): JsonResponse {
        $validated = $request->validate([
            'resolution_note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $updatedRequest = DB::transaction(function () use (
            $passwordResetRequest,
            $validated,
            $request
        ): PasswordResetRequest {
            $lockedRequest = PasswordResetRequest::query()
                ->whereKey($passwordResetRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== PasswordResetRequest::STATUS_PENDING) {
                abort(response()->json([
                    'message' => 'This password reset request is already closed.',
                ], 409));
            }

            $lockedRequest->status = PasswordResetRequest::STATUS_REJECTED;
            $lockedRequest->resolved_by_user_id = $request->user()->id;
            $lockedRequest->resolved_at = Carbon::now();
            $lockedRequest->resolution_note =
                $this->nullableTrimmed($validated['resolution_note'] ?? null);
            $lockedRequest->save();

            return $lockedRequest;
        });

        $auditLogger->log(
            module: AuditLog::MODULE_PASSWORD_RESET_REQUESTS,
            action: AuditLog::ACTION_PASSWORD_RESET_REQUEST_REJECTED,
            recordId: $updatedRequest->id,
            description: 'Password reset request rejected.',
            userId: $request->user()->id
        );

        return response()->json([
            'message' => 'Password reset request rejected.',
            'password_reset_request' => $this->resetRequestShape(
                $updatedRequest->load(['user:id,name,email', 'resolvedBy:id,name'])
            ),
        ]);
    }

    private function resetRequestShape(PasswordResetRequest $resetRequest): array
    {
        return [
            'id' => (int) $resetRequest->id,
            'user' => $resetRequest->user
                ? [
                    'id' => (int) $resetRequest->user->id,
                    'name' => (string) $resetRequest->user->name,
                    'email' => (string) $resetRequest->user->email,
                ]
                : null,
            'email' => (string) $resetRequest->email,
            'name' => $resetRequest->name,
            'message' => $resetRequest->message,
            'status' => (string) $resetRequest->status,
            'requested_ip' => $resetRequest->requested_ip,
            'resolved_by' => $resetRequest->resolvedBy
                ? [
                    'id' => (int) $resetRequest->resolvedBy->id,
                    'name' => (string) $resetRequest->resolvedBy->name,
                ]
                : null,
            'resolved_at' => $resetRequest->resolved_at,
            'resolution_note' => $resetRequest->resolution_note,
            'created_at' => $resetRequest->created_at,
            'updated_at' => $resetRequest->updated_at,
        ];
    }

    private function limiterKey(Request $request): string
    {
        $email = $this->normalizeEmail((string) $request->input('email', ''));

        return 'password-reset-request:'.hash(
            'sha256',
            $email."\0".$request->ip()
        );
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function nullableTrimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function boundedUserAgent(Request $request): ?string
    {
        return $request->userAgent()
            ? Str::limit($request->userAgent(), 255, '')
            : null;
    }
}
