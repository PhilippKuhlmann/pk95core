<?php

namespace App\Models;

use App\Services\Apps\AppClient;
use App\Services\Apps\Manifest;
use App\Services\Catalog\AppDefinition;
use App\Services\Catalog\Catalog;
use Database\Factories\InstalledAppFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An app from the catalog that has been activated: its container, token and last manifest.
 *
 * @property string $app_id
 * @property string $status
 * @property string $tag
 * @property string|null $container_id
 * @property string $token
 * @property array<string, mixed>|null $manifest
 * @property string|null $version
 */
#[Fillable(['app_id', 'status', 'tag', 'container_id', 'token', 'manifest', 'version', 'step', 'error', 'last_seen_at',
    'update_policy', 'auto_backup', 'image_id', 'available_version', 'update_checked_at'])]
#[Hidden(['token'])]
class InstalledApp extends Model
{
    /** @use HasFactory<InstalledAppFactory> */
    use HasFactory;

    public const INSTALLING = 'installing';

    public const STARTING = 'starting';

    public const RUNNING = 'running';

    public const UNHEALTHY = 'unhealthy';

    public const STOPPED = 'stopped';

    public const FAILED = 'failed';

    public const REMOVING = 'removing';

    /** States in which a job or the container itself is still moving - the UI polls. */
    public const TRANSITIONAL = [self::INSTALLING, self::STARTING, self::REMOVING];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'manifest' => 'array',
            'last_seen_at' => 'datetime',
            'auto_backup' => 'boolean',
            'update_checked_at' => 'datetime',
        ];
    }

    /** off: never check · notify: report new versions · patch/minor/all: install them in the maintenance window */
    public const UPDATE_POLICIES = [
        'off' => 'Aus',
        'notify' => 'Nur melden',
        'patch' => 'Automatisch: nur Fehlerbehebungen (x.y.Z)',
        'minor' => 'Automatisch: neue Funktionen (x.Y.z)',
        'all' => 'Automatisch: alle Versionen',
    ];

    public function getRouteKeyName(): string
    {
        return 'app_id';
    }

    /**
     * @return HasMany<AppLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(AppLog::class, 'app_id', 'app_id');
    }

    public function definition(): ?AppDefinition
    {
        return app(Catalog::class)->find($this->app_id);
    }

    public function manifest(): ?Manifest
    {
        return $this->manifest ? Manifest::fromArray($this->manifest) : null;
    }

    public function client(): AppClient
    {
        $definition = $this->definition();
        if ($definition === null) {
            throw new \RuntimeException("App {$this->app_id} ist nicht mehr im Katalog");
        }

        return new AppClient($definition->baseUrl(), $this->token);
    }

    public function isTransitional(): bool
    {
        return in_array($this->status, self::TRANSITIONAL, true);
    }

    public function displayName(): string
    {
        return $this->manifest['name'] ?? $this->definition()?->name ?? $this->app_id;
    }

    public function log(string $message, string $level = 'info'): void
    {
        AppLog::query()->create(['app_id' => $this->app_id, 'level' => $level, 'message' => $message]);
    }
}
