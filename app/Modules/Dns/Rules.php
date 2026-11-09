<?php

namespace App\Modules\Dns;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * PK95 DNS: own filter rules (one text, invalid lines are saved but reported) and a check
 * that runs a domain through the filter without asking an upstream.
 */
class Rules extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    public string $text = '';

    public int $ruleCount = 0;

    /** @var list<array{line: int, text: string, error: string}> */
    public array $invalid = [];

    /** @var array{name: string, type: string, client: string} */
    public array $check = ['name' => '', 'type' => 'A', 'client' => ''];

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public ?string $loadError = null;

    /**
     * True once the stored rules were read. PUT /api/rules replaces the whole text, so a
     * form that never saw the stored rules must not save (it would wipe them).
     */
    #[Locked]
    public bool $loaded = false;

    public const TYPES = ['A', 'AAAA', 'CNAME', 'HTTPS', 'MX', 'TXT', 'PTR', 'SRV'];

    public function mount(): void
    {
        $this->retry();
    }

    /** Reads the stored rules again after a failed first load ("Erneut versuchen"). */
    public function retry(): void
    {
        if ($this->loaded) {
            return;
        }
        [$d, $this->loadError] = $this->load('/api/rules');
        if (is_array($d)) {
            $this->apply($d);
            $this->loaded = true;
        }
    }

    public function save(): void
    {
        if (! $this->loaded) {
            return;
        }
        $response = $this->appRequest(fn ($api) => $api->put('/api/rules', ['text' => $this->text]));
        if (! $this->succeeded($response, '')) {
            return;
        }
        $this->apply((array) $response->json());
        $text = 'Gespeichert: '.Fmt::plural($this->ruleCount, 'Regel', 'Regeln').' aktiv.';
        if ($this->invalid) {
            $text .= ' '.Fmt::plural(count($this->invalid), 'Zeile wird', 'Zeilen werden').' ignoriert.';
        }
        $this->dispatch('notice', kind: 'success', text: $text);
    }

    public function runCheck(): void
    {
        $this->result = null;
        $query = array_filter(['name' => trim($this->check['name']), 'type' => $this->check['type'], 'client' => trim($this->check['client'])], fn ($v) => $v !== '');
        $response = $this->appRequest(fn ($api) => $api->get('/api/check', $query));
        if ($this->succeeded($response, 'check.')) {
            $this->result = (array) $response->json();
        }
    }

    public function render()
    {
        return view('modules.dns.rules');
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private function apply(array $d): void
    {
        $this->text = (string) ($d['text'] ?? '');
        $this->ruleCount = (int) ($d['rule_count'] ?? 0);
        $this->invalid = array_values((array) ($d['invalid'] ?? []));
    }
}
