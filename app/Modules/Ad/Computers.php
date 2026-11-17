<?php

namespace App\Modules\Ad;

use App\Modules\Ad\Concerns\AdPage;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 AD → Computer: the joined machines. Description, enabled and the OU (POST /api/move)
 * can be changed, a computer can be removed (it has to join again). Domain controllers are
 * locked (403).
 */
class Computers extends Component
{
    use AdPage;

    #[Url]
    public string $q = '';

    #[Url]
    public string $ou = '';

    /** null closed or the computer id */
    public ?string $editing = null;

    /** @var array{description: string, enabled: bool, ou_id: string} */
    public array $form = ['description' => '', 'enabled' => true, 'ou_id' => ''];

    /** @var array<string, mixed> */
    public array $details = [];

    public function edit(string $id): void
    {
        $this->resetErrorBag();
        $response = $this->appRequest(fn ($api) => $api->get('/api/computers/'.$id));
        if (! $this->succeeded($response, '')) {
            return;
        }
        $c = (array) $response->json();
        $this->form = ['description' => (string) ($c['description'] ?? ''), 'enabled' => (bool) ($c['enabled'] ?? true), 'ou_id' => (string) ($c['ou_id'] ?? '')];
        $this->details = $c;
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
        $id = (string) $this->editing;
        $body = [];
        if ($this->form['description'] !== (string) ($this->details['description'] ?? '')) {
            $body['description'] = $this->form['description'];
        }
        if ((bool) $this->form['enabled'] !== (bool) ($this->details['enabled'] ?? true)) {
            $body['enabled'] = (bool) $this->form['enabled'];
        }
        if ($body !== []) {
            $response = $this->appRequest(fn ($api) => $api->put('/api/computers/'.$id, $body));
            if (! $this->succeeded($response)) {
                return;
            }
            $this->details = array_merge($this->details, $body);
        }
        if ($this->form['ou_id'] !== (string) ($this->details['ou_id'] ?? '')) {
            $response = $this->appRequest(fn ($api) => $api->post('/api/move', ['ids' => [$id], 'target_id' => $this->form['ou_id']]));
            if (! $this->succeeded($response)) {
                return;
            }
        }
        $this->done('Computer gespeichert.');
        $this->editing = null;
    }

    public function toggle(string $id, bool $enabled): void
    {
        $response = $this->appRequest(fn ($api) => $api->put('/api/computers/'.$id, ['enabled' => ! $enabled]));
        if ($this->succeeded($response, '')) {
            $this->done($enabled ? 'Computer deaktiviert.' : 'Computer aktiviert.');
        }
    }

    public function delete(string $id): void
    {
        $response = $this->appRequest(fn ($api) => $api->delete('/api/computers/'.$id));
        if ($this->succeeded($response, '')) {
            $this->done('Computer entfernt.');
        }
    }

    protected function mapField(string $field): string
    {
        return $field === 'target_id' || str_starts_with($field, 'ids') ? 'ou_id' : $field;
    }

    public function render()
    {
        $ous = $this->ous();
        $list = null;
        $loadError = null;
        if (! $this->notReady) {
            $query = array_filter(['search' => trim($this->q), 'ou_id' => $this->ou, 'recursive' => $this->ou !== '' ? 1 : null]);
            [$list, $loadError] = $this->fetch('/api/computers', $query);
        }

        return view('modules.ad.computers', [
            'rows' => (array) ($list['items'] ?? []),
            'total' => (int) ($list['total'] ?? 0),
            'ouOptions' => self::ouOptions($ous),
            'loadError' => $loadError,
        ]);
    }
}
