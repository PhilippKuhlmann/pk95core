<?php

namespace Tests\Fixtures;

use App\Models\InstalledApp;
use Livewire\Component;

/**
 * A module page as a real module would write it: data comes from the app's API.
 */
class DemoItemsPage extends Component
{
    public InstalledApp $installedApp;

    /** @var list<array{id: int, title: string}> */
    public array $items = [];

    public function mount(): void
    {
        $this->items = $this->installedApp->client()->get('/api/items')->json();
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                <h2>Einträge der Demo-App</h2>
                @foreach ($items as $item)
                    <div>{{ $item['title'] }}</div>
                @endforeach
            </div>
            BLADE;
    }
}
