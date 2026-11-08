<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Component;

/**
 * PK95 Proxy: country rules (nftables), blocklists, automatic IP ban and the server
 * signature switch.
 */
class Security extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    public string $mode = 'block';

    /** @var list<string> */
    public array $blockList = [];

    /** @var list<string> */
    public array $allowList = [];

    /** @var array{mode: string, block: list<string>, allow: list<string>} */
    public array $saved = ['mode' => 'block', 'block' => [], 'allow' => []];

    /** @var array<string, mixed> */
    public array $guard = [];

    public bool $hideServer = false;

    public string $banIp = '';

    public string $banHours = '24';

    public string $blName = '';

    public string $blUrl = '';

    /** @var list<array<string, mixed>>|null result of the last dry run */
    public ?array $scanMatches = null;

    public ?string $loadError = null;

    public const REASONS = ['scan' => 'Scan', 'auth' => 'Anmeldefehler', 'not_found' => 'Nicht gefunden', 'unknown_hosts' => 'Unbekannte Hosts', 'manual' => 'Von Hand'];

    public const THRESHOLDS = [
        'scan_hits' => ['Scan-Pfade', 'Etwa /.env oder /.git/config. Kein normaler Besucher ruft das auf.', 0, 100],
        'auth_fails' => ['Anmeldefehler', 'Status 401 und 403, bei mindestens zwei Dritteln der Anfragen.', 0, 1000],
        'not_found' => ['Nicht gefundene Seiten', 'Verschiedene Pfade mit 404, bei mindestens zwei Dritteln der Anfragen.', 0, 1000],
        'unknown_hosts' => ['Unbekannte Hostnamen', 'Durchprobieren von Subdomains, die hier nicht eingerichtet sind.', 0, 100],
        'window_minutes' => ['Zeitfenster (Minuten)', 'In dieser Zeit müssen die Werte erreicht werden.', 1, 120],
        'ban_hours' => ['Sperrdauer (Stunden)', 'Für die erste Sperre einer Adresse.', 1, 8760],
    ];

    public function mount(): void
    {
        [$d, $this->loadError] = $this->load('/api/security');
        if (! is_array($d)) {
            return;
        }
        $this->saved = ['mode' => $d['mode'], 'block' => $d['blocked'], 'allow' => $d['allowed']];
        $this->mode = $d['mode'] === 'allow' ? 'allow' : 'block';
        $this->blockList = $d['blocked'];
        $this->allowList = $d['allowed'];
        $this->fillGuard($d['guard']);
        $this->hideServer = (bool) $d['hide_server'];
    }

    public function unselect(string $code): void
    {
        $prop = $this->mode === 'allow' ? 'allowList' : 'blockList';
        $this->{$prop} = array_values(array_diff($this->{$prop}, [$code]));
    }

    public function saveCountries(): void
    {
        $countries = array_values($this->mode === 'allow' ? $this->allowList : $this->blockList);
        $response = $this->appRequest(fn ($api) => $api->put('/api/security/countries', ['mode' => $this->mode, 'countries' => $countries]));
        if ($this->succeeded($response)) {
            $this->saved = array_merge($this->saved, ['mode' => $this->mode, $this->mode => $countries]);
            $this->dispatch('notice', kind: 'success', text: 'Regel wird geladen …');
        }
    }

    public function saveGuard(): void
    {
        $g = $this->guard;
        $body = ['enabled' => ! empty($g['enabled']), 'notify' => ! empty($g['notify']), 'exempt' => Streams::lines((string) ($g['exempt'] ?? ''))];
        foreach (array_keys(self::THRESHOLDS) as $key) {
            $body[$key] = (int) ($g[$key] ?? 0);
        }
        $response = $this->appRequest(fn ($api) => $api->put('/api/security/guard', $body));
        if ($this->succeeded($response)) {
            $this->fillGuard($response->json());
            $this->dispatch('notice', kind: 'success', text: 'Gespeichert.');
        }
    }

    /** Dry run with the saved rules; with $apply the matches are banned right away. */
    public function scan(bool $apply = false): void
    {
        $response = $this->appRequest(fn ($api) => $api->post('/api/security/guard/scan', ['apply' => $apply]));
        if (! $this->succeeded($response)) {
            return;
        }
        if ($apply) {
            $this->dispatch('notice', kind: 'success', text: Fmt::plural((int) $response->json('added'), 'Adresse', 'Adressen').' gesperrt.');
            $this->scanMatches = null;

            return;
        }
        $this->scanMatches = (array) $response->json('matches');
    }

    public function discardScan(): void
    {
        $this->scanMatches = null;
    }

    public function addBan(): void
    {
        $response = $this->appRequest(fn ($api) => $api->post('/api/security/bans', ['ip' => trim($this->banIp), 'hours' => (int) $this->banHours]));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: $response->json('ip').' gesperrt.');
            $this->banIp = '';
        }
    }

    public function removeBan(string $ip, bool $exempt = false): void
    {
        $response = $this->appRequest(fn ($api) => $api->delete('/api/security/bans?'.http_build_query(array_filter(['ip' => $ip, 'exempt' => $exempt ? '1' : null]))));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: $ip.' entsperrt.');
            if ($exempt) {
                $this->guard['exempt'] = trim(($this->guard['exempt'] ?? '')."\n".$ip);
            }
        }
    }

    public function saveHideServer(): void
    {
        $response = $this->appRequest(fn ($api) => $api->put('/api/security/hide-server', ['enabled' => $this->hideServer]));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: 'In Caddy übernommen.');
        } else {
            $this->hideServer = ! $this->hideServer;
        }
    }

    public function setBlocklist(string $id, bool $enabled): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->put('/api/blocklists/'.rawurlencode($id), ['enabled' => $enabled])))) {
            $this->dispatch('notice', kind: 'success', text: $enabled ? 'Liste eingeschaltet.' : 'Liste ausgeschaltet.');
        }
    }

    public function addBlocklist(): void
    {
        $response = $this->appRequest(fn ($api) => $api->post('/api/blocklists', ['name' => $this->blName, 'url' => $this->blUrl]));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: $response->json('name').' hinzugefügt.');
            $this->blName = $this->blUrl = '';
        }
    }

    public function deleteBlocklist(string $id): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->delete('/api/blocklists/'.rawurlencode($id))))) {
            $this->dispatch('notice', kind: 'success', text: 'Gelöscht.');
        }
    }

    public function render()
    {
        [$d, $error] = $this->load('/api/security');
        [$bl] = $this->load('/api/blocklists');
        [$stats] = $this->load('/api/stats', ['range' => '7d']);
        $d = is_array($d) ? $d : null;

        // Countries with traffic first, they are the likeliest candidates.
        $traffic = collect(is_array($stats) ? ($stats['countries'] ?? []) : [])->pluck('count', 'key')->all();
        $names = Fmt::countries();
        $codes = array_keys($names);
        usort($codes, fn ($a, $b) => [-($traffic[$a] ?? 0), $names[$a]] <=> [-($traffic[$b] ?? 0), $names[$b]]);

        $current = $this->mode === 'allow' ? $this->allowList : $this->blockList;
        $savedList = $this->mode === 'allow' ? $this->saved['allow'] : $this->saved['block'];
        $same = $this->mode === $this->saved['mode'] && count($savedList) === count($current) && ! array_diff($savedList, $current);
        $busy = $d && (! empty($d['queued']) || ! empty($d['running']));

        return view('modules.proxy.security', [
            'd' => $d,
            'bl' => is_array($bl) ? $bl : ['lists' => [], 'status' => null],
            'codes' => $codes,
            'names' => $names,
            'traffic' => $traffic,
            'current' => $current,
            'same' => $same,
            'busy' => $busy,
            'confirmText' => $this->confirmText($current, $traffic),
            'loadError' => $error ?? $this->loadError,
        ]);
    }

    /**
     * Text of the confirmation before a country rule is applied (as in the original).
     *
     * @param  list<string>  $next
     * @param  array<string, int>  $traffic
     */
    private function confirmText(array $next, array $traffic): string
    {
        $n = count($next);
        if ($this->mode === 'allow') {
            $text = 'Nur noch '.($n === 1 ? '1 Land erreicht' : "{$n} Länder erreichen").' deine Dienste von außen.';
            $text .= in_array('DE', $next, true)
                ? ' Im Urlaub oder über einen VPN-Dienst im Ausland kommst du dann nicht mehr an deine Dienste.'
                : ' Deutschland ist nicht dabei: Von unterwegs erreichst du deine Dienste dann nicht mehr über dein deutsches Mobilfunknetz.';
            $excluded = array_sum(array_filter($traffic, fn ($count, $code) => $code !== 'LAN' && ! in_array($code, $next, true), ARRAY_FILTER_USE_BOTH));
            if ($excluded) {
                $text .= ' In den letzten 7 Tagen kamen '.Fmt::num($excluded).' Zugriffe aus anderen Ländern.';
            }

            return $text;
        }
        $text = $n ? Fmt::plural($n, 'Land wird', 'Länder werden').' gesperrt.' : 'Die Sperre wird aufgehoben.';
        if (in_array('DE', $next, true)) {
            $text .= ' Deutschland ist dabei: Von unterwegs erreichst du deine Dienste dann nicht mehr über dein deutsches Mobilfunknetz.';
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $g
     */
    private function fillGuard(array $g): void
    {
        $this->guard = ['enabled' => (bool) $g['enabled'], 'notify' => (bool) $g['notify'], 'exempt' => implode("\n", $g['exempt'] ?? [])];
        foreach (array_keys(self::THRESHOLDS) as $key) {
            $this->guard[$key] = (string) ($g[$key] ?? 0);
        }
    }
}
