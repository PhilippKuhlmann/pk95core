<?php

namespace App\Livewire\Automation;

use App\Models\AppLink;
use App\Services\Automation\Dns;
use App\Services\Automation\Linker;
use App\Support\Settings;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Automation settings (base domain, what is linked) and the list of deployments Core
 * linked with PK95 Proxy and PK95 Uptime.
 */
#[Title('Automatik')]
class Index extends Component
{
    public string $baseDomain = '';

    public bool $proxy = true;

    public bool $uptime = true;

    public string $publicIp = '';

    public ?string $detectedIp = null;

    public function mount(Dns $dns): void
    {
        $this->baseDomain = (string) Settings::get('automation_base_domain');
        $this->proxy = (bool) Settings::get('automation_proxy');
        $this->uptime = (bool) Settings::get('automation_uptime');
        $this->publicIp = (string) Settings::get('automation_public_ip');
        $this->detectedIp = $this->publicIp === '' ? $dns->publicIp() : null;
    }

    public function save(): void
    {
        $this->baseDomain = strtolower(trim($this->baseDomain, " .\t\n"));
        $this->publicIp = trim($this->publicIp);
        $this->validate([
            'baseDomain' => ['nullable', 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
            'publicIp' => ['nullable', 'ipv4'],
        ], [
            'baseDomain.regex' => 'Eine Domain wie example.de, ohne https:// und ohne Pfad.',
            'publicIp.ipv4' => 'Eine IPv4-Adresse wie 203.0.113.10.',
        ]);
        Settings::put('automation_base_domain', $this->baseDomain);
        Settings::put('automation_proxy', $this->proxy);
        Settings::put('automation_uptime', $this->uptime);
        Settings::put('automation_public_ip', $this->publicIp);
        $this->dispatch('notice', kind: 'success', text: 'Automatik gespeichert.');
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
