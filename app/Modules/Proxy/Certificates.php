<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use App\Services\Automation\LanIp;
use App\Services\Catalog\AppDefinition;
use App\Services\Certsync\Certsync;
use App\Services\Docker\DockerClient;
use App\Services\Updates\UpdateService;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Throwable;

/**
 * PK95 Proxy: certificates of the hosts, Let's Encrypt certificates for other
 * applications (download) and uploaded own certificates.
 */
class Certificates extends Component
{
    use TalksToApp;

    /** From this version the exported files belong to the group of proxy_certs_gid (readable by certsync). */
    public const CERTS_GROUP = '1.7.0';

    public InstalledApp $installedApp;

    public string $acmeDomain = '';

    /** public key for the SFTP user certsync (one line) */
    public string $newKey = '';

    public string $acmeNote = '';

    public string $certName = '';

    public string $certPem = '';

    public string $keyPem = '';

    public function createAcme(): void
    {
        $response = $this->appRequest(fn ($api) => $api->post('/api/acme-certs', ['domain' => trim($this->acmeDomain), 'note' => $this->acmeNote]));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: 'Zertifikat für '.$response->json('domain').' wird geholt.');
            $this->acmeDomain = $this->acmeNote = '';
        }
    }

    public function deleteAcme(int $id): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->delete('/api/acme-certs/'.$id)))) {
            $this->dispatch('notice', kind: 'success', text: 'Gelöscht.');
        }
    }

    /** Download of fullchain.pem or privkey.pem through Core (the browser never talks to the panel). */
    public function download(int $id, string $file)
    {
        if (! in_array($file, ['fullchain.pem', 'privkey.pem'], true)) {
            return null;
        }
        $response = $this->appRequest(fn ($api) => $api->get('/api/acme-certs/'.$id.'/'.$file));
        if (! $this->succeeded($response)) {
            return null;
        }
        $name = preg_match('/filename="([^"]+)"/', (string) $response->header('Content-Disposition'), $m) ? $m[1] : $file;
        $body = $response->body();

        return response()->streamDownload(function () use ($body) {
            echo $body;
        }, $name, ['Content-Type' => 'application/x-pem-file']);
    }

    public function upload(): void
    {
        $response = $this->appRequest(fn ($api) => $api->post('/api/certificates', ['name' => $this->certName, 'certificate' => $this->certPem, 'key' => $this->keyPem]));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: $response->json('name').' ist aktiv.');
            $this->certName = $this->certPem = $this->keyPem = '';
        }
    }

    public function deleteCert(int $id): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->delete('/api/certificates/'.$id)))) {
            $this->dispatch('notice', kind: 'success', text: 'Gelöscht.');
        }
    }

    public function addKey(): void
    {
        try {
            $key = app(Certsync::class)->add($this->newKey);
        } catch (\InvalidArgumentException $e) {
            $this->addError('newKey', $e->getMessage());

            return;
        } catch (\RuntimeException $e) {
            $this->dispatch('notice', kind: 'error', text: $e->getMessage());

            return;
        }
        $this->newKey = '';
        $this->resetErrorBag('newKey');
        $this->dispatch('notice', kind: 'success', text: 'Schlüssel für certsync hinzugefügt ('.$key['fingerprint'].').');
    }

    public function removeKey(string $fingerprint): void
    {
        try {
            $removed = app(Certsync::class)->remove($fingerprint);
        } catch (\RuntimeException $e) {
            $this->dispatch('notice', kind: 'error', text: $e->getMessage());

            return;
        }
        if ($removed) {
            $this->dispatch('notice', kind: 'success', text: 'Schlüssel entfernt. Dieser Rechner kann die Zertifikate nicht mehr abholen.');
        }
    }

    public function render()
    {
        [$d, $error] = $this->load('/api/certificates');
        [$acme] = $this->load('/api/acme-certs');
        $d = is_array($d) ? $d : ['hosts' => [], 'custom' => []];

        // the export folder on the LXC (catalog bind), whether the container has it, the LAN IP
        $definition = $this->installedApp->definition();
        $bind = self::exportBind($definition?->binds ?? []);

        return view('modules.proxy.certificates', [
            'certBind' => $bind,
            // the SFTP user on the LXC: its keys, whether it is set up, the gid there vs. the setting
            'certsync' => Certsync::keys(),
            'certsyncSetup' => Certsync::setup(),
            'certsGid' => (int) Settings::get('proxy_certs_gid'),
            'bindActive' => $bind && $definition ? self::bindActive($definition, $bind) : null,
            // only what is known: the setting or the last detection (detecting starts a container)
            'lanIp' => trim((string) Settings::get('automation_lan_ip')) ?: (Cache::get(LanIp::CACHE)['ip'] ?? null),
            'groupTooOld' => UpdateService::isVersion((string) $this->installedApp->version)
                && version_compare(ltrim((string) $this->installedApp->version, 'v'), self::CERTS_GROUP, '<'),
            'hosts' => $d['hosts'] ?? [],
            'custom' => $d['custom'] ?? [],
            'acme' => is_array($acme) ? $acme : [],
            'secure' => request()->isSecure(),
            'loadError' => $error,
        ]);
    }

    /**
     * The host folder the exported certificates are bound to (catalog binds of the proxy).
     *
     * @param  list<array{source: string, target: string, create: bool}>  $binds
     * @return array{source: string, target: string, create: bool}|null
     */
    public static function exportBind(array $binds): ?array
    {
        foreach ($binds as $bind) {
            if (str_ends_with($bind['target'], '/acme-export')) {
                return $bind;
            }
        }

        return null;
    }

    /** Path of an exported file on the LXC: the container path below the bind's target. */
    public static function hostPath(string $path, array $bind): ?string
    {
        return str_starts_with($path, $bind['target'].'/') ? $bind['source'].substr($path, strlen($bind['target'])) : null;
    }

    /**
     * Whether the running proxy container has the bind (a container from before the catalog
     * change does not: then it has to be created again). null = Docker did not answer.
     *
     * @param  array{source: string, target: string, create: bool}  $bind
     */
    public static function bindActive(AppDefinition $definition, array $bind): ?bool
    {
        try {
            $container = app(DockerClient::class)->inspectContainer($definition->containerName());
        } catch (Throwable) {
            return null;
        }
        if ($container === null) {
            return null;
        }
        $mounts = [
            ...array_map(fn ($m) => [$m['Type'] ?? '', $m['Source'] ?? '', $m['Destination'] ?? ''], (array) ($container['Mounts'] ?? [])),
            ...array_map(fn ($m) => [$m['Type'] ?? '', $m['Source'] ?? '', $m['Target'] ?? ''], (array) ($container['HostConfig']['Mounts'] ?? [])),
        ];

        return in_array(['bind', $bind['source'], $bind['target']], $mounts, true);
    }

    /**
     * Whole days left until $notAfter (Unix seconds): the difference of two moments, so it
     * does not depend on the timezone or the time of day; negative once expired.
     */
    public static function daysLeft(int $notAfter): int
    {
        return (int) floor(($notAfter - now()->getTimestamp()) / 86400);
    }

    /**
     * Validity with dot: red when expired, amber within $warnDays.
     *
     * @return array{0: string, 1: string}
     */
    public static function validity(int $notAfter, int $warnDays = 14): array
    {
        $days = self::daysLeft($notAfter);
        $tone = $days < 0 ? 'bad' : ($days <= $warnDays ? 'warn' : 'good');

        return [$tone, Fmt::date($notAfter * 1000).' ('.($days < 0 ? 'abgelaufen' : Fmt::plural($days, 'Tag', 'Tage')).')'];
    }
}
