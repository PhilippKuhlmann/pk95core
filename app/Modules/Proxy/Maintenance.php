<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Component;

/**
 * PK95 Proxy: maintenance page in Core mode. Caddy, the panel and the OS come with the
 * image (updated by Core); here remain the country database, the vulnerability check of
 * the panel's libraries and the run history.
 */
class Maintenance extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    public const ACTIONS = [
        'check' => 'Prüfung', 'security' => 'Sicherheitsupdates', 'all' => 'Alle Updates', 'caddy' => 'Caddy',
        'panel' => 'PK95 Proxy', 'geoip' => 'Länder-Datenbank', 'firewall' => 'Ländersperre',
    ];

    public function request(string $action): void
    {
        if (! in_array($action, ['check', 'geoip'], true)) {
            return;
        }
        if ($this->succeeded($this->appRequest(fn ($api) => $api->post('/api/updates/request', ['action' => $action])))) {
            $this->dispatch('notice', kind: 'success', text: self::ACTIONS[$action].' angestoßen.');
        }
    }

    public function render()
    {
        [$d, $error] = $this->load('/api/updates');
        $d = is_array($d) ? $d : null;
        $agent = $d['agent'] ?? null;
        $busy = $d !== null && (! empty($agent['running']) || ! empty($d['queued']));

        return view('modules.proxy.maintenance', [
            'd' => $d,
            'agent' => $agent,
            'busy' => $busy,
            'vulns' => $d['vulns']['vulns'] ?? [],
            'loadError' => $error,
        ]);
    }
}
