<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Component;

/**
 * PK95 Proxy: default page for unknown names, general settings and system facts.
 * Password and notifications are Core's business in Core mode.
 */
class Settings extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    /** @var array<string, mixed> settings as the panel returned them (PUT sends them back whole) */
    public array $current = [];

    public string $acmeEmail = '';

    public string $retentionDays = '';

    public string $trustedProxies = '';

    public string $defaultAction = '404';

    public string $defaultHtml = '';

    public string $defaultRedirect = '';

    public ?string $loadError = null;

    public function mount(): void
    {
        [$v, $this->loadError] = $this->load('/api/settings');
        if (is_array($v)) {
            $this->applyConfig($v);
        }
    }

    public function saveGeneral(): void
    {
        $this->put([
            'acme_email' => $this->acmeEmail,
            'retention_days' => (int) $this->retentionDays,
            'trusted_proxies' => Streams::lines($this->trustedProxies),
        ], 'Einstellungen gespeichert.');
    }

    public function saveDefault(): void
    {
        $this->put([
            'default_action' => $this->defaultAction,
            'default_html' => $this->defaultHtml,
            'default_redirect' => $this->defaultRedirect,
        ], 'Standardseite gespeichert und in Caddy übernommen.');
    }

    public function render()
    {
        [$status] = $this->load('/api/status');

        return view('modules.proxy.settings', ['status' => is_array($status) ? $status : null]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function put(array $changes, string $notice): void
    {
        $response = $this->appRequest(fn ($api) => $api->put('/api/settings', array_merge($this->current, $changes)));
        if ($this->succeeded($response)) {
            $this->applyConfig($response->json());
            $this->dispatch('notice', kind: 'success', text: $notice);
        }
    }

    /**
     * @param  array<string, mixed>  $v
     */
    private function applyConfig(array $v): void
    {
        $this->current = $v;
        $this->acmeEmail = (string) ($v['acme_email'] ?? '');
        $this->retentionDays = (string) ($v['retention_days'] ?? '');
        $this->trustedProxies = implode("\n", $v['trusted_proxies'] ?? []);
        $this->defaultAction = (string) ($v['default_action'] ?? '404');
        $this->defaultHtml = (string) ($v['default_html'] ?? '');
        $this->defaultRedirect = (string) ($v['default_redirect'] ?? '');
    }
}
