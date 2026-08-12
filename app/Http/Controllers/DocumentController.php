<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentStatus;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    /**
     * Display all documents
     */
    public function index()
    {
        $documents = Document::with([
            'type',
            'status',
            'originOffice',
            'currentOffice',
            'creator',
        ])
        ->latest()
        ->get();

        return response()->json($documents);
    }

    /**
     * Store new document
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Get default Pending status
        |--------------------------------------------------------------------------
        */

        $pendingStatus = DocumentStatus::where(
            'status_name',
            'Pending'
        )->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Create document
        |--------------------------------------------------------------------------
        */

        $document = Document::create([
            'tracking_no' => 'DOC-' . now()->format('YmdHis') . rand(100, 999),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status_id' => $pendingStatus->id,
        ]);

        $document->load([
            'type',
            'status',
            'originOffice',
            'currentOffice',
            'creator',
        ]);

        return response()->json([
            'message' => 'Document created successfully',
            'document' => $document,
        ], 201);
    }

    /**
     * Show single document
     */
    public function show($id)
    {
        $document = Document::with([
            'type',
            'status',
            'originOffice',
            'currentOffice',
            'creator',
            'routes',
            'attachments',
            'comments',
        ])->findOrFail($id);

        return response()->json($document);
    }

    /**
     * Update document
     */
    public function update(Request $request, $id)
    {
        $document = Document::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status_id' => 'sometimes|exists:document_statuses,id',
        ]);

        $document->update($validated);

        $document->load([
            'type',
            'status',
            'originOffice',
            'currentOffice',
            'creator',
        ]);

        return response()->json([
            'message' => 'Document updated successfully',
            'document' => $document,
        ]);
    }

    /**
     * Delete document
     */
    public function destroy($id)
    {
        $document = Document::findOrFail($id);

        $document->delete();

        return response()->json([
            'message' => 'Document deleted successfully',
        ]);
    }
}