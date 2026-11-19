<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\AppPanel;
use App\Livewire\Dashboard\ProblemList;
use App\Models\InstalledApp;
use App\Modules\Ad\AdModule;
use App\Services\Dashboard\AppFigures;
use App\Services\Dashboard\Problems;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * The dashboard with PK95 AD (tile and warnings from /api/status, /api/domain, /api/users) and
 * with an app Core has no figures for: that is no problem.
 */
class DashboardAdTest extends FeatureTestCase
{
    /** @var list<string> paths PK95 AD was asked for */
    private array $asked = [];

    private string $state = 'ready';

    private bool $trouble = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
        Http::fake([
            'host.docker.internal:8389/*' => function (Request $r) {
                $path = (string) parse_url($r->url(), PHP_URL_PATH);
                $this->asked[] = $path;
                $ntp = $this->trouble ? ['running' => false, 'available' => false, 'message' => 'NTP nicht verfügbar: Port 123/udp ist im LXC belegt.']
                    : ['running' => true, 'available' => true, 'message' => ''];

                return match ($path) {
                    '/api/status' => Http::response(['version' => '1.3.0', 'state' => $this->state,
                        'samba' => ['state' => $this->state === 'ready' ? 'running' : 'stopped', 'restarts' => 0, 'last_exit' => '',
                            'services' => ['ldap' => ! $this->trouble, 'kerberos' => true, 'smb' => true, 'dns' => ! $this->trouble]],
                        'ntp' => $ntp, 'domain' => $this->state === 'ready' ? ['realm' => 'ad.example.com'] : null]),
                    '/api/domain' => Http::response(['realm' => 'ad.example.com', 'netbios_domain' => 'EXAMPLE',
                        'counts' => ['users' => 14, 'groups' => 52, 'computers' => 3, 'ous' => 4, 'gpos' => 5], 'ntp' => $ntp,
                        'resolver' => ['ok' => ! $this->trouble, 'nameserver' => '127.0.0.1', 'message' => $this->trouble ? 'PK95 DNS beantwortet die Domäne nicht.' : ''],
                        'idmap' => ['ok' => true, 'message' => '']]),
                    '/api/users' => Http::response(['items' => [['id' => 'u1', 'locked' => false], ['id' => 'u2', 'locked' => true]], 'total' => 2]),
                    default => Http::response(['error' => 'unbekannt'], 404),
                };
            },
        ]);
    }

    private function ad(): InstalledApp
    {
        config(['pk95.catalog_dir' => base_path('catalog'), 'pk95.modules' => ['ad' => AdModule::class]]);

        return InstalledApp::factory()->create(['app_id' => 'ad', 'version' => '1.3.0', 'tag' => '1.3.0',
            'manifest' => ['id' => 'ad', 'name' => 'PK95 AD', 'version' => '1.3.0', 'protocol' => 1, 'icon' => 'key', 'menu' => [], 'capabilities' => [], 'events' => []]]);
    }

    public function test_an_app_without_figures_is_no_problem(): void
    {
        // the demo app has a module but Core has no figures for it (before: "antwortet nicht")
        $app = InstalledApp::factory()->create();
        $this->assertSame(['ok' => true, 'none' => true], app(AppFigures::class)->for($app));
        $this->assertSame([], app(Problems::class)->collect());
        Livewire::withoutLazyLoading()->test(ProblemList::class)->assertSee('Alles in Ordnung')->assertDontSee('antwortet nicht');

        // a real failure still counts
        $app->update(['status' => InstalledApp::STOPPED]);
        $this->assertSame('Demo-App läuft nicht (gestoppt)', app(Problems::class)->collect()[0]['text']);
    }

    public function test_ad_tile_shows_the_counts_the_checks_and_locked_accounts(): void
    {
        $this->ad();

        Livewire::withoutLazyLoading()->test(AppPanel::class, ['appId' => 'ad'])
            ->assertSee('PK95 AD')->assertSeeHtml('data-realm')->assertSee('ad.example.com')->assertDontSee('24 h')
            ->assertSeeInOrder(['Benutzer', '14', 'Gruppen', '52', 'Computer', '3', 'Gruppenrichtlinien', '5'])
            ->assertSeeInOrder(['Samba', 'NTP', 'DNS-Auflösung', 'Unix-IDs', '1 gesperrtes Konto'])
            ->assertDontSeeHtml('data-check-failed');
        $this->assertSame(['/api/status', '/api/domain', '/api/users'], $this->asked);

        // the tiles and the problem list share the cached answer
        Livewire::withoutLazyLoading()->test(ProblemList::class)->assertSee('Alles in Ordnung');
        $this->assertCount(3, $this->asked);
    }

    public function test_every_failing_ad_check_is_a_warning_with_the_apps_message(): void
    {
        $app = $this->ad();
        $this->trouble = true;

        $html = Livewire::withoutLazyLoading()->test(ProblemList::class)
            ->assertSee('PK95 AD: Die Dienste LDAP, DNS antworten nicht.')
            ->assertSee('PK95 AD: NTP nicht verfügbar: Port 123/udp ist im LXC belegt.')
            ->assertSee('PK95 AD: PK95 DNS beantwortet die Domäne nicht.')
            ->assertDontSee('Unix-IDs')->assertDontSee('antwortet nicht:')
            ->html();
        $this->assertSame(3, substr_count($html, 'href="'.route('apps.page', [$app, 'domain']).'"'));

        Livewire::withoutLazyLoading()->test(AppPanel::class, ['appId' => 'ad'])
            ->assertSeeHtml('data-check-failed')->assertSeeHtml('data-tip="Die Dienste LDAP, DNS antworten nicht."');
    }

    public function test_a_domain_not_set_up_yet_links_to_the_wizard(): void
    {
        $app = $this->ad();
        $this->state = 'unconfigured';

        $html = Livewire::withoutLazyLoading()->test(ProblemList::class)
            ->assertSee('PK95 AD: Domäne noch nicht eingerichtet')->html();
        $this->assertStringContainsString('href="'.route('apps.page', [$app, 'domain']).'"', $html);
        $this->assertSame(['/api/status'], $this->asked, 'no counts without a domain');

        Livewire::withoutLazyLoading()->test(AppPanel::class, ['appId' => 'ad'])
            ->assertSeeHtml('data-ad-unconfigured')->assertSee('Zum Einrichtungsassistenten')->assertDontSee('Gruppenrichtlinien');
    }
}
