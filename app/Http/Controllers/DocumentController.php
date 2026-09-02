<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ConfidentialityLevel;
use App\Models\Document;
use App\Models\DocumentStatus;
use App\Models\DocumentType;
use App\Models\DocumentQrCode;
use App\Models\DocumentProcessingLog;
use App\Models\Office;
use App\Models\Priority;
use App\Models\ProcessingAction;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\AuditLogger;
use App\Services\DocumentReadScope;

class DocumentController extends Controller
{
    /**
     * Display all documents.
     */
    public function index(Request $request)
    {
        $filters = $this->validateListRequest($request, false);
        $query = Document::with([
            'type',
            'status',
            'priority',
            'currentOffice',
        ]);

        $this->applyAllDocumentSearch($query, $filters['search']);

        $documents = $query
            ->orderByDesc('documents.created_at')
            ->orderByDesc('documents.id')
            ->paginate($filters['per_page']);

        return response()->json(
            $this->serializeListPagination(
                $documents,
                $filters,
                'all'
            )
        );
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

        $filters = $this->validateListRequest($request, true);

        $query = Document::with([
            'type',

            'routes' => function ($query) use ($user) {
                $query
                    ->where(
                        'to_office_id',
                        $user->office_id
                    )
                    ->with([
                        'fromOffice',
                    ])
                    ->orderByDesc('id');
            },
        ])
            ->whereHas('routes', function ($query) use ($user) {
                $query->where(
                    'to_office_id',
                    $user->office_id
                );
            });

        $this->applyMovementSearch(
            $query,
            $filters['search'],
            'to_office_id',
            $user->office_id,
            'fromOffice',
            'offices.office_name'
        );
        $this->applyIncomingState(
            $query,
            $filters['state'],
            $user->office_id
        );

        $documents = $query
            ->orderByDesc('documents.created_at')
            ->orderByDesc('documents.id')
            ->paginate($filters['per_page']);

        return response()->json(
            $this->serializeListPagination(
                $documents,
                $filters,
                'incoming'
            )
        );
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

        $filters = $this->validateListRequest($request, false);

        $query = Document::with([
            'type',

            'routes' => function ($query) use ($user) {
                $query
                    ->where(
                        'from_office_id',
                        $user->office_id
                    )
                    ->with([
                        'toOffice',
                    ])
                    ->orderByDesc('id');
            },
        ])
            ->whereHas('routes', function ($query) use ($user) {
                $query->where(
                    'from_office_id',
                    $user->office_id
                );
            });

        $this->applyMovementSearch(
            $query,
            $filters['search'],
            'from_office_id',
            $user->office_id,
            'toOffice',
            'offices.office_name'
        );

        $documents = $query
            ->orderByDesc('documents.created_at')
            ->orderByDesc('documents.id')
            ->paginate($filters['per_page']);

        return response()->json(
            $this->serializeListPagination(
                $documents,
                $filters,
                'outgoing'
            )
        );
    }

