<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('module', 100);
            $table->string('action', 100);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('audit_logs');

        parent::tearDown();
    }

    public function test_it_creates_an_audit_log_with_request_context(): void
    {
        $request = Request::create(
            '/audit-test',
            'POST',
            server: [
                'REMOTE_ADDR' => '192.0.2.10',
                'HTTP_USER_AGENT' => 'DocTrack Test Agent',
            ]
        );

        $logger = new AuditLogger($request);

        $log = $logger->log(
            module: 'documents',
            action: 'created',
            recordId: 123,
            description: 'Created document #123'
        );

        $this->assertInstanceOf(AuditLog::class, $log);

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'user_id' => null,
            'module' => 'documents',
            'action' => 'created',
            'record_id' => 123,
            'description' => 'Created document #123',
            'ip_address' => '192.0.2.10',
            'user_agent' => 'DocTrack Test Agent',
        ]);
    }
}