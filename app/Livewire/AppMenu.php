<?php

namespace App\Livewire;

use App\Services\Apps\AppRegistry;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The app part of the sidebar. A Livewire component of its own so that it follows
 * start/stop/activate right away: those actions dispatch "apps-changed".
 */
class AppMenu extends Component
{
    /** @var array{route: ?string, app: ?string, page: ?string} */
    public array $current = ['route' => null, 'app' => null, 'page' => null];

    public function mount(): void
    {
        $this->current = AppRegistry::currentPage();
    }

    #[On('apps-changed')]
    public function refreshMenu(): void
    {
        // re-render only
    }

    public function render(AppRegistry $registry)
    {
        return view('livewire.app-menu', ['groups' => $registry->menu($this->current)]);
    }
}
