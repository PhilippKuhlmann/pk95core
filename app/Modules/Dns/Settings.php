<?php

namespace App\Modules\Dns;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * PK95 DNS: settings in sections. Each section PUTs only its own keys (the app merges a
 * subset), so unsaved input in another section stays untouched.
 */
class Settings extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    /** @var array<string, mixed> form values, keyed like the API (lists as text, one per line) */
    public array $s = [];

    /** @var list<array{upstream: string, ok: bool, ms: int|float, error: string}>|null */
    public ?array $testResults = null;

    public ?string $loadError = null;

    /** True once the settings were read; before that nothing may be saved (a section would be sent empty). */
    #[Locked]
    public bool $loaded = false;

    /** Keys whose value is a list of strings, edited as one entry per line. */
    public const LISTS = ['upstreams', 'fallback', 'bootstrap', 'private_upstreams', 'allowed_clients', 'disallowed_clients'];

    public const INTS = ['upstream_timeout_ms', 'cache_size', 'cache_min_ttl', 'cache_max_ttl', 'blocked_ttl', 'ratelimit', 'querylog_hours', 'stats_days', 'lists_update_hours'];

    public const SECTIONS = [
        'upstreams' => ['upstreams', 'fallback', 'bootstrap', 'encrypted_only', 'upstream_mode', 'upstream_timeout_ms', 'dnssec'],
        'routing' => ['conditional', 'private_ptr', 'private_upstreams'],
        'cache' => ['cache_enabled', 'cache_size', 'cache_min_ttl', 'cache_max_ttl'],
        'blocking' => ['blocking_mode', 'blocking_ipv4', 'blocking_ipv6', 'blocked_ttl', 'disable_ipv6'],
        'access' => ['allowed_clients', 'disallowed_clients', 'ratelimit', 'refuse_any'],
        'log' => ['querylog_enabled', 'querylog_hours', 'anonymize_ip', 'stats_days'],
        'safesearch' => ['safesearch'],
        'lists' => ['lists_update_hours'],
    ];

    public const NOTICES = [
        'upstreams' => 'Upstreams gespeichert.', 'routing' => 'Weiterleitungen gespeichert.', 'cache' => 'Cache-Einstellungen gespeichert.',
        'blocking' => 'Antworten gespeichert.', 'access' => 'Zugriff gespeichert.', 'log' => 'Log und Statistik gespeichert.',
        'safesearch' => 'SafeSearch gespeichert.', 'lists' => 'Aktualisierung der Listen gespeichert.',
    ];

    public const MODES = [
        'load_balance' => 'Lastverteilung (schnellere Upstreams öfter)',
        'parallel' => 'Parallel (alle fragen, schnellste Antwort gewinnt)',
        'sequential' => 'Der Reihe nach',
    ];

    public const BLOCKING = [
        'null_ip' => 'Null-Adresse (0.0.0.0 und ::)',
        'nxdomain' => 'Domain existiert nicht (NXDOMAIN)',
        'refused' => 'Anfrage ablehnen (REFUSED)',
        'custom_ip' => 'Eigene Adresse',
    ];

    public function mount(): void
    {
        $this->retry();
    }

    /** Reads the settings again after a failed first load ("Erneut versuchen"). */
    public function retry(): void
    {
        if ($this->loaded) {
            return;
        }
        [$v, $this->loadError] = $this->load('/api/settings');
        if (is_array($v)) {
            $this->s = self::toForm($v);
            $this->loaded = true;
        }
    }

    public function save(string $section): void
    {
        if (! $this->loaded || ! isset(self::SECTIONS[$section])) {
            return;
        }
        $keys = self::SECTIONS[$section];
        $this->forgetErrors($keys);
        if (in_array('conditional', $keys, true)) {
            // toApi() drops blank rows; drop them here too, so the app's "conditional.N"
            // errors (N = index in the sent list) land on the row that caused them
            $this->s['conditional'] = array_values(array_filter((array) ($this->s['conditional'] ?? []), fn ($c) => ! self::blankRow((array) $c)));
        }
        $body = array_intersect_key(self::toApi($this->s), array_flip($keys));
        $response = $this->appRequest(fn ($api) => $api->put('/api/settings', $body));
        if ($this->succeeded($response, 's.')) {
            // only this section: unsaved input elsewhere stays
            $this->s = array_merge($this->s, array_intersect_key(self::toForm((array) $response->json()), array_flip($keys)));
            $this->dispatch('notice', kind: 'success', text: self::NOTICES[$section]);
        }
    }

    public function addConditional(): void
    {
        $this->s['conditional'][] = ['domain' => '', 'upstream' => ''];
    }

    public function removeConditional(int $index): void
    {
        unset($this->s['conditional'][$index]);
        $this->s['conditional'] = array_values($this->s['conditional']);
        // errors are keyed by row index: after re-indexing they would sit on the wrong row
        $this->forgetErrors(['conditional']);
    }

    /** Asks the entered upstreams and fallbacks for example.org (unsaved input counts). */
    public function test(): void
    {
        $this->testResults = null;
        if (! $this->loaded) {
            return;
        }
        $upstreams = array_slice(array_values(array_unique([...Fmt::lines((string) $this->s['upstreams']), ...Fmt::lines((string) $this->s['fallback'])])), 0, 20);
        if (! $upstreams) {
            $this->addError('s.upstreams', 'Bitte mindestens einen Upstream eintragen.');

            return;
        }
        $response = $this->appRequest(fn ($api) => $api->post('/api/upstreams/test', ['upstreams' => $upstreams, 'bootstrap' => Fmt::lines((string) $this->s['bootstrap'])]));
        if ($this->succeeded($response, 's.')) {
            $this->testResults = array_values((array) $response->json('results'));
        }
    }

    public function clearCache(): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->delete('/api/cache')))) {
            $this->dispatch('notice', kind: 'success', text: 'Cache geleert.');
        }
    }

    public function clearLog(): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->delete('/api/querylog')))) {
            $this->dispatch('notice', kind: 'success', text: 'Abfragelog geleert.');
        }
    }

    public function resetStats(): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->delete('/api/stats')))) {
            $this->dispatch('notice', kind: 'success', text: 'Statistik zurückgesetzt.');
        }
    }

    public function render()
    {
        [$status] = $this->load('/api/status');

        return view('modules.dns.settings', ['status' => is_array($status) ? $status : null]);
    }

    /**
     * Removes the field errors of these keys ("s.<key>" and "s.<key>.…").
     *
     * @param  list<string>  $keys
     */
    private function forgetErrors(array $keys): void
    {
        $bag = $this->getErrorBag();
        foreach (array_keys($bag->messages()) as $field) {
            if (in_array(explode('.', $field)[1] ?? '', $keys, true)) {
                $bag->forget($field);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function blankRow(array $row): bool
    {
        return trim((string) ($row['domain'] ?? '')) === '' && trim((string) ($row['upstream'] ?? '')) === '';
    }

    /**
     * "upstreams.2" → the textarea, "safesearch.google" → the SafeSearch block,
     * "conditional.1" → the domain field of that row.
     */
    protected function mapField(string $field): string
    {
        if (preg_match('/^('.implode('|', self::LISTS).'|safesearch)\..+$/', $field, $m)) {
            return $m[1];
        }
        if (preg_match('/^conditional\.(\d+)$/', $field, $m)) {
            return "conditional.{$m[1]}.domain";
        }

        return $field;
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    public static function toForm(array $v): array
    {
        foreach (self::LISTS as $key) {
            if (array_key_exists($key, $v)) {
                $v[$key] = implode("\n", (array) $v[$key]);
            }
        }
        $v['conditional'] = array_values(array_map(fn ($c) => ['domain' => (string) ($c['domain'] ?? ''), 'upstream' => (string) ($c['upstream'] ?? '')], (array) ($v['conditional'] ?? [])));
        $v['safesearch'] = array_map('boolval', (array) ($v['safesearch'] ?? [])) + ['enabled' => false] + array_fill_keys(array_keys(Fmt::ENGINES), true);

        return $v;
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    public static function toApi(array $s): array
    {
        foreach (self::LISTS as $key) {
            if (array_key_exists($key, $s)) {
                $s[$key] = Fmt::lines((string) $s[$key]);
            }
        }
        foreach (self::INTS as $key) {
            if (array_key_exists($key, $s)) {
                $s[$key] = (int) $s[$key];
            }
        }
        $s['conditional'] = array_values(array_map(
            fn ($c) => ['domain' => trim((string) ($c['domain'] ?? '')), 'upstream' => trim((string) ($c['upstream'] ?? ''))],
            array_filter((array) ($s['conditional'] ?? []), fn ($c) => ! self::blankRow((array) $c)),
        ));
        $s['safesearch'] = array_map('boolval', (array) ($s['safesearch'] ?? []));
        foreach (['blocking_ipv4', 'blocking_ipv6', 'blocking_mode', 'upstream_mode'] as $key) {
            if (array_key_exists($key, $s)) {
                $s[$key] = trim((string) $s[$key]);
            }
        }

        return $s;
    }
}
