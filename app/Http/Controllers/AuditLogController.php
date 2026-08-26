<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuditLogController extends Controller
{
    private const MODULES = [
        AuditLog::MODULE_AUTHENTICATION,
        AuditLog::MODULE_USERS,
        AuditLog::MODULE_DOCUMENTS,
        AuditLog::MODULE_DOCUMENT_ROUTING,
        AuditLog::MODULE_DOCUMENT_PROCESSING,
        AuditLog::MODULE_QR_CODES,
        AuditLog::MODULE_ATTACHMENTS,
    ];

    private const ACTIONS = [
        AuditLog::ACTION_LOGIN,
        AuditLog::ACTION_LOGOUT,
        AuditLog::ACTION_CREATED,
        AuditLog::ACTION_UPDATED,
        AuditLog::ACTION_DELETED,
        AuditLog::ACTION_FORWARDED,
        AuditLog::ACTION_RECEIVED,
        AuditLog::ACTION_PROCESSING_UPDATED,
        AuditLog::ACTION_GENERATED,
        AuditLog::ACTION_REGISTERED,
        AuditLog::ACTION_VOIDED,
        AuditLog::ACTION_UPLOADED,
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'per_page' => [
                'sometimes',
                'integer',
                Rule::in([10, 25, 50]),
            ],
            'module' => [
                'sometimes',
                'string',
                Rule::in(self::MODULES),
            ],
            'action' => [
                'sometimes',
                'string',
                Rule::in(self::ACTIONS),
            ],
        ]);

        $auditLogs = AuditLog::query()
            ->select([
                'id',
                'user_id',
                'module',
                'action',
                'record_id',
                'description',
                'ip_address',
                'created_at',
            ])
            ->with('user:id,name')
            ->when(
                isset($validated['module']),
                fn ($query) => $query->where('module', $validated['module'])
            )
            ->when(
                isset($validated['action']),
                fn ($query) => $query->where('action', $validated['action'])
            )
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        $auditLogs->through(fn (AuditLog $auditLog): array => [
            'id' => $auditLog->id,
            'actor' => $auditLog->user
                ? [
                    'id' => $auditLog->user->id,
                    'name' => $auditLog->user->name,
                ]
                : null,
            'module' => $auditLog->module,
            'action' => $auditLog->action,
            'record_id' => $auditLog->record_id,
            'description' => $auditLog->description,
            'ip_address' => $auditLog->ip_address,
            'created_at' => $auditLog->created_at,
        ]);

        return response()->json($auditLogs);
    }
}
