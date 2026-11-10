<?php

namespace App\Services\Updates;

use App\Models\AppEvent;
use App\Models\InstalledApp;
use App\Services\Apps\AppException;
use App\Services\Apps\AppManager;
use App\Services\Backups\BackupService;
use App\Services\Catalog\Catalog;
use App\Services\Docker\DockerClient;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Update checks against the registry and updates with backup, health check and rollback.
 *
 * Version tags (1.4.2) are compared as versions and limited by the app's policy (patch,
 * minor, all). A moving tag (latest) has an update when its digest in the registry differs
 * from the local image; when it points to a released version, Core offers and installs that
 * version tag, so the app leaves the moving tag and from then on follows version numbers.
 * Builds without a release (main builds) stay on the moving tag and are shown by commit.
 */
class UpdateService
{
    public function __construct(
        private readonly DockerClient $docker,
        private readonly Catalog $catalog,
        private readonly AppManager $manager,
        private readonly BackupService $backups,
        private readonly Registry $registry,
    ) {}

    public static function isVersion(string $tag): bool
    {
        return (bool) preg_match('/^v?\d+\.\d+\.\d+$/', $tag);
    }

    /**
     * Installed and offered version in words, for the update badge.
     *
     * @return array{from: string, to: string}
     */
    public static function change(InstalledApp $app): array
    {
        $available = (string) $app->available_version;
        $build = (array) $app->available_build;
        $created = ($build['created'] ?? null) ? rescue(fn () => Carbon::parse($build['created'])->setTimezone(config('app.timezone')), null, false) : null;

        return [
            'from' => $app->version ? 'Version '.ltrim($app->version, 'v') : '„'.$app->tag.'“',
            'to' => match (true) {
                self::isVersion($available) => 'Version '.ltrim($available, 'v'),
                ! empty($build['revision']) => 'Build '.$build['revision'].($created ? ' vom '.$created->format('d.m.Y') : ''),
                default => 'neuer Build von „'.$available.'“',
            },
        ];
    }

    private static function short(?string $revision): ?string
    {
        return $revision ? substr($revision, 0, 7) : null;
    }

    /**
     * Highest tag above $current the policy allows; "notify" reports the highest of all.
     *
     * @param  list<string>  $tags
     */
    public static function pick(array $tags, string $current, string $policy): ?string
    {
        $norm = fn (string $t) => ltrim($t, 'v');
        [$major, $minor] = array_map('intval', explode('.', $norm($current)));
        $best = null;
        foreach ($tags as $tag) {
            if (! self::isVersion($tag) || version_compare($norm($tag), $norm($current), '<=')) {
                continue;
            }
            [$ma, $mi] = array_map('intval', explode('.', $norm($tag)));
            $allowed = match ($policy) {
                'patch' => $ma === $major && $mi === $minor,
                'minor' => $ma === $major,
                default => true,
            };
            if ($allowed && ($best === null || version_compare($norm($tag), $norm($best), '>'))) {
                $best = $tag;
            }
        }

        return $best;
    }

    /**
     * Look for a newer image; stores and returns it (version tag, or the moving tag itself).
     */
    public function check(InstalledApp $app): ?string
    {
        if ($app->update_policy === 'off') {
            $app->update(['available_version' => null, 'update_checked_at' => now()]);

            return null;
        }
        $definition = $this->catalog->get($app->app_id);

        $build = null;
        if (self::isVersion($app->tag)) {
            $available = self::pick($this->registry->tags($definition->image), $app->tag, $app->update_policy);
        } else {
            $remote = $this->registry->digest($definition->image, $app->tag);
            $local = array_map(fn (string $d) => substr($d, (int) strrpos($d, '@') + 1),
                (array) ($this->docker->inspectImage($definition->imageRef($app->tag))['RepoDigests'] ?? []));
            $available = null;
            if (! in_array($remote, $local, true)) {
                $labels = rescue(fn () => $this->registry->labels($definition->image, $app->tag), [], false);
                $release = rescue(fn () => $this->registry->release($definition->image, $app->tag, $labels), null, false);
                $installed = (string) $app->version;
                if ($release === null) {
                    $available = $app->tag;
                    $build = ['revision' => self::short($labels['org.opencontainers.image.revision'] ?? null), 'created' => $labels['org.opencontainers.image.created'] ?? null];
                } elseif (! self::isVersion($installed)) {
                    $available = $release;
                } else {
                    $cmp = version_compare(ltrim($release, 'v'), ltrim($installed, 'v'));
                    // newer: within the policy; same version: move to the version tag; older: never
                    $available = $cmp > 0 ? self::pick([$release], $installed, $app->update_policy) : ($cmp === 0 ? $release : null);
                }
            }
        }

        if ($available !== null && ($available !== $app->available_version || $build !== $app->available_build)) {
            $what = self::change(new InstalledApp(['tag' => $app->tag, 'available_version' => $available, 'available_build' => $build]))['to'];
            AppEvent::record($app->app_id, 'core.update.available', 'info', "Für {$app->displayName()} gibt es {$what}", null, ['version' => $available]);
        }
        $app->update(['available_version' => $available, 'available_build' => $build, 'update_checked_at' => now()]);

        return $available;
    }

