<?php

namespace App\Livewire\Events;

use App\Models\AppEvent;
use App\Models\InstalledApp;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Event log: what the apps and Core reported, newest first.
 */
#[Title('Ereignisse')]
class Index extends Component
{
    #[Url]
    public string $app = '';

    #[Url]
    public string $severity = '';

    public int $limit = 100;

    public function more(): void
    {
        $this->limit += 100;
    }

    public function render()
    {
        $query = AppEvent::query()->orderByDesc('occurred_at')->orderByDesc('id');
        if ($this->app !== '') {
            $query->where('app_id', $this->app);
        }
        if ($this->severity !== '') {
            $query->whereIn('severity', array_keys(array_filter(AppEvent::SEVERITIES, fn (int $rank) => $rank >= AppEvent::rank($this->severity))));
        }
        $events = $query->limit($this->limit + 1)->get();

        $names = InstalledApp::query()->get()->mapWithKeys(fn (InstalledApp $a) => [$a->app_id => $a->displayName()])->all();
        $apps = [AppEvent::CORE => 'PK95 Core'] + $names + AppEvent::query()->distinct()->pluck('app_id')->mapWithKeys(fn ($id) => [$id => $id])->all();

        return view('livewire.events.index', [
            'events' => $events->take($this->limit),
            'hasMore' => $events->count() > $this->limit,
            'apps' => $apps,
        ]);
    }
}
