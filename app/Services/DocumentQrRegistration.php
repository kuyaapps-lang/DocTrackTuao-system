<?php

namespace App\Services;

use App\Models\DocumentQrCode;
use App\Models\User;
use App\Support\PublicLookupSecurity;
use App\Support\QrTokenInput;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DocumentQrRegistration
{
    public function verify(User $user, string $input, bool $lock = false): DocumentQrCode
    {
        $token = QrTokenInput::normalize($input);

        if (!PublicLookupSecurity::validQrToken($token)) {
            $this->reject('The QR code is invalid or does not exist.');
        }

        $query = DocumentQrCode::where('qr_token', $token);

        if ($lock) {
            $query->lockForUpdate();
        }

        $qrCode = $query->first();

        if (!$qrCode) {
            $this->reject('The QR code is invalid or does not exist.');
        }

        if ($qrCode->status === 'void') {
            $this->reject('This QR code has been voided and can no longer be used.');
        }

        if ($qrCode->status !== 'unused' || $qrCode->document_id !== null) {
            $this->reject('This QR code has already been registered to a document.');
        }

        if (
            Schema::hasColumn('document_qr_codes', 'assigned_office_id') &&
            $qrCode->assigned_office_id !== null &&
            !$user->hasRole('Administrator') &&
            (int) $qrCode->assigned_office_id !== (int) $user->office_id
        ) {
            $this->reject('This QR code is assigned to another office.');
        }

        return $qrCode;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages([
            'qr_token' => [$message],
        ]);
    }
}
