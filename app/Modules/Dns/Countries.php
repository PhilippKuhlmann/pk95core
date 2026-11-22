<?php

namespace App\Modules\Dns;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use App\Services\Apps\AppException;
use App\Support\Countries as CountryNames;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 DNS → Länder (PK95 DNS 1.2.0): the country filter. "block" blocks the chosen countries,
 * "allow" lets only them through (private and unknown addresses always pass). The table lists
 * every country the network talked to (GET /api/countries) with a checkbox; checked = the list.
 * Older PK95 DNS versions answer 404 on /api/geo: the page says from which version on.
 */
class Countries extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    public const MODES = ['off' => 'Aus', 'block' => 'Ausgewählte Länder blockieren', 'allow' => 'Nur ausgewählte Länder erlauben'];

    public const MIN_VERSION = '1.2.0';

    public string $mode = 'off';

    /** @var list<string> the chosen countries (ISO codes) */
    public array $selected = [];

    /** the settings were read from /api/geo (not again on every render: they are being edited) */
    public bool $loaded = false;

    #[Url]
    public int $hours = 24;

    #[Url(as: 'q', except: '')]
    public string $q = '';

    /** "Land hinzufügen": a country without traffic */
    public string $addCode = '';

    /** @var list<string> the list as last sent, to map "countries.N" back to the country */
    private array $sent = [];

    public function mount(): void
    {
        $this->hours = isset(Overview::RANGES[$this->hours]) ? $this->hours : 24;
    }

    public function updatedHours(): void
    {
        $this->hours = isset(Overview::RANGES[$this->hours]) ? $this->hours : 24;
    }

    public function updatedMode(string $value): void
    {
        if (! isset(self::MODES[$value])) {
            $this->mode = 'off';
        }
    }

    public function updatedAddCode(string $code): void
    {
        $code = strtoupper(trim($code));
        if (isset(CountryNames::all()[$code]) && ! in_array($code, $this->selected, true)) {
            $this->selected[] = $code;
        }
        $this->addCode = '';
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $this->sent = array_values(array_unique(array_map('strtoupper', array_filter($this->selected, 'is_string'))));
        sort($this->sent);
        $response = $this->appRequest(fn ($api) => $api->put('/api/geo', ['mode' => $this->mode, 'countries' => $this->sent]));
        if ($this->succeeded($response, '')) {
            $this->apply((array) $response->json());
            $this->dispatch('notice', kind: 'success', text: 'Länderfilter gespeichert: '.self::modeLine($this->mode, count($this->selected)).'.');
        }
    }

    /** "countries.N" → the row of that country ("country.DE"); "mode", "countries" stay */
    protected function mapField(string $field): string
    {
        if (preg_match('/^countries\.(\d+)$/', $field, $m) && isset($this->sent[(int) $m[1]])) {
            return 'country.'.$this->sent[(int) $m[1]];
        }

        return $field;
    }

    /** @param array<string, mixed> $geo */
    private function apply(array $geo): void
    {
        $this->mode = isset(self::MODES[$geo['mode'] ?? '']) ? $geo['mode'] : 'off';
        $this->selected = array_values(array_map('strtoupper', array_filter((array) ($geo['countries'] ?? []), 'is_string')));
        $this->loaded = true;
    }

    /** "aus" / "blockiert 3 Länder" / "erlaubt nur 5 Länder" */
    public static function modeLine(string $mode, int $count): string
    {
        $n = $count === 1 ? '1 Land' : $count.' Länder';

        return match ($mode) {
            'block' => 'blockiert '.$n,
            'allow' => 'erlaubt nur '.$n,
            default => 'aus',
        };
    }

    /**
     * The badge of a country in the overview and the table: "blockiert" in the block list,
     * "nicht erlaubt" outside the list in allow mode, null otherwise.
     *
     * @param  array<string, mixed>|null  $geo
     */
    public static function badge(string $code, ?array $geo, ?array $selected = null): ?string
    {
        $list = array_map('strtoupper', $selected ?? (array) ($geo['countries'] ?? []));
        $listed = in_array(strtoupper($code), $list, true);

        return match ($geo['mode'] ?? 'off') {
            'block' => $listed ? 'blockiert' : null,
            'allow' => $listed || $code === '' ? null : 'nicht erlaubt',
            default => null,
        };
    }

    public function render()
    {
        [$geo, $geoError, $unsupported] = self::loadGeo($this->installedApp);
        if (is_array($geo) && ! $this->loaded) {
            $this->apply($geo);
        }
        $rows = [];
        $unknown = 0;
        $countriesError = null;
        if (is_array($geo)) {
            [$list, $countriesError] = $this->load('/api/countries', ['hours' => $this->hours]);
            $unknown = (int) ($list['unknown'] ?? 0);
            foreach ((array) ($list['countries'] ?? []) as $c) {
                $code = strtoupper((string) ($c['code'] ?? ''));
                if ($code !== '') {
                    $rows[$code] = ['code' => $code, 'count' => (int) ($c['count'] ?? 0), 'blocked' => (int) ($c['blocked'] ?? 0),
                        'removed' => (int) ($c['removed'] ?? 0), 'clients' => isset($c['clients']) ? (int) $c['clients'] : null];
                }
            }
            // chosen countries without traffic stay in the table, so they can be unchecked
            foreach ($this->selected as $code) {
                $rows[$code] ??= ['code' => $code, 'count' => 0, 'blocked' => 0, 'removed' => 0, 'clients' => null];
            }
        }
        $needle = mb_strtolower(trim($this->q));
        $shown = array_values(array_filter($rows, fn ($r) => $needle === ''
            || str_contains(mb_strtolower($r['code'].' '.CountryNames::name($r['code'])), $needle)));
        usort($shown, fn ($a, $b) => [$b['count'], CountryNames::name($a['code'])] <=> [$a['count'], CountryNames::name($b['code'])]);
        $add = array_diff_key(CountryNames::all(), $rows);
        asort($add);

        return view('modules.dns.countries', [
            'geo' => $geo,
            'unsupported' => $unsupported,
            'loadError' => $geoError ?? $countriesError,
            'rows' => $shown,
            'total' => count($rows),
            'unknown' => $unknown,
            'addOptions' => array_map(fn ($name, $code) => ['value' => $code, 'label' => CountryNames::flag($code).' '.$name], $add, array_keys($add)),
        ]);
    }

    /**
     * GET /api/geo: [answer|null, error, unsupported (404: PK95 DNS before 1.2.0)]
     *
     * @return array{0: array<string, mixed>|null, 1: ?string, 2: bool}
     */
    public static function loadGeo(InstalledApp $app): array
    {
        try {
            $response = $app->client()->get('/api/geo');
        } catch (AppException $e) {
            return [null, $e->getMessage(), false];
        }
        if ($response->status() === 404) {
            return [null, null, true];
        }
        if (! $response->successful()) {
            return [null, (string) ($response->json('error') ?? "Die App antwortet mit HTTP {$response->status()}."), false];
        }

        return [(array) $response->json(), null, false];
    }
}
