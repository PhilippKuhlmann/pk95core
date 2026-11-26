<?php

namespace App\Modules\File;

use App\Modules\Module;

/**
 * PK95 File (repo pk95file): Samba file server as a member of the PK95 AD domain. Shares with
 * permissions through AD groups (SIDs), recycle bin, previous versions (snapshots) and soft
 * quotas, managed through its API (docs/api.md of pk95file). Until the server has joined the
 * domain every share, session and usage route answers 409; the setup wizard, the dashboard and
 * the settings live on the page "server". Snapshots are a dialog of the shares page.
 */
class FileModule extends Module
{
    public function pages(): array
    {
        return [
            'server' => Server::class,
            'shares' => Shares::class,
            'sessions' => Sessions::class,
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
