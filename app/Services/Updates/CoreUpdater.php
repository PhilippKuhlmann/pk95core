<?php

namespace App\Services\Updates;

use App\Models\AppEvent;
use App\Services\Apps\AppException;
use App\Services\Docker\DockerClient;
use App\Services\Docker\DockerException;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Core itself: which build runs, whether the registry has a newer one, and the self update.
 *
 * Core cannot replace the container it runs in. It starts a helper container (docker CLI,
 * the docker socket and the compose project directory mounted) that runs "docker compose
 * pull" and "up -d" for the compose project Core was started from, waits for /up of the new
 * Core and otherwise tags the old image back and starts it again. The helper outlives the old
 * Core; the new Core reads its last line ("RESULT …") in finish(), records the outcome and
 * removes the helper.
 *
 * State in the settings table: core_update (newer build in the registry), core_update_checked_at,
 * core_self_update (running), core_self_update_result (last outcome).
 */
class CoreUpdater
{
    public const HELPER = 'pk95core-updater';

    /** A helper still running after this long has hung. */
    private const STALE_MINUTES = 20;

    public function __construct(
        private readonly DockerClient $docker,
        private readonly Registry $registry,
    ) {}

    /**
     * Inspect data of Core's own container, null when Core does not run in Docker (dev).
     *
     * @return array<string, mixed>|null
     */
    public function container(): ?array
    {
        try {
            if (! $this->docker->available()) {
                return null;
            }
            // In Docker the hostname is the short container id, unless a hostname was set.
            foreach (array_unique(array_filter([gethostname() ?: null, (string) config('pk95.core_container')])) as $ref) {
                $container = $this->docker->inspectContainer($ref);
                if ($container !== null) {
                    return $container;
                }
            }
        } catch (DockerException) {
            // shown as "not in Docker"
        }

        return null;
    }

    /**
     * Everything the Apps page shows about Core.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $container = $this->container();
        $labels = [];
        if ($container !== null) {
            try {
                $labels = (array) ($this->docker->inspectImage((string) $container['Image'])['Config']['Labels'] ?? []);
            } catch (DockerException) {
                // without labels: no build shown
            }
        }

        return [
            'version' => (string) config('pk95.version'),
            'docker' => $container !== null,
            'image' => $container['Config']['Image'] ?? null,
            'revision' => self::short($labels['org.opencontainers.image.revision'] ?? null),
            'built' => self::time($labels['org.opencontainers.image.created'] ?? null),
            'compose' => $container !== null ? self::compose($container) : null,
            'available' => Settings::get('core_update'),
            'checked_at' => self::time(Settings::get('core_update_checked_at')),
            'running' => Settings::get('core_self_update'),
            'last' => Settings::get('core_self_update_result'),
        ];
    }

    /**
     * Ask the registry whether the tag Core runs on points to another image now.
     *
     * @return array{digest: string, revision: ?string, created: ?string}|null the newer build
     */
    public function check(): ?array
    {
        $container = $this->container() ?? throw new AppException('PK95 Core läuft nicht als Docker-Container.');
        [$image, $tag] = self::splitRef((string) ($container['Config']['Image'] ?? ''));

        $remote = $this->registry->digest($image, $tag);
        $local = array_map(fn (string $d) => substr($d, (int) strrpos($d, '@') + 1),
            (array) ($this->docker->inspectImage((string) $container['Image'])['RepoDigests'] ?? []));

        $found = null;
        if (! in_array($remote, $local, true)) {
            $labels = rescue(fn () => $this->registry->labels($image, $tag), [], false);
            $found = [
                'digest' => $remote,
                'revision' => self::short($labels['org.opencontainers.image.revision'] ?? null),
                'created' => $labels['org.opencontainers.image.created'] ?? null,
            ];
            if ((Settings::get('core_update')['digest'] ?? null) !== $remote) {
                AppEvent::record(AppEvent::CORE, 'core.update.available', 'info', 'Für PK95 Core gibt es eine neue Version', self::describe($found));
            }
        }
        Settings::put('core_update', $found);
        Settings::put('core_update_checked_at', now()->toIso8601String());

        return $found;
    }

