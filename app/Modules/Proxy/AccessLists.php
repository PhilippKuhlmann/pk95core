<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Component;

/**
 * PK95 Proxy: access lists (users with their own login page and IP rules) and the
 * built-in list "Intern" that defines the internal networks.
 */
class AccessLists extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    public ?string $editing = null;

    public bool $internal = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public const MODES = ['all' => 'Beides nötig', 'any' => 'Eine Bedingung genügt'];

    public const HOURS = [1 => '1 Stunde', 12 => '12 Stunden', 24 => '1 Tag', 168 => '7 Tage', 720 => '30 Tage'];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['name' => '', 'title' => '', 'mode' => 'all', 'allow' => '', 'deny' => '', 'session_hours' => '12', 'users' => []];
        $this->internal = false;
        $this->editing = 'new';
    }

    public function edit(int $id): void
    {
        $l = $this->find($id);
        if ($l === null) {
            return;
        }
        $this->resetErrorBag();
        $this->form = [
            'name' => $l['name'], 'title' => (string) $l['title'], 'mode' => $l['mode'] ?: 'all',
            'allow' => implode("\n", $l['allow']), 'deny' => implode("\n", $l['deny']),
            'session_hours' => (string) $l['session_hours'],
            'users' => array_map(fn ($u) => ['username' => $u['username'], 'password' => '', 'new' => false], $l['users']),
        ];
        $this->internal = (bool) $l['is_internal'];
        $this->editing = (string) $id;
    }

    public function addUser(): void
    {
        $this->form['users'][] = ['username' => '', 'password' => '', 'new' => true];
    }

    public function removeUser(int $index): void
    {
        unset($this->form['users'][$index]);
        $this->form['users'] = array_values($this->form['users']);
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function save(): void
    {
        $f = $this->form;
        $users = array_values(array_filter(
            array_map(fn ($u) => ['username' => trim((string) $u['username']), 'password' => (string) $u['password']], $f['users'] ?? []),
            fn ($u) => $u['username'] !== '' || $u['password'] !== '',
        ));
        $body = [
            'name' => (string) $f['name'], 'title' => (string) $f['title'], 'mode' => ($f['mode'] ?? 'all') === 'any' ? 'any' : 'all',
            'allow' => Streams::lines((string) $f['allow']), 'deny' => Streams::lines((string) $f['deny']),
            'session_hours' => (int) $f['session_hours'], 'users' => $users, 'used_by' => [],
        ];
        $new = $this->editing === 'new';
        $response = $this->appRequest(fn ($api) => $new ? $api->post('/api/access-lists', $body) : $api->put('/api/access-lists/'.(int) $this->editing, $body));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: 'Gespeichert.');
            $this->editing = null;
        }
    }

    public function delete(int $id): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->delete('/api/access-lists/'.$id)))) {
            $this->dispatch('notice', kind: 'success', text: 'Gelöscht.');
        }
    }

    public function render()
    {
        [$lists, $error] = $this->load('/api/access-lists');

        return view('modules.proxy.access-lists', [
            'lists' => is_array($lists) ? $lists : [],
            'loadError' => $error,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(int $id): ?array
    {
        $response = $this->appRequest(fn ($api) => $api->get('/api/access-lists'));
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
