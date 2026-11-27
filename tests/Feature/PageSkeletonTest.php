<?php

namespace Tests\Feature;

use App\Models\InstalledApp;
use App\Modules\Concerns\LoadsLazily;
use App\Modules\Module;
use App\Support\PageQuery;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * Every page that asks an app opens at once with a skeleton in its own shape
 * (LoadsLazily::PLACEHOLDER → partials/skeleton.blade.php); the app is asked only in the
 * Livewire request that follows.
 */
class PageSkeletonTest extends FeatureTestCase
{
    private const PARTS = ['root', 'header', 'filters', 'tabs', 'tiles', 'panel', 'panels', 'cards', 'table', 'form', 'text', 'card'];

    /** @var list<string> requests to anything but Docker */
    private array $asked = [];

    /**
     * The modules of config/pk95.php (FeatureTestCase registers only the demo module).
     *
     * @return array<string, class-string<Module>>
     */
    private function realModules(): array
    {
        return (require base_path('config/pk95.php'))['modules'];
    }

    /**
     * @return array<string, class-string> "app/page" → page class
     */
    private function modulePages(): array
    {
        $pages = [];
        foreach ($this->realModules() as $id => $class) {
            $module = new $class;
            foreach ($module->pages() + $module->subPages() as $key => $page) {
                $pages["{$id}/{$key}"] = $page;
            }
        }

        return $pages;
    }

    public function test_every_module_page_defines_its_skeleton(): void
    {
        $pages = $this->modulePages();
        $this->assertGreaterThan(40, count($pages));

        foreach ($pages as $name => $class) {
            $this->assertContains(LoadsLazily::class, class_uses_recursive($class), "{$name} uses LoadsLazily");
            $this->assertTrue(defined($class.'::PLACEHOLDER'), "{$name} defines PLACEHOLDER");
            $parts = $class::PLACEHOLDER;
            $this->assertIsArray($parts);
            $this->assertNotEmpty($parts, $name);
            foreach ($parts as $part) {
                $this->assertContains($part[0] ?? null, self::PARTS, "{$name}: unknown part");
            }
            $this->assertNotEmpty(array_filter($parts, fn ($p) => $p[0] !== 'root'), "{$name}: more than a root");

            $html = view('partials.skeleton', ['parts' => $parts])->render();
            $this->assertStringContainsString('data-skeleton', $html, $name);
            $this->assertStringContainsString('aria-busy="true"', $html, $name);
        }
    }

    public function test_every_module_page_answers_first_with_its_skeleton_without_asking_the_app(): void
    {
        $this->signIn();
        config(['pk95.catalog_dir' => base_path('catalog'), 'pk95.modules' => $this->realModules()]);
        // anything but Docker is an app: noted and answered empty
        Http::fake(function (Request $request) {
            $this->asked[] = $request->url();

            return Http::response([]);
        });
        $this->withSkeletons();

        foreach ($this->realModules() as $id => $class) {
            $module = new $class;
            $version = $module->minVersion() ?? '1.0.0';
            $app = InstalledApp::factory()->create([
                'app_id' => $id, 'version' => $version,
                'manifest' => ['id' => $id, 'name' => "PK95 {$id}", 'version' => $version, 'protocol' => 1, 'icon' => 'box',
                    'menu' => array_map(fn ($k) => ['key' => $k, 'label' => $k, 'icon' => 'box'], array_keys($module->pages())),
                    'capabilities' => [], 'events' => []],
            ]);

            foreach (array_keys($module->pages() + $module->subPages()) as $key) {
                $this->asked = [];
                $this->get(route('apps.page', [$app, $key]).'?id=1&app=demo')->assertOk()
                    ->assertSee('data-skeleton', false)
                    ->assertSee('x-init="$wire.__lazyLoad', false);
                $this->assertSame([], $this->asked, "{$id}/{$key}: the skeleton waits on nothing");
            }
        }
    }

    public function test_the_apps_page_opens_with_its_skeleton_and_loads_the_catalog_after(): void
    {
        $this->signIn();
        $this->withSkeletons()->get('/apps')->assertOk()
            ->assertSee('data-skeleton', false)->assertSee('x-init="$wire.__lazyLoad', false)
            ->assertDontSee('Demo-App');
    }

    public function test_a_deferred_page_reads_its_query_from_the_page_url(): void
    {
        $this->assertSame(['id' => '7'], $this->withRequest(HttpRequest::create('/apps/x/deployment?id=7'), fn () => PageQuery::all()));

        // the Livewire request that loads a deferred page: the page URL is the Referer
        $update = HttpRequest::create('/livewire/update', 'POST', server: [
            'HTTP_X_LIVEWIRE' => '1', 'HTTP_REFERER' => 'http://localhost/apps/x/deploy?app=nginx&from=3',
        ]);
        $this->assertSame('nginx', $this->withRequest($update, fn () => PageQuery::get('app')));
        $this->assertTrue($this->withRequest($update, fn () => PageQuery::has('from')));
        $this->assertFalse($this->withRequest($update, fn () => PageQuery::has('hide')));
    }

    private function withRequest(HttpRequest $request, callable $read): mixed
    {
        $before = app('request');
        $this->app->instance('request', $request);
        try {
            return $read();
        } finally {
            $this->app->instance('request', $before);
        }
    }
}
