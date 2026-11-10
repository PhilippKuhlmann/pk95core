<?php

namespace App\Services\Apps;

use App\Models\InstalledApp;
use App\Modules\Module;
use App\Services\Catalog\Catalog;
use App\Services\Updates\UpdateService;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Knows which apps are running, which Core module renders them and which menu entries
 * they contribute to the sidebar. The sidebar grows as soon as an app runs.
 */
class AppRegistry
{
    /** @var array<string, Module|null> */
    private array $modules = [];

    public function __construct(private readonly Catalog $catalog) {}

    /**
     * @return Collection<int, InstalledApp>
     */
    public function running(): Collection
    {
        return InstalledApp::query()
            ->whereIn('status', [InstalledApp::RUNNING, InstalledApp::UNHEALTHY])
            ->get()
            ->filter(fn (InstalledApp $app) => $this->catalog->find($app->app_id) !== null)
            ->sortBy(fn (InstalledApp $app) => mb_strtolower($app->displayName()))
            ->values();
    }

    public function module(InstalledApp $app): ?Module
    {
        $name = $this->catalog->find($app->app_id)?->module;
        if ($name === null) {
            return null;
        }
        if (! array_key_exists($name, $this->modules)) {
            $class = config('pk95.modules.'.$name);
            $this->modules[$name] = is_string($class) && class_exists($class) ? app($class) : null;
        }

        return $this->modules[$name];
    }

    /**
     * Why the app's own pages are not available, or null when they are.
     */
    public function unavailableReason(InstalledApp $app): ?string
    {
        $manifest = $app->manifest();
        if ($manifest === null) {
            return 'Die App hat noch kein Manifest geliefert.';
        }
        $module = $this->module($app);
        if ($module === null) {
            return 'Für diese App ist in dieser Core-Version noch kein Modul enthalten.';
        }
        if (! $module->supports($manifest)) {
            $version = ltrim($manifest->version, 'v');
            $min = $module->minVersion();
            $protocolOk = in_array($manifest->protocol, (array) config('pk95.protocols', [1]), true);
            if ($protocolOk && $min !== null && version_compare($version, $min, '<')) {
                $name = $app->displayName();

                return "Das Core-Modul braucht {$name} ab Version {$min}, installiert ist {$manifest->version}. {$name} aktualisieren.";
            }

            return "Das Core-Modul unterstützt Version {$manifest->version} (Protokoll {$manifest->protocol}) dieser App nicht. Core oder App aktualisieren.";
        }

        return null;
    }

    /**
     * Would this version of the app work with its Core module? Unknown (no module, a build
     * without version) counts as yes.
     */
    public function fits(InstalledApp $app, ?string $version): bool
    {
        $module = $this->module($app);
        if ($module === null || $version === null || ! UpdateService::isVersion($version)) {
            return true;
        }
        $v = ltrim($version, 'v');

        return ($module->minVersion() === null || version_compare($v, $module->minVersion(), '>='))
            && ($module->maxVersion() === null || version_compare($v, $module->maxVersion(), '<'));
    }

