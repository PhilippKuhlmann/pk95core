<?php

namespace App\Modules\Dns;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Component;

/**
 * PK95 DNS: configured clients (IPs/CIDRs with their own filtering, SafeSearch and blocked
 * services) and the clients seen in the last 24 hours.
 */
class Clients extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    public ?string $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function create(string $ip = '', string $name = ''): void
    {
        $this->resetErrorBag();
        $this->form = [
            'name' => $name, 'ids' => $ip,
            'use_global_settings' => true, 'filtering_enabled' => true,
            'safesearch' => ['enabled' => false] + array_fill_keys(array_keys(Fmt::ENGINES), true),
            'use_global_services' => true, 'blocked_services' => [],
        ];
        $this->editing = 'new';
    }

    public function edit(int $id): void
    {
        $c = $this->find($id);
        if ($c === null) {
            return;
        }
        $this->resetErrorBag();
        $this->form = [
            'name' => (string) $c['name'], 'ids' => implode("\n", (array) $c['ids']),
            'use_global_settings' => (bool) $c['use_global_settings'], 'filtering_enabled' => (bool) $c['filtering_enabled'],
            'safesearch' => array_map('boolval', (array) $c['safesearch']) + ['enabled' => false] + array_fill_keys(array_keys(Fmt::ENGINES), true),
            'use_global_services' => (bool) $c['use_global_services'], 'blocked_services' => array_values((array) $c['blocked_services']),
        ];
        $this->editing = (string) $id;
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function save(): void
    {
        $f = $this->form;
        $body = [
            'name' => trim((string) $f['name']),
            'ids' => Fmt::lines((string) $f['ids']),
            'use_global_settings' => (bool) $f['use_global_settings'],
            'filtering_enabled' => (bool) $f['filtering_enabled'],
            'safesearch' => array_map('boolval', (array) $f['safesearch']),
            'use_global_services' => (bool) $f['use_global_services'],
            'blocked_services' => array_values((array) $f['blocked_services']),
        ];
        $new = $this->editing === 'new';
        $response = $this->appRequest(fn ($api) => $new ? $api->post('/api/clients', $body) : $api->put('/api/clients/'.(int) $this->editing, $body));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: 'Gespeichert.');
            $this->editing = null;
        }
    }

    public function delete(int $id): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->delete('/api/clients/'.$id)))) {
            $this->dispatch('notice', kind: 'success', text: 'Client gelöscht.');
        }
    }

    public function render()
    {
        [$d, $error] = $this->load('/api/clients');
        $services = [];
        if ($this->editing !== null || is_array($d)) {
            [$s] = $this->load('/api/services');
            $services = array_values((array) ($s['services'] ?? []));
        }

        return view('modules.dns.clients', [
            'clients' => is_array($d) ? (array) ($d['clients'] ?? []) : [],
            'recent' => is_array($d) ? (array) ($d['recent'] ?? []) : [],
            'serviceGroups' => Services::grouped($services),
            'serviceNames' => array_column($services, 'name', 'id'),
            'loadError' => $error,
        ]);
    }

    /** "ids.3" → the textarea, "safesearch.google" → the SafeSearch block. */
    protected function mapField(string $field): string
    {
        return preg_replace('/^(ids|safesearch|blocked_services)\..*$/', '$1', $field);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(int $id): ?array
    {
        $response = $this->appRequest(fn ($api) => $api->get('/api/clients'));
        if (! $this->succeeded($response)) {
            return null;
        }
        foreach ((array) $response->json('clients') as $c) {
            if ((int) $c['id'] === $id) {
                return $c;
            }
        }
        $this->dispatch('notice', kind: 'error', text: 'Den Client gibt es nicht mehr.');

        return null;
    }
}
