<?php

namespace App\Modules\ProxApps\Pages;

use App\Modules\ProxApps\Bridged;
use Livewire\Component;

class Apps extends Component
{
    use Bridged;

    public $search = '';

    protected function component(): string
    {
        return 'apps';
    }

    protected function viewName(): string
    {
        return 'modules.proxapps.apps';
    }
}
