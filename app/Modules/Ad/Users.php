<?php

namespace App\Modules\Ad;

use App\Http\Support\Format;
use App\Modules\Ad\Concerns\AdPage;
use App\Modules\Concerns\LoadsLazily;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 AD → Benutzer: list with search and OU filter, create/edit in one dialog (the OU
 * goes through POST /api/move, the memberships through PUT /api/users/{id}/groups),
 * password, unlock, enable/disable and delete. Protected built-ins (Administrator, Guest)
 * answer 403 on what they refuse; the form already locks those fields.
 */
class Users extends Component
{
    use AdPage;
    use LoadsLazily;

    public const PLACEHOLDER = [['header', 'Benutzer', 1, 'sub' => 1], ['filters', 2], ['table', 8, 5, 2]];

    #[Url]
    public string $q = '';

    /** OU filter (objectGUID, '' = all; recursive) */
    #[Url]
    public string $ou = '';

    /** null closed, 'new' or the user id */
    public ?string $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<string, mixed> the form as loaded (PUT sends only what changed) */
    public array $original = [];

    /** @var array<string, mixed> read-only details of the user in the dialog */
    public array $details = [];

    /** user id of the password dialog */
    public ?string $passwordFor = null;

    public string $passwordName = '';

    /** @var array{password: string, must_change_password: bool} */
    public array $passwordForm = ['password' => '', 'must_change_password' => true];

    /** fields written with POST/PUT /api/users as they are */
    private const FIELDS = ['username', 'given_name', 'surname', 'display_name', 'upn', 'email', 'description', 'enabled',
        'must_change_password', 'password_never_expires', 'profile_path', 'home_directory', 'home_drive', 'script_path'];

