<?php

namespace App\Modules\Uptime;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
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
            'pages' => is_array($pages) ? $pages : [],
            'monitors' => collect(is_array($monitors) ? $monitors : [])->sortBy(fn ($m) => mb_strtolower($m['name']))->values(),
            'loadError' => $error,
            'ranges' => self::RANGES,
        ]);
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
