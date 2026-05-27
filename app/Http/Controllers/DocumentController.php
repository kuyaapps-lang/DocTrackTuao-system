<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    /**
     * Display all documents
     */
    public function index()
    {
        return response()->json(Document::all());
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

        $document = Document::create([
            'tracking_no' => 'DOC-' . now()->format('YmdHis') . rand(100, 999),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Document created successfully',
            'document' => $document
        ], 201);
    }

    /**
     * Show single document
     */
    public function show($id)
    {
        $document = Document::findOrFail($id);

        return response()->json($document);
    }

    /**
     * Update document
     */
    public function update(Request $request, $id)
    {
        $document = Document::findOrFail($id);

        $document->update($request->only([
            'title',
            'description',
            'status'
        ]));

        return response()->json([
            'message' => 'Document updated successfully',
            'document' => $document
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
            'message' => 'Document deleted successfully'
        ]);
    }
}