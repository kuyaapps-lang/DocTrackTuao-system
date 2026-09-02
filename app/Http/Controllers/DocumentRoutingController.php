<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentProcessingLog;
use App\Models\DocumentRoute;
use App\Models\DocumentStatus;
use App\Models\Office;
use App\Models\ProcessingAction;
use App\Models\RouteAction;
use App\Services\AuditLogger;
use App\Services\DocumentReadScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentRoutingController extends Controller
{
    /**
     * Return routing information/options for a document.
     */
    public function options(
        Request $request,
        DocumentReadScope $readScope,
        $documentId
    ) {
        $document = Document::findOrFail($documentId);
        $readScope->authorize($request->user(), $document);

        $document->load([
            'currentOffice',
            'originOffice',
            'status',
            'currentAction',
        ]);

        $user = $request->user();

        return response()->json([
            'document' => [
                'id' => $document->id,
                'tracking_no' => $document->tracking_no,
                'title' => $document->title,
                'origin_office_id' => $document->origin_office_id,
                'current_office_id' => $document->current_office_id,
                'origin_office' => $this->officeShape($document->originOffice),
                'current_office' => $this->officeShape($document->currentOffice),
                'status' => $document->status
                    ? [
                        'id' => $document->status->id,
                        'status_name' => $document->status->status_name,
                    ]
                    : null,
                'current_action' => $document->currentAction
                    ? [
                        'id' => $document->currentAction->id,
                        'action_code' => $document->currentAction->action_code,
                        'action_name' => $document->currentAction->action_name,
                    ]
                    : null,
            ],

            'offices' =>
                Office::where(
                    'id',
                    '!=',
                    $document->current_office_id
                )
                    ->orderBy(
                        'office_name'
                    )
                    ->get(['id', 'office_name', 'office_code']),

            'user' => [
                'id' =>
                    $user->id,

                'name' =>
                    $user->name,

                'office_id' =>
                    $user->office_id,
            ],

            'can_act' =>
                $user->office_id !== null &&
                (int) $user->office_id ===
                (int) $document->current_office_id,
        ]);
    }

    /**
     * Forward / release a document to another office.
     */
    public function forward(
        Request $request,
        AuditLogger $auditLogger,
        $documentId
    ) {
        $validated = $request->validate([
            'to_office_id' => ['required', 'exists:offices,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        $user = $request->user();

        $route = DB::transaction(
            function () use ($documentId, $user, $validated, $auditLogger) {
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
                    abort(403, 'You cannot forward this document because it is not currently assigned to your office.');
                }

                if (
                    DocumentRoute::where('document_id', $document->id)
                        ->whereNull('received_at')
                        ->lockForUpdate()
                        ->exists()
                ) {
                    abort(409, 'This document already has a pending route and must be received first.');
                }

                $destination = Office::whereKey($validated['to_office_id'])->first();
                if (!$destination) {
                    abort(422, 'The selected destination office is not available.');
                }

                if ((int) $destination->id === (int) $document->current_office_id) {
                    abort(422, 'Destination office must be different from the current office.');
                }

                $forwardedStatus = DocumentStatus::where('status_name', 'Forwarded')
                    ->firstOrFail();
                $forwardAction = RouteAction::where('action_name', 'Forward')
                    ->firstOrFail();
                $awaitingReceiptAction = ProcessingAction::where(
                    'action_code',
                    'AWAITING_RECEIPT'
                )->where('is_active', true)->firstOrFail();
                    /*
                    |--------------------------------------------------------------------------
                    | Remember source office before current office changes
                    |--------------------------------------------------------------------------
                    */

                    $fromOfficeId =
                        $document->current_office_id;

                    /*
                    |--------------------------------------------------------------------------
                    | Create routing record
                    |--------------------------------------------------------------------------
                    */

                    $route =
                        DocumentRoute::create([
                            'document_id' =>
                                $document->id,

                            'from_office_id' =>
                                $fromOfficeId,

                            'to_office_id' =>
                                $validated[
                                    'to_office_id'
                                ],

                            'forwarded_by' =>
                                $user->id,

                            'forwarded_at' =>
                                now(),

                            'status_id' =>
                                $forwardedStatus->id,

                            'action_id' =>
                                $forwardAction->id,

                            'remarks' =>
                                $validated[
                                    'remarks'
                                ] ?? null,
                        ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Move document to destination office
                    |--------------------------------------------------------------------------
                    |
                    | The destination becomes current_office_id so the receiving office
                    | becomes responsible for accepting it.
                    |
                    */

                    $document->update([
                        'current_office_id' =>
                            $validated[
                                'to_office_id'
                            ],

                        'status_id' =>
                            $forwardedStatus->id,

                        'current_action_id' =>
                            $awaitingReceiptAction->id,

                        /*
                        |--------------------------------------------------------------------------
                        | Previous processing note belongs to previous processing stage
                        |--------------------------------------------------------------------------
                        */

                        'processing_note' =>
                            null,

                        'current_action_updated_by' =>
                            $user->id,

                        'current_action_updated_at' =>
                            now(),
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Processing history
                    |--------------------------------------------------------------------------
                    */

                    $fromOffice =
                        Office::find(
                            $fromOfficeId
                        );

                    $toOffice =
                        Office::find(
                            $validated[
                                'to_office_id'
                            ]
                        );

                    DocumentProcessingLog::create([
                        'document_id' =>
                            $document->id,

                        /*
                        |--------------------------------------------------------------------------
                        | Awaiting Receipt belongs to destination office
                        |--------------------------------------------------------------------------
                        */

                        'office_id' =>
                            $validated[
                                'to_office_id'
                            ],

                        'user_id' =>
                            $user->id,

                        'processing_action_id' =>
                            $awaitingReceiptAction->id,

                        'document_route_id' =>
                            $route->id,

                        'event_type' =>
                            'forwarded',

                        'processing_note' =>
                            null,

                        'event_note' =>
                            'Forwarded from ' .
                            (
                                $fromOffice
                                    ?->office_name
                                ?? 'previous office'
                            ) .
                            ' to ' .
                            (
                                $toOffice
                                    ?->office_name
                                ?? 'destination office'
                            ) .
                            '.',
                    ]);

                    $auditLogger->log(
                        module: AuditLog::MODULE_DOCUMENT_ROUTING,
                        action: AuditLog::ACTION_FORWARDED,
                        recordId: $document->id,
                        description:
                            'Document forwarded from ' .
                            (
                                $fromOffice?->office_name
                                ?? 'previous office'
                            ) .
                            ' to ' .
                            (
                                $toOffice?->office_name
                                ?? 'destination office'
                            ) .
                            '.',
                        userId: $user->id
                    );

                return $route;
            }
        );

        $route->load([
            'fromOffice',
            'toOffice',
            'forwardedBy',
            'receivedBy',
            'status',
            'action',
        ]);

        return response()->json([
            'message' =>
                'Document forwarded successfully.',

            'route' =>
                $route,
        ], 201);
    }

    /**
     * Receive the current pending route.
     */
    public function receive(
        Request $request,
        AuditLogger $auditLogger,
        $documentId
    ) {
        $user = $request->user();

        $route = DB::transaction(
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

                $pendingRoutes = DocumentRoute::where('document_id', $document->id)
                    ->whereNull('received_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($pendingRoutes->isEmpty()) {
                    abort(409, 'This document has no pending route to receive.');
                }

                if ($pendingRoutes->count() !== 1) {
                    abort(409, 'This document has an invalid pending routing state.');
                }

                $route = $pendingRoutes->first();
                if ((int) $route->to_office_id !== (int) $user->office_id) {
                    abort(403, 'You cannot receive this document because it is routed to another office.');
                }

                $receivedStatus = DocumentStatus::where('status_name', 'Received')
                    ->firstOrFail();
                $forAction = ProcessingAction::where('action_code', 'FOR_ACTION')
                    ->where('is_active', true)
                    ->firstOrFail();
                /*
                |--------------------------------------------------------------------------
                | Complete route
                |--------------------------------------------------------------------------
                */

                $route->update([
                    'received_by' =>
                        $user->id,

                    'received_at' =>
                        now(),

                    'status_id' =>
                        $receivedStatus->id,
                ]);

                /*
                |--------------------------------------------------------------------------
                | Update document
                |--------------------------------------------------------------------------
                */

                $document->update([
                    'current_office_id' =>
                        $route->to_office_id,

                    'status_id' =>
                        $receivedStatus->id,

                    'current_action_id' =>
                        $forAction->id,

                    'processing_note' =>
                        null,

                    'current_action_updated_by' =>
                        $user->id,

                    'current_action_updated_at' =>
                        now(),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Processing history
                |--------------------------------------------------------------------------
                */

                DocumentProcessingLog::create([
                    'document_id' =>
                        $document->id,

                    'office_id' =>
                        $route->to_office_id,

                    'user_id' =>
                        $user->id,

                    'processing_action_id' =>
                        $forAction->id,

                    'document_route_id' =>
                        $route->id,

                    'event_type' =>
                        'received',

                    'processing_note' =>
                        null,

                    'event_note' =>
                        'Document received and ready for action.',
                ]);

                $auditLogger->log(
                    module: AuditLog::MODULE_DOCUMENT_ROUTING,
                    action: AuditLog::ACTION_RECEIVED,
                    recordId: $document->id,
                    description:
                        'Document received by ' .
                        $user->name .
                        ' from office ID ' .
                        $route->from_office_id .
                        '.',
                    userId: $user->id
                );

                return $route;
            }
        );

        $route->load([
            'fromOffice',
            'toOffice',
            'forwardedBy',
            'receivedBy',
            'status',
            'action',
        ]);

        return response()->json([
            'message' =>
                'Document received successfully.',

            'route' =>
                $route,
        ]);
    }

    /**
     * Display complete routing history.
     */
    public function history(
        Request $request,
        DocumentReadScope $readScope,
        $documentId
    ) {
        $document = Document::findOrFail($documentId);
        $readScope->authorize($request->user(), $document);

        $routes =
            DocumentRoute::with([
                'fromOffice',
                'toOffice',
                'forwardedBy',
                'receivedBy',
                'status',
                'action',
            ])
                ->where(
                    'document_id',
                    $documentId
                )
                ->orderBy('id')
                ->get();

        return response()->json($routes->map(
            fn (DocumentRoute $route): array => $this->routeShape($route)
        )->values());
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

    private function routeShape(DocumentRoute $route): array
    {
        return [
            'id' => $route->id,
            'document_id' => $route->document_id,
            'from_office_id' => $route->from_office_id,
            'to_office_id' => $route->to_office_id,
            'forwarded_at' => $route->forwarded_at,
            'received_at' => $route->received_at,
            'remarks' => $route->remarks,
            'from_office' => $this->officeShape($route->fromOffice),
            'to_office' => $this->officeShape($route->toOffice),
            'forwarded_by' => $this->userShape($route->forwardedBy),
            'received_by' => $this->userShape($route->receivedBy),
            'status' => $route->status
                ? [
                    'id' => $route->status->id,
                    'status_name' => $route->status->status_name,
                ]
                : null,
            'action' => $route->action
                ? [
                    'id' => $route->action->id,
                    'action_name' => $route->action->action_name,
                ]
                : null,
        ];
    }
}
