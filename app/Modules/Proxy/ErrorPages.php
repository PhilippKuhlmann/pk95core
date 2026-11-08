<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Component;

/**
 * PK95 Proxy: error pages Caddy shows itself (target down, no access, not found, other,
 * maintenance), with title/text or own HTML and a preview.
 */
class ErrorPages extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    public bool $enabled = false;

    /** @var array<string, array{title: string, text: string, html: string}> */
    public array $pages = [];

    /** @var array<string, array{title: string, text: string}> */
    public array $defaults = [];

    public ?string $previewHtml = null;

    public ?string $loadError = null;

    public const GROUPS = [
        'unavailable' => ['Dienst nicht erreichbar', '502, 503 und 504: Das Ziel ist ausgefallen oder antwortet nicht. Die Seite lädt sich alle 30 Sekunden neu.'],
        'forbidden' => ['Kein Zugriff', '401 und 403: Die Zugriffsliste des Hosts lässt die Adresse nicht zu.'],
        'not_found' => ['Nicht gefunden', '404: 404-Hosts und, wenn als Standardseite „Fehlerseite 404“ gewählt ist, unbekannte Domains.'],
        'other' => ['Sonstige Fehler', 'Alle anderen Fehler, die Caddy selbst erzeugt.'],
        'maintenance' => ['Wartung', 'Für Hosts im Wartungsmodus, auch wenn eigene Fehlerseiten ausgeschaltet sind. Pro Host lässt sich ein eigener Text festlegen.'],
    ];

    public function mount(): void
    {
        [$d, $this->loadError] = $this->load('/api/error-pages');
        if (is_array($d)) {
            $this->defaults = $d['defaults'] ?? [];
            $this->applyConfig($d['config']);
        }
    }

    public function resetGroup(string $key): void
    {
        if (isset(self::GROUPS[$key], $this->defaults[$key])) {
            $this->pages[$key] = ['title' => $this->defaults[$key]['title'], 'text' => $this->defaults[$key]['text'], 'html' => ''];
        }
    }

    public function save(): void
    {
        $pages = [];
        foreach (array_keys(self::GROUPS) as $key) {
            $p = $this->pages[$key] ?? [];
            $pages[$key] = ['title' => (string) ($p['title'] ?? ''), 'text' => (string) ($p['text'] ?? ''), 'html' => (string) ($p['html'] ?? '')];
        }
        $response = $this->appRequest(fn ($api) => $api->put('/api/error-pages', ['enabled' => $this->enabled, 'pages' => $pages]));
        if ($this->succeeded($response)) {
            $this->applyConfig($response->json());
            $this->dispatch('notice', kind: 'success', text: 'Gespeichert und in Caddy übernommen.');
        }
    }

    public function preview(string $key): void
    {
        if (! isset(self::GROUPS[$key])) {
            return;
        }
        $response = $this->appRequest(fn ($api) => $api->get('/api/error-pages/preview/'.$key));
        if ($this->succeeded($response)) {
            $this->previewHtml = $response->body();
        }
    }

    public function closePreview(): void
    {
        $this->previewHtml = null;
    }

    public function render()
    {
        return view('modules.proxy.error-pages');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function applyConfig(array $config): void
    {
        $this->enabled = (bool) ($config['enabled'] ?? false);
        foreach (array_keys(self::GROUPS) as $key) {
            $p = $config['pages'][$key] ?? [];
            $this->pages[$key] = ['title' => (string) ($p['title'] ?? ''), 'text' => (string) ($p['text'] ?? ''), 'html' => (string) ($p['html'] ?? '')];
        }
    }
}
