<?php

namespace App\Livewire\Settings;

use App\Support\Settings;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Global settings: maintenance window, backup retention and the group of exported certificates.
 */
#[Title('Einstellungen')]
class Index extends Component
{
    public string $maintenanceTime = '';

    public int|string $backupKeep = 7;

    /** group of PK95 Proxy's exported certificates on the LXC (pk95-certs) */
    public int|string $proxyCertsGid = 2001;

    public function mount(): void
    {
        $this->maintenanceTime = (string) Settings::get('maintenance_time');
        $this->backupKeep = (int) Settings::get('backup_keep');
        $this->proxyCertsGid = (int) Settings::get('proxy_certs_gid');
    }

    public function save(): void
    {
        $this->validate([
            'maintenanceTime' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'backupKeep' => ['required', 'integer', 'between:1,100'],
            'proxyCertsGid' => config('pk95.setting_rules.proxy_certs_gid'),
        ], ['maintenanceTime.regex' => 'Bitte als HH:MM angeben, z. B. 03:30', 'proxyCertsGid.between' => 'Eine Gruppen-ID von 1000 bis 65534.'],
            ['maintenanceTime' => 'Wartungszeit', 'backupKeep' => 'Anzahl', 'proxyCertsGid' => 'Gruppen-ID']);

        Settings::put('maintenance_time', $this->maintenanceTime);
        Settings::put('backup_keep', (int) $this->backupKeep);
        Settings::put('proxy_certs_gid', (int) $this->proxyCertsGid);
        $this->dispatch('notice', kind: 'success', text: 'Einstellungen gespeichert.');
    }

    public function checkUpdates(): void
    {
        Artisan::call('pk95:check-updates');
        $this->dispatch('notice', kind: 'success', text: 'Nach Updates gesucht. Ergebnisse stehen bei den Apps und unter „Ereignisse“.');
        $this->dispatch('apps-changed');
    }

    public function render()
    {
        return view('livewire.settings.index', [
            'lastRun' => Settings::get('maintenance_last_run'),
            'dataDir' => config('pk95.data_dir'),
        ]);
    }
}
