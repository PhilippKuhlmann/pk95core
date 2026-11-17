<?php

namespace App\Modules\Ad;

use App\Modules\Ad\Concerns\AdPage;
use Livewire\Component;

/**
 * PK95 AD → Domäne: the setup wizard while the domain is not set up (POST /api/setup, then
 * polling GET /api/setup), afterwards overview, status, DNS forwarder, password policy,
 * database check and the conditional forwarding in PK95 DNS.
 */
class Domain extends Component
{
    use AdPage;

    /** @var array<string, string> */
    public array $setupForm = ['realm' => '', 'netbios_domain' => '', 'hostname' => '', 'host_ip' => '', 'dns_forwarder' => '127.0.0.1', 'admin_password' => ''];

    /** set while this page watched a provisioning: when it is ready the DNS forwarding follows */
    public bool $watching = false;

    public string $forwarder = '';

    /** @var array<string, mixed> */
    public array $policy = [];

    public const POLICY = [
        'min_length' => ['Mindestlänge', 'Zeichen, 0 bis 255'],
        'history_length' => ['Kennwortchronik', 'gemerkte Kennwörter, 0 bis 24'],
        'max_age_days' => ['Höchstalter', 'Tage, 0 = läuft nie ab'],
        'min_age_days' => ['Mindestalter', 'Tage'],
        'lockout_threshold' => ['Kontosperre nach', 'Fehlversuchen, 0 = nie sperren'],
        'lockout_duration_minutes' => ['Sperrdauer', 'Minuten, 0 = bis ein Admin entsperrt'],
        'lockout_window_minutes' => ['Zähler zurücksetzen nach', 'Minuten'],
    ];

    public function mount(): void
    {
        [$setup] = $this->fetch('/api/setup');
        if (is_array($setup) && ($setup['state'] ?? '') !== 'ready') {
            $defaults = (array) ($setup['defaults'] ?? []);
            $this->setupForm['hostname'] = (string) ($defaults['hostname'] ?? 'dc1');
            $this->setupForm['host_ip'] = (string) (((array) ($defaults['host_ips'] ?? []))[0] ?? '');
            $this->setupForm['dns_forwarder'] = (string) ($defaults['dns_forwarder'] ?? '127.0.0.1');
            $this->watching = ($setup['state'] ?? '') === 'provisioning';
        }
    }

    /** NetBIOS name suggested from the first label of the realm while it is empty. */
    public function updatedSetupFormRealm(): void
    {
        $this->setupForm['realm'] = strtolower(trim($this->setupForm['realm']));
        if ($this->setupForm['netbios_domain'] === '') {
            $this->setupForm['netbios_domain'] = strtoupper(substr(explode('.', $this->setupForm['realm'])[0], 0, 15));
        }
    }

    public function startSetup(): void
    {
        $body = $this->setupForm;
        $body['realm'] = strtolower(trim($body['realm']));
        $body['netbios_domain'] = strtoupper(trim($body['netbios_domain']));
        $body['hostname'] = strtolower(trim($body['hostname']));
        $response = $this->appRequest(fn ($api) => $api->post('/api/setup', $body));
        $this->setupForm['admin_password'] = '';
        if ($this->succeeded($response, 'setupForm.')) {
            $this->watching = true;
            $this->dispatch('notice', kind: 'success', text: 'Die Domäne wird eingerichtet. Das dauert etwa eine Minute.');
        }
    }

    public function saveForwarder(): void
    {
        $response = $this->appRequest(fn ($api) => $api->put('/api/domain/settings', ['dns_forwarder' => trim($this->forwarder)]));
        if ($this->succeeded($response, '')) {
            $this->dispatch('notice', kind: 'success', text: 'Gespeichert. Samba startet kurz neu.');
        }
    }

    public function savePolicy(): void
    {
        $body = [];
        foreach (array_keys(self::POLICY) as $key) {
            $body[$key] = (int) ($this->policy[$key] ?? 0);
        }
        $body['complexity'] = (bool) ($this->policy['complexity'] ?? false);
        $response = $this->appRequest(fn ($api) => $api->put('/api/domain/password-policy', $body));
        if ($this->succeeded($response, 'policy.')) {
            $this->policy = (array) $response->json();
            $this->dispatch('notice', kind: 'success', text: 'Kennwortrichtlinie gespeichert.');
        }
    }

    public function runDbcheck(bool $fix = false): void
    {
        $response = $this->appRequest(fn ($api) => $api->post('/api/domain/dbcheck', ['fix' => $fix]));
        if ($this->succeeded($response, '')) {
            $this->dispatch('notice', kind: 'success', text: $fix ? 'Datenbankprüfung mit Reparatur läuft.' : 'Datenbankprüfung läuft.');
        }
    }

    public function addDnsForward(): void
    {
        [$domain] = $this->fetch('/api/domain');
        if (! is_array($domain)) {
            return;
        }
        $state = DnsForward::ensure((string) $domain['realm'], (int) ($domain['dns_port'] ?? 5300));
        $this->dispatch('notice', ...($state['state'] === 'present'
            ? ['kind' => 'success', 'text' => 'In PK95 DNS eingetragen: '.$domain['realm'].' → '.$state['upstream']]
            : ['kind' => 'error', 'text' => $state['error'] ?? 'Die Weiterleitung ließ sich nicht anlegen.']));
    }

    public function render()
    {
        [$setup, $loadError] = $this->fetch('/api/setup');
        $state = is_array($setup) ? (string) ($setup['state'] ?? '') : '';
        $data = ['setup' => $setup, 'state' => $state, 'loadError' => $loadError, 'domain' => null, 'status' => null, 'dbcheck' => null, 'forward' => null];

        if ($state === 'ready') {
            [$data['domain']] = $this->fetch('/api/domain');
            [$data['status']] = $this->fetch('/api/status');
            [$data['dbcheck']] = $this->fetch('/api/domain/dbcheck');
            if ($this->policy === []) {
                [$policy] = $this->fetch('/api/domain/password-policy');
                $this->policy = is_array($policy) ? $policy : [];
            }
            if (is_array($data['domain'])) {
                $this->forwarder = $this->forwarder !== '' ? $this->forwarder : (string) ($data['domain']['dns_forwarder'] ?? '');
                $realm = (string) $data['domain']['realm'];
                $port = (int) ($data['domain']['dns_port'] ?? 5300);
                // right after the wizard: PK95 DNS forwards the realm to Samba (idempotent)
                $data['forward'] = $this->watching ? DnsForward::ensure($realm, $port) : DnsForward::state($realm, $port);
                $this->watching = false;
            }
        }

        return view('modules.ad.domain', $data);
    }
}
