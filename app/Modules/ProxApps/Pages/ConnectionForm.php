<?php

namespace App\Modules\ProxApps\Pages;

use App\Modules\Concerns\LoadsLazily;
use App\Modules\ProxApps\Bridged;
use Livewire\Component;

/**
 * Create or edit a Proxmox connection (?id= for editing), incl. fetching the TLS fingerprint.
 */
class ConnectionForm extends Component
{
    use Bridged;
    use LoadsLazily;

    public const PLACEHOLDER = [['root', 'mx-auto max-w-3xl'], ['form', 8]];

    /** read from the page URL in the first request (LoadsLazily) */
    public const QUERY = ['id'];

    public $connectionId = null;

    public $name = '';

    public $url = '';

    public $token_id = '';

    public $token_secret = '';

    public $tls_mode = 'pin';

    public $pool = '';

    public $fingerprint = '';

    public $fetched = '';

    public $fetchError = null;

    public $hasSecret = false;

    protected function component(): string
    {
        return 'connection-form';
    }

    protected function viewName(): string
    {
        return 'modules.proxapps.connection-form';
    }

    protected function mountArgs(): array
    {
        $id = $this->fromUrl('id');

        return ['connection' => is_numeric($id) ? (int) $id : null];
    }

    public function fetchFingerprint(): void
    {
        $this->remote('fetchFingerprint');
    }

    public function accept(): void
    {
        $this->remote('accept');
    }

    public function save(): void
    {
        $this->remote('save');
    }
}
