<?php

namespace App\Livewire\Automation;

use App\Models\AppLink;
use App\Services\Automation\Dns;
use App\Services\Automation\LanIp;
use App\Services\Automation\Linker;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Automation settings (base domain, what is linked, public and LAN IP) and the list of
 * deployments Core linked with PK95 Proxy, PK95 DNS and PK95 Uptime.
 */
#[Title('Automatik')]
class Index extends Component
{
    public string $baseDomain = '';

    public bool $proxy = true;

    public bool $dns = true;

    public bool $uptime = true;

    public string $publicIp = '';

    public ?string $detectedIp = null;

    public string $lanIp = '';

    /** @var array{ip: ?string, at: ?string, error: ?string}|null last detection of the LAN IP; null = not yet */
    public ?array $detected = null;

    public function mount(Dns $dns): void
    {
        $this->baseDomain = (string) Settings::get('automation_base_domain');
        $this->proxy = (bool) Settings::get('automation_proxy');
        $this->dns = (bool) Settings::get('automation_dns');
        $this->uptime = (bool) Settings::get('automation_uptime');
        $this->publicIp = (string) Settings::get('automation_public_ip');
        $this->detectedIp = $this->publicIp === '' ? $dns->publicIp() : null;
        $this->lanIp = (string) Settings::get('automation_lan_ip');
        // Only the cached result here: a new detection starts a container (wire:init, button).
        $cached = Cache::get(LanIp::CACHE);
        $this->detected = is_array($cached) ? $cached : null;
    }

    public function save(): void
    {
        $this->baseDomain = strtolower(trim($this->baseDomain, " .\t\n"));
        $this->publicIp = trim($this->publicIp);
        $this->lanIp = trim($this->lanIp);
        $this->validate([
            'baseDomain' => ['nullable', 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
            'publicIp' => ['nullable', 'ipv4'],
            'lanIp' => ['nullable', 'ipv4'],
        ], [
            'baseDomain.regex' => 'Eine Domain wie example.de, ohne https:// und ohne Pfad.',
            'publicIp.ipv4' => 'Eine IPv4-Adresse wie 203.0.113.10.',
            'lanIp.ipv4' => 'Eine IPv4-Adresse aus deinem Netz wie 192.168.1.20.',
        ]);
        Settings::put('automation_base_domain', $this->baseDomain);
        Settings::put('automation_proxy', $this->proxy);
        Settings::put('automation_dns', $this->dns);
        Settings::put('automation_uptime', $this->uptime);
        Settings::put('automation_public_ip', $this->publicIp);
        Settings::put('automation_lan_ip', $this->lanIp);
        $this->dispatch('notice', kind: 'success', text: 'Automatik gespeichert.');
    }

    /** First visit: detect the LAN IP once (cached afterwards). */
    public function loadLanIp(LanIp $lanIp): void
    {
        $this->detected = $lanIp->detected();
    }

    /** Button: detect the LAN IP of the proxy again. */
    public function detectLanIp(LanIp $lanIp): void
    {
        $this->detected = $lanIp->detected(fresh: true);
        $this->dispatch('notice', kind: $this->detected['ip'] ? 'success' : 'error',
            text: $this->detected['ip'] ? 'Erkannt: '.$this->detected['ip'].'.' : 'LAN-IP nicht erkannt: '.$this->detected['error']);
    }

    /** Catch up the local DNS records of all deployed apps. */
    public function syncDns(Linker $linker): void
    {
        $result = $linker->syncDns();
        $text = match (true) {
            $result['open'] !== [] => ($result['changed'] ? $result['changed'].' angelegt oder angepasst. ' : '').'Noch offen: '.implode(', ', $result['open']).'. Den Grund siehst du in der Liste.',
            $result['changed'] > 0 => $result['changed'].($result['changed'] === 1 ? ' DNS-Eintrag' : ' DNS-Einträge').' angelegt oder angepasst.',
            default => 'Alle DNS-Einträge sind aktuell.',
        };
        $this->dispatch('notice', kind: $result['open'] ? 'error' : 'success', text: $text);
    }

    public function recheck(int $id, Linker $linker): void
    {
        $link = AppLink::query()->findOrFail($id);
        $linker->recheck($link);
        $link->refresh();
        $this->dispatch('notice', kind: $link->state === AppLink::ACTIVE ? 'success' : 'error',
            text: $link->state === AppLink::ACTIVE ? "{$link->domain} ist jetzt aktiv." : ($link->message ?? 'DNS passt noch nicht.'));
    }

    public function forget(int $id): void
    {
        AppLink::query()->whereKey($id)->where('state', AppLink::REMOVED)->delete();
    }

    public function render()
    {
        return view('livewire.automation.index', [
            'links' => AppLink::query()->orderByRaw("state = 'removed'")->latest()->limit(100)->get(),
        ]);
    }
}
