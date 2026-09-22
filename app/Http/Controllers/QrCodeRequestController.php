<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DocumentQrCode;
use App\Models\QrCodeRequest;
use App\Services\AuditLogger;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QrCodeRequestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'status' => [
                'sometimes',
                'string',
                Rule::in([
                    QrCodeRequest::STATUS_PENDING,
                    QrCodeRequest::STATUS_APPROVED,
                    QrCodeRequest::STATUS_REJECTED,
                ]),
            ],
        ]);

        $query = QrCodeRequest::query()
            ->with(['requestedBy', 'requestedOffice', 'reviewedBy', 'qrCodes'])
            ->when(
                isset($validated['status']),
                fn ($builder) => $builder->where('status', $validated['status'])
            )
            ->latest('id');

        if (!$user->hasPermission('qr.approve')) {
            if (!$user->office_id) {
                abort(403);
            }

            $query->where('requested_office_id', $user->office_id);
        }

        return response()->json([
            'data' => $query->get()
                ->map(fn (QrCodeRequest $qrRequest): array => $this->requestShape($qrRequest))
                ->values(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger)
    {
        $user = $request->user();

        if (!$user->office_id) {
            abort(403);
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'purpose' => ['nullable', 'string', 'max:2000'],
        ]);

        $qrRequest = QrCodeRequest::create([
            'requested_by_user_id' => $user->id,
            'requested_office_id' => $user->office_id,
            'quantity' => $validated['quantity'],
            'purpose' => $validated['purpose'] ?? null,
            'status' => QrCodeRequest::STATUS_PENDING,
        ]);

        $auditLogger->log(
            module: AuditLog::MODULE_QR_CODE_REQUESTS,
            action: AuditLog::ACTION_REQUESTED,
            recordId: $qrRequest->id,
            description: 'QR code request submitted.',
            userId: $user->id
        );

        return response()->json([
            'message' => 'QR code request submitted.',
            'request' => $this->requestShape($qrRequest->load(['requestedBy', 'requestedOffice'])),
        ], 201);
    }

    public function approve(
        Request $request,
        AuditLogger $auditLogger,
        QrCodeRequest $qrCodeRequest
    ) {
        $validated = $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        $result = DB::transaction(function () use ($qrCodeRequest, $validated, $user) {
            $lockedRequest = QrCodeRequest::whereKey($qrCodeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== QrCodeRequest::STATUS_PENDING) {
                throw new HttpResponseException(response()->json([
                    'message' => 'This QR code request has already been reviewed.',
                ], 409));
            }

            $qrCodes = collect();

            for ($index = 0; $index < $lockedRequest->quantity; $index++) {
                $qrCodes->push(DocumentQrCode::create([
                    'qr_token' => $this->generateQrToken(),
                    'status' => 'unused',
                    'document_id' => null,
                    'qr_code_request_id' => $lockedRequest->id,
                    'assigned_office_id' => $lockedRequest->requested_office_id,
                    'generated_by' => $user->id,
                    'generated_at' => now(),
                ]));
            }

            $lockedRequest->update([
                'status' => QrCodeRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now(),
                'review_note' => $validated['review_note'] ?? null,
            ]);

            return [$lockedRequest->fresh(['requestedBy', 'requestedOffice', 'reviewedBy', 'qrCodes']), $qrCodes];
        });

        [$approvedRequest, $qrCodes] = $result;

        $auditLogger->log(
            module: AuditLog::MODULE_QR_CODE_REQUESTS,
            action: AuditLog::ACTION_APPROVED,
            recordId: $approvedRequest->id,
            description: 'QR code request approved.',
            userId: $user->id
        );

        foreach ($qrCodes as $qrCode) {
            $auditLogger->log(
                module: AuditLog::MODULE_QR_CODES,
                action: AuditLog::ACTION_GENERATED,
                recordId: $qrCode->id,
                description: 'QR code generated and assigned to approved request.',
                userId: $user->id
            );
        }

        return response()->json([
            'message' => 'QR code request approved.',
            'request' => $this->requestShape($approvedRequest),
        ]);
    }

    public function reject(
        Request $request,
        AuditLogger $auditLogger,
        QrCodeRequest $qrCodeRequest
    ) {
        $validated = $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        $rejectedRequest = DB::transaction(function () use ($qrCodeRequest, $validated, $user) {
            $lockedRequest = QrCodeRequest::whereKey($qrCodeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== QrCodeRequest::STATUS_PENDING) {
                throw new HttpResponseException(response()->json([
                    'message' => 'This QR code request has already been reviewed.',
                ], 409));
            }

            $lockedRequest->update([
                'status' => QrCodeRequest::STATUS_REJECTED,
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now(),
                'review_note' => $validated['review_note'] ?? null,
            ]);

            return $lockedRequest->fresh(['requestedBy', 'requestedOffice', 'reviewedBy', 'qrCodes']);
        });

        $auditLogger->log(
            module: AuditLog::MODULE_QR_CODE_REQUESTS,
            action: AuditLog::ACTION_REJECTED,
            recordId: $rejectedRequest->id,
            description: 'QR code request rejected.',
            userId: $user->id
        );

        return response()->json([
            'message' => 'QR code request rejected.',
            'request' => $this->requestShape($rejectedRequest),
        ]);
    }

    private function requestShape(QrCodeRequest $qrRequest): array
    {
        return [
            'id' => (int) $qrRequest->id,
            'quantity' => (int) $qrRequest->quantity,
            'purpose' => $qrRequest->purpose,
            'status' => (string) $qrRequest->status,
            'requested_by' => $qrRequest->requestedBy
                ? [
                    'id' => (int) $qrRequest->requestedBy->id,
                    'name' => (string) $qrRequest->requestedBy->name,
                ]
                : null,
            'requested_office' => $qrRequest->requestedOffice
                ? [
                    'id' => (int) $qrRequest->requestedOffice->id,
                    'office_name' => (string) $qrRequest->requestedOffice->office_name,
                ]
                : null,
            'reviewed_by' => $qrRequest->reviewedBy
                ? [
                    'id' => (int) $qrRequest->reviewedBy->id,
                    'name' => (string) $qrRequest->reviewedBy->name,
                ]
                : null,
            'reviewed_at' => $qrRequest->reviewed_at
                ? Carbon::parse($qrRequest->reviewed_at)->toIso8601String()
                : null,
            'review_note' => $qrRequest->review_note,
            'qr_codes' => $qrRequest->qrCodes
                ->map(fn (DocumentQrCode $qrCode): array => [
                    'id' => (int) $qrCode->id,
                    'qr_token' => (string) $qrCode->qr_token,
                    'status' => (string) $qrCode->status,
                    'linked' => $qrCode->document_id !== null,
                    'scan_path' => '/q/' . $qrCode->qr_token,
                ])
                ->values(),
            'created_at' => $qrRequest->created_at?->toIso8601String(),
            'updated_at' => $qrRequest->updated_at?->toIso8601String(),
        ];
    }

    private function generateQrToken(): string
    {
        $characters = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        $makePart = function (int $length) use ($characters): string {
            $result = '';
            $maxIndex = strlen($characters) - 1;

            for ($index = 0; $index < $length; $index++) {
                $result .= $characters[random_int(0, $maxIndex)];
            }

            return $result;
        };

        do {
            $token = $makePart(5) . '-' . $makePart(7);
        } while (DocumentQrCode::where('qr_token', $token)->exists());

        return $token;
    }
}
