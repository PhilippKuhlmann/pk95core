<?php

namespace App\Modules\Dns;

use App\Modules\Module;

/**
 * PK95 DNS (repo pk95dns): network-wide DNS filter and DNS server with block lists, own rules,
 * own zones and records, blocked services, clients and query log. Pages call the app's JSON API
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
            'records' => Records::class,
            'services' => Services::class,
            'clients' => Clients::class,
            'settings' => Settings::class,
        ];
    }

    public function minVersion(): ?string
    {
        // 1.1.0 brought zones and records (/api/zones, /api/records)
        return '1.1.0';
    }

    public function maxVersion(): ?string
    {
        return '2.0.0';
    }
}
