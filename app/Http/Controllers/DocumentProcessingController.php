<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\DocumentProcessingLog;
use App\Models\ProcessingAction;
use App\Services\AuditLogger;
use App\Services\DocumentReadScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentProcessingController extends Controller
{
    /**
     * Return current processing information and actions that a user
     * may manually select.
     */
    public function show(
        Request $request,
        DocumentReadScope $readScope,
        $documentId
    ) {
        $document = Document::findOrFail($documentId);
        $readScope->authorize($request->user(), $document);

        $document->load([
            'currentOffice',
            'currentAction',
            'currentActionUpdatedBy',
        ]);

        $user = $request->user();

        $pendingRoute =
            DocumentRoute::where(
                'document_id',
                $document->id
            )
                ->whereNull(
                    'received_at'
                )
                ->latest('id')
                ->first();

        $sameOffice =
            $user->office_id !== null &&
            (int) $user->office_id ===
            (int) $document->current_office_id;

        $canUpdate =
            $sameOffice &&
            !$pendingRoute;

        $restrictionReason = null;

        if (!$user->office_id) {
            $restrictionReason =
                'Your account is not assigned to an office.';
        } elseif (!$sameOffice) {
            $restrictionReason =
                'Only an authorized user from the office currently holding this document may update its processing action.';
        } elseif ($pendingRoute) {
            $restrictionReason =
                'This document is currently in transit and must be received before its processing action can be updated.';
        }

        /*
        |--------------------------------------------------------------------------
        | Manual actions only
        |--------------------------------------------------------------------------
        |
        | REGISTERED and AWAITING_RECEIPT are system-controlled actions.
        | They will be assigned automatically by registration/routing.
        |
        */

        $actions =
            ProcessingAction::where(
                'is_active',
                true
            )
                ->whereNotIn(
                    'action_code',
                    [
                        'REGISTERED',
                        'AWAITING_RECEIPT',
                        'FOR_ACTION',
                    ]
                )
                ->orderBy(
                    'sort_order'
                )
                ->orderBy(
                    'action_name'
                )
                ->get([
                    'id',
                    'action_code',
                    'action_name',
                ]);

        $history =
            DocumentProcessingLog::with([
                'office',
                'user',
                'action',
                'route.fromOffice',
                'route.toOffice',
            ])
                ->where(
                    'document_id',
                    $document->id
                )
                ->orderByDesc(
                    'created_at'
                )
                ->orderByDesc(
                    'id'
                )
                ->get();

        return response()->json([
            'document_id' =>
                $document->id,

            'tracking_no' =>
                $document->tracking_no,

            'current_office' => $this->officeShape(
                $document->currentOffice
            ),

            'current_action' => $this->actionShape(
                $document->currentAction
            ),

            'processing_note' =>
                $document->processing_note,

            'current_action_updated_by' => $this->userShape(
                $document->currentActionUpdatedBy
            ),

            'current_action_updated_at' =>
                $document->current_action_updated_at,

            'available_actions' =>
                $actions,

            'can_update' =>
                $canUpdate,

            'restriction_reason' =>
                $restrictionReason,

            'is_in_transit' =>
                (bool) $pendingRoute,

            'history' => $history->map(fn (
                DocumentProcessingLog $log
            ): array => [
                'id' => $log->id,
                'document_route_id' => $log->document_route_id,
                'event_type' => $log->event_type,
                'processing_note' => $log->processing_note,
                'event_note' => $log->event_note,
                'created_at' => $log->created_at,
                'office' => $this->officeShape($log->office),
                'user' => $this->userShape($log->user),
                'action' => $this->actionShape($log->action),
                'route' => $log->route
                    ? $this->processingRouteShape($log->route)
                    : null,
            ])->values(),
        ]);
    }

    private function officeShape($office): ?array
    {
        return $office
            ? [
                'id' => $office->id,
                'office_name' => $office->office_name,
                'office_code' => $office->office_code,
            ]
            : null;
    }

    private function userShape($user): ?array
    {
        return $user
            ? [
                'id' => $user->id,
                'name' => $user->name,
            ]
            : null;
    }

    private function actionShape($action): ?array
    {
        return $action
            ? [
                'id' => $action->id,
                'action_code' => $action->action_code,
                'action_name' => $action->action_name,
            ]
            : null;
    }

    private function processingRouteShape($route): array
    {
        return [
            'id' => $route->id,
            'from_office_id' => $route->from_office_id,
            'to_office_id' => $route->to_office_id,
            'forwarded_at' => $route->forwarded_at,
            'received_at' => $route->received_at,
            'remarks' => $route->remarks,
            'from_office' => $this->officeShape($route->fromOffice),
            'to_office' => $this->officeShape($route->toOffice),
        ];
    }

    /**
     * Update the document's current processing action and internal note.
     */
    public function update(
        Request $request,
        AuditLogger $auditLogger,
        $documentId
    ) {
        $validated = $request->validate([
            'current_action_id' => [
                'required',
                'integer',
                'exists:processing_actions,id',
            ],

            'processing_note' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $note =
            isset(
                $validated['processing_note']
            )
                ? trim(
                    $validated['processing_note']
                )
                : null;

        if ($note === '') {
            $note = null;
        }

        $user = $request->user();

        $changed = DB::transaction(
            function () use (
                $documentId,
                $validated,
                $note,
                $user,
                $auditLogger
            ) {
                $document = Document::whereKey($documentId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    !$user->office_id ||
                    !DB::table('offices')->where('id', $user->office_id)->exists()
                ) {
                    abort(403, 'Your user account is not assigned to a valid office.');
                }

                if ((int) $user->office_id !== (int) $document->current_office_id) {
                    abort(403, 'You cannot update the processing action because this document is not currently assigned to your office.');
                }

                if (
                    DocumentRoute::where('document_id', $document->id)
                        ->whereNull('received_at')
                        ->lockForUpdate()
                        ->exists()
                ) {
                    abort(409, 'This document must be received before its processing action can be updated.');
                }

                $action = ProcessingAction::whereKey($validated['current_action_id'])
                    ->where('is_active', true)
                    ->first();

                if (!$action) {
                    throw ValidationException::withMessages([
                        'current_action_id' =>
                            'The selected processing action is not available.',
                    ]);
                }

                if (in_array($action->action_code, [
                    'REGISTERED',
                    'AWAITING_RECEIPT',
                    'FOR_ACTION',
                ], true)) {
                    throw ValidationException::withMessages([
                        'current_action_id' =>
                            'This processing action is controlled automatically by the system.',
                    ]);
                }

                if ($action->action_code === 'OTHER' && !$note) {
                    throw ValidationException::withMessages([
                        'processing_note' =>
                            'Please enter a processing note when selecting Other.',
                    ]);
                }

                if (
                    (int) $document->current_action_id === (int) $action->id &&
                    $document->processing_note === $note
                ) {
                    return false;
                }

                $document->update([
                    'current_action_id' =>
                        $action->id,

                    'processing_note' =>
                        $note,

                    'current_action_updated_by' =>
                        $user->id,

                    'current_action_updated_at' =>
                        now(),
                ]);

                DocumentProcessingLog::create([
                    'document_id' =>
                        $document->id,

                    'office_id' =>
                        $document->current_office_id,

                    'user_id' =>
                        $user->id,

                    'processing_action_id' =>
                        $action->id,

                    'event_type' =>
                        'action_updated',

                    'processing_note' =>
                        $note,

                    'event_note' =>
                        'Current processing action updated.',
                ]);

                $auditLogger->log(
                    module: AuditLog::MODULE_DOCUMENT_PROCESSING,
                    action: AuditLog::ACTION_PROCESSING_UPDATED,
                    recordId: $document->id,
                    description:
                        'Processing updated to ' .
                        $action->action_code .
                        ' (' .
                        $action->action_name .
                        '); note supplied: ' .
                        ($note !== null ? 'yes' : 'no') .
                        '.',
                    userId: $user->id
                );

                return true;
            }
        );

        $document = Document::findOrFail($documentId);

        $document->load([
            'currentOffice',
            'currentAction',
            'currentActionUpdatedBy',
        ]);

        return response()->json([
            'message' =>
                $changed
                    ? 'Current processing action updated successfully.'
                    : 'Current processing action is already up to date.',

            'processing' => [
                'current_office' =>
                    $document->currentOffice,

                'current_action' =>
                    $document->currentAction,

                'processing_note' =>
                    $document->processing_note,

                'current_action_updated_by' =>
                    $document->currentActionUpdatedBy,

                'current_action_updated_at' =>
                    $document->current_action_updated_at,
            ],
        ]);
    }
}
