<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OfficeController extends Controller
{
    /**
     * Display all offices.
     */
    public function index()
    {
        $offices = Office::with('department:id,department_name')
            ->orderBy('office_name')
            ->get();

        return response()->json($offices->map(fn ($office) => $this->shape($office)));
    }

    /**
     * Store a new office.
     */
    public function store(Request $request, AuditLogger $auditLogger)
    {
        $validated = $this->validated($request);

        $office = DB::transaction(function () use ($validated, $request, $auditLogger) {
            $office = Office::create($validated);
            $this->requireAudit($auditLogger, $request, 'office_created', 'Office created.', $office->id);

            return $office;
        });

        return response()->json([
            'message' => 'Office created successfully',
            'office' => $this->shape($office),
        ], 201);
    }

    /**
     * Display a single office.
     */
    public function show($id)
    {
        $office = $this->record($id);

        return response()->json($this->shape($office));
    }

    /**
     * Update an office.
     */
    public function update(Request $request, AuditLogger $auditLogger, $id)
    {
        $office = $this->record($id);

        $validated = $this->validated($request, $office);

        DB::transaction(function () use ($office, $validated, $request, $auditLogger) {
            $office->update($validated);
            $this->requireAudit($auditLogger, $request, 'office_updated', 'Office updated.', $office->id);
        });

        return response()->json([
            'message' => 'Office updated successfully',
            'office' => $this->shape($office),
        ]);
    }

    /**
     * Delete an office.
     */
    public function destroy(Request $request, AuditLogger $auditLogger, $id)
    {
        $this->rejectUnknownFields($request, []);

        return DB::transaction(function () use ($id, $request, $auditLogger) {
            $office = $this->record($id, true);
            // Preserve references even where a foreign key would otherwise SET NULL.
            if ($office->users()->exists() ||
                DB::table('documents')->where('origin_office_id', $office->id)->orWhere('current_office_id', $office->id)->exists() ||
                DB::table('document_routes')->where('from_office_id', $office->id)->orWhere('to_office_id', $office->id)->exists() ||
                DB::table('document_processing_logs')->where('office_id', $office->id)->exists()) {
                return response()->json([
                    'message' => 'This office cannot be deleted because it is already being used by one or more records.',
                ], 409);
            }

            $office->delete();
            $this->requireAudit($auditLogger, $request, 'office_deleted', 'Office deleted.', $office->id);

            return response()->json(['message' => 'Office deleted successfully']);
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

    private function record($id, bool $lock = false): Office
    {
        abort_unless(is_string($id) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) === 1, 404);
        $query = Office::query()->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $office = $query->first();
        abort_if($office === null, 404);

        return $office;
    }

    private function validated(Request $request, ?Office $office = null): array
    {
        $this->rejectUnknownFields($request, ['department_id', 'office_name', 'office_code', 'description']);

        return $request->validate([
            'department_id' => ['bail', 'nullable', function ($attribute, $value, $fail) {
                if (!is_int($value) && !(is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1)) {
                    $fail('The department id must be an integer.');
                }
            }, 'integer', 'min:1', 'exists:departments,id'],
            'office_name' => ['required', 'string', 'max:150'],
            'office_code' => ['required', 'string', 'max:20', Rule::unique('offices', 'office_code')->ignore($office?->id)],
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

    private function shape(Office $office): array
    {
        $office->loadMissing('department:id,department_name');

        return [
            'id' => (int) $office->id,
            'department_id' => $office->department_id === null ? null : (int) $office->department_id,
            'office_name' => $office->office_name,
            'office_code' => $office->office_code,
            'description' => $office->description,
            'department' => $office->department === null ? null : [
                'department_name' => $office->department->department_name,
            ],
        ];
    }
}
