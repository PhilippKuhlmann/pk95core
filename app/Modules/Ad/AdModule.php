<?php

namespace App\Modules\Ad;

use App\Modules\Module;

/**
 * PK95 AD (repo pk95ad): Samba Active Directory domain controller. Users, groups, computers,
 * OUs, Group Policy, the DNS of the AD zone and NETLOGON scripts, managed through its API
 * (docs/api.md of pk95ad). Until the domain is set up every directory route answers 409;
 * the setup wizard lives on the page "domain".
 */
class AdModule extends Module
{
    public function pages(): array
    {
        return [
            'domain' => Domain::class,
            'users' => Users::class,
            'groups' => Groups::class,
            'computers' => Computers::class,
            'ous' => Ous::class,
            'gpo' => Gpos::class,
            'dns' => DnsRecords::class,
            'scripts' => Scripts::class,
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
