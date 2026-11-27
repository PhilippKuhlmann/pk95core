<?php

namespace App\Modules\ProxApps\Pages;

use App\Modules\Concerns\LoadsLazily;
use App\Modules\ProxApps\Bridged;
use Livewire\Component;

class Deployments extends Component
{
    use Bridged;
    use LoadsLazily;

    public const PLACEHOLDER = [['header', 'Deployments', 1, 'sub' => 1], ['cards', 4, 'h-36', 'md:grid-cols-2 2xl:grid-cols-3']];

    public $status = '';

    public int $page = 1;

    protected function component(): string
    {
        return 'deployments';
    }

    protected function viewName(): string
    {
        return 'modules.proxapps.deployments';
    }

    protected function localProperties(): array
    {
        return ['page'];
    }

    protected function query(): array
    {
        return ['page' => $this->page];
    }

    public function updatedStatus(): void
    {
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
        $this->bridge(['state' => $this->bridgeState(), 'query' => $this->query()]);
    }
}
