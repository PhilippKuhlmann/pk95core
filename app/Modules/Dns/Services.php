<?php

namespace App\Modules\Dns;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * PK95 DNS: blocked services (built-in domain sets like TikTok or Steam) for the whole
 * network; clients can override them on the Clients page.
 */
class Services extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    /** @var list<string> */
    public array $blocked = [];

    /** @var list<string> as saved in the app */
    public array $saved = [];

    public ?string $loadError = null;

    /**
     * True once the saved selection was read. PUT /api/services replaces it, so a form that
     * started empty after a failed load must not save (it would unblock everything).
     */
    #[Locked]
    public bool $loaded = false;

    public function mount(): void
    {
        $this->retry();
    }

    /** Reads the saved selection again after a failed first load ("Erneut versuchen"). */
    public function retry(): void
    {
        if ($this->loaded) {
            return;
        }
        [$d, $this->loadError] = $this->load('/api/services');
        if (is_array($d)) {
            $this->blocked = $this->saved = array_values((array) ($d['blocked'] ?? []));
            $this->loaded = true;
        }
    }

    /** All services of a group on or off. */
    public function group(string $group, bool $on): void
    {
        if (! $this->loaded) {
            return;
        }
        [$d] = $this->load('/api/services');
        $ids = array_column(array_filter((array) ($d['services'] ?? []), fn ($s) => $s['group'] === $group), 'id');
        $this->blocked = array_values($on ? array_unique([...$this->blocked, ...$ids]) : array_diff($this->blocked, $ids));
    }

    public function save(): void
    {
        if (! $this->loaded) {
            return;
        }
        $response = $this->appRequest(fn ($api) => $api->put('/api/services', ['blocked' => array_values($this->blocked)]));
        if ($this->succeeded($response, '')) {
            $this->blocked = $this->saved = array_values((array) $response->json('blocked'));
            $this->dispatch('notice', kind: 'success', text: $this->blocked ? Fmt::plural(count($this->blocked), 'Dienst', 'Dienste').' blockiert.' : 'Keine Dienste blockiert.');
        }
    }

    public function render()
    {
        [$d, $error] = $this->load('/api/services');

        return view('modules.dns.services', [
            'groups' => $this->loaded ? self::grouped(array_values((array) ($d['services'] ?? []))) : [],
            'loadError' => $this->loaded ? $error : ($error ?? $this->loadError ?? 'Die blockierten Dienste konnten nicht geladen werden.'),
        ]);
    }

    /**
     * Services sorted by name and grouped in the order of Fmt::GROUPS.
     *
     * @param  list<array{id: string, name: string, group: string}>  $services
     * @return array<string, list<array{id: string, name: string, group: string}>>
     */
    public static function grouped(array $services): array
    {
        $groups = array_fill_keys(array_keys(Fmt::GROUPS), []);
        usort($services, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        foreach ($services as $s) {
            $groups[isset(Fmt::GROUPS[$s['group']]) ? $s['group'] : 'other'][] = $s;
        }

        return array_filter($groups);
    }
}
