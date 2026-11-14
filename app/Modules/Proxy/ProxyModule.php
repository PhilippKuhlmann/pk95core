<?php

namespace App\Modules\Proxy;

use App\Modules\Module;

/**
 * PK95 Proxy (repo proxypanel): Caddy reverse proxy with hosts, streams, certificates,
 * access lists, security and statistics. Pages call the panel's JSON API (/api/…).
 */
class ProxyModule extends Module
{
    public function pages(): array
    {
        return [
            'overview' => Overview::class,
            'log' => AccessLog::class,
            'hosts' => Hosts::class,
            'views' => Views::class,
            'health' => Health::class,
            'streams' => Streams::class,
            'access-lists' => AccessLists::class,
            'certificates' => Certificates::class,
            'security' => Security::class,
            'error-pages' => ErrorPages::class,
            'updates' => Maintenance::class,
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