    /**
     * Does the installed or the offered version fit the Core module (minVersion/maxVersion)?
     * For the update badge: whether the update unlocks the pages, or why it does not fit.
     *
     * @return array{tone: 'info'|'warn', text: string}|null null = nothing to say
     */
    public function updateHint(InstalledApp $app): ?array
    {
        $module = $this->module($app);
        if ($module === null) {
            return null;
        }
        $name = $app->displayName();
        [$min, $max] = [$module->minVersion(), $module->maxVersion()];
        $installed = ltrim((string) $app->version, 'v');
        $available = $app->available_version !== null && UpdateService::isVersion($app->available_version) ? ltrim($app->available_version, 'v') : null;
        $tooOld = fn (string $v) => $min !== null && version_compare($v, $min, '<');
        $tooNew = fn (string $v) => $max !== null && version_compare($v, $max, '>=');

        if ($available !== null && $tooNew($available)) {
            return ['tone' => 'warn', 'text' => "Version {$available} braucht ein neueres PK95 Core. Aktualisiere zuerst PK95 Core, sonst bleiben die Seiten von {$name} gesperrt."];
        }
        if ($available !== null && $tooOld($available)) {
            return ['tone' => 'warn', 'text' => "Das Core-Modul braucht {$name} ab Version {$min}. Auch {$available} ist älter, eine passende Version ist noch nicht veröffentlicht."];
        }
        if ($installed !== '' && UpdateService::isVersion($installed) && $tooOld($installed)) {
            return match (true) {
                $available !== null => ['tone' => 'info', 'text' => "Die Seiten von {$name} sind gesperrt: Das Core-Modul braucht Version {$min} oder neuer. Das Update auf {$available} behebt das."],
                $app->available_version !== null => ['tone' => 'info', 'text' => "Die Seiten von {$name} sind gesperrt: Das Core-Modul braucht Version {$min} oder neuer. Ob dieser Build reicht, zeigt sich nach dem Aktualisieren."],
                default => ['tone' => 'warn', 'text' => "Die Seiten von {$name} sind gesperrt: Das Core-Modul braucht Version {$min} oder neuer, installiert ist {$installed}. Eine passende Version ist noch nicht veröffentlicht."],
            };
        }
        if ($installed !== '' && UpdateService::isVersion($installed) && $tooNew($installed)) {
            return ['tone' => 'warn', 'text' => "{$name} {$installed} ist neuer, als dieses PK95 Core kennt. Aktualisiere PK95 Core."];
        }

        return null;
    }

    /**
     * Page component for a menu key, null when the page does not exist or is locked.
     *
     * @return class-string<Component>|null
     */
    public function page(InstalledApp $app, string $key): ?string
    {
        if (! in_array($app->status, [InstalledApp::RUNNING, InstalledApp::UNHEALTHY], true)
            || $this->unavailableReason($app) !== null) {
            return null;
        }
        $module = $this->module($app);
        if (array_key_exists($key, $module->subPages())) {
            return $module->subPages()[$key];
        }
        $announced = collect($app->manifest()->menu)->contains('key', $key);

        return $announced ? ($module->pages()[$key] ?? null) : null;
    }

    /**
     * Sidebar groups: one per running app, each with the manifest pages the matching Core
     * module provides and Core's details page of the app.
     *
     * The current page is passed in (see currentPage()) because the menu is re-rendered by
     * Livewire update requests, where the request route is no longer the page route.
     *
     * @param  array{route: ?string, app: ?string, page: ?string}  $current
     * @return list<array{id: string, label: string, icon: string, items: list<array{label: string, icon: string, url: string, active: bool}>}>
     */
    public function menu(array $current = ['route' => null, 'app' => null, 'page' => null]): array
    {
        $currentRoute = $current['route'];
        $currentApp = $current['app'];
        $currentPage = $current['page'];

        $groups = [];
        foreach ($this->running() as $app) {
            $definition = $this->catalog->find($app->app_id);
            $items = [];
            foreach ($app->manifest()?->menu ?? [] as $entry) {
                if ($this->page($app, $entry['key']) === null) {
                    continue;
                }
                $items[] = [
                    'label' => $entry['label'],
                    'icon' => $entry['icon'],
                    'url' => route('apps.page', [$app, $entry['key']]),
                    'active' => $currentRoute === 'apps.page' && $currentApp === $app->app_id && $currentPage === $entry['key'],
                ];
            }
            // Core's own page of the app (container, log, backups, updates) last, so it does not
            // compete with an overview page of the app itself.
            $items[] = [
                'label' => 'App-Details',
                'icon' => 'info',
                'url' => route('apps.show', $app),
                'active' => $currentRoute === 'apps.show' && $currentApp === $app->app_id,
            ];
            $groups[] = [
                'id' => $app->app_id,
                'label' => $app->displayName(),
                'icon' => $app->manifest()?->icon ?? $definition?->icon ?? 'box',
                'items' => $items,
            ];
        }

        return $groups;
    }

    /**
     * Route name and app/page parameters of the page being rendered.
     *
     * @return array{route: ?string, app: ?string, page: ?string}
     */
    public static function currentPage(): array
    {
        $route = request()->route();
        $app = $route?->parameter('installedApp');

        return [
            'route' => $route?->getName(),
            'app' => $app instanceof InstalledApp ? $app->app_id : $app,
            'page' => $route?->parameter('page'),
        ];
    }
}
