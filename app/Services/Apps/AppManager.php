<?php

namespace App\Services\Apps;

use App\Jobs\InstallApp;
use App\Models\AppEvent;
use App\Models\InstalledApp;
use App\Services\Catalog\AppDefinition;
use App\Services\Catalog\Catalog;
use App\Services\Docker\ContainerSpec;
use App\Services\Docker\DockerClient;
use App\Services\Docker\DockerException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lifecycle of the app containers: activate (pull, create, start, wait for health,
 * read the manifest), start/stop/restart, status refresh and removal.
 */
class AppManager
{
    public function __construct(
        private readonly DockerClient $docker,
        private readonly Catalog $catalog,
    ) {}

    /**
     * Mark the app as installing and queue the installation. Re-activating an existing
     * app recreates its container (volumes and their data are kept).
     */
    public function activate(string $appId, ?string $tag = null): InstalledApp
    {
        $definition = $this->catalog->get($appId);

        $app = DB::transaction(function () use ($definition, $tag) {
            $app = InstalledApp::query()->where('app_id', $definition->id)->lockForUpdate()->first();
            if ($app !== null && $app->isTransitional()) {
                throw new AppException("{$definition->name} wird gerade bearbeitet.");
            }
            $app ??= new InstalledApp(['app_id' => $definition->id]);
            $app->fill([
                'status' => InstalledApp::INSTALLING,
                'tag' => $tag ?? ($app->exists ? $app->tag : $definition->tag),
                'token' => Str::random(48),
                'step' => 'Eingeplant',
                'error' => null,
            ])->save();

            return $app;
        });

        $app->log("Aktivierung von {$definition->imageRef($app->tag)} eingeplant");
        InstallApp::dispatch($app->id);

        return $app;
    }

    /**
     * The installation itself (runs in the queue). Never throws: failures end in status "failed".
     */
    public function install(InstalledApp $app): void
    {
        try {
            $this->deploy($app);
        } catch (Throwable $e) {
            report($e);
            $app->update(['status' => InstalledApp::FAILED, 'error' => $e->getMessage()]);
            $app->log($e->getMessage(), 'error');
            AppEvent::record($app->app_id, 'core.app.failed', 'critical', "{$app->displayName()} konnte nicht gestartet werden", $e->getMessage());
        }
    }

    /**
     * Pull (unless $image is given), (re)create and start the container, wait for health and
     * read the manifest. Ends in status "running"; throws on any failure.
     *
     * @param  string|null  $image  an image id to run instead of image:tag (rollback)
     */
    public function deploy(InstalledApp $app, ?string $image = null): Manifest
    {
        $definition = $this->catalog->get($app->app_id);

        if ($image === null) {
            $this->step($app, 'Image laden', "Lade Image {$definition->imageRef($app->tag)}");
            $this->pull($app, $definition);
        }

        $this->step($app, 'Container anlegen', 'Bereite Netzwerk und Volumes vor');
        if ($definition->networkMode === 'bridge') {
            $this->docker->ensureNetwork((string) config('pk95.network'));
        }
        foreach ($definition->volumes as $volume) {
            $this->docker->ensureVolume($definition->volumeName($volume['name']), ['pk95.app' => $definition->id]);
        }
        if ($this->docker->inspectContainer($definition->containerName()) !== null) {
            $app->log('Ersetze vorhandenen Container '.$definition->containerName());
            $this->docker->removeContainer($definition->containerName());
        }
        $id = $this->docker->createContainer(
            $definition->containerName(),
            ContainerSpec::for($definition, $app->tag, $app->token, $image),
        );
        $app->update(['container_id' => $id]);

        $this->step($app, 'Starten', 'Starte Container '.$definition->containerName());
        $this->docker->startContainer($id);

        $this->step($app, 'Funktionsprüfung', "Warte auf {$definition->health}");
        $this->waitForHealth($app, $definition);

        $manifest = $this->loadManifest($app);
        $app->update([
            'status' => InstalledApp::RUNNING, 'step' => null, 'error' => null, 'last_seen_at' => now(),
            'image_id' => $this->docker->inspectContainer($id)['Image'] ?? null,
        ]);
        $app->log("{$manifest->name} {$manifest->version} läuft", 'success');

        return $manifest;
    }

    /**
     * Stop the container (for backups); returns whether it was running.
     */
    public function pause(InstalledApp $app): bool
    {
        $container = $this->docker->inspectContainer($this->containerRef($app));
        $running = (bool) ($container['State']['Running'] ?? false);
        if ($running) {
            $this->docker->stopContainer($this->containerRef($app));
        }

        return $running;
    }

