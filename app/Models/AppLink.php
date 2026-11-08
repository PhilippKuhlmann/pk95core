<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A deployment (e.g. Nextcloud from ProxApps) that Core linked automatically: the proxy
 * host in PK95 Proxy and the monitors in PK95 Uptime it created for it.
 *
 * @property list<string> $monitor_ids
 */
class AppLink extends Model
{
    public const ACTIVE = 'active';

    /** Host created disabled: the domain does not point to the public IP yet. */
    public const PENDING_DNS = 'pending_dns';

    public const FAILED = 'failed';

    public const REMOVED = 'removed';

    public const STATES = [
        self::ACTIVE => 'Aktiv',
        self::PENDING_DNS => 'Wartet auf DNS',
        self::FAILED => 'Fehlgeschlagen',
        self::REMOVED => 'Entfernt',
    ];

    /** How long Core keeps checking DNS for a disabled host. */
    public const DNS_WAIT_DAYS = 7;

    protected $guarded = ['id'];

    /** The link Core made for a ProxApps deployment (for the deployment pages; one query per request). */
    public static function forProxApps(int $deploymentId): ?self
    {
        $key = 'app-links.proxapps';
        if (! app()->bound($key)) {
            app()->instance($key, self::query()->where('source_app', 'proxapps')->where('state', '!=', self::REMOVED)
                ->get()->keyBy('source_id')->all());
        }

        return app($key)[$deploymentId] ?? null;
    }

    /** Link of a ProxApps deployment in any state, also taken down (progress of a removal). */
    public static function anyForProxApps(int $deploymentId): ?self
    {
        return self::query()->where('source_app', 'proxapps')->where('source_id', $deploymentId)->first();
    }

    /** Domain worth showing: a proxy host exists for it. */
    public function shownDomain(): ?string
    {
        return $this->domain !== '' && $this->proxy_host_id ? $this->domain : null;
    }

    protected function casts(): array
    {
        return [
            'monitor_ids' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
