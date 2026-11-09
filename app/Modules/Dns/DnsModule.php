<?php

namespace App\Modules\Dns;

use App\Modules\Module;

/**
 * PK95 DNS (repo pk95dns): network-wide DNS filter with block lists, own rules, local
 * records, blocked services, clients and query log. Pages call the app's JSON API
 * (pk95dns docs/api.md).
 */
class DnsModule extends Module
{
    public function pages(): array
    {
        return [
            'overview' => Overview::class,
            'log' => QueryLog::class,
            'lists' => Lists::class,
            'rules' => Rules::class,
            'rewrites' => Rewrites::class,
            'services' => Services::class,
            'clients' => Clients::class,
            'settings' => Settings::class,
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
