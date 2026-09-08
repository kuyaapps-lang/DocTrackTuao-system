<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentTypeController extends Controller
{
    /**
     * Display all document types.
     */
    public function index()
    {
        $documentTypes = DocumentType::orderBy('type_name')->get();

        return response()->json($documentTypes->map(fn ($type) => $this->shape($type)));
    }

    /**
     * Store a new document type.
     */
    public function store(Request $request, AuditLogger $auditLogger)
    {
        $validated = $this->validated($request);

        $documentType = DB::transaction(function () use ($validated, $request, $auditLogger) {
            $documentType = DocumentType::create($validated);
            $this->requireAudit($auditLogger, $request, 'document_type_created', 'Document type created.', $documentType->id);

            return $documentType;
        });

        return response()->json([
            'message' => 'Document type created successfully',
            'document_type' => $this->shape($documentType),
        ], 201);
    }

    /**
     * Display a single document type.
     */
    public function show($id)
    {
        $documentType = $this->record($id);

        return response()->json($this->shape($documentType));
    }

    /**
     * Update a document type.
     */
    public function update(Request $request, AuditLogger $auditLogger, $id)
    {
        $documentType = $this->record($id);

        $validated = $this->validated($request, $documentType);

        DB::transaction(function () use ($documentType, $validated, $request, $auditLogger) {
            $documentType->update($validated);
            $this->requireAudit($auditLogger, $request, 'document_type_updated', 'Document type updated.', $documentType->id);
        });

        return response()->json([
            'message' => 'Document type updated successfully',
            'document_type' => $this->shape($documentType),
        ]);
    }

    /**
     * Delete a document type.
     */
    public function destroy(Request $request, AuditLogger $auditLogger, $id)
    {
        $this->rejectUnknownFields($request, []);

        return DB::transaction(function () use ($id, $request, $auditLogger) {
            $documentType = $this->record($id, true);

            if ($documentType->documents()->exists()) {
                return response()->json([
                    'message' => 'This document type cannot be deleted because it is already being used by one or more documents.',
                ], 409);
            }

            $documentType->delete();
            $this->requireAudit($auditLogger, $request, 'document_type_deleted', 'Document type deleted.', $documentType->id);

            return response()->json([
                'message' => 'Document type deleted successfully',
            ]);
        });
    }

    private function requireAudit(AuditLogger $auditLogger, Request $request, string $action, string $description, int $recordId): void
    {
        // The shared logger is best-effort; master-data mutations require atomic auditing.
        $audit = $auditLogger->log(
            module: 'master_data',
            action: $action,
            recordId: $recordId,
            description: $description,
            userId: $request->user()->id,
        );
        abort_if($audit === null, 500);
    }

    private function record($id, bool $lock = false): DocumentType
    {
        abort_unless(is_string($id) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) === 1, 404);
        $query = DocumentType::query()->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $type = $query->first();
        abort_if($type === null, 404);

        return $type;
    }

    private function validated(Request $request, ?DocumentType $type = null): array
    {
        $this->rejectUnknownFields($request, ['type_name', 'description']);

        return $request->validate([
            'type_name' => ['required', 'string', 'max:100', Rule::unique('document_types', 'type_name')->ignore($type?->id)],
            'description' => ['nullable', 'string', 'max:65535', function ($attribute, $value, $fail) {
                if (is_string($value) && strlen($value) > 65535) {
                    $fail('The description must not exceed 65535 bytes.');
                }
            }],
        ]);
    }

    private function rejectUnknownFields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => ['The request contains unsupported fields.']]);
        }
    }

    private function shape(DocumentType $type): array
    {
        return ['id' => (int) $type->id, 'type_name' => $type->type_name, 'description' => $type->description];
    }
}