    public function unpause(InstalledApp $app): void
    {
        $this->docker->startContainer($this->containerRef($app));
    }

    private function pull(InstalledApp $app, AppDefinition $definition): void
    {
        try {
            $this->docker->pull($definition->image, $app->tag);
        } catch (DockerException $e) {
            // Registry down or a locally built image: use the local copy if there is one.
            if ($this->docker->inspectImage($definition->imageRef($app->tag)) === null) {
                // GHCR answers "denied" both for private and for not (yet) published images.
                $hint = preg_match('/denied|not found|manifest unknown|does not exist/i', $e->getMessage())
                    ? ' Das Image gibt es in der Registry nicht oder es ist nicht öffentlich – wurde es schon gebaut und veröffentlicht?'
                    : '';
                throw new AppException("Image {$definition->imageRef($app->tag)} konnte nicht geladen werden.{$hint} (Docker: ".trim(preg_replace('/\s+/', ' ', $e->getMessage())).')', previous: $e);
            }
            $app->log('Image nicht aus der Registry ladbar, nutze die lokale Kopie ('.$e->getMessage().')', 'warning');
        }
    }

    public function start(InstalledApp $app): void
    {
        $this->guard($app);
        $this->docker->startContainer($this->containerRef($app));
        $app->update(['status' => InstalledApp::STARTING, 'error' => null]);
        $app->log('Gestartet');
    }

    public function stop(InstalledApp $app): void
    {
        $this->guard($app);
        $this->docker->stopContainer($this->containerRef($app));
        $app->update(['status' => InstalledApp::STOPPED, 'error' => null]);
        $app->log('Gestoppt');
    }

    public function restart(InstalledApp $app): void
    {
        $this->guard($app);
        $this->docker->restartContainer($this->containerRef($app));
        $app->update(['status' => InstalledApp::STARTING, 'error' => null]);
        $app->log('Neu gestartet');
    }

    /**
     * Remove container (and with $keepData = false also the app's volumes) and forget the app.
     */
    public function remove(InstalledApp $app, bool $keepData = true): void
    {
        $this->guard($app);
        $definition = $this->catalog->find($app->app_id);
        $app->update(['status' => InstalledApp::REMOVING]);

        try {
            $this->docker->removeContainer($this->containerRef($app));
            if (! $keepData && $definition !== null) {
                foreach ($definition->volumes as $volume) {
                    $this->docker->removeVolume($definition->volumeName($volume['name']));
                }
            }
        } catch (Throwable $e) {
            $app->update(['status' => InstalledApp::FAILED, 'error' => $e->getMessage()]);
            $app->log('Entfernen fehlgeschlagen: '.$e->getMessage(), 'error');
            throw $e;
        }

        $app->log($keepData ? 'Entfernt, Daten behalten' : 'Entfernt samt Daten');
        $app->delete();
    }

    /**
     * Compare the stored status with the container and the app's health endpoint.
     */
    public function refresh(InstalledApp $app): InstalledApp
    {
        // Also leave apps alone while a backup, restore or update works on them (step set):
        // their container is stopped on purpose then.
        if (in_array($app->status, [InstalledApp::INSTALLING, InstalledApp::REMOVING], true) || $app->step !== null) {
            return $app;
        }
        $definition = $this->catalog->find($app->app_id);
        if ($definition === null) {
            return $app;
        }

        $container = $this->docker->inspectContainer($this->containerRef($app));
        if ($container === null) {
            $this->transition($app, InstalledApp::FAILED, 'Der Container existiert nicht mehr. App neu aktivieren.');

            return $app;
        }
        if (! ($container['State']['Running'] ?? false)) {
            $this->transition($app, InstalledApp::STOPPED);

            return $app;
        }

        $client = $app->client();
        if (! $client->healthy($definition->health)) {
            $grace = $app->status === InstalledApp::STARTING
                && $app->updated_at?->gt(now()->subSeconds((int) config('pk95.health_timeout', 120)));
            if (! $grace) {
                $this->transition($app, InstalledApp::UNHEALTHY, 'Die Funktionsprüfung schlägt fehl.');
            }

            return $app;
        }

        try {
            $this->loadManifest($app);
        } catch (AppException $e) {
            $this->transition($app, InstalledApp::UNHEALTHY, $e->getMessage());

            return $app;
        }
        $app->last_seen_at = now();
        $this->transition($app, InstalledApp::RUNNING);

        return $app;
    }

