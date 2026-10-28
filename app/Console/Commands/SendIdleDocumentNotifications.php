<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Notification;
use App\Services\InAppNotificationService;
use Illuminate\Console\Command;

class SendIdleDocumentNotifications extends Command
{
    protected $signature = 'documents:notify-idle';

    protected $description = 'Notify the responsible office about documents with no recorded action.';

    public function handle(InAppNotificationService $notifications): int
    {
        $days = max(1, (int) config('doctrack.idle_notification_days', 3));
        $cutoff = now()->subDays($days);
        $sent = 0;

        Document::query()
            ->whereNull('completed_at')
            ->whereNull('archived_at')
            ->whereNotNull('current_office_id')
            ->where(function ($query) use ($cutoff): void {
                $query->where('current_action_updated_at', '<=', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->whereNull('current_action_updated_at')
                            ->where('created_at', '<=', $cutoff);
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($documents) use ($notifications, &$sent): void {
                foreach ($documents as $document) {
                    $idleSince = $document->current_action_updated_at ?? $document->created_at;
                    $before = $this->notificationCount($document->id);
                    $notifications->documentIdle($document, $idleSince);
                    $sent += $this->notificationCount($document->id) - $before;
                }
            });

        $this->info("Created {$sent} idle-document notification(s).");

        return self::SUCCESS;
    }

    private function notificationCount(int $documentId): int
    {
        return Notification::query()
            ->where('document_id', $documentId)
            ->where('type', InAppNotificationService::TYPE_DOCUMENT_IDLE)
            ->count();
    }
}
