<?php

namespace App\Modules\Uptime;

use App\Modules\Module;

/**
 * PK95 Uptime (~/code/Uptime): monitors and status pages, rendered in Core.
 */
class UptimeModule extends Module
{
    public function pages(): array
    {
        return [
            'monitors' => Monitors::class,
            'status-pages' => StatusPages::class,
        ];
    }

    public function minVersion(): ?string
    {
        return '0.1.0';
    }

    public function maxVersion(): ?string
    {
        return '2.0.0';
    }
}
