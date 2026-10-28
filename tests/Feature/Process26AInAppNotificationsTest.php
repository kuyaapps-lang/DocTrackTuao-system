<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\Notification;
use App\Models\Office;
use App\Models\PasswordResetRequest;
use App\Models\QrCodeRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\InAppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Process26AInAppNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $requester;

    private User $destinationOfficer;

    private User $wrongOfficeUser;

    private Office $officeA;

    private Office $officeB;

    private Office $officeC;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Administrator', 'Records Officer', 'Office User', 'Viewer'] as $name) {
            Role::query()->create(['name' => $name]);
        }

        $this->officeA = Office::query()->create(['office_name' => 'Office A', 'office_code' => 'A']);
        $this->officeB = Office::query()->create(['office_name' => 'Office B', 'office_code' => 'B']);
        $this->officeC = Office::query()->create(['office_name' => 'Office C', 'office_code' => 'C']);
        $this->admin = $this->user('Administrator', 'admin@example.test', $this->officeA);
        $this->requester = $this->user('Records Officer', 'requester@example.test', $this->officeA);
        $this->destinationOfficer = $this->user('Office User', 'destination@example.test', $this->officeB);
        $this->wrongOfficeUser = $this->user('Office User', 'wrong-office@example.test', $this->officeC);
    }

    public function test_notifications_are_owned_readable_and_mutable_only_by_the_recipient(): void
    {
        $own = $this->notification($this->requester, 'Own notification', '/dashboard');
        $other = $this->notification($this->admin, 'Other notification', '/users');

        Sanctum::actingAs($this->requester);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonMissing(['id' => $other->id]);

        $this->patchJson("/api/notifications/{$other->id}/read")
            ->assertNotFound();
        $this->patchJson("/api/notifications/{$own->id}/read")
            ->assertOk()
            ->assertJsonPath('notification.is_read', true);
        $this->assertNotNull($own->fresh()->read_at);

        $this->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0);
    }

    public function test_workflow_recipient_selection_and_inaccessible_document_links(): void
    {
        $service = app(InAppNotificationService::class);
        $document = Document::query()->create([
            'tracking_no' => 'NOTIFY-001',
            'title' => 'Sensitive routing subject',
            'origin_office_id' => $this->officeA->id,
            'current_office_id' => $this->officeB->id,
            'created_by' => $this->requester->id,
        ]);
        $route = DocumentRoute::query()->create([
            'document_id' => $document->id,
            'from_office_id' => $this->officeA->id,
            'to_office_id' => $this->officeB->id,
            'forwarded_by' => $this->requester->id,
            'forwarded_at' => now(),
            'status_id' => $this->statusId(),
            'action_id' => $this->routeActionId(),
        ]);

        $service->documentForwarded($route);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->destinationOfficer->id,
            'document_id' => $document->id,
            'type' => InAppNotificationService::TYPE_DOCUMENT_FORWARDED,
            'title' => 'Document forwarded: Sensitive routing subject',
            'message' => '“Sensitive routing subject” was forwarded to your office.',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->requester->id,
            'type' => InAppNotificationService::TYPE_DOCUMENT_FORWARDED,
        ]);

        $service->documentReceived($route);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->requester->id,
            'document_id' => $document->id,
            'type' => InAppNotificationService::TYPE_DOCUMENT_RECEIVED,
            'title' => 'Document received: Sensitive routing subject',
        ]);

        $protected = $this->notification($this->wrongOfficeUser, 'A document changed', '/documents/'.$document->id, $document->id);
        Sanctum::actingAs($this->wrongOfficeUser);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.id', $protected->id)
            ->assertJsonPath('data.0.link', null)
            ->assertJsonMissing(['title' => $document->title]);
        $this->getJson("/api/documents/{$document->id}")
            ->assertForbidden();
    }

    public function test_idle_documents_notify_only_the_responsible_office_once_per_activity_period(): void
    {
        config()->set('doctrack.idle_notification_days', 3);
        $clock = Carbon::getTestNow();

        try {
            Carbon::setTestNow(Carbon::parse('2026-10-28 08:00:00', 'Asia/Manila'));
            $idleSince = now()->subDays(3)->subMinute();
            $idle = Document::query()->create([
                'tracking_no' => 'IDLE-001',
                'title' => 'Budget proposal',
                'origin_office_id' => $this->officeA->id,
                'current_office_id' => $this->officeB->id,
                'created_by' => $this->requester->id,
                'current_action_updated_at' => $idleSince,
            ]);
            $completed = Document::query()->create([
                'tracking_no' => 'IDLE-002',
                'title' => 'Completed proposal',
                'origin_office_id' => $this->officeA->id,
                'current_office_id' => $this->officeB->id,
                'created_by' => $this->requester->id,
                'current_action_updated_at' => $idleSince,
                'completed_at' => now(),
            ]);

            $this->artisan('documents:notify-idle')->assertSuccessful();
            $this->assertDatabaseHas('notifications', [
                'user_id' => $this->destinationOfficer->id,
                'document_id' => $idle->id,
                'type' => InAppNotificationService::TYPE_DOCUMENT_IDLE,
                'title' => 'Document needs attention: Budget proposal',
                'message' => '“Budget proposal” has had no recorded action since '.$idleSince->format('M j, Y g:i A').'.',
            ]);
            $this->assertDatabaseMissing('notifications', [
                'document_id' => $completed->id,
                'type' => InAppNotificationService::TYPE_DOCUMENT_IDLE,
            ]);
            $this->assertDatabaseMissing('notifications', [
                'user_id' => $this->wrongOfficeUser->id,
                'document_id' => $idle->id,
                'type' => InAppNotificationService::TYPE_DOCUMENT_IDLE,
            ]);

            $this->artisan('documents:notify-idle')->assertSuccessful();
            $this->assertSame(1, Notification::query()
                ->where('document_id', $idle->id)
                ->where('type', InAppNotificationService::TYPE_DOCUMENT_IDLE)
                ->count());

            Carbon::setTestNow(now()->addDays(4));
            $idle->update(['current_action_updated_at' => now()->subDays(3)->subMinute()]);
            $this->artisan('documents:notify-idle')->assertSuccessful();
            $this->assertSame(2, Notification::query()
                ->where('document_id', $idle->id)
                ->where('type', InAppNotificationService::TYPE_DOCUMENT_IDLE)
                ->count());
        } finally {
            Carbon::setTestNow($clock);
        }
    }

    public function test_qr_and_password_reset_notifications_go_only_to_permitted_recipients(): void
    {
        $service = app(InAppNotificationService::class);
        $qrRequest = QrCodeRequest::query()->create([
            'requested_by_user_id' => $this->requester->id,
            'requested_office_id' => $this->officeA->id,
            'quantity' => 5,
            'status' => QrCodeRequest::STATUS_PENDING,
        ]);

        $service->qrRequestSubmitted($qrRequest);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->admin->id,
            'type' => InAppNotificationService::TYPE_QR_REQUEST_SUBMITTED,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->destinationOfficer->id,
            'type' => InAppNotificationService::TYPE_QR_REQUEST_SUBMITTED,
        ]);

        $qrRequest->update(['status' => QrCodeRequest::STATUS_APPROVED]);
        $service->qrRequestReviewed($qrRequest);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->requester->id,
            'type' => InAppNotificationService::TYPE_QR_REQUEST_APPROVED,
        ]);

        $resetRequest = PasswordResetRequest::query()->create([
            'user_id' => $this->requester->id,
            'email' => $this->requester->email,
            'status' => PasswordResetRequest::STATUS_PENDING,
        ]);
        $service->passwordResetSubmitted($resetRequest);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->admin->id,
            'type' => InAppNotificationService::TYPE_PASSWORD_RESET_SUBMITTED,
        ]);

        $resetRequest->update(['status' => PasswordResetRequest::STATUS_REJECTED]);
        $service->passwordResetReviewed($resetRequest);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->requester->id,
            'type' => InAppNotificationService::TYPE_PASSWORD_RESET_REJECTED,
            'link' => '/dashboard',
        ]);
    }

    public function test_password_reset_and_routing_endpoints_create_scoped_notifications(): void
    {
        $this->postJson('/api/password-reset-requests', [
            'email' => $this->requester->email,
        ])->assertAccepted();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->admin->id,
            'type' => InAppNotificationService::TYPE_PASSWORD_RESET_SUBMITTED,
        ]);

        $resetRequest = PasswordResetRequest::query()->sole();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/password-reset-requests/{$resetRequest->id}/resolve", [
            'password' => 'safe-temporary-password',
            'password_confirmation' => 'safe-temporary-password',
        ])->assertOk();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->requester->id,
            'type' => InAppNotificationService::TYPE_PASSWORD_RESET_RESOLVED,
        ]);

        $this->prepareRoutingLookups();
        $document = Document::query()->create([
            'tracking_no' => 'NOTIFY-ROUTE-001',
            'title' => 'Routing notification test',
            'origin_office_id' => $this->officeA->id,
            'current_office_id' => $this->officeA->id,
            'status_id' => \DB::table('document_statuses')->where('status_name', 'Pending')->value('id'),
            'created_by' => $this->requester->id,
        ]);

        Sanctum::actingAs($this->requester);
        $this->postJson("/api/documents/{$document->id}/forward", [
            'to_office_id' => $this->officeB->id,
        ])->assertCreated();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->destinationOfficer->id,
            'document_id' => $document->id,
            'type' => InAppNotificationService::TYPE_DOCUMENT_FORWARDED,
        ]);

        Sanctum::actingAs($this->destinationOfficer);
        $this->postJson("/api/documents/{$document->id}/receive")
            ->assertOk();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->requester->id,
            'document_id' => $document->id,
            'type' => InAppNotificationService::TYPE_DOCUMENT_RECEIVED,
        ]);
    }

    private function notification(User $user, string $title, string $link, ?int $documentId = null): Notification
    {
        return Notification::query()->create([
            'user_id' => $user->id,
            'document_id' => $documentId,
            'title' => $title,
            'message' => 'Generic safe message.',
            'type' => 'test',
            'link' => $link,
        ]);
    }

    private function user(string $role, string $email, Office $office): User
    {
        return User::query()->create([
            'name' => $role.' '.$email,
            'email' => $email,
            'password' => Hash::make('notification-test-password'),
            'role_id' => Role::query()->where('name', $role)->value('id'),
            'office_id' => $office->id,
        ]);
    }

    private function statusId(): int
    {
        return (int) \DB::table('document_statuses')->insertGetId(['status_name' => 'Forwarded', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function routeActionId(): int
    {
        return (int) \DB::table('route_actions')->insertGetId(['action_name' => 'Forward', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function prepareRoutingLookups(): void
    {
        foreach (['Pending', 'Forwarded', 'Received'] as $name) {
            \DB::table('document_statuses')->insert([
                'status_name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        \DB::table('route_actions')->insert([
            'action_name' => 'Forward',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([['AWAITING_RECEIPT', 'Awaiting Receipt'], ['FOR_ACTION', 'For Action']] as [$code, $name]) {
            \DB::table('processing_actions')->insert([
                'action_code' => $code,
                'action_name' => $name,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