    private function validateListRequest(
        Request $request,
        bool $allowState
    ): array {
        if ($request->has('search') && is_string($request->query('search'))) {
            $request->merge([
                'search' => trim($request->query('search')),
            ]);
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50])],
            'search' => ['sometimes', 'string', 'max:100'],
            'state' => $allowState
                ? ['sometimes', 'string', Rule::in(['pending', 'received'])]
                : ['prohibited'],
        ]);

        return [
            'page' => $validated['page'] ?? 1,
            'per_page' => $validated['per_page'] ?? 25,
            'search' => $validated['search'] ?? '',
            'state' => $validated['state'] ?? null,
        ];
    }

    private function applyAllDocumentSearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $pattern = $this->escapedLikePattern($search);

        $query->where(function ($query) use ($pattern) {
            $query
                ->whereRaw("documents.tracking_no LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("documents.title LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('type', fn ($query) =>
                    $query->whereRaw("document_types.type_name LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhereHas('status', fn ($query) =>
                    $query->whereRaw("document_statuses.status_name LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhereHas('priority', fn ($query) =>
                    $query->whereRaw("priorities.priority_name LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhereHas('currentOffice', fn ($query) =>
                    $query->whereRaw("offices.office_name LIKE ? ESCAPE '!'", [$pattern]));
        });
    }

    private function applyMovementSearch(
        $query,
        string $search,
        string $officeColumn,
        int $officeId,
        string $officeRelation,
        string $officeNameColumn
    ): void {
        if ($search === '') {
            return;
        }

        $pattern = $this->escapedLikePattern($search);

        $query->where(function ($query) use (
            $pattern,
            $officeColumn,
            $officeId,
            $officeRelation,
            $officeNameColumn
        ) {
            $query
                ->whereRaw("documents.tracking_no LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("documents.title LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('type', fn ($query) =>
                    $query->whereRaw("document_types.type_name LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhereHas('routes', function ($query) use (
                    $officeColumn,
                    $officeId,
                    $officeRelation,
                    $officeNameColumn,
                    $pattern
                ) {
                    $this->constrainNewestRelevantRoute(
                        $query,
                        $officeColumn,
                        $officeId
                    );
                    $query->whereHas($officeRelation, fn ($query) =>
                        $query->whereRaw("{$officeNameColumn} LIKE ? ESCAPE '!'", [$pattern]));
                });
        });
    }

    private function applyIncomingState(
        $query,
        ?string $state,
        int $officeId
    ): void {
        if ($state === null) {
            return;
        }

        $query->whereHas('routes', function ($query) use ($state, $officeId) {
            $this->constrainNewestRelevantRoute(
                $query,
                'to_office_id',
                $officeId
            );

            if ($state === 'pending') {
                $query->whereNull('received_at');
            } else {
                $query->whereNotNull('received_at');
            }
        });
    }

    private function constrainNewestRelevantRoute(
        $query,
        string $officeColumn,
        int $officeId
    ): void {
        $query
            ->where($officeColumn, $officeId)
            ->whereRaw(
                "document_routes.id = (".
                "select max(latest_route.id) from document_routes as latest_route ".
                "where latest_route.document_id = documents.id ".
                "and latest_route.{$officeColumn} = ?)",
                [$officeId]
            );
    }

    private function escapedLikePattern(string $search): string
    {
        return '%'.str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $search
        ).'%';
    }

    private function serializeListPagination(
        LengthAwarePaginator $paginator,
        array $filters,
        string $view
    ): array {
        $approvedQuery = [
            'per_page' => $filters['per_page'],
        ];

        if ($filters['search'] !== '') {
            $approvedQuery['search'] = $filters['search'];
        }

        if ($view === 'incoming' && $filters['state'] !== null) {
            $approvedQuery['state'] = $filters['state'];
        }

        $paginator->appends($approvedQuery);

        return [
            'data' => $paginator->getCollection()
                ->map(fn (Document $document): array =>
                    $this->serializeListDocument($document, $view))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }

    /**
     * Return only fields consumed by the active document list.
     */
    private function serializeListDocument(
        Document $document,
        string $view = 'all'
    ): array {
        $data = [
            'id' => $document->id,
            'tracking_no' => $document->tracking_no,
            'title' => $document->title,
            'type' => $document->type
                ? [
                    'id' => $document->type->id,
                    'type_name' => $document->type->type_name,
                ]
                : null,
        ];

        if ($view === 'all') {
            $status = $document->relationLoaded('status')
                ? $document->getRelation('status')
                : null;

            return [
                ...$data,
                'status' => $status
                    ? [
                        'id' => $status->id,
                        'status_name' => $status->status_name,
                    ]
                    : null,
                'priority' => $document->priority
                    ? [
                        'id' => $document->priority->id,
                        'priority_name' =>
                            $document->priority->priority_name,
                    ]
                    : null,
                'current_office' => $document->currentOffice
                    ? [
                        'id' => $document->currentOffice->id,
                        'office_name' =>
                            $document->currentOffice->office_name,
                    ]
                    : null,
                'created_at' => $document->created_at,
            ];
        }

        $route = $document->routes->first();

        if ($view === 'incoming') {
            $routeData = [
                'from_office' => $route?->fromOffice
                    ? [
                        'id' => $route->fromOffice->id,
                        'office_name' =>
                            $route->fromOffice->office_name,
                    ]
                    : null,
                'received_at' => $route?->received_at,
            ];
        } else {
            $routeData = [
                'to_office' => $route?->toOffice
                    ? [
                        'id' => $route->toOffice->id,
                        'office_name' =>
                            $route->toOffice->office_name,
                    ]
                    : null,
                'forwarded_at' => $route?->forwarded_at,
            ];
        }

        return [
            ...$data,
            'routes' => [$routeData],
        ];
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
    public function store(
    Request $request,
    AuditLogger $auditLogger)

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

            /*
            |--------------------------------------------------------------------------
            | Optional QR token
            |--------------------------------------------------------------------------
            |
            | Normal manual registration may omit this field.
            | QR-based registration supplies the issued token.
            |
            */

            'qr_token' => [
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        $document = DB::transaction(
            function () use (
                $validated,
                $request,
                $auditLogger
            ) {
                /*
                |--------------------------------------------------------------------------
                | Validate and lock issued QR when supplied
                |--------------------------------------------------------------------------
                |
                | lockForUpdate prevents two users from registering the same QR at
                | nearly the same time.
                |
                */

                $qrCode = null;

                if (!empty($validated['qr_token'])) {
                    $qrCode =
                        DocumentQrCode::where(
                            'qr_token',
                            $validated['qr_token']
                        )
                            ->lockForUpdate()
                            ->first();

                    if (!$qrCode) {
                        throw ValidationException::withMessages([
                            'qr_token' =>
                                'The QR code is invalid or does not exist.',
                        ]);
                    }

                    if ($qrCode->status === 'void') {
                        throw ValidationException::withMessages([
                            'qr_token' =>
                                'This QR code has been voided and can no longer be used.',
                        ]);
                    }

                    if (
                        $qrCode->status !== 'unused' ||
                        $qrCode->document_id
                    ) {
                        throw ValidationException::withMessages([
                            'qr_token' =>
                                'This QR code has already been registered to a document.',
                        ]);
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Default document status
                |--------------------------------------------------------------------------
                */

                $pendingStatus =
                    DocumentStatus::where(
                        'status_name',
                        'Pending'
                    )->firstOrFail();

                $registeredAction =
                    ProcessingAction::where(
                        'action_code',
                        'REGISTERED'
                    )
                        ->where(
                            'is_active',
                            true
                        )
                        ->firstOrFail();

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

                    'current_action_id' =>
                        $registeredAction->id,

                    'processing_note' =>
                        null,

                    'current_action_updated_by' =>
                        $request->user()->id,

                    'current_action_updated_at' =>
                        now(),

                    'created_by' =>
                        $request->user()->id,

                    'document_date' =>
                        $validated['document_date'],

                    'due_date' =>
                        $validated['due_date'] ?? null,
                ]);

                /*
                |--------------------------------------------------------------------------
                | Processing history: registered
                |--------------------------------------------------------------------------
                */

                DocumentProcessingLog::create([
                    'document_id' =>
                        $document->id,

                    'office_id' =>
                        $document->current_office_id,

                    'user_id' =>
                        $request->user()->id,

                    'processing_action_id' =>
                        $registeredAction->id,

                    'event_type' =>
                        'registered',

                    'processing_note' =>
                        null,

                    'event_note' =>
                        'Document registered.',
                ]);

                /*
                |--------------------------------------------------------------------------
                | Activate / link QR
                |--------------------------------------------------------------------------
                */

                if ($qrCode) {
                    $qrCode->update([
                        'document_id' =>
                            $document->id,

                        'status' =>
                            'registered',

                        'registered_at' =>
                            now(),
                    ]);

                    $auditLogger->log(
                        module: AuditLog::MODULE_QR_CODES,
                        action: AuditLog::ACTION_REGISTERED,
                        recordId: $qrCode->id,
                        description: "QR linked to document ID {$document->id}.",
                        userId: $request->user()->id
                    );
                }

                $auditLogger->log(
                    module: AuditLog::MODULE_DOCUMENTS,
                    action: AuditLog::ACTION_CREATED,
                    recordId: $document->id,
                    description: 'Document registered successfully.',
                    userId: $request->user()->id
                );

                return $document;
            }
        );

        $document->load([
            'type',
            'status',
            'priority',
            'confidentiality',
            'originOffice',
            'currentOffice',
            'currentAction',
            'currentActionUpdatedBy',
            'creator',
        ]);

        return response()->json([
            'message' =>
                !empty($validated['qr_token'])
                    ? 'Document registered and QR code activated successfully'
                    : 'Document registered successfully',

            'document' =>
                $document,

            'qr_linked' =>
                !empty($validated['qr_token']),
        ], 201);
    }

    /**
     * Display a single document.
     */
    public function show(
        Request $request,
        DocumentReadScope $readScope,
        $id
    )
    {
        $document = Document::findOrFail($id);
        $readScope->authorize($request->user(), $document);

        $document->load([
            'type',
            'status',
            'priority',
            'confidentiality',
            'originOffice',
            'currentOffice',
            'currentAction',
            'currentActionUpdatedBy',
            'creator',

        ]);

        /*
        |--------------------------------------------------------------------------
        | Canonical issued QR
        |--------------------------------------------------------------------------
        |
        | A document may have been created through the pre-issued QR workflow.
        | Return that registered QR record so DocumentDetails.vue can display the
        | same permanent QR token instead of creating a second tracking-number QR.
        |
        */

        $qrCode = DocumentQrCode::where(
            'document_id',
            $document->id
        )
            ->where(
                'status',
                'registered'
            )
            ->latest('id')
            ->first();

        return response()->json([
            'id' => $document->id,
            'tracking_no' => $document->tracking_no,
            'title' => $document->title,
            'description' => $document->description,
            'document_type_id' => $document->document_type_id,
            'status_id' => $document->status_id,
            'priority_id' => $document->priority_id,
            'confidentiality_level_id' =>
                $document->confidentiality_level_id,
            'origin_office_id' => $document->origin_office_id,
            'current_office_id' => $document->current_office_id,
            'document_date' => $document->document_date,
            'due_date' => $document->due_date,
            'type' => $this->namedRelation($document->type, 'type_name'),
            'status' => $this->namedRelation(
                $document->status,
                'status_name'
            ),
            'priority' => $this->namedRelation(
                $document->priority,
                'priority_name'
            ),
            'confidentiality' => $this->namedRelation(
                $document->confidentiality,
                'level_name'
            ),
            'origin_office' => $this->officeShape($document->originOffice),
            'current_office' => $this->officeShape($document->currentOffice),
            'current_action' => $document->currentAction
                ? [
                    'id' => $document->currentAction->id,
                    'action_code' => $document->currentAction->action_code,
                    'action_name' => $document->currentAction->action_name,
                ]
                : null,
            'current_action_updated_by' => $this->userShape(
                $document->currentActionUpdatedBy
            ),
            'creator' => $this->userShape($document->creator),
            'qr_code' => $qrCode
                ? [
                    'id' => $qrCode->id,
                    'qr_token' => $qrCode->qr_token,
                    'status' => $qrCode->status,
                ]
                : null,
        ]);
    }

    /**
     * Update a document.
     */
    public function update(
    Request $request,
    AuditLogger $auditLogger,
    $id
    )

    {
        $document = Document::findOrFail($id);
        $user = $request->user();

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
                    'You cannot update this document because it is not currently assigned to your office.',
            ], 403);
        }

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

            'origin_office_id' => ['prohibited'],

            'current_office_id' => ['prohibited'],

            'document_date' =>
                'sometimes|required|date',

            'due_date' =>
                'nullable|date|after_or_equal:document_date',

            'status_id' => ['prohibited'],
        ]);

            DB::transaction(
                function () use (
                    $document,
                    $validated,
                    $request,
                    $auditLogger
                ) {
                    $document->update($validated);

                    $auditLogger->log(
                        module: AuditLog::MODULE_DOCUMENTS,
                        action: AuditLog::ACTION_UPDATED,
                        recordId: $document->id,
                        description: 'Document updated successfully.',
                        userId: $request->user()->id
                    );
                }
            );

        $document->load([
            'type',
            'status',
            'priority',
            'confidentiality',
            'originOffice',
            'currentOffice',
            'currentAction',
            'currentActionUpdatedBy',
            'creator',
        ]);

        return response()->json([
            'message' =>
                'Document updated successfully',

            'document' =>
                $document,
        ]);
    }

    private function namedRelation($model, string $nameField): ?array
    {
        return $model
            ? [
                'id' => $model->id,
                $nameField => $model->getAttribute($nameField),
            ]
            : null;
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

    /**
     * Delete a document.
     */
        public function destroy(
            Request $request,
            AuditLogger $auditLogger,
            $id
        ) {
            $document = Document::findOrFail($id);

            DB::transaction(
                function () use (
                    $document,
                    $request,
                    $auditLogger
                ) {
                    $documentId = $document->id;
                    $trackingNumber = $document->tracking_no;

                    $document->delete();

                    $auditLogger->log(
                        module: AuditLog::MODULE_DOCUMENTS,
                        action: AuditLog::ACTION_DELETED,
                        recordId: $documentId,
                        description:
                            'Document ' .
                            $trackingNumber .
                            ' was deleted.',
                        userId: $request->user()->id
                    );
                }
            );

            return response()->json([
                'message' => 'Document deleted successfully',
            ]);
        }
}
