<?php

namespace Tests\Feature;

use App\Livewire\AppMenu;
use App\Livewire\Apps\Index;
use App\Livewire\Apps\Show;
use App\Models\InstalledApp;
use App\Services\Apps\AppManager;
use Livewire\Livewire;

class AppPagesTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    private function runningDemo(): InstalledApp
    {
        return app(AppManager::class)->activate('demo')->refresh();
    }

    public function test_dashboard_without_apps_shows_onboarding(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Noch keine App aktiviert')
            ->assertDontSee('Docker ist nicht erreichbar');
    }

    public function test_dashboard_warns_when_docker_is_unreachable(): void
    {
        $this->docker['reachable'] = false;

        $this->get('/')->assertOk()->assertSee('Docker ist nicht erreichbar');
    }

    public function test_apps_page_lists_catalog_and_activates(): void
    {
        $this->get('/apps')->assertOk()->assertSee('Demo-App')->assertSee('example/demo:1.0.0')->assertSee('Aktivieren');

        Livewire::test(Index::class)
            ->call('activate', 'demo')
            ->assertDispatched('notice', kind: 'success')
            ->assertSee('Läuft')
            ->assertSee('Version 1.2.0');

        $this->assertSame(InstalledApp::RUNNING, InstalledApp::query()->first()->status);
    }

    public function test_apps_page_reports_errors_as_toast(): void
    {
        Livewire::test(Index::class)
            ->call('activate', 'does-not-exist')
            ->assertDispatched('notice', kind: 'error');
    }

    public function test_sidebar_grows_when_an_app_runs_and_shrinks_when_it_stops(): void
    {
        $this->get('/')->assertOk()->assertDontSee('data-app-menu="demo"', false);

        $app = $this->runningDemo();

        $this->get('/')->assertOk()
            ->assertSee('data-app-menu="demo"', false)
            ->assertSee(route('apps.show', $app), false)
            ->assertSee(route('apps.page', [$app, 'items']), false)
            ->assertSee('Einträge')
            // announced in the manifest, but the module has no page for it
            ->assertDontSee(route('apps.page', [$app, 'unknown']), false);

        app(AppManager::class)->stop($app);

        $this->get('/')->assertOk()->assertDontSee('data-app-menu="demo"', false);
    }

    public function test_app_menu_follows_actions_without_a_page_reload(): void
    {
        $menu = Livewire::test(AppMenu::class)->assertDontSee('data-app-menu="demo"', false);

        Livewire::test(Index::class)->call('activate', 'demo')->assertDispatched('apps-changed');
        $menu->dispatch('apps-changed')->assertSee('data-app-menu="demo"', false);

        Livewire::test(Index::class)->call('stop', 'demo')->assertDispatched('apps-changed');
        $menu->dispatch('apps-changed')->assertDontSee('data-app-menu="demo"', false);
    }

    public function test_app_menu_marks_the_current_page_after_livewire_updates(): void
    {
        $app = $this->runningDemo();

        $html = $this->get(route('apps.page', [$app, 'items']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('apps.page', [$app, 'items']), '#').'"\s+aria-current="page"#', $html);
    }

    public function test_module_page_renders_data_from_the_app_api(): void
    {
        $app = $this->runningDemo();

        $this->get(route('apps.page', [$app, 'items']))->assertOk()
            ->assertSee('Einträge der Demo-App')
            ->assertSee('Erster Eintrag');
        $this->get(route('apps.page', [$app, 'unknown']))->assertNotFound();
        $this->get('/apps/demo/nope')->assertNotFound();
    }

    public function test_module_pages_are_locked_for_unsupported_versions(): void
    {
        $this->appManifest['version'] = '2.1.0';
        $app = $this->runningDemo();

        $this->get(route('apps.page', [$app, 'items']))->assertNotFound();
        $this->get(route('apps.show', $app))->assertOk()
            ->assertSee('Seiten der App nicht verfügbar')
            ->assertSee('unterstützt Version 2.1.0');
    }

    public function test_module_pages_need_a_running_app(): void
    {
        $app = $this->runningDemo();
        app(AppManager::class)->stop($app);

        $this->get(route('apps.page', [$app, 'items']))->assertNotFound();
    }

    public function test_show_page_with_details_history_and_logs(): void
    {
        $app = $this->runningDemo();

        $this->get(route('apps.show', $app))->assertOk()
            ->assertSee('Demo-App')
            ->assertSee('pk95-demo-data → /data')
            ->assertSee('http://pk95-demo:8080')
            ->assertSee('Demo-App 1.2.0 läuft')
            ->assertSee('item.created');

        Livewire::test(Show::class, ['installedApp' => $app])
            ->toggle('showLogs')
            ->assertSee('server listening on :8080')
            ->assertSee('warn: demo mode')
            ->call('action', 'stop')
            ->assertDispatched('notice', kind: 'success')
            ->assertSee('Gestoppt');
    }

    public function test_app_cards_show_the_version_and_details_but_no_remove_button(): void
    {
        $app = $this->runningDemo();

        $html = $this->get('/apps')->assertOk()
            ->assertSee('Version '.$app->version)
            ->assertSee(route('apps.show', $app))
            ->assertSee('Details')
            ->assertSee('>example/demo</span>', false)          // image without its tag
            ->assertDontSee('entfernen?')                          // removing is on the details page
            ->getContent();
        $this->assertStringContainsString('md:auto-rows-fr', $html);  // cards equally high side by side
    }

    public function test_show_page_keeps_remove_in_a_danger_zone(): void
    {
        $app = $this->runningDemo();

        $html = $this->get(route('apps.show', $app))->assertOk()
            ->assertSeeInOrder(['Neustart', 'Stoppen', 'Neu erstellen', 'Container-Log', 'Gefahrenzone', 'Demo-App entfernen?'])
            ->getContent();
        // removing is only offered in the danger zone, not among the everyday actions above it
        $this->assertStringNotContainsString('entfernen?', strstr($html, 'Gefahrenzone', true));
        $this->assertSame(1, substr_count($html, 'entfernen?'));
        $this->assertStringContainsString('data-danger-zone', $html);
    }

    public function test_show_page_remove_redirects_to_the_catalog(): void
    {
        $app = $this->runningDemo();

        Livewire::test(Show::class, ['installedApp' => $app])
            ->call('remove', true)
            ->assertRedirect(route('apps.index'));

        $this->assertSame(0, InstalledApp::query()->count());
    }

    public function test_unknown_app_is_404(): void
    {
        $this->get('/apps/nope')->assertNotFound();
    }
}
