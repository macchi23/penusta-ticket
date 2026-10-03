<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ActivityRecorder
{
    public const SESSION_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function record(string $type, Request $request, ?User $user, array $payload, CarbonImmutable $now): ActivityEvent
    {
        return ActivityEvent::create([
            'event_type' => $type,
            'user_id' => $user?->id,
            'session_id' => $this->sessionId($request),
            'device' => $this->device($request),
            'payload' => $payload,
            'occurred_at' => $now,
        ]);
    }

    private function sessionId(Request $request): string
    {
        $id = $request->header('X-Session-Id');

        return is_string($id) && preg_match(self::SESSION_ID_PATTERN, $id)
            ? $id
            : 'unknown';
    }

    private function device(Request $request): ?string
    {
        $device = $request->header('X-Device');

        return in_array($device, ['mobile', 'desktop', 'tablet'], true) ? $device : null;
    }
}