    /**
     * Backup → pull → new container → health check. On failure: back to the old image and
     * the backup. Never throws; the outcome is in the app log and the events.
     */
    public function update(InstalledApp $app, ?string $target = null): bool
    {
        $target ??= $app->available_version ?? $app->tag;
        $previousTag = $app->tag;
        $previousVersion = $app->version;
        $name = $app->displayName();
        $definition = $this->catalog->get($app->app_id);
        if (! self::isVersion($target)) {
            // the moving tag may point to a release by now: install that version tag
            $target = rescue(fn () => $this->registry->release($definition->image, $target), null, false) ?? $target;
        }
        $previousImage = $app->image_id ?? ($this->docker->inspectContainer($definition->containerName())['Image'] ?? null);

        try {
            $backup = $definition->volumes !== [] ? $this->backups->create($app, 'update') : null;
        } catch (Throwable $e) {
            AppEvent::record($app->app_id, 'core.update.failed', 'critical', "Update von {$name} abgebrochen: Sicherung fehlgeschlagen", $e->getMessage());

            return false;
        }

        $app->log("Update auf {$definition->imageRef($target)}");
        $app->update(['tag' => $target, 'status' => InstalledApp::INSTALLING, 'step' => 'Update']);
        try {
            $manifest = $this->manager->deploy($app);
            $app->update(['available_version' => null, 'available_build' => null]);
            AppEvent::record($app->app_id, 'core.update.done', 'info', "{$name} wurde aktualisiert", ($previousVersion ?? $previousTag).' → '.$manifest->version);

            return true;
        } catch (Throwable $e) {
            report($e);
            $app->log('Update fehlgeschlagen, stelle die vorige Version wieder her: '.$e->getMessage(), 'error');
        }

        // Rollback: old image, old data.
        try {
            if ($previousImage === null) {
                throw new AppException('Das vorige Image ist nicht bekannt.');
            }
            $app->update(['tag' => $previousTag]);
            $this->manager->pause($app);
            if ($backup !== null) {
                $this->backups->restore($backup, $app);
            }
            // Name the old image by its tag when the tag still points to it (readable in
            // "docker ps"); a moved tag (latest) leaves only the image id.
            $previousRef = ($this->docker->inspectImage($definition->imageRef($previousTag))['Id'] ?? null) === $previousImage
                ? $definition->imageRef($previousTag)
                : $previousImage;
            $this->manager->deploy($app, $previousRef);
            AppEvent::record($app->app_id, 'core.update.failed', 'critical', "Update von {$name} fehlgeschlagen – vorige Version läuft wieder", $e->getMessage());
        } catch (Throwable $rollback) {
            report($rollback);
            $app->update(['status' => InstalledApp::FAILED, 'error' => 'Update und Rückkehr zur vorigen Version fehlgeschlagen: '.$rollback->getMessage(), 'step' => null]);
            $app->log('Rückkehr zur vorigen Version fehlgeschlagen: '.$rollback->getMessage(), 'error');
            AppEvent::record($app->app_id, 'core.update.failed', 'critical', "Update von {$name} fehlgeschlagen, Rückkehr ebenfalls", $e->getMessage()."\n".$rollback->getMessage());
        }

        return false;
    }
}
