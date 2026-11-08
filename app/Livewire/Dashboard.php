<?php

namespace App\Livewire;

use App\Models\AppEvent;
use App\Models\InstalledApp;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\CatalogException;
use App\Services\Docker\DockerClient;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render(Catalog $catalog, DockerClient $docker)
    {
        try {
            $available = count($catalog->all());
        } catch (CatalogException) {
            $available = 0;
        }
        $apps = InstalledApp::query()->get()->sortBy(fn (InstalledApp $a) => mb_strtolower($a->displayName()))->values();

        return view('livewire.dashboard', [
            'apps' => $apps,
            'available' => $available,
            'running' => $apps->where('status', InstalledApp::RUNNING)->count(),
            'problems' => $apps->whereIn('status', [InstalledApp::FAILED, InstalledApp::UNHEALTHY])->count(),
            'dockerOk' => $docker->available(),
            'events' => AppEvent::query()->orderByDesc('occurred_at')->orderByDesc('id')->limit(8)->get(),
        ]);
    }
}