    /**
     * Start the helper. Core stops a few seconds later and comes back as the new version.
     */
    public function start(): void
    {
        if ($this->running()) {
            throw new AppException('Ein Update von PK95 Core läuft bereits.');
        }
        $container = $this->container() ?? throw new AppException('PK95 Core läuft nicht als Docker-Container.');
        $compose = self::compose($container)
            ?? throw new AppException('PK95 Core wurde nicht mit docker compose gestartet. Update von Hand im LXC: docker compose pull && docker compose up -d');

        $helperImage = (string) config('pk95.updater_image');
        if ($this->docker->inspectImage($helperImage) === null) {
            [$name, $tag] = self::splitRef($helperImage);
            $this->docker->pull($name, $tag);
        }
        $this->docker->removeContainer(self::HELPER);

        $binds = ['/var/run/docker.sock:/var/run/docker.sock'];
        foreach (array_unique([$compose['dir'], ...array_map('dirname', $compose['files'])]) as $dir) {
            $binds[] = "{$dir}:{$dir}";
        }
        $script = self::script($compose, (string) $container['Config']['Image'], (string) $container['Image'], ltrim((string) $container['Name'], '/'));

        $id = $this->docker->createContainer(self::HELPER, [
            'Image' => $helperImage,
            'Cmd' => ['sh', '-c', $script],
            'Labels' => ['pk95.managed' => '1', 'pk95.helper' => '1'],
            'HostConfig' => ['Binds' => $binds, 'NetworkMode' => (string) config('pk95.network')],
        ]);
        Settings::put('core_self_update', ['started_at' => now()->toIso8601String(), 'from' => $this->status()['revision']]);
        try {
            $this->docker->startContainer($id);
        } catch (Throwable $e) {
            Settings::put('core_self_update', null);
            $this->docker->removeContainer($id);
            throw $e;
        }
    }

    public function running(): bool
    {
        return Settings::get('core_self_update') !== null;
    }

    /**
     * After the helper has ended: record the outcome and remove it. Called every minute and by
     * the Apps page; returns the result (done, current, rolled_back, failed) or null meanwhile.
     */
    public function finish(): ?string
    {
        $state = Settings::get('core_self_update');
        if ($state === null) {
            return null;
        }
        $started = Carbon::parse($state['started_at'] ?? 'now');
        try {
            $helper = $this->docker->inspectContainer(self::HELPER);
            $stale = $started->diffInMinutes(now()) >= self::STALE_MINUTES;
            if ($helper !== null && ($helper['State']['Running'] ?? false) && ! $stale) {
                return null;
            }
            // a missing helper right after start(): give Docker a moment before calling it lost
            if ($helper === null && $started->diffInSeconds(now()) < 60) {
                return null;
            }
            $lines = $helper !== null ? array_column($this->docker->logs(self::HELPER, 80), 'line') : [];
        } catch (DockerException) {
            return null;
        }

        $result = 'failed';
        $message = $helper === null ? 'Der Hilfscontainer ist verschwunden.' : 'Das Update hat sich nicht zurückgemeldet.';
        foreach ($lines as $line) {
            if (preg_match('/RESULT (\w+) ?(.*)$/', $line, $m)) {
                [$result, $message] = [$m[1], trim($m[2])];
            }
        }
        // Docker log lines carry a timestamp in front.
        $log = implode("\n", array_map(fn ($l) => preg_replace('/^\S+Z /', '', $l), $lines));
        $now = $this->status()['revision'];

        match ($result) {
            'done' => AppEvent::record(AppEvent::CORE, 'core.update.done', 'info', 'PK95 Core wurde aktualisiert',
                ($state['from'] ?? '?').' → '.($now ?? '?')),
            'current' => null,
            'rolled_back' => AppEvent::record(AppEvent::CORE, 'core.update.failed', 'critical', 'Update von PK95 Core fehlgeschlagen – vorige Version läuft wieder', $message ?: $log),
            default => AppEvent::record(AppEvent::CORE, 'core.update.failed', 'critical', 'Update von PK95 Core fehlgeschlagen', $message ?: $log),
        };
        if (in_array($result, ['done', 'current'], true)) {
            Settings::put('core_update', null);
        }
        Settings::put('core_self_update_result', ['result' => $result, 'message' => $message, 'at' => now()->toIso8601String(), 'log' => $log]);
        Settings::put('core_self_update', null);
        try {
            $this->docker->removeContainer(self::HELPER);
        } catch (DockerException) {
            // left for the next start(), which removes it first
        }

        return $result;
    }

