<?php

namespace App\Modules\ProxApps\Pages;

use App\Modules\ProxApps\Bridged;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Live state of a deployment's container (nested in the deployment page).
 */
class ContainerStatus extends Component
{
    use Bridged;

    public int $deploymentId = 0;

    public $deployment = null;

    public $st = null;

    protected function component(): string
    {
        return 'container-status';
    }

    protected function viewName(): string
    {
        return 'modules.proxapps.container-status';
    }

    protected function localProperties(): array
    {
        return ['deploymentId'];
    }

    protected function mountArgs(): array
    {
        return ['deployment' => $this->deploymentId];
    }

    #[On('ct-status-refresh')]
    public function load(): void
    {
        $this->remote('load');
    }
}
