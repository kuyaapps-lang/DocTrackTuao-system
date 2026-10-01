<?php

namespace App\Services;

use App\Events\RealtimeInvalidated;
use App\Models\Document;
use App\Models\DocumentQrCode;
use App\Models\QrCodeRequest;
use Illuminate\Support\Facades\Log;

class RealtimeBroadcaster
{
    public function document(Document $document, string $type, array $areas): void
    {
        $officeIds = $document->routes()
            ->get(['from_office_id', 'to_office_id'])
            ->flatMap(fn ($route) => [$route->from_office_id, $route->to_office_id])
            ->push($document->origin_office_id, $document->current_office_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $channels = $officeIds->map(fn (int $id) => "doc-track.documents.office.{$id}")
            ->push('doc-track.documents.system', "doc-track.document.{$document->id}")
            ->all();

        $this->dispatch($channels, $type, $document->id, $areas);
    }

    public function qrRequest(QrCodeRequest $request, string $type): void
    {
        $this->dispatch([
            'doc-track.qr.approvers',
            'doc-track.qr.office.'.$request->requested_office_id,
            'doc-track.user.'.$request->requested_by_user_id,
        ], $type, $request->id, ['qr-requests', 'qr-summary', 'qr-inventory']);
    }

    public function qr(DocumentQrCode $qrCode, string $type): void
    {
        $channels = ['doc-track.qr.approvers'];
        if ($qrCode->assigned_office_id) $channels[] = 'doc-track.qr.office.'.$qrCode->assigned_office_id;
        if ($qrCode->generated_by) $channels[] = 'doc-track.user.'.$qrCode->generated_by;
        $this->dispatch($channels, $type, $qrCode->id, ['qr-summary', 'qr-inventory', 'qr-requests']);
    }

    public function passwordReset(int $requestId, ?int $userId, string $type): void
    {
        $channels = ['doc-track.password-reset.admins'];
        if ($userId) $channels[] = 'doc-track.user.'.$userId;
        $this->dispatch($channels, $type, $requestId, ['password-reset-requests']);
    }

    public function notification(int $userId): void
    {
        $this->dispatch(['doc-track.user.'.$userId], 'notification.changed', null, ['notifications']);
    }

    private function dispatch(array $channels, string $type, ?int $resourceId, array $areas): void
    {
        try {
            event(new RealtimeInvalidated(array_values(array_unique($channels)), $type, $resourceId, $areas));
        } catch (\Throwable $exception) {
            // Realtime is an enhancement; an unavailable socket must not fail a workflow mutation.
            Log::warning('Realtime broadcast failed.', ['type' => $type, 'exception' => $exception->getMessage()]);
        }
    }
}
