<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DocumentQrCode;
use App\Services\AuditLogger;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentQrCodeController extends Controller
{
    /**
     * Display issued QR codes.
     */
    public function index()
    {
        $qrCodes = DocumentQrCode::with([
            'generatedBy',
            'document',
        ])
            ->latest('id')
            ->get();

        return response()->json($qrCodes);
    }

    /**
     * Generate one or multiple QR codes.
     */
    public function store(Request $request, AuditLogger $auditLogger)
    {
        $validated = $request->validate([
            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:50',
            ],
        ]);

        $quantity = $validated['quantity'];
        $user = $request->user();

        $qrCodes = DB::transaction(
            function () use ($quantity, $user) {
                $created = collect();

                for ($i = 0; $i < $quantity; $i++) {
                    $qrCode = DocumentQrCode::create([
                        'qr_token' => $this->generateQrToken(),
                        'status' => 'unused',
                        'generated_by' => $user->id,
                        'generated_at' => now(),
                    ]);

                    $qrCode->load([
                        'generatedBy',
                        'document',
                    ]);

                    $created->push($qrCode);
                }

                return $created;
            }
        );

        foreach ($qrCodes as $qrCode) {
            $auditLogger->log(
                module: AuditLog::MODULE_QR_CODES,
                action: AuditLog::ACTION_GENERATED,
                recordId: $qrCode->id,
                description: 'QR code generated successfully.',
                userId: $user->id
            );
        }

        return response()->json([
            'message' =>
                $quantity === 1
                    ? 'QR code generated successfully.'
                    : $quantity . ' QR codes generated successfully.',

            'quantity' => $quantity,
            'qr_codes' => $qrCodes,

            'scan_paths' =>
                $qrCodes
                    ->map(
                        fn ($qrCode) =>
                            '/q/' . $qrCode->qr_token
                    )
                    ->values(),
        ], 201);
    }

    /**
     * Display one QR record internally.
     */
    public function show($id)
    {
        $qrCode = DocumentQrCode::with([
            'generatedBy',
            'document',
        ])->findOrFail($id);

        return response()->json($qrCode);
    }

    /**
     * Void an unused QR code.
     */
    public function void(
        Request $request,
        AuditLogger $auditLogger,
        $id
    )
    {
        $qrCode = DB::transaction(function () use ($id, $request, $auditLogger) {
            $qrCode = DocumentQrCode::whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($qrCode->status === 'registered') {
                throw new HttpResponseException(response()->json([
                    'message' =>
                        'This QR code cannot be voided because it is already linked to a registered document.',
                ], 409));
            }

            if ($qrCode->status === 'void') {
                throw new HttpResponseException(response()->json([
                    'message' => 'This QR code has already been voided.',
                ], 409));
            }

            if ($qrCode->status !== 'unused') {
                throw new HttpResponseException(response()->json([
                    'message' => 'This QR code is not in a voidable state.',
                ], 409));
            }

            $qrCode->update(['status' => 'void']);

            $auditLogger->log(
                module: AuditLog::MODULE_QR_CODES,
                action: AuditLog::ACTION_VOIDED,
                recordId: $qrCode->id,
                description: 'QR code voided successfully.',
                userId: $request->user()->id
            );

            return $qrCode;
        });

        return response()->json([
            'message' => 'QR code voided successfully.',
            'qr_code' => $qrCode,
        ]);
    }

    /**
     * Resolve a scanned QR code.
     *
     * Public endpoint.
     */
    public function resolve($token)
    {
        $qrCode = DocumentQrCode::with([
            'document',
        ])
            ->where('qr_token', $token)
            ->first();

        if (!$qrCode) {
            return response()->json([
                'state' => 'invalid',
                'message' =>
                    'The QR code is invalid or does not exist.',
            ], 404);
        }

        if ($qrCode->status === 'void') {
            return response()->json([
                'state' => 'void',
                'message' =>
                    'This QR code has been voided and can no longer be used.',
            ], 410);
        }

        if ($qrCode->status === 'unused') {
            return response()->json([
                'state' => 'unused',
                'qr_token' => $qrCode->qr_token,
                'message' =>
                    'This QR code is ready for document registration.',
                'registration_path' =>
                    '/register-document/' . $qrCode->qr_token,
            ]);
        }

        if (
            $qrCode->status === 'registered' &&
            $qrCode->document
        ) {
            return response()->json([
                'state' => 'registered',
                'qr_token' => $qrCode->qr_token,
                'tracking_no' =>
                    $qrCode->document->tracking_no,
                'tracking_path' =>
                    '/track/' .
                    $qrCode->document->tracking_no,
            ]);
        }

        return response()->json([
            'state' => 'invalid',
            'message' =>
                'The QR code is not in a valid state.',
        ], 409);
    }

    /**
     * Generate a human-friendly unique token:
     * XXXXX-XXXXXXX
     *
     * Uses uppercase letters and numbers while excluding
     * easily confused characters: 0, O, 1, I and L.
     */
    private function generateQrToken(): string
    {
        $characters =
            'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        $makePart = function (
            int $length
        ) use ($characters): string {
            $result = '';
            $maxIndex =
                strlen($characters) - 1;

            for ($i = 0; $i < $length; $i++) {
                $result .=
                    $characters[
                        random_int(
                            0,
                            $maxIndex
                        )
                    ];
            }

            return $result;
        };

        do {
            $token =
                $makePart(5) .
                '-' .
                $makePart(7);

        } while (
            DocumentQrCode::where(
                'qr_token',
                $token
            )->exists()
        );

        return $token;
    }
}
