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

    /** the section "Länderfilter (optional)" is open (checkboxes in the table); open by default when the filter is on */
    public bool $showFilter = false;

    /** only countries with blocked queries (PK95 DNS 1.3.0) */
    #[Url(as: 'geblockt', except: false)]
    public bool $onlyBlocked = false;

    /** PK95 DNS 1.3.0: resolve blocked domains in the background to know their country; null = the app has no such setting */
    public ?bool $resolveBlocked = null;

    /** @var array{mode: string, countries: list<string>} the filter as saved in the app (the switch sends it unchanged) */
    public array $saved = ['mode' => 'off', 'countries' => []];

    /** @var list<string> the list as last sent, to map "countries.N" back to the country */
    private array $sent = [];

    public function toggleFilter(): void
    {
        $this->showFilter = ! $this->showFilter;
    }

    public function resetSearch(): void
    {
        $this->q = '';
        $this->onlyBlocked = false;
    }

    /** the switch saves at once, with the filter as saved (unsaved changes of the filter stay unsent) */
    public function updatedResolveBlocked(bool $value): void
    {
        $response = $this->appRequest(fn ($api) => $api->put('/api/geo', $this->saved + ['resolve_blocked' => $value]));
        if ($this->succeeded($response, '')) {
            $this->resolveBlocked = (bool) ($response->json('resolve_blocked') ?? $value);
            $this->dispatch('notice', kind: 'success', text: $value ? 'PK95 DNS ermittelt jetzt die Länder geblockter Domains.' : 'Länder geblockter Domains werden nicht mehr ermittelt.');
        } else {
            $this->resolveBlocked = ! $value;
        }
    }

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
        $body = ['mode' => $this->mode, 'countries' => $this->sent] + ($this->resolveBlocked === null ? [] : ['resolve_blocked' => $this->resolveBlocked]);
        $response = $this->appRequest(fn ($api) => $api->put('/api/geo', $body));
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
        $this->saved = ['mode' => $this->mode, 'countries' => $this->selected];
        $this->resolveBlocked = array_key_exists('resolve_blocked', $geo) ? (bool) $geo['resolve_blocked'] : null;
        if (! $this->loaded) {
            $this->showFilter = $this->mode !== 'off';
        }
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
        $unknownBlocked = 0;
        $split = false;
        $countriesError = null;
        if (is_array($geo)) {
            [$list, $countriesError] = $this->load('/api/countries', ['hours' => $this->hours]);
            $unknown = (int) ($list['unknown'] ?? 0);
            $unknownBlocked = (int) ($list['unknown_blocked'] ?? 0);
            $countries = array_values((array) ($list['countries'] ?? []));
            $split = CountrySplit::hasSplit($countries);
            // chosen countries without traffic stay in the table, so they can be unchecked
            $known = array_map(fn ($c) => strtoupper((string) ($c['code'] ?? '')), $countries);
            foreach ($this->selected as $code) {
                if (! in_array($code, $known, true)) {
                    $countries[] = ['code' => $code, 'count' => 0, 'blocked' => 0, 'removed' => 0];
                }
            }
            // sorted by all queries (let through + blocked), the biggest first
            foreach (CountrySplit::rows($countries) as $row) {
                $rows[$row['code']] = $row;
            }
        }
        $needle = mb_strtolower(trim($this->q));
        $shown = array_values(array_filter($rows, fn ($r) => ($needle === ''
            || str_contains(mb_strtolower($r['code'].' '.CountryNames::name($r['code'])), $needle))
            && (! $this->onlyBlocked || $r['blocked'] > 0)));
        $add = array_diff_key(CountryNames::all(), $rows);
        asort($add);
        $filterUsed = $this->mode !== 'off' || collect($rows)->contains(fn ($r) => $r['filter_blocked'] > 0);

        return view('modules.dns.countries', [
            'geo' => $geo,
            'unsupported' => $unsupported,
            'loadError' => $geoError ?? $countriesError,
            'rows' => $shown,
            'total' => count($rows),
            'unknown' => $unknown,
            'unknownBlocked' => $unknownBlocked,
            'split' => $split,
            // the column "Länderfilter" only with the filter on or something it blocked
            'filterColumn' => $filterUsed,
            'max' => collect($rows)->max('total') ?: 0,
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
