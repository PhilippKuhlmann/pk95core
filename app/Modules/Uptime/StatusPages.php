<?php

namespace App\Modules\Uptime;

use App\Models\AppLink;
use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use App\Services\Automation\Linker;
use App\Support\Settings;
use Livewire\Component;

/**
 * PK95 Uptime: public status pages (/status/<slug> of the Uptime container).
 */
class StatusPages extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    /** null = list, 'new' = create, otherwise the id of the page being edited */
    public ?string $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public const RANGES = ['24h' => '24 Stunden', '7d' => '7 Tage', '30d' => '30 Tage', '90d' => '90 Tage'];

    /** Base address of the public status pages (setting uptime_public_url). */
    public string $publicUrl = '';

    public function mount(): void
    {
        $this->publicUrl = (string) Settings::get('uptime_public_url');
    }

    /** Store the public address and set up proxy host and DNS record for it. */
    public function savePublicUrl(Linker $linker): void
    {
        $this->resetErrorBag('publicUrl');
        $url = rtrim(trim($this->publicUrl), '/');
        $host = parse_url($url, PHP_URL_HOST);
        if ($url !== '' && (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || ! $host || str_contains($url, '?'))) {
            $this->addError('publicUrl', 'Bitte eine Adresse wie https://status.example.de angeben.');

            return;
        }
        $changed = $url !== rtrim((string) Settings::get('uptime_public_url'), '/');
        Settings::put('uptime_public_url', $url);
        $this->publicUrl = $url;
        $this->link($linker, $changed
            ? ' PK95 Uptime übernimmt die Adresse, wenn der Container neu erstellt wird (App-Details → Neu erstellen).' : '');
    }

    /** Proxy host and DNS record for the public address (button "Einrichten"; idempotent). */
    public function setupPublic(Linker $linker): void
    {
        $this->link($linker);
    }

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = $this->blank();
        $this->editing = 'new';
    }

    public function edit(string $id): void
    {
        $this->resetErrorBag();
        $response = $this->appRequest(fn ($api) => $api->get('/api/status-pages/'.rawurlencode($id)));
        if (! $this->succeeded($response)) {
            return;
        }
        $page = $response->json();
        $form = $this->blank();
        foreach (['slug', 'title', 'description', 'enabled', 'range'] as $key) {
            $form[$key] = $page[$key];
        }
        foreach ($page['monitors'] as $entry) {
            $form['monitors'][$entry['monitorId']] = ['selected' => true, 'publicName' => $entry['publicName'], 'group' => $entry['group']];
        }
        $this->form = $form;
        $this->editing = $id;
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $payload = [
            'slug' => trim((string) $this->form['slug']),
            'title' => trim((string) $this->form['title']),
            'description' => trim((string) $this->form['description']),
            'enabled' => (bool) $this->form['enabled'],
            'range' => (string) $this->form['range'],
            'monitors' => collect($this->form['monitors'])
                ->filter(fn (array $m) => ! empty($m['selected']))
                ->map(fn (array $m, string $id) => ['monitorId' => $id, 'publicName' => trim((string) $m['publicName']), 'group' => trim((string) $m['group'])])
                ->values()
                ->all(),
        ];
        $new = $this->editing === 'new';
        $response = $this->appRequest(fn ($api) => $new
            ? $api->post('/api/status-pages', $payload)
            : $api->put('/api/status-pages/'.rawurlencode((string) $this->editing), $payload));

        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: $new ? 'Statusseite angelegt.' : 'Statusseite gespeichert.');
            $this->editing = null;
        }
    }

    public function delete(string $id): void
    {
        $response = $this->appRequest(fn ($api) => $api->delete('/api/status-pages/'.rawurlencode($id)));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: 'Statusseite gelöscht.');
        }
    }

    public function render()
    {
        [$pages, $error] = $this->load('/api/status-pages');
        [$monitors] = $this->load('/api/monitors');

        return view('modules.uptime.status-pages', [
            // links "Öffnen"/copy point here; empty = no public address
            'publicBase' => rtrim(trim((string) Settings::get('uptime_public_url')), '/'),
            'statusLink' => Linker::statusLink(),
            'pages' => is_array($pages) ? $pages : [],
            'monitors' => collect(is_array($monitors) ? $monitors : [])->sortBy(fn ($m) => mb_strtolower($m['name']))->values(),
            'loadError' => $error,
            'ranges' => self::RANGES,
        ]);
    }

    private function link(Linker $linker, string $suffix = ''): void
    {
        $link = $linker->linkStatusPages();
        if ($link === null || trim((string) Settings::get('uptime_public_url')) === '') {
            $this->dispatch('notice', kind: 'success', text: 'Keine öffentliche Adresse: Die Statusseiten sind nur intern.'.$suffix);

            return;
        }
        [$kind, $text] = match ($link->state) {
            AppLink::ACTIVE => ['success', "Eingerichtet: https://{$link->domain} führt zu den Statusseiten."],
            AppLink::PENDING_DNS => ['success', "Proxy-Host angelegt, er wartet auf DNS für {$link->domain}."],
            default => ['error', 'Nicht eingerichtet: '.($link->message ?? 'unbekannter Fehler')],
        };
        $this->dispatch('notice', kind: $kind, text: $text.$suffix);
    }

    protected function mapField(string $field): string
    {
        // "monitors.3.publicName" refers to the position in the payload; show it at the list.
        return str_starts_with($field, 'monitors.') ? 'monitors' : $field;
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(): array
    {
        [$monitors] = $this->load('/api/monitors');
        $entries = [];
        foreach (is_array($monitors) ? $monitors : [] as $m) {
            $entries[$m['id']] = ['selected' => false, 'publicName' => $m['name'], 'group' => $m['group']];
        }

        return ['slug' => '', 'title' => '', 'description' => '', 'enabled' => true, 'range' => '90d', 'monitors' => $entries];
    }
}
