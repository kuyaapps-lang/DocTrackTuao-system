<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\Notification;
use App\Models\PasswordResetRequest;
use App\Models\QrCodeRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class InAppNotificationService
{
    public function __construct(private readonly RealtimeBroadcaster $realtime) {}
    public const TYPE_QR_REQUEST_SUBMITTED = 'qr_request_submitted';

    public const TYPE_QR_REQUEST_APPROVED = 'qr_request_approved';

    public const TYPE_QR_REQUEST_REJECTED = 'qr_request_rejected';

    public const TYPE_DOCUMENT_FORWARDED = 'document_forwarded';

    public const TYPE_DOCUMENT_RECEIVED = 'document_received';

    public const TYPE_PASSWORD_RESET_SUBMITTED = 'password_reset_submitted';

    public const TYPE_PASSWORD_RESET_RESOLVED = 'password_reset_resolved';

    public const TYPE_PASSWORD_RESET_REJECTED = 'password_reset_rejected';

    public function qrRequestSubmitted(QrCodeRequest $request): void
    {
        $this->createForUsers(
            $this->usersWithPermission('qr.approve'),
            self::TYPE_QR_REQUEST_SUBMITTED,
            'QR request submitted',
            'A QR code request is ready for review.',
            '/qr-codes'
        );
    }

    public function qrRequestReviewed(QrCodeRequest $request): void
    {
        $type = $request->status === QrCodeRequest::STATUS_APPROVED
            ? self::TYPE_QR_REQUEST_APPROVED
            : self::TYPE_QR_REQUEST_REJECTED;
        $title = $request->status === QrCodeRequest::STATUS_APPROVED
            ? 'QR request approved'
            : 'QR request rejected';

        $this->createForUsers(
            $this->usersByIds([$request->requested_by_user_id]),
            $type,
            $title,
            'Your QR code request has been reviewed.',
            '/qr-codes'
        );
    }

    public function documentForwarded(DocumentRoute $route): void
    {
        $this->createForUsers(
            $this->usersWithPermission('documents.route', $route->to_office_id),
            self::TYPE_DOCUMENT_FORWARDED,
            'Document forwarded',
            'A document was forwarded to your office.',
            '/documents/'.$route->document_id,
            $route->document_id
        );
    }

    public function documentReceived(DocumentRoute $route): void
    {
        $this->createForUsers(
            $this->usersByIds([$route->forwarded_by]),
            self::TYPE_DOCUMENT_RECEIVED,
            'Document received',
            'A document forwarded by your office was received.',
            '/documents/'.$route->document_id,
            $route->document_id
        );
    }

    public function passwordResetSubmitted(PasswordResetRequest $request): void
    {
        $this->createForUsers(
            $this->usersWithPermission('users.manage'),
            self::TYPE_PASSWORD_RESET_SUBMITTED,
            'Password reset request submitted',
            'A password reset request is ready for review.',
            '/users'
        );
    }

    public function passwordResetReviewed(PasswordResetRequest $request): void
    {
        $type = $request->status === PasswordResetRequest::STATUS_RESOLVED
            ? self::TYPE_PASSWORD_RESET_RESOLVED
            : self::TYPE_PASSWORD_RESET_REJECTED;
        $title = $request->status === PasswordResetRequest::STATUS_RESOLVED
            ? 'Password reset request resolved'
            : 'Password reset request rejected';

        $this->createForUsers(
            $this->usersByIds([$request->user_id]),
            $type,
            $title,
            'Your password reset request has been reviewed.',
            '/dashboard'
        );
    }

    /**
     * Return a link only when the notification's recipient may still open it.
     */
    public function accessibleLink(User $user, Notification $notification): ?string
    {
        $link = $notification->link;

        if (! is_string($link) || ! str_starts_with($link, '/') || str_starts_with($link, '//')) {
            return null;
        }

        if ($notification->document_id) {
            $document = Document::find($notification->document_id);

            if (! $document || ! $user->hasPermission('documents.view') || ! $this->canReadDocument($user, $document)) {
                return null;
            }
        }

        if ($link === '/qr-codes' && ! $user->hasPermission('qr.request')) {
            return null;
        }

        if ($link === '/users' && ! $user->hasPermission('users.manage')) {
            return null;
        }

        if ($link === '/dashboard' && ! $user->hasPermission('reports.view')) {
            return null;
        }

        return $link;
    }

    private function createForUsers(
        Collection $users,
        string $type,
        string $title,
        string $message,
        string $link,
        ?int $documentId = null
    ): void {
        // Several legacy, isolated tests provide only the tables under test.
        // A deployed application has this table through the existing migration.
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $now = now();
        $users->unique('id')->each(function (User $user) use ($type, $title, $message, $link, $documentId, $now): void {
            Notification::query()->create([
                'user_id' => $user->id,
                'document_id' => $documentId,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'link' => $link,
                'is_read' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->realtime->notification((int) $user->id);
        });
    }

    private function usersWithPermission(string $permission, ?int $officeId = null): Collection
    {
        return User::query()
            ->with('role')
            ->when($officeId !== null, fn ($query) => $query->where('office_id', $officeId))
            ->get()
            ->filter(fn (User $user) => $user->hasPermission($permission))
            ->values();
    }

    private function usersByIds(array $ids): Collection
    {
        $ids = array_values(array_filter($ids));

        if ($ids === []) {
            return collect();
        }

        return User::query()
            ->with('role')
            ->whereIn('id', $ids)
            ->get();
    }

    private function canReadDocument(User $user, Document $document): bool
    {
        if ($user->hasRole('Administrator') || $user->hasRole('Records Officer')) {
            return true;
        }

        if (! $user->office_id || ! Schema::hasTable('document_routes')) {
            return false;
        }

        $officeId = (int) $user->office_id;

        return (int) $document->origin_office_id === $officeId
            || (int) $document->current_office_id === $officeId
            || DocumentRoute::query()
                ->where('document_id', $document->id)
                ->where(fn ($query) => $query
                    ->where('from_office_id', $officeId)
                    ->orWhere('to_office_id', $officeId))
                ->exists();
    }
}
