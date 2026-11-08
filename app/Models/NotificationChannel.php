<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * A central notification channel (Telegram, e-mail, ntfy, webhook) with its own filter:
 * minimum severity, apps (empty = all) and event types (empty = all, "monitor.*" allowed).
 *
 * @property string $name
 * @property string $type
 * @property array<string, mixed> $config
 * @property bool $enabled
 * @property string $min_severity
 * @property list<string>|null $apps
 * @property list<string>|null $types
 */
#[Fillable(['name', 'type', 'config', 'enabled', 'min_severity', 'apps', 'types', 'last_success_at', 'last_error_at', 'last_error'])]
#[Hidden(['config'])]
class NotificationChannel extends Model
{
    public const TYPES = ['telegram' => 'Telegram', 'email' => 'E-Mail', 'ntfy' => 'ntfy', 'webhook' => 'Webhook'];

    /** Config keys that are secrets: never shown again, an empty input keeps them. */
    public const SECRETS = ['bot_token', 'password', 'token'];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'enabled' => 'boolean',
            'apps' => 'array',
            'types' => 'array',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function matches(AppEvent $event): bool
    {
        if (! $this->enabled || AppEvent::rank($event->severity) < AppEvent::rank($this->min_severity)) {
            return false;
        }
        if ($this->apps && ! in_array($event->app_id, $this->apps, true)) {
            return false;
        }
        if ($this->types) {
            return collect($this->types)->contains(fn (string $pattern) => fnmatch($pattern, $event->type));
        }

        return true;
    }
}
