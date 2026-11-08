<?php

namespace App\Modules\ProxApps\Pages;

use App\Modules\ProxApps\Bridged;
use Livewire\Component;

/**
 * One Proxmox connection with version, nodes and load (nested in the start page).
 */
class ConnectionStatus extends Component
{
    use Bridged;

    public int $connectionId = 0;

    public mixed $connection = null;

    public array $info = [];

    protected function component(): string
    {
        return 'connection-status';
    }

    protected function viewName(): string
    {
        return 'modules.proxapps.connection-status';
    }

    protected function localProperties(): array
    {
        return ['connectionId'];
    }

    protected function mountArgs(): array
    {
        return ['connection' => $this->connectionId];
    }

    public function refresh(): void
    {
        $this->remote('refresh');
    }
}
