<?php

namespace App\Modules\Dns;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Component;

/**
 * PK95 DNS: block and allow lists (filter lists by URL), with a picker of well-known lists.
 * Downloads run in the app; the page polls while a list says "updating".
 */
class Lists extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    public ?string $editing = null;

    /** @var array{name: string, url: string, kind: string, enabled: bool} */
    public array $form = ['name' => '', 'url' => '', 'kind' => 'block', 'enabled' => true];

    /** Poll for a few seconds after a refresh, until the app reports the download. */
    public int $watchUntil = 0;

    public const KINDS = ['block' => 'Blocklisten', 'allow' => 'Erlaubnislisten'];

    public function create(string $kind = 'block'): void
    {
        $this->resetErrorBag();
        $this->form = ['name' => '', 'url' => '', 'kind' => $kind === 'allow' ? 'allow' : 'block', 'enabled' => true];
        $this->editing = 'new';
    }

    public function edit(int $id): void
    {
        $l = $this->find($id);
        if ($l === null) {
            return;
        }
        $this->resetErrorBag();
        $this->form = ['name' => (string) $l['name'], 'url' => (string) $l['url'], 'kind' => (string) $l['kind'], 'enabled' => (bool) $l['enabled']];
        $this->editing = (string) $id;
    }

    /** Fills the form from the catalog of well-known lists. */
    public function pick(string $url): void
    {
        [$catalog] = $this->load('/api/lists/catalog');
        foreach (is_array($catalog) ? $catalog : [] as $c) {
            if (($c['url'] ?? null) === $url) {
                $this->form = ['name' => (string) $c['name'], 'url' => $url, 'kind' => (string) $c['kind'], 'enabled' => true];
                $this->resetErrorBag();
            }
        }
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function save(): void
    {
        $body = ['name' => trim($this->form['name']), 'url' => trim($this->form['url']), 'kind' => $this->form['kind'], 'enabled' => (bool) $this->form['enabled']];
        $new = $this->editing === 'new';
        $response = $this->appRequest(fn ($api) => $new ? $api->post('/api/lists', $body) : $api->put('/api/lists/'.(int) $this->editing, $body));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: $body['enabled'] ? 'Gespeichert. Die Liste wird geladen.' : 'Gespeichert.');
            $this->editing = null;
            $this->watch();
        }
    }

    public function toggle(int $id): void
    {
        $l = $this->find($id);
        if ($l === null) {
            return;
        }
        $body = ['name' => $l['name'], 'url' => $l['url'], 'kind' => $l['kind'], 'enabled' => ! $l['enabled']];
        if ($this->succeeded($this->appRequest(fn ($api) => $api->put('/api/lists/'.$id, $body)))) {
            $this->watch();
        }
    }

    public function refresh(?int $id = null): void
    {
        $response = $this->appRequest(fn ($api) => $api->post($id === null ? '/api/lists/refresh' : "/api/lists/{$id}/refresh"));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: $id === null ? 'Alle aktiven Listen werden neu geladen.' : 'Die Liste wird neu geladen.');
            $this->watch();
        }
    }

    public function delete(int $id): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->delete('/api/lists/'.$id)))) {
            $this->dispatch('notice', kind: 'success', text: 'Liste entfernt.');
        }
    }

    public function render()
    {
        [$lists, $error] = $this->load('/api/lists');
        $lists = is_array($lists) ? $lists : [];
        $catalog = [];
        if ($this->editing === 'new') {
            [$c] = $this->load('/api/lists/catalog');
            $known = array_column($lists, 'url');
            $catalog = array_values(array_filter(is_array($c) ? $c : [], fn ($x) => ! in_array($x['url'], $known, true)));
        }
        $updating = collect($lists)->contains(fn ($l) => ! empty($l['updating']));

        return view('modules.dns.lists', [
            'groups' => collect($lists)->groupBy('kind')->all(),
            'catalog' => $catalog,
            'poll' => $updating || time() < $this->watchUntil,
            'loadError' => $error,
        ]);
    }

    private function watch(): void
    {
        $this->watchUntil = time() + 10;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(int $id): ?array
    {
        $response = $this->appRequest(fn ($api) => $api->get('/api/lists'));
        if (! $this->succeeded($response)) {
            return null;
        }
        foreach ((array) $response->json() as $l) {
            if ((int) $l['id'] === $id) {
                return $l;
            }
        }
        $this->dispatch('notice', kind: 'error', text: 'Die Liste gibt es nicht mehr.');

        return null;
    }
}
