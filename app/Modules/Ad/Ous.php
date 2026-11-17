<?php

namespace App\Modules\Ad;

use App\Modules\Ad\Concerns\AdPage;
use Livewire\Component;

/**
 * PK95 AD → Organisationseinheiten: the tree (GET /api/ous), create, rename, describe, move
 * (POST /api/move), block GPO inheritance, delete (with recursive=1 for a non-empty OU) and
 * the resulting GPO order for a container.
 */
class Ous extends Component
{
    use AdPage;

    /** null closed, 'new' or the OU id */
    public ?string $editing = null;

    /** @var array{name: string, description: string, parent_id: string, block_inheritance: bool} */
    public array $form = ['name' => '', 'description' => '', 'parent_id' => '', 'block_inheritance' => false];

    /** @var array<string, mixed> the node being edited (flat form plus parent_id) */
    public array $node = [];

    /** delete dialog: the OU id */
    public ?string $deleting = null;

    public bool $recursive = false;

    /** GPO inheritance dialog: the container id */
    public ?string $inheritanceFor = null;

    public function create(string $parentId = ''): void
    {
        $this->resetErrorBag();
        $this->form = ['name' => '', 'description' => '', 'parent_id' => $parentId, 'block_inheritance' => false];
        $this->node = [];
        $this->editing = 'new';
    }

    public function edit(string $id): void
    {
        $this->resetErrorBag();
        [$root] = $this->fetch('/api/ous');
        $node = is_array($root) ? self::findNode($root, $id) : null;
        if ($node === null) {
            $this->dispatch('notice', kind: 'error', text: 'Die Organisationseinheit gibt es nicht mehr.');

            return;
        }
        $this->node = [
            'id' => $id, 'name' => (string) $node['name'], 'description' => (string) ($node['description'] ?? ''),
            'type' => (string) ($node['type'] ?? 'ou'), 'path' => (string) ($node['path'] ?? ''), 'parent_id' => (string) ($node['parent_id'] ?? ''),
            'protected' => (bool) ($node['protected'] ?? false), 'block_inheritance' => (bool) ($node['block_inheritance'] ?? false),
        ];
        $this->form = ['name' => $this->node['name'], 'description' => $this->node['description'], 'parent_id' => $this->node['parent_id'], 'block_inheritance' => $this->node['block_inheritance']];
        $this->editing = $id;
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->deleting = null;
        $this->inheritanceFor = null;
        $this->recursive = false;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $name = trim($this->form['name']);
        if ($this->editing === 'new') {
            $response = $this->appRequest(fn ($api) => $api->post('/api/ous', ['name' => $name, 'parent_id' => $this->form['parent_id'], 'description' => $this->form['description']]));
            if ($this->succeeded($response)) {
                $this->done('Organisationseinheit '.$name.' angelegt.');
                $this->editing = null;
            }

            return;
        }

        $id = (string) $this->editing;
        $body = [];
        if (! $this->node['protected'] && $name !== $this->node['name']) {
            $body['name'] = $name;
        }
        if ($this->form['description'] !== $this->node['description']) {
            $body['description'] = $this->form['description'];
        }
        if ($this->node['type'] === 'ou' && (bool) $this->form['block_inheritance'] !== $this->node['block_inheritance']) {
            $body['block_inheritance'] = (bool) $this->form['block_inheritance'];
        }
        if ($body !== []) {
            $response = $this->appRequest(fn ($api) => $api->put('/api/ous/'.$id, $body));
            if (! $this->succeeded($response)) {
                return;
            }
            $this->node = array_merge($this->node, $body);
        }
        if (! $this->node['protected'] && $this->form['parent_id'] !== $this->node['parent_id']) {
            $response = $this->appRequest(fn ($api) => $api->post('/api/move', ['ids' => [$id], 'target_id' => $this->form['parent_id']]));
            if (! $this->succeeded($response)) {
                return;
            }
        }
        $this->done('Organisationseinheit gespeichert.');
        $this->editing = null;
    }

    public function confirmDelete(string $id): void
    {
        $this->resetErrorBag();
        $this->recursive = false;
        $this->deleting = $id;
    }

    public function delete(): void
    {
        $this->resetErrorBag();
        $id = (string) $this->deleting;
        $response = $this->appRequest(fn ($api) => $api->delete('/api/ous/'.$id.($this->recursive ? '?recursive=1' : '')));
        if ($response?->status() === 409 && ! $this->recursive) {
            // not empty: the dialog offers the recursive delete
            $this->addError('recursive', (string) ($response->json('error') ?? 'Die Organisationseinheit ist nicht leer.'));

            return;
        }
        if ($this->succeeded($response, '')) {
            $this->done('Organisationseinheit gelöscht.');
            $this->deleting = null;
        }
    }

    public function inheritance(string $id): void
    {
        $this->inheritanceFor = $id;
    }

    protected function mapField(string $field): string
    {
        return $field === 'target_id' || str_starts_with($field, 'ids') ? 'parent_id' : $field;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null the node with parent_id filled from the tree
     */
    private static function findNode(array $node, string $id, string $parent = ''): ?array
    {
        if (($node['id'] ?? null) === $id) {
            return $node + ['parent_id' => $parent];
        }
        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child) && ($found = self::findNode($child, $id, (string) ($node['id'] ?? ''))) !== null) {
                return $found;
            }
        }

        return null;
    }

    public function render()
    {
        [$root, $loadError] = $this->fetch('/api/ous');
        $ous = self::flattenOus(is_array($root) ? $root : null);
        $byId = collect($ous)->keyBy('id');
        $inheritance = null;
        if ($this->inheritanceFor !== null && ! $this->notReady) {
            [$inheritance] = $this->fetch('/api/ous/'.$this->inheritanceFor.'/gpo-inheritance');
        }

        // where an OU can go: the domain, OUs and containers, not into itself or below itself
        $self = $this->editing !== null && $this->editing !== 'new' ? $byId->get($this->editing) : null;
        $parentOptions = array_values(array_filter(self::ouOptions($ous), function (array $o) use ($byId, $self) {
            if ($self === null) {
                return true;
            }
            $path = (string) ($byId->get($o['value'])['path'] ?? '');

            return $o['value'] !== $self['id'] && ! str_starts_with($path.'/', $self['path'].'/');
        }));

        return view('modules.ad.ous', [
            'ous' => $ous,
            'byId' => $byId,
            'parentOptions' => $parentOptions,
            'inheritance' => $inheritance,
            'loadError' => $loadError,
        ]);
    }
}
