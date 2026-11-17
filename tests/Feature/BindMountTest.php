<?php

namespace Tests\Feature;

use App\Livewire\Settings\Index;
use App\Services\Apps\AppManager;
use App\Services\Backups\BackupService;
use App\Services\Catalog\Catalog;
use App\Services\Docker\ContainerSpec;
use App\Support\Settings;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/**
 * Host folders in the catalog (binds): only below pk95.bind_root, created by Docker when
 * missing, lying on top of a volume, and left out of that volume's backup.
 */
class BindMountTest extends FeatureTestCase
{
    private string $catalogDir;

    protected function setUp(): void
    {
        parent::setUp();
        // a copy of the demo app whose export folder lies inside its data volume
        $this->catalogDir = sys_get_temp_dir().'/pk95-binds-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->catalogDir);
        File::put($this->catalogDir.'/demo.yaml', File::get(base_path('tests/Fixtures/catalog/demo.yaml'))
            ."binds:\n  - source: /srv/pk95/demo-export\n    target: /data/export\n    create: true\n");
        // backups land in pk95.data_dir: a temporary one
        File::ensureDirectoryExists($this->catalogDir.'/data');
        config(['pk95.catalog_dir' => $this->catalogDir, 'pk95.data_dir' => $this->catalogDir.'/data']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->catalogDir);
        parent::tearDown();
    }

    public function test_the_proxy_binds_its_certificate_export_to_the_lxc(): void
    {
        $proxy = (new Catalog(base_path('catalog')))->get('proxy');

        $this->assertSame([['source' => '/srv/pk95/certs', 'target' => '/var/lib/proxypanel/acme-export', 'create' => true]], $proxy->binds);
        $spec = ContainerSpec::for($proxy, 'latest', 'token');
        $mounts = $spec['HostConfig']['Mounts'];
        // the volume data and, deeper, the folder on the LXC on top of it (Docker mounts by depth)
        $this->assertContains(['Type' => 'volume', 'Source' => 'pk95-proxy-data', 'Target' => '/var/lib/proxypanel'], $mounts);
        $this->assertContains(['Type' => 'bind', 'Source' => '/srv/pk95/certs', 'Target' => '/var/lib/proxypanel/acme-export',
            'BindOptions' => ['CreateMountpoint' => true]], $mounts);

        // the group of the exported files: setting proxy_certs_gid, 2001 by default
        $this->assertContains('PROXYPANEL_CERTS_GID=2001', $spec['Env']);
        Settings::put('proxy_certs_gid', 3005);
        $this->assertContains('PROXYPANEL_CERTS_GID=3005', ContainerSpec::for($proxy, 'latest', 'token')['Env']);
        Settings::put('proxy_certs_gid', '');
        $this->assertContains('PROXYPANEL_CERTS_GID=2001', ContainerSpec::for($proxy, 'latest', 'token')['Env']);
        // an invalid gid would keep the container from starting: the default instead
        foreach ([500, 'abc', 70000] as $invalid) {
            Settings::put('proxy_certs_gid', $invalid);
            $this->assertContains('PROXYPANEL_CERTS_GID=2001', ContainerSpec::for($proxy, 'latest', 'token')['Env'], (string) $invalid);
        }
        $this->assertSame('plain', ContainerSpec::envValue('plain'));
    }

    public function test_deploy_binds_the_folder_and_the_backup_leaves_it_out(): void
    {
        $app = app(AppManager::class)->activate('demo')->refresh();

        // a helper makes the bind root traversable (0755) and creates the folder empty
        $prepare = $this->docker['helperRuns'][0];
        $this->assertSame(['sh', '-c', "chmod 0755 /bindroot && mkdir -p '/bindroot/demo-export'"], $prepare['cmd']);
        $this->assertSame([['Type' => 'bind', 'Source' => '/srv/pk95', 'Target' => '/bindroot', 'BindOptions' => ['CreateMountpoint' => true]]], $prepare['mounts']);
        // and Docker would create a missing folder on the LXC anyway (Core itself does not see host paths)
        $mounts = $this->container('pk95-demo')['spec']['HostConfig']['Mounts'];
        $this->assertSame(['Type' => 'volume', 'Source' => 'pk95-demo-data', 'Target' => '/data'], $mounts[0]);
        $this->assertSame(['Type' => 'bind', 'Source' => '/srv/pk95/demo-export', 'Target' => '/data/export', 'BindOptions' => ['CreateMountpoint' => true]], $mounts[1]);

        // the folder lies outside the volume: its old copy inside the volume is not backed up again
        $backup = app(BackupService::class)->create($app, 'manual');
        $this->assertSame('done', $backup->status);
        $this->assertSame(['sh', '-c', "umask 027 && tar czf '/backups/".basename($backup->files['data'])."' '--exclude=./export' -C /source ."], $this->docker['helperRuns'][1]['cmd']);
        $this->assertCount(1, $backup->files, 'only the volume, not the host folder');
    }

    public function test_a_failed_preparation_is_only_logged(): void
    {
        $this->docker['helperExit'] = 1;
        $this->docker['helperOutput'] = 'chmod: Operation not permitted';

        $app = app(AppManager::class)->activate('demo')->refresh();

        $this->assertSame('running', $app->status);
        $this->assertTrue($app->logs()->where('level', 'warning')->where('message', 'like', 'Host-Ordner unter /srv/pk95 vorbereiten endete mit Code 1%')->exists());
    }

    public function test_the_certificate_group_is_a_validated_setting(): void
    {
        $this->signIn();

        Livewire::test(Index::class)
            ->assertSet('proxyCertsGid', 2001)
            ->set('proxyCertsGid', '999')->call('save')->assertHasErrors(['proxyCertsGid' => 'between'])
            ->set('proxyCertsGid', 'abc')->call('save')->assertHasErrors(['proxyCertsGid' => 'integer'])
            ->set('proxyCertsGid', '2002')->call('save')->assertHasNoErrors();

        $this->assertSame(2002, Settings::get('proxy_certs_gid'));
        $this->get(route('settings'))->assertOk()->assertSee('Gruppen-ID (gid) von pk95-certs');
    }
}
