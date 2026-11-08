<?php

namespace App\Modules\ProxApps\Pages;

use App\Modules\ProxApps\Bridged;
use Livewire\Component;

/**
 * Create or edit a network profile (?id= for editing).
 */
class ProfileForm extends Component
{
    use Bridged;

    public $profileId = null;

    public $name = '';

    public $bridge = 'vmbr0';

    public $vlan = '';

    public $cidr = '';

    public $gateway = '';

    public $dns = '';

    public $searchdomain = '';

    public $pool_start = '';

    public $pool_end = '';

    public $vmid_pattern = '';

    public $firewall = false;

    public $is_default = false;

    protected function component(): string
    {
        return 'profile-form';
    }

    protected function viewName(): string
    {
        return 'modules.proxapps.profile-form';
    }

    protected function mountArgs(): array
    {
        $id = request()->query('id');

        return ['profile' => is_numeric($id) ? (int) $id : null];
    }

    public function save(): void
    {
        $this->remote('save');
    }
}
