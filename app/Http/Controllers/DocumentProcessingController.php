<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\DocumentProcessingLog;
use App\Models\ProcessingAction;
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
        $documentId
    ) {
        $document = Document::with([
            'currentOffice',
            'currentAction',
            'currentActionUpdatedBy',
        ])->findOrFail($documentId);

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
                'route',
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

            'available_actions' =>
                $actions,

            'can_update' =>
                $canUpdate,

            'restriction_reason' =>
                $restrictionReason,

            'is_in_transit' =>
                (bool) $pendingRoute,

            'history' =>
                $history,
        ]);
    }

    /**
     * Update the document's current processing action and internal note.
     */
    public function update(
        Request $request,
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

        $document =
            Document::findOrFail(
                $documentId
            );

        $user =
            $request->user();

        /*
        |--------------------------------------------------------------------------
        | Office authorization
        |--------------------------------------------------------------------------
        */

        if (!$user->office_id) {
            return response()->json([
                'message' =>
                    'Your user account is not assigned to an office.',
            ], 403);
        }

        if (
            (int) $user->office_id !==
            (int) $document->current_office_id
        ) {
            return response()->json([
                'message' =>
                    'You cannot update the processing action because this document is not currently assigned to your office.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Do not allow processing updates while document is in transit
        |--------------------------------------------------------------------------
        */

        $pendingRouteExists =
            DocumentRoute::where(
                'document_id',
                $document->id
            )
                ->whereNull(
                    'received_at'
                )
                ->exists();

        if ($pendingRouteExists) {
            return response()->json([
                'message' =>
                    'This document must be received before its processing action can be updated.',
            ], 409);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate selected action
        |--------------------------------------------------------------------------
        */

        $action =
            ProcessingAction::where(
                'id',
                $validated['current_action_id']
            )
                ->where(
                    'is_active',
                    true
                )
                ->first();

        if (!$action) {
            throw ValidationException::withMessages([
                'current_action_id' =>
                    'The selected processing action is not available.',
            ]);
        }

        if (
            in_array(
                $action->action_code,
                [
                    'REGISTERED',
                    'AWAITING_RECEIPT',
                    'FOR_ACTION',
                ],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'current_action_id' =>
                    'This processing action is controlled automatically by the system.',
            ]);
        }

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

        /*
        |--------------------------------------------------------------------------
        | OTHER requires an explanation
        |--------------------------------------------------------------------------
        */

        if (
            $action->action_code ===
                'OTHER' &&
            !$note
        ) {
            throw ValidationException::withMessages([
                'processing_note' =>
                    'Please enter a processing note when selecting Other.',
            ]);
        }

        DB::transaction(
            function () use (
                $document,
                $action,
                $note,
                $user
            ) {
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
            }
        );

        $document->load([
            'currentOffice',
            'currentAction',
            'currentActionUpdatedBy',
        ]);

        return response()->json([
            'message' =>
                'Current processing action updated successfully.',

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