    /**
     * Compose project Core was started from, from the labels compose puts on its containers.
     *
     * @param  array<string, mixed>  $container
     * @return array{project: string, dir: string, files: list<string>, service: string}|null
     */
    public static function compose(array $container): ?array
    {
        $labels = (array) ($container['Config']['Labels'] ?? []);
        $project = $labels['com.docker.compose.project'] ?? '';
        $dir = $labels['com.docker.compose.project.working_dir'] ?? '';
        $service = $labels['com.docker.compose.service'] ?? '';
        $files = array_values(array_filter(explode(',', (string) ($labels['com.docker.compose.project.config_files'] ?? ''))));
        if ($project === '' || $dir === '' || $service === '' || $files === []) {
            return null;
        }

        return ['project' => $project, 'dir' => $dir, 'files' => $files, 'service' => $service];
    }

    /**
     * The helper's shell script. Its last line "RESULT <word> <text>" is what finish() reads.
     *
     * @param  array{project: string, dir: string, files: list<string>, service: string}  $compose
     */
    public static function script(array $compose, string $imageRef, string $oldImageId, string $containerName): string
    {
        $q = 'escapeshellarg';
        $files = implode(' ', array_map(fn ($f) => '-f '.$q($f), $compose['files']));
        $service = $q($compose['service']);
        $image = $q($imageRef);
        $old = $q($oldImageId);
        $up = $q("http://{$containerName}:8080/up");

        return <<<SH
            set -u
            say() { echo "\$(date '+%H:%M:%S') \$*"; }
            dc() { docker compose -p {$q($compose['project'])} --project-directory {$q($compose['dir'])} {$files} "\$@"; }
            say "Lade das neue Image"
            dc pull {$service} || { say "RESULT failed Das neue Image ließ sich nicht laden."; exit 1; }
            if [ "\$(docker image inspect -f '{{.Id}}' {$image})" = {$old} ]; then say "RESULT current PK95 Core ist bereits aktuell."; exit 0; fi
            say "Starte PK95 Core mit dem neuen Image"
            if dc up -d --no-build {$service}; then
              i=0
              while [ \$i -lt 60 ]; do
                sleep 3
                if wget -q -T 3 -O /dev/null {$up} 2>/dev/null; then say "RESULT done"; exit 0; fi
                i=\$((i + 1))
              done
              say "Die neue Version antwortet nach 3 Minuten nicht"
            fi
            say "Gehe zur vorigen Version zurück"
            if docker tag {$old} {$image} && dc up -d --no-build {$service}; then
              say "RESULT rolled_back Die neue Version startete nicht, die vorige läuft wieder."
              exit 2
            fi
            say "RESULT failed Auch die Rückkehr zur vorigen Version schlug fehl. Im LXC: docker compose up -d"
            exit 1
            SH;
    }

    /**
     * "ghcr.io/o/pk95core:latest" → [ghcr.io/o/pk95core, latest]; a port in the registry host
     * is not a tag; a digest reference falls back to latest.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitRef(string $ref): array
    {
        $ref = explode('@', $ref, 2)[0];
        $slash = (int) strrpos($ref, '/');
        $colon = strrpos($ref, ':');
        if ($colon !== false && $colon > $slash) {
            return [substr($ref, 0, $colon), substr($ref, $colon + 1)];
        }

        return [$ref, 'latest'];
    }

    /**
     * @param  array{revision: ?string, created: ?string}  $build
     */
    public static function describe(array $build): string
    {
        $parts = array_filter([
            $build['revision'] ? "Build {$build['revision']}" : null,
            ($t = self::time($build['created'])) ? 'vom '.$t->format('d.m.Y, H:i').' Uhr' : null,
        ]);

        return $parts ? implode(' ', $parts) : 'Neues Image in der Registry';
    }

    private static function short(?string $revision): ?string
    {
        return $revision ? substr($revision, 0, 7) : null;
    }

    private static function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
