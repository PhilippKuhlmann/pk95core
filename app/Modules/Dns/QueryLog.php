<?php

namespace App\Modules\Dns;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 DNS: query log with filters, paging ("Mehr laden" over next_before), quick block/allow
 * rules and an optional auto refresh of the newest entries.
 */
class QueryLog extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    #[Url]
    public string $search = '';

    #[Url]
    public string $client = '';

    #[Url]
    public string $status = 'all';

    public bool $auto = false;

    /** @var list<array<string, mixed>> loaded entries, newest first */
    public array $rows = [];

    public int $nextBefore = 0;

    public ?string $loadError = null;

    public const PAGE = 50;

    public const FILTERS = ['all' => 'Alle Anfragen', 'blocked' => 'Blockiert', 'allowed' => 'Erlaubt', 'rewritten' => 'Umgeschrieben', 'failed' => 'Fehlgeschlagen'];

    public function mount(): void
    {
        if (! isset(self::FILTERS[$this->status])) {
            $this->status = 'all';
        }
        $this->reload();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'client', 'status'], true)) {
            $this->reload();
        }
    }

    /** First page again (filters changed, auto refresh, "Aktualisieren"). */
    public function reload(): void
    {
        [$d, $this->loadError] = $this->load('/api/querylog', $this->query());
        $this->rows = is_array($d) ? array_values((array) ($d['items'] ?? [])) : [];
        $this->nextBefore = is_array($d) ? (int) ($d['next_before'] ?? 0) : 0;
    }

    public function more(): void
    {
        if ($this->nextBefore <= 0) {
            return;
        }
        [$d, $error] = $this->load('/api/querylog', $this->query() + ['before' => $this->nextBefore]);
        if (! is_array($d)) {
            $this->dispatch('notice', kind: 'error', text: (string) $error);

            return;
        }
        // older pages would slide away under an auto refresh of the first page
        $this->auto = false;
        $this->rows = [...$this->rows, ...array_values((array) ($d['items'] ?? []))];
        $this->nextBefore = (int) ($d['next_before'] ?? 0);
    }

    public function toggleAuto(): void
    {
        $this->auto = ! $this->auto;
        if ($this->auto) {
            $this->reload();
        }
    }

    public function filterClient(string $client): void
    {
        $this->client = $client;
        $this->reload();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->client = '';
        $this->status = 'all';
        $this->reload();
    }

    /** Own rule for exactly this domain: block, allow or remove (POST /api/rules/quick). */
    public function quick(string $domain, string $action): void
    {
        if (! in_array($action, ['block', 'allow', 'remove'], true)) {
            return;
        }
        $response = $this->appRequest(fn ($api) => $api->post('/api/rules/quick', ['domain' => $domain, 'action' => $action]));
        if ($response === null) {
            return;
        }
        if (! $response->successful()) {
            // there is no field for these errors on this page: the toast carries the reason
            // (invalid domain, rules full) instead of the app's generic "Bitte Eingaben prüfen."
            $reason = collect((array) $response->json('fields'))->first();
            $this->dispatch('notice', kind: 'error', text: (string) ($reason ?? $response->json('error') ?? "Die App antwortet mit HTTP {$response->status()}."));

            return;
        }
        $this->dispatch('notice', kind: 'success', text: match ($action) {
            'block' => "{$domain} wird blockiert.",
            'allow' => "{$domain} ist erlaubt.",
            default => "Eigene Regel für {$domain} entfernt.",
        });
    }

    public function clear(): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->delete('/api/querylog')))) {
            $this->rows = [];
            $this->nextBefore = 0;
            $this->dispatch('notice', kind: 'success', text: 'Abfragelog geleert.');
        }
    }

    public function render()
    {
        [$rules] = $this->load('/api/rules');

        return view('modules.dns.log', ['quickRules' => self::quickRules(is_array($rules) ? (string) ($rules['text'] ?? '') : '')]);
    }

    /**
     * Domains with an own rule of exactly the form the quick actions write.
     *
     * @return array<string, string> domain → block|allow
     */
    public static function quickRules(string $text): array
    {
        $rules = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/^(@@)?\|\|([a-z0-9.\-_]+)\^$/i', trim($line), $m)) {
                $rules[strtolower($m[2])] = $m[1] === '@@' ? 'allow' : 'block';
            }
        }

        return $rules;
    }

    /**
     * Whether a logged name can become a quick rule: the domain check of the app
     * (filter.ValidDomain), so root queries ("" after the trailing dot is cut) and names with
     * escapes or spaces get no Blockieren/Erlauben button that could only fail.
     */
    public static function quickable(string $name): bool
    {
        $name = strtolower(str_ends_with($name, '.') ? substr($name, 0, -1) : $name);
        $label = '(?!-)[a-z0-9_-]{1,63}(?<!-)';

        return strlen($name) <= 253 && preg_match('/\\A'.$label.'(?:\\.'.$label.')*\\z/', $name) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function query(): array
    {
        return array_filter([
            'limit' => self::PAGE,
            'search' => trim($this->search),
            'client' => trim($this->client),
            'status' => $this->status,
        ], fn ($v) => $v !== '' && $v !== 'all');
    }
}
