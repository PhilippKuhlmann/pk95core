<?php

namespace App\Modules\ProxApps\Pages;

use App\Modules\Concerns\LoadsLazily;
use App\Modules\ProxApps\Bridged;
use Livewire\Component;

class Apps extends Component
{
    use Bridged;
    use LoadsLazily;

    public const PLACEHOLDER = [['header', 'App-Katalog', 1], ['cards', 6, 'h-44', 'md:grid-cols-2 xl:grid-cols-3']];

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
