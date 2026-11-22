<?php

namespace App\Livewire\Dashboard;

use App\Models\InstalledApp;
use App\Services\Dashboard\AppFigures;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * "Wohin gehen deine Daten?" on the dashboard: the countries PK95 DNS answered with in the last
 * 24 h, split into let through and blocked (PK95 DNS 1.3.0). Lazy, from the cached figures of
 * the DNS tile (AppFigures), so it asks PK95 DNS nothing extra.
 */
#[Lazy]
class CountriesCard extends Component
{
    public const LIMIT = 10;

    public function placeholder()
    {
        return view('livewire.dashboard.placeholder', ['title' => 'Wohin gehen deine Daten?']);
    }

    public function render(AppFigures $figures)
    {
        $app = InstalledApp::query()->firstWhere('app_id', 'dns');
        $f = $app !== null ? $figures->for($app) : null;

        return view('livewire.dashboard.countries-card', [
            'app' => $app,
            'countries' => ($f['ok'] ?? false) ? ($f['countries'] ?? null) : null,
        ]);
    }
}
