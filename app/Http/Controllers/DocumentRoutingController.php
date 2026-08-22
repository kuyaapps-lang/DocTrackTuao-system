<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\DocumentStatus;
use App\Models\Office;
use App\Models\RouteAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentRoutingController extends Controller
{
    /**
     * Return routing information/options for a document.
     */
    public function options(Request $request, $documentId)
    {
        $document = Document::with([
            'currentOffice',
            'originOffice',
            'status',
        ])->findOrFail($documentId);

        $user = $request->user();

        return response()->json([
            'document' => $document,

            'offices' => Office::where(
                'id',
                '!=',
                $document->current_office_id
            )
                ->orderBy('office_name')
                ->get(),

            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'office_id' => $user->office_id,
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
    public function forward(Request $request, $documentId)
    {
        $validated = $request->validate([
            'to_office_id' => [
                'required',
                'exists:offices,id',
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $document = Document::findOrFail($documentId);
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Authorization by current office
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
                    'You cannot forward this document because it is not currently assigned to your office.',
            ], 403);
        }

        if (
            (int) $validated['to_office_id'] ===
            (int) $document->current_office_id
        ) {
            return response()->json([
                'message' =>
                    'Destination office must be different from the current office.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent double forwarding before receipt
        |--------------------------------------------------------------------------
        */

        $pendingRoute = DocumentRoute::where(
            'document_id',
            $document->id
        )
            ->whereNull('received_at')
            ->latest('id')
            ->first();

        if ($pendingRoute) {
            return response()->json([
                'message' =>
                    'This document already has a pending route and must be received first.',
            ], 409);
        }

        $forwardedStatus = DocumentStatus::where(
            'status_name',
            'Forwarded'
        )->firstOrFail();

        $forwardAction = RouteAction::where(
            'action_name',
            'Forward'
        )->firstOrFail();

        $route = DB::transaction(function () use (
            $document,
            $user,
            $validated,
            $forwardedStatus,
            $forwardAction
        ) {
            $route = DocumentRoute::create([
                'document_id' =>
                    $document->id,

                'from_office_id' =>
                    $document->current_office_id,

                'to_office_id' =>
                    $validated['to_office_id'],

                'forwarded_by' =>
                    $user->id,

                'forwarded_at' =>
                    now(),

                'status_id' =>
                    $forwardedStatus->id,

                'action_id' =>
                    $forwardAction->id,

                'remarks' =>
                    $validated['remarks'] ?? null,
            ]);

            $document->update([
                'current_office_id' =>
                    $validated['to_office_id'],

                'status_id' =>
                    $forwardedStatus->id,
            ]);

            return $route;
        });

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

            'route' => $route,
        ], 201);
    }

    /**
     * Receive the current pending route.
     */
    public function receive(Request $request, $documentId)
    {
        $document = Document::findOrFail($documentId);
        $user = $request->user();

        if (!$user->office_id) {
            return response()->json([
                'message' =>
                    'Your user account is not assigned to an office.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Find latest unreceived route
        |--------------------------------------------------------------------------
        */

        $route = DocumentRoute::where(
            'document_id',
            $document->id
        )
            ->whereNull('received_at')
            ->latest('id')
            ->first();

        if (!$route) {
            return response()->json([
                'message' =>
                    'This document has no pending route to receive.',
            ], 409);
        }

        /*
        |--------------------------------------------------------------------------
        | Only destination office may receive
        |--------------------------------------------------------------------------
        */

        if (
            (int) $route->to_office_id !==
            (int) $user->office_id
        ) {
            return response()->json([
                'message' =>
                    'You cannot receive this document because it is routed to another office.',
            ], 403);
        }

        $receivedStatus = DocumentStatus::where(
            'status_name',
            'Received'
        )->firstOrFail();

        DB::transaction(function () use (
            $route,
            $document,
            $user,
            $receivedStatus
        ) {
            /*
            |--------------------------------------------------------------------------
            | Keep action_id as Forward.
            |
            | The route itself represents one transfer.
            | received_at / received_by indicate completion of that transfer.
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

            $document->update([
                'current_office_id' =>
                    $route->to_office_id,

                'status_id' =>
                    $receivedStatus->id,
            ]);
        });

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

            'route' => $route,
        ]);
    }

    /**
     * Display complete routing history.
     */
    public function history($documentId)
    {
        Document::findOrFail($documentId);

        $routes = DocumentRoute::with([
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

        return response()->json($routes);
    }
}