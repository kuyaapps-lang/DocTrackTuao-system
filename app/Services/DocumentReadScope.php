<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DocumentReadScope
{
    public function authorize(User $user, Document $document): void
    {
        if ($document->confidentiality?->level_name === 'Public') {
            return;
        }

        $officeId = $user->office_id;

        if (
            $officeId === null ||
            !DB::table('offices')->where('id', $officeId)->exists()
        ) {
            abort(403, 'Your user account is not assigned to a valid office.');
        }

        $officeId = (int) $officeId;
        $creatorOfficeId = DB::table('users')->where('id', $document->created_by)->value('office_id');
        $inOfficeUniverse =
            (int) $creatorOfficeId === $officeId ||
            (int) $document->origin_office_id === $officeId ||
            (int) $document->current_office_id === $officeId ||
            DB::table('document_office_tags')->where('document_id', $document->id)->where('office_id', $officeId)->exists() ||
            DB::table('document_routes')
                ->where('document_id', $document->id)
                ->where(function ($query) use ($officeId): void {
                    $query
                        ->where('from_office_id', $officeId)
                        ->orWhere('to_office_id', $officeId);
                })
                ->exists();

        if (!$inOfficeUniverse) {
            abort(403, 'You are not authorized to view this document.');
        }
    }
}
