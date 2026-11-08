<?php

namespace Tests\Unit;

use App\Services\Catalog\AppDefinition;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\CatalogException;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pk95-catalog-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_shipped_catalog_is_valid(): void
    {
        $apps = (new Catalog(base_path('catalog')))->all();

        $this->assertArrayHasKey('uptime', $apps);
        $this->assertSame('ghcr.io/philippkuhlmann/pk95-uptime', $apps['uptime']->image);
        $this->assertSame('http://pk95-uptime:3001', $apps['uptime']->baseUrl());
    }

    public function test_reads_defaults_and_derived_names(): void
    {
        file_put_contents($this->dir.'/tool.yaml', "id: tool\nname: Tool\nimage: example/tool\nport: 80\n");
        $app = (new Catalog($this->dir))->get('tool');

        $this->assertSame('latest', $app->tag);
        $this->assertSame('/core/health', $app->health);
        $this->assertSame('bridge', $app->networkMode);
        $this->assertSame('tool', $app->module);
        $this->assertSame('pk95-tool', $app->containerName());
        $this->assertSame('pk95-tool-data', $app->volumeName('data'));
        $this->assertSame('example/tool:1.2', $app->imageRef('1.2'));
    }

    public function test_host_network_apps_are_reached_through_the_host(): void
    {
        $app = AppDefinition::fromArray(['id' => 'proxy', 'name' => 'Proxy', 'image' => 'x/proxy', 'port' => 8080, 'network_mode' => 'host'], 'proxy.yaml');

        $this->assertSame('http://host.docker.internal:8080', $app->baseUrl());
    }

    public function test_file_name_must_match_id(): void
    {
        file_put_contents($this->dir.'/other.yaml', "id: tool\nname: Tool\nimage: example/tool\nport: 80\n");

        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('Dateiname passt nicht');
        (new Catalog($this->dir))->all();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidEntries(): array
    {
        $base = ['id' => 'tool', 'name' => 'Tool', 'image' => 'x/tool', 'port' => 80];

        return [
            'bad id' => [['id' => 'Tool!'] + $base, 'id fehlt'],
            'no image' => [array_diff_key($base, ['image' => 1]), 'image fehlt'],
            'bad port' => [['port' => 70000] + $base, 'port'],
            'bad network' => [['network_mode' => 'macvlan'] + $base, 'network_mode'],
            'reserved env' => [['env' => ['PK95_CORE_TOKEN' => 'x']] + $base, 'reserviert'],
            'relative volume' => [['volumes' => [['name' => 'data', 'target' => 'data']]] + $base, 'volumes[0]'],
            'bad port mapping' => [['ports' => ['80']] + $base, 'ports-Eintrag'],
            'non-net sysctl' => [['sysctls' => ['kernel.shmmax' => '1']] + $base, 'nur net.*'],
            'sysctl on host network' => [['network_mode' => 'host', 'sysctls' => ['net.ipv4.ip_forward' => '1']] + $base, 'network_mode host'],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    #[DataProvider('invalidEntries')]
    public function test_rejects_invalid_entries(array $raw, string $message): void
    {
        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage($message);
        AppDefinition::fromArray($raw, 'tool.yaml');
    }
}
