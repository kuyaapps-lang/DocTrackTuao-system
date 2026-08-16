<?php

namespace App\Http\Controllers;

use App\Models\Office;
use Illuminate\Http\Request;

class OfficeController extends Controller
{
    /**
     * Display all offices.
     */
    public function index()
    {
        $offices = Office::with('department')
            ->orderBy('office_name')
            ->get();

        return response()->json($offices);
    }

    /**
     * Store a new office.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'department_id' => 'nullable|exists:departments,id',
            'office_name' => 'required|string|max:150',
            'office_code' => 'required|string|max:20|unique:offices,office_code',
            'description' => 'nullable|string',
        ]);

        $office = Office::create($validated);

        $office->load('department');

        return response()->json([
            'message' => 'Office created successfully',
            'office' => $office,
        ], 201);
    }

    /**
     * Display a single office.
     */
    public function show($id)
    {
        $office = Office::with('department')
            ->findOrFail($id);

        return response()->json($office);
    }

    /**
     * Update an office.
     */
    public function update(Request $request, $id)
    {
        $office = Office::findOrFail($id);

        $validated = $request->validate([
            'department_id' => 'nullable|exists:departments,id',
            'office_name' => 'required|string|max:150',
            'office_code' => 'required|string|max:20|unique:offices,office_code,' . $office->id,
            'description' => 'nullable|string',
        ]);

        $office->update($validated);

        $office->load('department');

        return response()->json([
            'message' => 'Office updated successfully',
            'office' => $office,
        ]);
    }

    /**
     * Delete an office.
     */
    public function destroy($id)
    {
        $office = Office::findOrFail($id);

        $office->delete();

        return response()->json([
            'message' => 'Office deleted successfully',
        ]);
    }
}