<?php

namespace App\Livewire\Settings;

use App\Http\Support\Format;
use App\Models\InstalledApp;
use App\Services\Updates\AutoUpdates;
use App\Services\Updates\CoreUpdater;
use App\Services\Updates\UpdateAll;
use App\Support\Settings;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Einstellungen → Updates: the update policies of Core and of every app in one place, the
 * policy new apps start with, and what the next maintenance window installs (or why not).
 */
#[Title('Updates')]
class Updates extends Component
{
    public string $corePolicy = 'notify';

    public string $appPolicy = 'notify';

    /** "Für alle Apps setzen" */
    public string $allPolicy = '';

    /** @var array<string, string> app id → update policy, saved on change */
    public array $policyOf = [];

    public function mount(): void
    {
        $this->corePolicy = AutoUpdates::corePolicy();
        $this->appPolicy = AutoUpdates::appPolicy();
        $this->loadPolicies();
    }

    public function updatedCorePolicy(string $value): void
    {
        if (! array_key_exists($value, AutoUpdates::CORE_POLICIES)) {
            $this->corePolicy = AutoUpdates::corePolicy();

            return;
        }
        Settings::put(AutoUpdates::CORE_POLICY, $value);
        $this->dispatch('notice', kind: 'success', text: 'Core-Updates: '.AutoUpdates::CORE_POLICIES[$value].'.');
    }

    public function updatedAppPolicy(string $value): void
    {
        if (! array_key_exists($value, InstalledApp::UPDATE_POLICIES)) {
            $this->appPolicy = AutoUpdates::appPolicy();

            return;
        }
        Settings::put(AutoUpdates::APP_POLICY, $value);
        $this->dispatch('notice', kind: 'success', text: 'Neue Apps starten mit „'.InstalledApp::UPDATE_POLICIES[$value].'“.');
    }

    public function updatedPolicyOf(string $policy, string $appId): void
    {
        $app = InstalledApp::query()->where('app_id', $appId)->first();
        if ($app === null || ! array_key_exists($policy, InstalledApp::UPDATE_POLICIES)) {
            $this->loadPolicies();

            return;
        }
        $app->update(['update_policy' => $policy]);
        $this->dispatch('notice', kind: 'success', text: "{$app->displayName()}: ".InstalledApp::UPDATE_POLICIES[$policy].'.');
    }

    public function setAll(): void
    {
        $this->resetErrorBag('allPolicy');
        if (! array_key_exists($this->allPolicy, InstalledApp::UPDATE_POLICIES)) {
            $this->addError('allPolicy', 'Bitte eine Regel wählen.');

            return;
        }
        $count = InstalledApp::query()->update(['update_policy' => $this->allPolicy]);
        $this->dispatch('notice', kind: 'success', text: ($count === 1 ? '1 App' : "{$count} Apps").': '.InstalledApp::UPDATE_POLICIES[$this->allPolicy].'.');
        $this->allPolicy = '';
        $this->loadPolicies();
    }

    public function checkUpdates(): void
    {
        Artisan::call('pk95:check-updates');
        $this->dispatch('notice', kind: 'success', text: 'Nach Updates gesucht.');
        $this->dispatch('apps-changed');
    }

    /** "Jetzt alle Updates installieren": the same run as on the Apps page, followed there. */
    public function updateAll(UpdateAll $all): void
    {
        try {
            $all->start();
        } catch (Throwable $e) {
            $this->dispatch('notice', kind: 'error', text: $e->getMessage());

            return;
        }
        $this->redirectRoute('apps.index', navigate: true);
    }

    private function loadPolicies(): void
    {
        $this->policyOf = InstalledApp::query()->orderBy('app_id')->pluck('update_policy', 'app_id')->all();
    }

    public function render(AutoUpdates $auto, UpdateAll $all, CoreUpdater $core)
    {
        $status = $core->status();
        $plan = $auto->plan();
        $window = AutoUpdates::nextWindow();
        $today = now(Format::timezone())->startOfDay();
        $day = match ((int) $today->diffInDays($window['at']->copy()->startOfDay())) {
            0 => 'heute',
            1 => 'morgen',
            default => $window['at']->format('d.m.'),
        };

        return view('livewire.settings.updates', [
            'apps' => InstalledApp::query()->orderBy('app_id')->get(),
            'plan' => $plan,
            'reasons' => collect($plan['apps'])->mapWithKeys(fn ($row) => [$row['app']->app_id => $row]),
            'core' => $status,
            'window' => $window['due'] ? 'läuft jetzt' : $day.' '.$window['at']->format('H:i').' Uhr',
            'maintenanceTime' => (string) Settings::get('maintenance_time'),
            'policies' => InstalledApp::UPDATE_POLICIES,
            'corePolicies' => AutoUpdates::CORE_POLICIES,
            'run' => $all->running(),
            'updateCount' => $all->apps()->count() + (UpdateAll::coreReady($status) ? 1 : 0),
        ]);
    }
}
