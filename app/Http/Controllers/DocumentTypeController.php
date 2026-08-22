<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentTypeController extends Controller
{
    /**
     * Display all document types.
     */
    public function index()
    {
        $documentTypes = DocumentType::orderBy('type_name')->get();

        return response()->json($documentTypes);
    }

    /**
     * Store a new document type.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type_name' => 'required|string|max:100|unique:document_types,type_name',
            'description' => 'nullable|string',
        ]);

        $documentType = DocumentType::create($validated);

        return response()->json([
            'message' => 'Document type created successfully',
            'document_type' => $documentType,
        ], 201);
    }

    /**
     * Display a single document type.
     */
    public function show($id)
    {
        $documentType = DocumentType::findOrFail($id);

        return response()->json($documentType);
    }

    /**
     * Update a document type.
     */
    public function update(Request $request, $id)
    {
        $documentType = DocumentType::findOrFail($id);

        $validated = $request->validate([
            'type_name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('document_types', 'type_name')
                    ->ignore($documentType->id),
            ],
            'description' => 'nullable|string',
        ]);

        $documentType->update($validated);

        return response()->json([
            'message' => 'Document type updated successfully',
            'document_type' => $documentType,
        ]);
    }

    /**
     * Delete a document type.
     */
    public function destroy($id)
    {
        $documentType = DocumentType::findOrFail($id);

        if ($documentType->documents()->exists()) {
            return response()->json([
                'message' => 'This document type cannot be deleted because it is already being used by one or more documents.',
            ], 409);
        }

        $documentType->delete();

        return response()->json([
            'message' => 'Document type deleted successfully',
        ]);
    }
}