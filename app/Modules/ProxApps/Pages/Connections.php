<?php

namespace App\Modules\ProxApps\Pages;

use App\Modules\ProxApps\Bridged;
use Livewire\Component;

class Connections extends Component
{
    use Bridged;

    protected function component(): string
    {
        return 'connections';
    }

    protected function viewName(): string
    {
        return 'modules.proxapps.connections';
    }

    public function test(int $id): void
    {
        $this->remote('test', ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->remote('delete', ['id' => $id]);
    }
}
