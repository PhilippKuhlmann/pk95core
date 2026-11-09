<?php

namespace App\Modules\Dns;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Component;

/**
 * PK95 DNS: local DNS records (rewrites): a name or *.wildcard answered with an address
 * or a CNAME target. They apply also while protection is off.
 */
class Rewrites extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    public ?string $editing = null;

    /** @var array{domain: string, answer: string, enabled: bool} */
    public array $form = ['domain' => '', 'answer' => '', 'enabled' => true];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['domain' => '', 'answer' => '', 'enabled' => true];
        $this->editing = 'new';
    }

    public function edit(int $id): void
    {
        $r = $this->find($id);
        if ($r === null) {
            return;
        }
        $this->resetErrorBag();
        $this->form = ['domain' => (string) $r['domain'], 'answer' => (string) $r['answer'], 'enabled' => (bool) $r['enabled']];
        $this->editing = (string) $id;
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function save(): void
    {
        $body = ['domain' => trim($this->form['domain']), 'answer' => trim($this->form['answer']), 'enabled' => (bool) $this->form['enabled']];
        $new = $this->editing === 'new';
        $response = $this->appRequest(fn ($api) => $new ? $api->post('/api/rewrites', $body) : $api->put('/api/rewrites/'.(int) $this->editing, $body));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: 'Gespeichert.');
            $this->editing = null;
        }
    }

    public function toggle(int $id): void
    {
        $r = $this->find($id);
        if ($r !== null) {
            $this->succeeded($this->appRequest(fn ($api) => $api->put('/api/rewrites/'.$id,
                ['domain' => $r['domain'], 'answer' => $r['answer'], 'enabled' => ! $r['enabled']])));
        }
    }

    public function delete(int $id): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->delete('/api/rewrites/'.$id)))) {
            $this->dispatch('notice', kind: 'success', text: 'Eintrag gelöscht.');
        }
    }

    public function render()
    {
        [$rows, $error] = $this->load('/api/rewrites');

        return view('modules.dns.rewrites', ['rows' => is_array($rows) ? $rows : [], 'loadError' => $error]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(int $id): ?array
    {
        $response = $this->appRequest(fn ($api) => $api->get('/api/rewrites'));
        if (! $this->succeeded($response)) {
            return null;
        }
        foreach ((array) $response->json() as $r) {
            if ((int) $r['id'] === $id) {
                return $r;
            }
        }
        $this->dispatch('notice', kind: 'error', text: 'Den Eintrag gibt es nicht mehr.');

        return null;
    }
}
