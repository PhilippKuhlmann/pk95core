<?php

namespace App\Modules\ProxApps\Pages;

use App\Modules\Concerns\LoadsLazily;
use App\Modules\ProxApps\Bridged;
use Livewire\Component;

/**
 * One deployment (?id=): steps, live log, container actions, secrets, updates.
 */
class Deployment extends Component
{
    use Bridged;
    use LoadsLazily;

    public const PLACEHOLDER = [['header', null, 2, true, 'sub' => 1], ['panels', 2, 'h-96', 'xl:grid-cols-[minmax(0,1fr)_24rem]']];

    /** read from the page URL in the first request (LoadsLazily) */
    public const QUERY = ['id'];

    public $deployment = null;

    public $lastLogId = 0;

    public $showSecrets = false;

    protected function component(): string
    {
        return 'deployment';
    }

    protected function viewName(): string
    {
        return 'modules.proxapps.deployment';
    }

    protected function mountArgs(): array
    {
        return ['deployment' => (int) $this->fromUrl('id')];
    }

    public function refreshLive(): void
    {
        $this->remote('refreshLive');
    }

    public function cancel(): void
    {
        $this->remote('cancel');
    }

    public function action(string $action): void
    {
        $this->remote('action', ['action' => $action]);
    }

    public function destroyContainer(): void
    {
        $this->remote('destroyContainer');
    }

    public function deleteEntry(): void
    {
        $this->remote('deleteEntry');
    }

    public function toggleReveal(): void
    {
        $this->remote('toggleReveal');
    }

    public function checkUpdates(): void
    {
        $this->remote('checkUpdates');
    }

    public function startUpdate(): void
    {
        $this->remote('startUpdate');
    }

    public function rollback(int $runId): void
    {
        $this->remote('rollback', ['runId' => $runId]);
    }
}
