<?php

namespace App\Jobs;

use App\Models\AppEvent;
use App\Models\InstalledApp;
use App\Modules\Ad\DnsForward;
use App\Services\Apps\AppException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reacts to ad.setup.done of PK95 AD: PK95 DNS gets the conditional forwarding of the realm
 * to Samba's DNS ({domain: <realm>, upstream: "127.0.0.1:<dns_port>"}), with the same logic
 * as the domain page (DnsForward::ensure: added once, an existing rule for the realm stays).
 * Without PK95 DNS nothing happens; the domain page shows the needed entry. When PK95 DNS
 * refuses, a warning event tells the user, the button on the domain page is the fallback.
 */
class EnsureAdDnsForward implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $data  data of ad.setup.done (realm, netbios_domain, dc)
     */
    public function __construct(public string $appId, public array $data = []) {}

    public function handle(): void
    {
        [$realm, $port] = $this->target();
        if ($realm === '') {
            return;
        }
        $state = DnsForward::ensure($realm, $port);
        if ($state['state'] === 'error') {
            AppEvent::record($this->appId, 'core.dns_forward.failed', 'warning', 'DNS-Weiterleitung für '.$realm.' nicht angelegt',
                'PK95 Core konnte in PK95 DNS die Weiterleitung '.$realm.' → '.$state['upstream'].' nicht eintragen: '.$state['error']
                .' Auf der Seite Domäne von PK95 AD lässt sie sich mit „Eintragen“ nachholen.',
                ['realm' => $realm, 'upstream' => $state['upstream']]);
        }
    }

    /**
     * Realm and DNS port from GET /api/domain; when the app does not answer, the realm of the
     * event and the default port (dns_port is stable, 5300).
     *
     * @return array{0: string, 1: int}
     */
    private function target(): array
    {
        $realm = strtolower(trim((string) ($this->data['realm'] ?? '')));
        $port = 5300;
        $app = InstalledApp::query()->where('app_id', $this->appId)->first();
        try {
            $response = $app?->client()->get('/api/domain');
            if ($response?->successful()) {
                $realm = strtolower(trim((string) ($response->json('realm') ?? $realm)));
                $port = (int) ($response->json('dns_port') ?? $port);
            }
        } catch (AppException) {
            // keep the event's realm
        }

        return [$realm, $port];
    }
}
