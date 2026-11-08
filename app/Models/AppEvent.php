<?php

namespace App\Models;

use App\Jobs\DeliverEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something that happened in an app or in Core: "monitor.down" from PK95 Uptime,
 * "core.app.unhealthy", "core.update.failed", … Delivered to the matching channels.
 *
 * @property string $app_id
 * @property string $type
 * @property string $severity
 * @property string $title
 * @property string|null $body
 * @property array<string, mixed>|null $data
 * @property Carbon $occurred_at
 */
#[Fillable(['app_id', 'type', 'severity', 'title', 'body', 'data', 'occurred_at'])]
class AppEvent extends Model
{
    public const UPDATED_AT = null;

    public const SEVERITIES = ['info' => 0, 'warning' => 1, 'critical' => 2];

    /** app_id of events Core reports about itself or the platform */
    public const CORE = 'core';

    protected function casts(): array
    {
        return ['data' => 'array', 'occurred_at' => 'datetime'];
    }

    public static function rank(string $severity): int
    {
        return self::SEVERITIES[$severity] ?? 0;
    }

    /**
     * Store an event and hand it to the notification channels.
     *
     * @param  array<string, mixed>  $data
     */
    public static function record(string $appId, string $type, string $severity, string $title, ?string $body = null, array $data = [], ?Carbon $occurredAt = null): self
    {
        $event = self::query()->create([
            'app_id' => $appId,
            'type' => $type,
            'severity' => array_key_exists($severity, self::SEVERITIES) ? $severity : 'info',
            'title' => mb_substr($title, 0, 255),
            'body' => $body === '' ? null : $body,
            'data' => $data ?: null,
            'occurred_at' => $occurredAt ?? now(),
        ]);
        DeliverEvent::dispatch($event->id);

        return $event;
    }

    public function appName(): string
    {
        if ($this->app_id === self::CORE) {
            return 'PK95 Core';
        }

        return InstalledApp::query()->firstWhere('app_id', $this->app_id)?->displayName() ?? $this->app_id;
    }
}
