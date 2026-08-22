<?php

namespace App\Http\Controllers;

use App\Models\Document;

class DocumentTrackingController extends Controller
{
    /**
     * Public document tracking lookup.
     *
     * Only safe tracking information is exposed.
     * Internal comments, attachments, user identities,
     * and administrative controls are excluded.
     */
    public function show($trackingNo)
    {
        /*
        |--------------------------------------------------------------------------
        | Find Document
        |--------------------------------------------------------------------------
        */

        $document = Document::with([
            'type',
            'status',
            'priority',
            'confidentiality',
            'originOffice',
            'currentOffice',

            'routes' => function ($query) {
                $query
                    ->with([
                        'fromOffice',
                        'toOffice',
                        'status',
                    ])
                    ->orderBy('id');
            },
        ])
            ->where(
                'tracking_no',
                $trackingNo
            )
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Document Not Found
        |--------------------------------------------------------------------------
        */

        if (!$document) {
            return response()->json([
                'message' =>
                    'Document tracking number not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Get Loaded Relationships Explicitly
        |--------------------------------------------------------------------------
        |
        | The documents table still contains a legacy "status" string column.
        | The Document model also has a status() relationship.
        |
        | Using getRelation('status') prevents the old status column from
        | conflicting with the DocumentStatus relationship.
        |
        */

        $documentType =
            $document->getRelation('type');

        $documentStatus =
            $document->getRelation('status');

        $priority =
            $document->getRelation('priority');

        $confidentiality =
            $document->getRelation('confidentiality');

        $originOffice =
            $document->getRelation('originOffice');

        $currentOffice =
            $document->getRelation('currentOffice');

        /*
        |--------------------------------------------------------------------------
        | Confidentiality Protection
        |--------------------------------------------------------------------------
        */

        $confidentialityName =
            $confidentiality
                ?->level_name;

        $isProtected = in_array(
            strtolower(
                (string) $confidentialityName
            ),
            [
                'confidential',
                'restricted',
            ],
            true
        );

        /*
        |--------------------------------------------------------------------------
        | Public Movement History
        |--------------------------------------------------------------------------
        |
        | Only office movement and timestamps are exposed.
        | Staff names and internal remarks are intentionally excluded.
        |
        */

        $movementHistory =
            $document->routes->map(
                function ($route) {

                    $routeStatus =
                        $route->getRelation('status');

                    return [
                        'id' =>
                            $route->id,

                        'from_office' =>
                            $route->fromOffice
                                ?->office_name,

                        'to_office' =>
                            $route->toOffice
                                ?->office_name,

                        'status' =>
                            $routeStatus
                                ?->status_name,

                        'forwarded_at' =>
                            $route->forwarded_at,

                        'received_at' =>
                            $route->received_at,
                    ];
                }
            )
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Public Tracking Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'tracking_no' =>
                $document->tracking_no,

            'title' =>
                $isProtected
                    ? 'Protected Document'
                    : $document->title,

            'document_type' =>
                $documentType
                    ?->type_name,

            'status' =>
                $documentStatus
                    ?->status_name,

            'priority' =>
                $priority
                    ?->priority_name,

            'confidentiality' =>
                $confidentialityName,

            'origin_office' =>
                $originOffice
                    ?->office_name,

            'current_office' =>
                $currentOffice
                    ?->office_name,

            'document_date' =>
                $document->document_date,

            'due_date' =>
                $document->due_date,

            'registered_at' =>
                $document->created_at,

            'details' =>
                $isProtected
                    ? null
                    : $document->description,

            'is_protected' =>
                $isProtected,

            'movement_history' =>
                $movementHistory,
        ]);
    }
}