    /**
     * @return list<array{stream: string, line: string}>
     */
    public function containerLogs(InstalledApp $app, int $tail = 200): array
    {
        return $this->docker->logs($this->containerRef($app), $tail);
    }

    // ------------------------------------------------------------------------------------

    private function waitForHealth(InstalledApp $app, AppDefinition $definition): void
    {
        $client = $app->client();
        $deadline = now()->addSeconds((int) config('pk95.health_timeout', 120));
        while (true) {
            if ($client->healthy($definition->health)) {
                return;
            }
            $container = $this->docker->inspectContainer($this->containerRef($app));
            if ($container !== null && ! ($container['State']['Running'] ?? false)) {
                $code = $container['State']['ExitCode'] ?? '?';
                throw new AppException("Der Container hat sich beendet (Exit-Code {$code}). Siehe Container-Log.");
            }
            // With restart policy "unless-stopped" a crashing app is restarted again and again
            // and looks "running" in between: stop waiting after a few crashes.
            if ($container !== null && ((int) ($container['RestartCount'] ?? 0) >= 3 || ($container['State']['Restarting'] ?? false))) {
                $code = $container['State']['ExitCode'] ?? '?';
                throw new AppException("Der Container stürzt immer wieder ab (Exit-Code {$code}). Siehe Container-Log.");
            }
            if (now()->gte($deadline)) {
                throw new AppException("Die App meldet sich nicht unter {$definition->health} (Zeitlimit erreicht).");
            }
            Sleep::for((float) config('pk95.poll_interval', 2))->seconds();
        }
    }

    private function loadManifest(InstalledApp $app): Manifest
    {
        $manifest = $app->client()->manifest();
        if ($manifest->id !== $app->app_id) {
            throw new AppException("Manifest gehört zu „{$manifest->id}“, erwartet „{$app->app_id}“.");
        }
        if (! in_array($manifest->protocol, (array) config('pk95.protocols', [1]), true)) {
            throw new AppException("App spricht Protokoll {$manifest->protocol}, Core unterstützt ".implode(', ', (array) config('pk95.protocols')).'.');
        }
        if ($app->version !== null && $app->version !== $manifest->version) {
            $app->log("Version {$app->version} → {$manifest->version}");
        }
        $app->fill(['manifest' => $manifest->toArray(), 'version' => $manifest->version])->save();

        return $manifest;
    }

    private function transition(InstalledApp $app, string $status, ?string $error = null): void
    {
        $previous = $app->status;
        if ($previous !== $status) {
            $app->log(match ($status) {
                InstalledApp::RUNNING => 'Läuft wieder',
                InstalledApp::STOPPED => 'Container ist gestoppt',
                default => $error ?? $status,
            }, $status === InstalledApp::RUNNING ? 'success' : ($status === InstalledApp::STOPPED ? 'info' : 'error'));
        }
        $app->fill(['status' => $status, 'error' => $error])->save();

        // Changes nobody asked for in Core are worth a notification.
        $name = $app->displayName();
        $bad = [InstalledApp::UNHEALTHY, InstalledApp::FAILED];
        if (in_array($status, $bad, true) && ! in_array($previous, $bad, true)) {
            AppEvent::record($app->app_id, $status === InstalledApp::FAILED ? 'core.app.failed' : 'core.app.unhealthy',
                $status === InstalledApp::FAILED ? 'critical' : 'warning', "{$name} ist gestört", $error);
        } elseif ($status === InstalledApp::STOPPED && $previous === InstalledApp::RUNNING) {
            AppEvent::record($app->app_id, 'core.app.stopped', 'warning', "{$name} wurde außerhalb von Core gestoppt");
        } elseif ($status === InstalledApp::RUNNING && in_array($previous, [...$bad, InstalledApp::STOPPED], true)) {
            AppEvent::record($app->app_id, 'core.app.recovered', 'info', "{$name} läuft wieder");
        }
    }

    private function step(InstalledApp $app, string $step, string $message): void
    {
        $app->update(['step' => $step]);
        $app->log($message);
    }

    private function guard(InstalledApp $app): void
    {
        if ($app->isTransitional() && $app->status !== InstalledApp::STARTING) {
            throw new AppException("{$app->displayName()} wird gerade bearbeitet.");
        }
    }

    private function containerRef(InstalledApp $app): string
    {
        return $app->container_id
            ?? $this->catalog->find($app->app_id)?->containerName()
            ?? config('pk95.container_prefix').$app->app_id;
    }
}
