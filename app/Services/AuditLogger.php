<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AuditLogger
{
    public function __construct(
        private readonly Request $request
    ) {
    }

    public function log(
        string $module,
        string $action,
        ?int $recordId = null,
        ?string $description = null,
        ?int $userId = null
    ): ?AuditLog {
        try {
            return AuditLog::create([
                'user_id' => $userId ?? Auth::id(),
                'module' => Str::limit($module, 100, ''),
                'action' => Str::limit($action, 100, ''),
                'record_id' => $recordId,
                'description' => $description,
                'ip_address' => $this->request->ip(),
                'user_agent' => $this->request->userAgent()
                    ? Str::limit($this->request->userAgent(), 255, '')
                    : null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Unable to write audit log.', [
                'module' => Str::limit($module, 100, ''),
                'action' => Str::limit($action, 100, ''),
                'record_id' => $recordId,
                'exception_class' => $exception::class,
            ]);

            return null;
        }
    }
}
