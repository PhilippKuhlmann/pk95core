<?php

namespace App\Modules\ProxApps\Pages;

use App\Modules\ProxApps\Bridged;
use Livewire\Component;

/**
 * Central updates of the deployed containers (Debian packages + app versions).
 */
class Updates extends Component
{
    use Bridged;

    public $auto_update_enabled = false;

    public $auto_update_time = '03:00';

    public $snapshot_before_update = true;

    public $auto_rollback = true;

    public $keep_snapshots = '3';

    public $pollUntil = 0;

    protected function component(): string
    {
        return 'updates';
    }

    protected function viewName(): string
    {
        return 'modules.proxapps.updates';
    }

    public function checkAll(): void
    {
        $this->remote('checkAll');
    }

    public function checkOne(int $id): void
    {
        $this->remote('checkOne', ['id' => $id]);
    }

    public function update(int $id): void
    {
        $this->remote('update', ['id' => $id]);
    }

    public function updateAll(): void
    {
        $this->remote('updateAll');
    }

    public function saveSettings(): void
    {
        $this->remote('saveSettings');
    }
}
