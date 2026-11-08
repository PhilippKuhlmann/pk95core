<?php

namespace Tests\Unit;

use App\Services\Apps\AppException;
use App\Services\Apps\Manifest;
use Tests\Fixtures\DemoModule;
use Tests\TestCase;

class ManifestModuleTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function manifest(array $overrides = []): Manifest
    {
        return Manifest::fromArray($overrides + [
            'id' => 'demo', 'name' => 'Demo', 'version' => '1.2.0', 'protocol' => 1,
            'menu' => [['key' => 'items', 'label' => 'Einträge']],
        ]);
    }

    public function test_parses_manifest_with_defaults(): void
    {
        $m = $this->manifest();

        $this->assertSame('box', $m->icon);
        $this->assertSame([['key' => 'items', 'label' => 'Einträge', 'icon' => 'box']], $m->menu);
        $this->assertSame([], $m->capabilities);
        $this->assertSame($m->toArray(), Manifest::fromArray($m->toArray())->toArray());
    }

    public function test_rejects_incomplete_manifests(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('menu[0]');
        $this->manifest(['menu' => [['key' => 'Bad Key', 'label' => 'x']]]);
    }

    public function test_rejects_manifest_without_protocol(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('protocol');
        $this->manifest(['protocol' => '1']);
    }

    public function test_module_version_range_and_protocol(): void
    {
        $module = new DemoModule;

        $this->assertTrue($module->supports($this->manifest(['version' => '1.0.0'])));
        $this->assertTrue($module->supports($this->manifest(['version' => 'v1.9.3'])));
        $this->assertFalse($module->supports($this->manifest(['version' => '0.9.0'])));
        $this->assertFalse($module->supports($this->manifest(['version' => '2.0.0'])));
        $this->assertFalse($module->supports($this->manifest(['protocol' => 2])));
    }
}
