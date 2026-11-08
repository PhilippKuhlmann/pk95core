<?php

namespace App\Modules\ProxApps\Pages;

use App\Modules\ProxApps\Bridged;
use Livewire\Component;

class Dashboard extends Component
{
    use Bridged;

    protected function component(): string
    {
        return 'dashboard';
    }

    protected function viewName(): string
    {
        return 'modules.proxapps.dashboard';
    }
}
