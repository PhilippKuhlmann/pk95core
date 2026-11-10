<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Component;

/**
 * PK95 Proxy: certificates of the hosts, Let's Encrypt certificates for other
 * applications (download) and uploaded own certificates.
 */
class Certificates extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    public string $acmeDomain = '';

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

    public function render()
    {
        [$d, $error] = $this->load('/api/certificates');
        [$acme] = $this->load('/api/acme-certs');
        $d = is_array($d) ? $d : ['hosts' => [], 'custom' => []];

        return view('modules.proxy.certificates', [
            'hosts' => $d['hosts'] ?? [],
            'custom' => $d['custom'] ?? [],
            'acme' => is_array($acme) ? $acme : [],
            'secure' => request()->isSecure(),
            'loadError' => $error,
        ]);
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
