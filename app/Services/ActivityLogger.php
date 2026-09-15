<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogger
{
    /**
     * Registra una actividad realizada por el usuario actual.
     */
    public function log(
        string $action,
        ?string $description = null,
        array $metadata = [],
        ?Request $request = null
    ): ActivityLog {
        $request = $request ?? request();

        return ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => $description,
            'metadata' => !empty($metadata) ? $metadata : null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}