<?php

namespace App\Modules\ProxApps;

use App\Modules\Module;

/**
 * ProxApps (~/Proxmox/proxapps): every page is ProxApps' own Livewire component, run in
 * the ProxApps container through /api/bridge and rendered here in Core's layout.
 */
class ProxAppsModule extends Module
{
    public function pages(): array
    {
        return [
            'dashboard' => Pages\Dashboard::class,
            'apps' => Pages\Apps::class,
            'deployments' => Pages\Deployments::class,
            'updates' => Pages\Updates::class,
            'connections' => Pages\Connections::class,
            'settings' => Pages\Settings::class,
        ];
    }

    public function subPages(): array
    {
        return [
            'deploy' => Pages\Deploy::class,
            'deployment' => Pages\Deployment::class,
            'connection-form' => Pages\ConnectionForm::class,
            'profile-form' => Pages\ProfileForm::class,
        ];
    }

    public function minVersion(): ?string
    {
        return '1.0.0';
    }

    public function maxVersion(): ?string
    {
        return '2.0.0';
    }
}
