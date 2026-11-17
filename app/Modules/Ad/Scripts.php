<?php

namespace App\Modules\Ad;

use App\Modules\Ad\Concerns\AdPage;
use Livewire\Component;

/**
 * PK95 AD → Skripte: the files in the NETLOGON share, for a user's logon script and the
 * scripts of a Group Policy. Edit in place (PUT creates or replaces), delete.
 */
class Scripts extends Component
{
    use AdPage;

    /** null closed, 'new' or the file name */
    public ?string $editing = null;

    /** @var array{name: string, content: string} */
    public array $form = ['name' => '', 'content' => ''];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['name' => '', 'content' => ''];
        $this->editing = 'new';
    }

    public function edit(string $name): void
    {
        $this->resetErrorBag();
        $response = $this->appRequest(fn ($api) => $api->get('/api/scripts/'.rawurlencode($name)));
        if (! $this->succeeded($response, '')) {
            return;
        }
        $this->form = ['name' => (string) $response->json('name'), 'content' => (string) $response->json('content')];
        $this->editing = $name;
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $name = $this->editing === 'new' ? trim($this->form['name']) : (string) $this->editing;
        if ($name === '') {
            $this->addError('form.name', 'Gib einen Dateinamen an, etwa logon.bat.');

            return;
        }
        if ($this->editing === 'new' && ! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name)) {
            $this->addError('form.name', '1 bis 64 Zeichen: Buchstaben, Ziffern, Punkt, Minus und Unterstrich.');

            return;
        }
        $response = $this->appRequest(fn ($api) => $api->put('/api/scripts/'.rawurlencode($name), ['content' => $this->form['content']]));
        if ($this->succeeded($response)) {
            $this->done($name.' gespeichert.');
            $this->editing = null;
        }
    }

    public function delete(string $name): void
    {
        $response = $this->appRequest(fn ($api) => $api->delete('/api/scripts/'.rawurlencode($name)));
        if ($this->succeeded($response, '')) {
            $this->done($name.' gelöscht.');
        }
    }

    public function render()
    {
        [$list, $loadError] = $this->fetch('/api/scripts');
        [$domain] = $this->notReady ? [null] : $this->fetch('/api/domain');

        return view('modules.ad.scripts', [
            'rows' => is_array($list) ? $list : [],
            'share' => is_array($domain) ? '\\\\'.$domain['realm'].'\\NETLOGON' : 'NETLOGON',
            'loadError' => $loadError,
        ]);
    }
}
