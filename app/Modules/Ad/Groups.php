<?php

namespace App\Modules\Ad;

use App\Modules\Ad\Concerns\AdPage;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 AD → Gruppen: list with search, OU, type and scope filter; create/edit with the
 * direct members (PUT /api/groups/{id}/members, only when they changed). Built-in groups
 * keep name, type, scope and place; description and members can be changed.
 */
class Groups extends Component
{
    use AdPage;

    #[Url]
    public string $q = '';

    #[Url]
    public string $ou = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $scope = '';

    /** null closed, 'new' or the group id */
    public ?string $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<string, mixed> */
    public array $original = [];

    /** @var array<string, mixed> */
    public array $details = [];

    public const TYPES = ['security' => 'Sicherheitsgruppe', 'distribution' => 'Verteilergruppe'];

    public const SCOPES = ['global' => 'Global', 'domain_local' => 'Lokal (in Domäne)', 'universal' => 'Universal', 'builtin_local' => 'Lokal (Builtin)'];

    private const FIELDS = ['name', 'description', 'email', 'type', 'scope'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['name' => '', 'description' => '', 'email' => '', 'type' => 'security', 'scope' => 'global', 'ou_id' => $this->ou, 'member_ids' => []];
        $this->original = [];
        $this->details = [];
        $this->editing = 'new';
    }

    public function edit(string $id): void
    {
        $this->resetErrorBag();
        $response = $this->appRequest(fn ($api) => $api->get('/api/groups/'.$id));
        if (! $this->succeeded($response, '')) {
            return;
        }
        $g = (array) $response->json();
        $form = [];
        foreach (self::FIELDS as $key) {
            $form[$key] = (string) ($g[$key] ?? '');
        }
        $form['ou_id'] = (string) ($g['ou_id'] ?? '');
        $form['member_ids'] = array_values(array_map(fn ($m) => (string) $m['id'], (array) ($g['members'] ?? [])));
        $this->form = $form;
        $this->original = $form;
        $this->details = $g;
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
        if ($this->editing === 'new') {
            $body = array_intersect_key($this->form, array_flip(self::FIELDS)) + ['member_ids' => array_values((array) $this->form['member_ids'])];
            if ($this->form['ou_id'] !== '') {
                $body['ou_id'] = $this->form['ou_id'];
            }
            $response = $this->appRequest(fn ($api) => $api->post('/api/groups', $body));
            if ($this->succeeded($response)) {
                $this->done('Gruppe '.$this->form['name'].' angelegt.');
                $this->editing = null;
            }

            return;
        }

        $id = $this->editing;
        $body = [];
        foreach (self::FIELDS as $key) {
            if ($this->form[$key] !== $this->original[$key]) {
                $body[$key] = $this->form[$key];
            }
        }
        if ($body !== []) {
            $response = $this->appRequest(fn ($api) => $api->put('/api/groups/'.$id, $body));
            if (! $this->succeeded($response)) {
                return;
            }
            $this->original = array_merge($this->original, $body);
        }
        if ($this->form['ou_id'] !== $this->original['ou_id']) {
            $response = $this->appRequest(fn ($api) => $api->post('/api/move', ['ids' => [$id], 'target_id' => $this->form['ou_id']]));
            if (! $this->succeeded($response)) {
                return;
            }
            $this->original['ou_id'] = $this->form['ou_id'];
        }
        $members = array_values((array) $this->form['member_ids']);
        $before = (array) $this->original['member_ids'];
        sort($members);
        sort($before);
        if ($members !== $before) {
            $response = $this->appRequest(fn ($api) => $api->put('/api/groups/'.$id.'/members', ['member_ids' => array_values((array) $this->form['member_ids'])]));
            if (! $this->succeeded($response)) {
                return;
            }
        }
        $this->done('Gruppe gespeichert.');
        $this->editing = null;
    }

    public function delete(string $id): void
    {
        $response = $this->appRequest(fn ($api) => $api->delete('/api/groups/'.$id));
        if ($this->succeeded($response, '')) {
            $this->done('Gruppe gelöscht.');
        }
    }

    protected function mapField(string $field): string
    {
        return match (true) {
            $field === 'target_id', str_starts_with($field, 'ids') => 'ou_id',
            str_starts_with($field, 'member_ids') => 'member_ids',
            default => $field,
        };
    }

    /**
     * Users, groups and computers as member options (grouped); members the lists do not
     * carry (foreign principals) stay selectable under "Andere".
     *
     * @return list<array{value: string, label: string, hint?: string, group: string}>
     */
    private function memberOptions(): array
    {
        $options = [];
        $seen = [];
        foreach (['/api/users' => 'Benutzer', '/api/groups' => 'Gruppen', '/api/computers' => 'Computer'] as $path => $group) {
            [$list] = $this->fetch($path);
            foreach ((array) ($list['items'] ?? []) as $item) {
                $id = (string) $item['id'];
                if ($id === $this->editing) {
                    continue; // not a member of itself
                }
                $label = (string) ($item['display_name'] ?? $item['name'] ?? $item['username'] ?? $id);
                $hint = (string) ($item['username'] ?? $item['ou_path'] ?? '');
                $options[] = ['value' => $id, 'label' => $label, 'hint' => $hint !== $label ? $hint : '', 'group' => $group];
                $seen[$id] = true;
            }
        }
        foreach ((array) ($this->details['members'] ?? []) as $m) {
            if (! isset($seen[(string) $m['id']])) {
                $options[] = ['value' => (string) $m['id'], 'label' => (string) $m['name'], 'group' => 'Andere'];
            }
        }

        return $options;
    }

    public function render()
    {
        $ous = $this->ous();
        $list = null;
        $loadError = null;
        $members = [];
        if (! $this->notReady) {
            $query = array_filter(['search' => trim($this->q), 'ou_id' => $this->ou, 'recursive' => $this->ou !== '' ? 1 : null, 'type' => $this->type, 'scope' => $this->scope]);
            [$list, $loadError] = $this->fetch('/api/groups', $query);
            if ($this->editing !== null) {
                $members = $this->memberOptions();
            }
        }

        return view('modules.ad.groups', [
            'rows' => (array) ($list['items'] ?? []),
            'total' => (int) ($list['total'] ?? 0),
            'ouOptions' => self::ouOptions($ous),
            'memberOptions' => $members,
            'loadError' => $loadError,
        ]);
    }
}