    public function updatedQ(): void
    {
        $this->q = trim($this->q);
    }

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = array_fill_keys(self::FIELDS, '') + ['ou_id' => $this->ou, 'password' => '', 'expires' => '', 'group_ids' => []];
        $this->form['enabled'] = true;
        $this->form['must_change_password'] = true;
        $this->form['password_never_expires'] = false;
        $this->original = [];
        $this->details = [];
        $this->editing = 'new';
    }

    public function edit(string $id): void
    {
        $this->resetErrorBag();
        $response = $this->appRequest(fn ($api) => $api->get('/api/users/'.$id));
        if (! $this->succeeded($response, '')) {
            return;
        }
        $u = (array) $response->json();
        $form = [];
        foreach (self::FIELDS as $key) {
            $form[$key] = is_bool($u[$key] ?? null) ? $u[$key] : (string) ($u[$key] ?? '');
        }
        $form['ou_id'] = (string) ($u['ou_id'] ?? '');
        $form['expires'] = ! empty($u['expires_at']) ? Format::fromMs((int) $u['expires_at'])->format('Y-m-d') : '';
        $form['group_ids'] = array_values(array_map(fn ($g) => (string) $g['id'], (array) ($u['groups'] ?? [])));
        $this->form = $form;
        $this->original = $form;
        $this->details = $u;
        $this->editing = $id;
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->passwordFor = null;
        $this->passwordForm = ['password' => '', 'must_change_password' => true];
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->resetErrorBag();
        // display_name is the object name (CN): 1 to 64 characters, no / or \ (empty = given name and surname)
        $display = trim((string) ($this->form['display_name'] ?? ''));
        if (mb_strlen($display) > 64 || strpbrk($display, '/\\') !== false) {
            $this->addError('form.display_name', 'Der Anzeigename hat höchstens 64 Zeichen und enthält kein / oder \\.');

            return;
        }
        if ($this->editing === 'new') {
            $body = array_intersect_key($this->form, array_flip(self::FIELDS)) + [
                'password' => (string) $this->form['password'],
                'expires_at' => $this->expiresMs(),
                'group_ids' => array_values((array) $this->form['group_ids']),
            ];
            if ($this->form['ou_id'] !== '') {
                $body['ou_id'] = $this->form['ou_id'];
            }
            $response = $this->appRequest(fn ($api) => $api->post('/api/users', $body));
            $this->form['password'] = '';
            if ($this->succeeded($response)) {
                $this->done('Benutzer '.($response->json('username') ?? '').' angelegt.');
                $this->editing = null;
            }

            return;
        }

        $id = $this->editing;
        $body = [];
        foreach (self::FIELDS as $key) {
            if (($this->form[$key] ?? null) !== ($this->original[$key] ?? null)) {
                $body[$key] = $this->form[$key];
            }
        }
        if ($this->form['expires'] !== $this->original['expires']) {
            $body['expires_at'] = $this->expiresMs();
        }
        if ($body !== []) {
            $response = $this->appRequest(fn ($api) => $api->put('/api/users/'.$id, $body));
            if (! $this->succeeded($response)) {
                return;
            }
            $this->original = array_merge($this->original, array_intersect_key($this->form, $body + ['expires' => true]));
        }
        if ($this->form['ou_id'] !== $this->original['ou_id']) {
            $response = $this->appRequest(fn ($api) => $api->post('/api/move', ['ids' => [$id], 'target_id' => $this->form['ou_id']]));
            if (! $this->succeeded($response)) {
                return;
            }
            $this->original['ou_id'] = $this->form['ou_id'];
        }
        $groups = array_values((array) $this->form['group_ids']);
        if (self::differ($groups, (array) $this->original['group_ids'])) {
            $response = $this->appRequest(fn ($api) => $api->put('/api/users/'.$id.'/groups', ['group_ids' => $groups]));
            if (! $this->succeeded($response)) {
                return;
            }
        }
        $this->done('Benutzer gespeichert.');
        $this->editing = null;
    }

    public function toggle(string $id, bool $enabled): void
    {
        $response = $this->appRequest(fn ($api) => $api->put('/api/users/'.$id, ['enabled' => ! $enabled]));
        if ($this->succeeded($response, '')) {
            $this->done($enabled ? 'Benutzer deaktiviert.' : 'Benutzer aktiviert.');
        }
    }

    public function unlock(string $id): void
    {
        $response = $this->appRequest(fn ($api) => $api->post('/api/users/'.$id.'/unlock'));
        if ($this->succeeded($response, '')) {
            $this->done('Konto entsperrt.');
        }
    }

    public function delete(string $id): void
    {
        $response = $this->appRequest(fn ($api) => $api->delete('/api/users/'.$id));
        if ($this->succeeded($response, '')) {
            $this->done('Benutzer gelöscht.');
        }
    }

    public function password(string $id, string $name): void
    {
        $this->resetErrorBag();
        $this->passwordForm = ['password' => '', 'must_change_password' => true];
        $this->passwordName = $name;
        $this->passwordFor = $id;
    }

    public function savePassword(): void
    {
        $this->resetErrorBag();
        $id = (string) $this->passwordFor;
        $response = $this->appRequest(fn ($api) => $api->post('/api/users/'.$id.'/password', [
            'password' => $this->passwordForm['password'],
            'must_change_password' => (bool) $this->passwordForm['must_change_password'],
        ]));
        $this->passwordForm['password'] = '';
        if ($this->succeeded($response, 'passwordForm.')) {
            $this->done('Kennwort gesetzt.');
            $this->passwordFor = null;
        }
    }

    protected function mapField(string $field): string
    {
        return match (true) {
            $field === 'expires_at' => 'expires',
            $field === 'target_id', str_starts_with($field, 'ids') => 'ou_id',
            str_starts_with($field, 'group_ids') => 'group_ids',
            default => $field,
        };
    }

    /** the account's last day (date field) as the end of that day in ms, 0 = never */
    private function expiresMs(): int
    {
        $day = trim((string) ($this->form['expires'] ?? ''));
        if ($day === '') {
            return 0;
        }
        try {
            return (int) Carbon::createFromFormat('Y-m-d', $day, Format::timezone())->endOfDay()->getTimestampMs();
        } catch (\Throwable) {
            return -1; // the app answers 422 fields.expires_at
        }
    }

    /**
     * @param  list<mixed>  $a
     * @param  list<mixed>  $b
     */
    private static function differ(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a !== $b;
    }

    public function render()
    {
        $ous = $this->ous();
        $list = null;
        $loadError = null;
        $groups = [];
        if (! $this->notReady) {
            $query = array_filter(['search' => $this->q, 'ou_id' => $this->ou, 'recursive' => $this->ou !== '' ? 1 : null]);
            [$list, $loadError] = $this->fetch('/api/users', $query);
            if ($this->editing !== null) {
                [$g] = $this->fetch('/api/groups');
                foreach ((array) ($g['items'] ?? []) as $group) {
                    $groups[] = ['value' => (string) $group['id'], 'label' => (string) $group['name'], 'hint' => (string) ($group['ou_path'] ?? '')];
                }
            }
        }

        return view('modules.ad.users', [
            'rows' => (array) ($list['items'] ?? []),
            'total' => (int) ($list['total'] ?? 0),
            'ouOptions' => self::ouOptions($ous),
            'groupOptions' => $groups,
            'loadError' => $loadError,
        ]);
    }
}
