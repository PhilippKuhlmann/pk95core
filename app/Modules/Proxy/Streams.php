<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Component;

/**
 * PK95 Proxy: TCP/UDP streams (port forwarding without domain), live counters and the
 * most recent connections.
 */
class Streams extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    public ?string $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public const PROTOS = ['tcp' => 'TCP', 'udp' => 'UDP', 'both' => 'TCP und UDP'];

    public const ACCESS = [
        'internal' => 'Nur intern: aus deinen internen Netzen',
        'public' => 'Öffentlich: aus dem Internet erreichbar',
        'custom' => 'Eigene IP-Bereiche',
    ];

    public const ACCESS_HINTS = [
        'internal' => 'Verbindungen von anderswo werden sofort getrennt.',
        'public' => 'Der Dienst steht ohne Anmeldung durch das Panel im Internet. Das Ziel muss sich selbst absichern.',
        'custom' => 'Verbindungen von anderswo werden sofort getrennt.',
    ];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['name' => '', 'protocol' => 'tcp', 'listen_port' => '', 'target_host' => '', 'target_port' => '',
            'access_mode' => 'internal', 'ip_allow' => '', 'enabled' => true, 'notes' => ''];
        $this->editing = 'new';
    }

    public function edit(int $id): void
    {
        $s = $this->find($id);
        if ($s !== null) {
            $this->resetErrorBag();
            $this->form = ['name' => $s['name'], 'protocol' => $s['protocol'], 'listen_port' => (string) $s['listen_port'],
                'target_host' => $s['target_host'], 'target_port' => (string) $s['target_port'], 'access_mode' => $s['access_mode'],
                'ip_allow' => implode("\n", $s['ip_allow'] ?? []), 'enabled' => (bool) $s['enabled'], 'notes' => (string) $s['notes']];
            $this->editing = (string) $id;
        }
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function save(): void
    {
        $f = $this->form;
        $custom = ($f['access_mode'] ?? '') === 'custom';
        $body = [
            'name' => (string) $f['name'], 'protocol' => (string) $f['protocol'], 'listen_port' => (int) $f['listen_port'],
            'target_host' => trim((string) $f['target_host']), 'target_port' => (int) $f['target_port'],
            'access_mode' => (string) $f['access_mode'],
            'ip_allow' => $custom ? self::lines((string) $f['ip_allow']) : [],
            'enabled' => ! empty($f['enabled']), 'notes' => (string) $f['notes'],
        ];
        $new = $this->editing === 'new';
        $response = $this->appRequest(fn ($api) => $new ? $api->post('/api/streams', $body) : $api->put('/api/streams/'.(int) $this->editing, $body));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: 'Gespeichert.');
            $this->editing = null;
        }
    }

    public function toggle(int $id): void
    {
        $s = $this->find($id);
        if ($s === null) {
            return;
        }
        $body = array_intersect_key($s, array_flip(['name', 'protocol', 'listen_port', 'target_host', 'target_port', 'access_mode', 'ip_allow', 'enabled', 'notes']));
        $body['enabled'] = ! $s['enabled'];
        if ($this->succeeded($this->appRequest(fn ($api) => $api->put('/api/streams/'.$id, $body)))) {
            $this->dispatch('notice', kind: 'success', text: $s['name'].($body['enabled'] ? ' ist aktiv.' : ' ist deaktiviert.'));
        }
    }

    public function delete(int $id): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->delete('/api/streams/'.$id)))) {
            $this->dispatch('notice', kind: 'success', text: 'Gelöscht.');
        }
    }

    public function render()
    {
        [$d, $error] = $this->load('/api/streams');
        $d = is_array($d) ? $d : ['streams' => [], 'recent' => [], 'service_ok' => true];

        return view('modules.proxy.streams', [
            'streams' => $d['streams'],
            'recent' => array_slice($d['recent'], 0, 30),
            'serviceOk' => (bool) $d['service_ok'],
            'serverHost' => request()->getHost(),
            'loadError' => $error,
        ]);
    }

    /**
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $text)), fn ($v) => $v !== ''));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(int $id): ?array
    {
        $response = $this->appRequest(fn ($api) => $api->get('/api/streams'));
        if (! $this->succeeded($response)) {
            return null;
        }
        foreach ((array) $response->json('streams') as $s) {
            if ((int) $s['id'] === $id) {
                return $s;
            }
        }
        $this->dispatch('notice', kind: 'error', text: 'Den Stream gibt es nicht mehr.');

        return null;
    }
}
