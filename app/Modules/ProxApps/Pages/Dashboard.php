<?php

namespace App\Modules\ProxApps\Pages;

use App\Modules\Concerns\LoadsLazily;
use App\Modules\ProxApps\Bridged;
use Livewire\Component;

class Dashboard extends Component
{
    use Bridged;
    use LoadsLazily;

    public const PLACEHOLDER = [['root', 'p-3 sm:p-5'], ['header', 'PK95 ProxApps', 1], ['tiles', 5], ['cards', 2, 'h-48'], ['cards', 4, 'h-28']];

    protected function component(): string
    {
        return 'dashboard';
    }

    protected function viewName(): string
    {
        return 'modules.proxapps.dashboard';
    }
}
