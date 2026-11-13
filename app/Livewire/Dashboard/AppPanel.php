<?php

namespace App\Livewire\Dashboard;

use App\Models\InstalledApp;
use App\Services\Dashboard\AppFigures;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * One app on the dashboard: its figures of the last 24 h and a small chart. Lazy and in its
 * own request, so a slow or stopped app only keeps its own tile waiting.
 */
#[Lazy]
class AppPanel extends Component
{
    public string $appId;

    public function placeholder(array $params = [])
    {
        $app = InstalledApp::query()->firstWhere('app_id', $params['appId'] ?? $this->appId ?? '');

        return view('livewire.dashboard.placeholder', ['title' => $app?->displayName() ?? 'App']);
    }

    public function render(AppFigures $figures)
    {
        $app = InstalledApp::query()->firstWhere('app_id', $this->appId);

        return view('livewire.dashboard.app-panel', [
            'app' => $app,
            'f' => $app ? $figures->for($app) : ['ok' => false, 'error' => 'Die App ist nicht mehr installiert.'],
        ]);
    }
}
