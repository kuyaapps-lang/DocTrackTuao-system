<?php

namespace App\Http\Controllers;

use App\Models\ConfidentialityLevel;
use App\Models\Document;
use App\Models\DocumentStatus;
use App\Models\DocumentType;
use App\Models\Office;
use App\Models\Priority;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    /**
     * Display all documents.
     */
    public function index()
    {
        $documents = Document::with([
            'type',
            'status',
            'priority',
            'confidentiality',
            'originOffice',
            'currentOffice',
            'creator',
        ])
            ->latest()
            ->get();

        return response()->json($documents);
    }

    /**
     * Display documents incoming to the logged-in user's office.
     */
    public function incoming(Request $request)
    {
        $user = $request->user();

        if (!$user->office_id) {
            return response()->json([
                'message' => 'Your user account is not assigned to an office.',
            ], 403);
        }

        $documents = Document::with([
            'type',
            'status',
            'priority',
            'confidentiality',
            'originOffice',
            'currentOffice',
            'creator',

            'routes' => function ($query) use ($user) {
                $query
                    ->where(
                        'to_office_id',
                        $user->office_id
                    )
                    ->with([
                        'fromOffice',
                        'toOffice',
                        'forwardedBy',
                        'receivedBy',
                        'status',
                        'action',
                    ])
                    ->orderByDesc('id');
            },
        ])
            ->whereHas('routes', function ($query) use ($user) {
                $query->where(
                    'to_office_id',
                    $user->office_id
                );
            })
            ->latest()
            ->get();

        return response()->json($documents);
    }

    /**
     * Display documents outgoing from the logged-in user's office.
     */
    public function outgoing(Request $request)
    {
        $user = $request->user();

        if (!$user->office_id) {
            return response()->json([
                'message' => 'Your user account is not assigned to an office.',
            ], 403);
        }

        $documents = Document::with([
            'type',
            'status',
            'priority',
            'confidentiality',
            'originOffice',
            'currentOffice',
            'creator',

            'routes' => function ($query) use ($user) {
                $query
                    ->where(
                        'from_office_id',
                        $user->office_id
                    )
                    ->with([
                        'fromOffice',
                        'toOffice',
                        'forwardedBy',
                        'receivedBy',
                        'status',
                        'action',
                    ])
                    ->orderByDesc('id');
            },
        ])
            ->whereHas('routes', function ($query) use ($user) {
                $query->where(
                    'from_office_id',
                    $user->office_id
                );
            })
            ->latest()
            ->get();

        return response()->json($documents);
    }

    /**
     * Return lookup data needed by the document registration form.
     */
    public function formOptions()
    {
        return response()->json([
            'document_types' =>
                DocumentType::orderBy('type_name')->get(),

            'priorities' =>
                Priority::orderBy('id')->get(),

            'confidentiality_levels' =>
                ConfidentialityLevel::orderBy('id')->get(),

            'offices' =>
                Office::with('department')
                    ->orderBy('office_name')
                    ->get(),
        ]);
    }

    /**
     * Store a new document.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' =>
                'required|string|max:255',

            'description' =>
                'nullable|string',

            'document_type_id' =>
                'required|exists:document_types,id',

            'priority_id' =>
                'required|exists:priorities,id',

            'confidentiality_level_id' =>
                'required|exists:confidentiality_levels,id',

            'origin_office_id' =>
                'required|exists:offices,id',

            'document_date' =>
                'required|date',

            'due_date' =>
                'nullable|date|after_or_equal:document_date',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Default document status
        |--------------------------------------------------------------------------
        */

        $pendingStatus = DocumentStatus::where(
            'status_name',
            'Pending'
        )->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Generate unique tracking number
        |--------------------------------------------------------------------------
        */

        do {
            $trackingNumber =
                'DOC-' .
                now()->format('YmdHis') .
                random_int(100, 999);
        } while (
            Document::where(
                'tracking_no',
                $trackingNumber
            )->exists()
        );

        /*
        |--------------------------------------------------------------------------
        | Create document
        |--------------------------------------------------------------------------
        |
        | A newly registered document starts at its origin office.
        |
        */

        $document = Document::create([
            'tracking_no' =>
                $trackingNumber,

            'title' =>
                $validated['title'],

            'description' =>
                $validated['description'] ?? null,

            'document_type_id' =>
                $validated['document_type_id'],

            'status_id' =>
                $pendingStatus->id,

            'priority_id' =>
                $validated['priority_id'],

            'confidentiality_level_id' =>
                $validated['confidentiality_level_id'],

            'origin_office_id' =>
                $validated['origin_office_id'],

            'current_office_id' =>
                $validated['origin_office_id'],

            'created_by' =>
                $request->user()->id,

            'document_date' =>
                $validated['document_date'],

            'due_date' =>
                $validated['due_date'] ?? null,
        ]);

        $document->load([
            'type',
            'status',
            'priority',
            'confidentiality',
            'originOffice',
            'currentOffice',
            'creator',
        ]);

        return response()->json([
            'message' =>
                'Document registered successfully',

            'document' =>
                $document,
        ], 201);
    }

    /**
     * Display a single document.
     */
    public function show($id)
    {
        $document = Document::with([
            'type',
            'status',
            'priority',
            'confidentiality',
            'originOffice',
            'currentOffice',
            'creator',

            'routes' => function ($query) {
                $query
                    ->with([
                        'fromOffice',
                        'toOffice',
                        'forwardedBy',
                        'receivedBy',
                        'status',
                        'action',
                    ])
                    ->orderBy('id');
            },

            'attachments',
            'comments',
        ])->findOrFail($id);

        return response()->json($document);
    }

    /**
     * Update a document.
     */
    public function update(Request $request, $id)
    {
        $document = Document::findOrFail($id);

        $validated = $request->validate([
            'title' =>
                'sometimes|required|string|max:255',

            'description' =>
                'nullable|string',

            'document_type_id' =>
                'sometimes|required|exists:document_types,id',

            'priority_id' =>
                'sometimes|required|exists:priorities,id',

            'confidentiality_level_id' =>
                'sometimes|required|exists:confidentiality_levels,id',

            'origin_office_id' =>
                'sometimes|required|exists:offices,id',

            'current_office_id' =>
                'sometimes|required|exists:offices,id',

            'document_date' =>
                'sometimes|required|date',

            'due_date' =>
                'nullable|date|after_or_equal:document_date',

            'status_id' =>
                'sometimes|required|exists:document_statuses,id',
        ]);

        $document->update($validated);

        $document->load([
            'type',
            'status',
            'priority',
            'confidentiality',
            'originOffice',
            'currentOffice',
            'creator',
        ]);

        return response()->json([
            'message' =>
                'Document updated successfully',

            'document' =>
                $document,
        ]);
    }

    /**
     * Delete a document.
     */
    public function destroy($id)
    {
        $document = Document::findOrFail($id);

        $document->delete();

        return response()->json([
            'message' =>
                'Document deleted successfully',
        ]);
    }
}