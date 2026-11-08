<?php

namespace App\Modules\ProxApps\Pages;

use App\Modules\ProxApps\Bridged;
use Livewire\Component;

/**
 * The deploy form of an app (?app=, optionally ?from= to clone a deployment): connection,
 * node, storages, network profile, IP/VMID suggestions, live checks - all of it run by
 * ProxApps' own deploy component.
 */
class Deploy extends Component
{
    use Bridged;

    public $appId = '';

    public $v = [];

    public $nodes = [];

    public $options = ['rootdir' => [], 'vztmpl' => [], 'bridges' => [], 'templates' => []];

    public $apiError = null;

    public $checks = ['vmid' => null, 'ip' => null];

    public $showAdvanced = false;

    public $clonedFrom = null;

    protected function component(): string
    {
        return 'deploy';
    }

    protected function viewName(): string
    {
        return 'modules.proxapps.deploy';
    }

    protected function mountArgs(): array
    {
        return ['app' => (string) request()->query('app')];
    }

    protected function query(): array
    {
        $from = request()->query('from');

        return is_numeric($from) ? ['from' => (int) $from] : [];
    }

    public function loadNodes(bool $keep = false): void
    {
        $this->remote('loadNodes', ['keep' => $keep]);
    }

    public function loadOptions(bool $keep = false): void
    {
        $this->remote('loadOptions', ['keep' => $keep]);
    }

    public function suggest(): void
    {
        $this->remote('suggest');
    }

    public function suggestIp(): void
    {
        $this->remote('suggestIp');
    }

    public function suggestVmid(): void
    {
        $this->remote('suggestVmid');
    }

    public function check(): void
    {
        $this->remote('check');
    }

    public function resetResources(): void
    {
        $this->remote('resetResources');
    }

    public function setNetMode(string $mode): void
    {
        $this->remote('setNetMode', ['mode' => $mode]);
    }

    public function submit(): void
    {
        $this->remote('submit');
    }
}
