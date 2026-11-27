<?php

namespace App\Modules\ProxApps\Pages;

use App\Modules\Concerns\LoadsLazily;
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
    use LoadsLazily;

    public const PLACEHOLDER = [['header', null, 1, true, 'sub' => 1], ['form', 10]];

    /** read from the page URL in the first request (LoadsLazily) */
    public const QUERY = ['app', 'from'];

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
        return ['app' => (string) $this->fromUrl('app')];
    }

    protected function query(): array
    {
        // only the first call clones (the page URL keeps ?from= while the form is edited)
        $from = $this->mounting ? $this->fromUrl('from') : null;

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
