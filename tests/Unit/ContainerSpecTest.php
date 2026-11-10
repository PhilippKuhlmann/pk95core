<?php

namespace Tests\Unit;

use App\Services\Catalog\AppDefinition;
use App\Services\Docker\ContainerSpec;
use Tests\TestCase;

class ContainerSpecTest extends TestCase
{
    public function test_bridge_app_joins_the_core_network_with_volumes_and_core_env(): void
    {
        config(['pk95.core_url' => 'http://pk95core:8080', 'pk95.timezone' => 'Europe/Berlin']);
        $app = AppDefinition::fromArray([
            'id' => 'uptime', 'name' => 'Uptime', 'image' => 'ghcr.io/x/uptime', 'port' => 3001,
            'volumes' => [['name' => 'data', 'target' => '/data']],
            'env' => ['RETENTION_DAYS' => 30],
            'ports' => ['8443:443'],
            'sysctls' => ['net.ipv4.ping_group_range' => '0 65535'],
        ], 'uptime.yaml');

        $spec = ContainerSpec::for($app, '1.4.0', 'secret-token');

        $this->assertSame('ghcr.io/x/uptime:1.4.0', $spec['Image']);
        $this->assertContains('PK95_CORE_MODE=1', $spec['Env']);
        $this->assertContains('PK95_CORE_URL=http://pk95core:8080', $spec['Env']);
        $this->assertContains('PK95_CORE_TOKEN=secret-token', $spec['Env']);
        $this->assertContains('RETENTION_DAYS=30', $spec['Env']);
        $this->assertSame(['pk95.managed' => '1', 'pk95.app' => 'uptime'], $spec['Labels']);
        $this->assertSame('pk95', $spec['HostConfig']['NetworkMode']);
        $this->assertSame(['pk95-uptime'], $spec['NetworkingConfig']['EndpointsConfig']['pk95']['Aliases']);
        $this->assertSame([['Type' => 'volume', 'Source' => 'pk95-uptime-data', 'Target' => '/data']], $spec['HostConfig']['Mounts']);
        $this->assertSame([['HostPort' => '8443']], $spec['HostConfig']['PortBindings']['443/tcp']);
        $this->assertSame('unless-stopped', $spec['HostConfig']['RestartPolicy']['Name']);
        $this->assertSame(['net.ipv4.ping_group_range' => '0 65535'], $spec['HostConfig']['Sysctls']);
    }

    public function test_host_network_app_gets_capabilities_and_no_network_config(): void
    {
        $app = AppDefinition::fromArray([
            'id' => 'proxy', 'name' => 'Proxy', 'image' => 'x/proxy', 'port' => 8080,
            'network_mode' => 'host', 'cap_add' => ['NET_ADMIN', 'NET_RAW'],
        ], 'proxy.yaml');

        $spec = ContainerSpec::for($app, 'latest', 't');

        $this->assertSame('host', $spec['HostConfig']['NetworkMode']);
        $this->assertSame(['NET_ADMIN', 'NET_RAW'], $spec['HostConfig']['CapAdd']);
        $this->assertContains('PK95_CORE_URL=http://127.0.0.1:8080', $spec['Env']);
        $this->assertArrayNotHasKey('NetworkingConfig', $spec);
        $this->assertArrayNotHasKey('PortBindings', $spec['HostConfig']);
    }
}
