<?php

namespace App\Http\Controllers;

use App\Models\DocumentStatus;
use App\Models\ProcessingAction;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentStatusController extends Controller
{
    public function index()
    {
        return response()->json(DocumentStatus::query()->orderBy('id')->get(['id', 'status_name', 'color']));
    }

    public function store(Request $request, AuditLogger $auditLogger)
    {
        if (!Schema::hasColumn('document_statuses', 'processing_action_id')) {
            return response()->json(['message' => 'Document status setup is incomplete.'], 503);
        }
        if (array_diff(array_keys($request->all()), ['status_name', 'color']) !== []) {
            throw ValidationException::withMessages(['request' => ['The request contains unsupported fields.']]);
        }

        $validated = $request->validate([
            'status_name' => ['required', 'string', 'max:50', Rule::unique('document_statuses', 'status_name')],
            'color' => ['nullable', 'string', Rule::in(['gray', 'yellow', 'blue', 'indigo', 'orange', 'green', 'emerald', 'slate', 'red'])],
        ]);

        $status = DB::transaction(function () use ($validated, $request, $auditLogger) {
            $action = ProcessingAction::create([
                'action_code' => 'CUSTOM_'.str_replace('-', '', (string) Str::uuid()),
                'action_name' => $validated['status_name'],
                'is_active' => true,
                'sort_order' => 65535,
            ]);
            $validated['processing_action_id'] = $action->id;
            $status = DocumentStatus::create($validated);
            $audit = $auditLogger->log(
                module: 'master_data',
                action: 'document_status_created',
                recordId: $status->id,
                description: 'Document status created.',
                userId: $request->user()->id,
            );
            abort_if($audit === null, 500);
            return $status;
        });

        return response()->json([
            'message' => 'Document status created successfully.',
            'document_status' => ['id' => (int) $status->id, 'status_name' => $status->status_name, 'color' => $status->color],
        ], 201);
    }

    public function update(Request $request, AuditLogger $auditLogger, string $id)
    {
        if (array_diff(array_keys($request->all()), ['status_name', 'color']) !== []) {
            throw ValidationException::withMessages(['request' => ['The request contains unsupported fields.']]);
        }
        $validated = $request->validate([
            'status_name' => ['required', 'string', 'max:50', Rule::unique('document_statuses', 'status_name')->ignore($id)],
            'color' => ['nullable', 'string', Rule::in(['gray', 'yellow', 'blue', 'indigo', 'orange', 'green', 'emerald', 'slate', 'red'])],
        ]);
        DB::transaction(function () use ($id, $validated, $request, $auditLogger) {
            [$status, $action] = $this->editableStatus($id);
            $status->update($validated);
            $action->update(['action_name' => $validated['status_name']]);
            $this->audit($auditLogger, $request, 'document_status_updated', $status->id);
        });
        return response()->json(['message' => 'Document status updated successfully.']);
    }

    public function destroy(Request $request, AuditLogger $auditLogger, string $id)
    {
        if ($request->all() !== []) {
            throw ValidationException::withMessages(['request' => ['The request contains unsupported fields.']]);
        }
        DB::transaction(function () use ($id, $request, $auditLogger) {
            [$status, $action] = $this->editableStatus($id);
            $this->audit($auditLogger, $request, 'document_status_deleted', $status->id);
            $status->delete();
            $action->delete();
        });
        return response()->json(['message' => 'Document status deleted successfully.']);
    }

    private function editableStatus(string $id): array
    {
        abort_unless(preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) === 1, 404);
        $status = DocumentStatus::whereKey($id)->lockForUpdate()->firstOrFail();
        $action = ProcessingAction::whereKey($status->processing_action_id)->lockForUpdate()->first();
        if (!$action || !str_starts_with($action->action_code, 'CUSTOM_')) {
            abort(409, 'This system status cannot be edited or deleted.');
        }
        if (DB::table('documents')->where('status_id', $status->id)->orWhere('current_action_id', $action->id)->exists()
            || DB::table('document_routes')->where('status_id', $status->id)->exists()
            || DB::table('document_processing_logs')->where('processing_action_id', $action->id)->exists()) {
            abort(409, 'This status is already used by a document or its history and cannot be edited or deleted.');
        }
        return [$status, $action];
    }

    private function audit(AuditLogger $logger, Request $request, string $event, int $id): void
    {
        $audit = $logger->log(module: 'master_data', action: $event, recordId: $id,
            description: 'Document status changed.', userId: $request->user()->id);
        abort_if($audit === null, 500);
    }
}
