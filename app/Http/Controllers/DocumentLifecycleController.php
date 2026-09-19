<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentProcessingLog;
use App\Models\DocumentRoute;
use App\Models\DocumentStatus;
use App\Models\Office;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentLifecycleController extends Controller
{
    public function complete(
        Request $request,
        AuditLogger $auditLogger,
        $documentId
    ) {
        $user = $request->user();

        $document = DB::transaction(
            function () use ($documentId, $user, $auditLogger) {
                $document = Document::whereKey($documentId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    !$user->office_id ||
                    !Office::whereKey($user->office_id)->exists()
                ) {
                    abort(403, 'Your user account is not assigned to a valid office.');
                }

                if ((int) $user->office_id !== (int) $document->current_office_id) {
                    abort(403, 'You cannot complete this document because it is not currently assigned to your office.');
                }

                if (
                    DocumentRoute::where('document_id', $document->id)
                        ->whereNull('received_at')
                        ->lockForUpdate()
                        ->exists()
                ) {
                    abort(409, 'This document must be received before it can be completed.');
                }

                $currentStatus = DocumentStatus::whereKey($document->status_id)
                    ->lockForUpdate()
                    ->first();

                if ($currentStatus && $currentStatus->status_name === 'Completed') {
                    abort(409, 'This document has already been completed.');
                }

                if ($currentStatus && $currentStatus->status_name === 'Archived') {
                    abort(409, 'Archived documents cannot be completed.');
                }

                $completedStatus = DocumentStatus::where('status_name', 'Completed')
                    ->firstOrFail();

                $completedAt = now();

                $document->update([
                    'status_id' => $completedStatus->id,
                    'completed_at' => $completedAt,
                    'completed_by' => $user->id,
                ]);

                DocumentProcessingLog::create([
                    'document_id' => $document->id,
                    'office_id' => $document->current_office_id,
                    'user_id' => $user->id,
                    'processing_action_id' => $document->current_action_id,
                    'event_type' => 'completed',
                    'processing_note' => null,
                    'event_note' => 'Document completed.',
                ]);

                $auditLogger->log(
                    module: AuditLog::MODULE_DOCUMENTS,
                    action: AuditLog::ACTION_COMPLETED,
                    recordId: $document->id,
                    description: 'Document completed.',
                    userId: $user->id
                );

                return $document;
            }
        );

        $document->load([
            'status',
            'currentOffice',
            'currentAction',
            'completedBy',
        ]);
        $status = $document->getRelation('status');

        return response()->json([
            'message' => 'Document completed successfully.',
            'document' => [
                'id' => $document->id,
                'tracking_no' => $document->tracking_no,
                'status' => $status
                    ? [
                        'id' => $status->id,
                        'status_name' => $status->status_name,
                    ]
                    : null,
                'current_office' => $document->currentOffice
                    ? [
                        'id' => $document->currentOffice->id,
                        'office_name' => $document->currentOffice->office_name,
                    ]
                    : null,
                'current_action' => $document->currentAction
                    ? [
                        'id' => $document->currentAction->id,
                        'action_code' => $document->currentAction->action_code,
                        'action_name' => $document->currentAction->action_name,
                    ]
                    : null,
                'completed_at' => $document->completed_at,
                'completed_by' => $document->completedBy
                    ? [
                        'id' => $document->completedBy->id,
                        'name' => $document->completedBy->name,
                    ]
                    : null,
            ],
        ]);
    }
}
