<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentReadScope;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('doc-track.user.{id}', fn (User $user, int $id) => (int) $user->id === $id, ['guards' => ['sanctum']]);
Broadcast::channel('doc-track.documents.system', fn (User $user) => $user->hasPermission('documents.view') && ($user->hasRole('Administrator') || $user->hasRole('Records Officer')), ['guards' => ['sanctum']]);
Broadcast::channel('doc-track.documents.office.{officeId}', fn (User $user, int $officeId) => $user->hasPermission('documents.view') && (int) $user->office_id === $officeId, ['guards' => ['sanctum']]);
Broadcast::channel('doc-track.document.{document}', function (User $user, Document $document) {
    if (! $user->hasPermission('documents.view')) return false;
    try { app(DocumentReadScope::class)->authorize($user, $document); return true; } catch (\Throwable) { return false; }
}, ['guards' => ['sanctum']]);
Broadcast::channel('doc-track.qr.approvers', fn (User $user) => $user->hasPermission('qr.approve'), ['guards' => ['sanctum']]);
Broadcast::channel('doc-track.qr.office.{officeId}', fn (User $user, int $officeId) => $user->hasPermission('qr.request') && (int) $user->office_id === $officeId, ['guards' => ['sanctum']]);
Broadcast::channel('doc-track.password-reset.admins', fn (User $user) => $user->hasPermission('users.manage'), ['guards' => ['sanctum']]);
