<?php

namespace App\Modules\ProxApps\Pages;

use App\Modules\ProxApps\Bridged;
use Livewire\Component;

/**
 * ProxApps settings: VMID range and pattern, defaults, deployer key, network profiles.
 */
class Settings extends Component
{
    use Bridged;

    public $vmid_min = '';

    public $vmid_max = '';

    public $vmid_pattern = '';

    public $timezone = '';

    public $default_tags = '';

    public $apt_upgrade = true;

    public $destroy_on_failure = false;

    public $authorized_keys = '';

    protected function component(): string
    {
        return 'settings';
    }

    protected function viewName(): string
    {
        return 'modules.proxapps.settings';
    }

    public function save(): void
    {
        $this->remote('save');
    }

    public function deleteProfile(int $id): void
    {
        $this->remote('deleteProfile', ['id' => $id]);
    }

    public function regenerateKey(): void
    {
        $this->remote('regenerateKey');
    }
}
