<?php

namespace App\Services\Docker;

use App\Http\Support\Format;
use App\Services\Catalog\AppDefinition;
use App\Support\Settings;

/**
 * Builds the Docker "container create" body for an app from its catalog entry.
 */
final class ContainerSpec
{
    /**
     * @return array<string, mixed>
     */
    public static function for(AppDefinition $app, string $tag, string $token, ?string $image = null): array
    {
        // $image: an image id (sha256:…) instead of image:tag, used to roll back to the
        // exact image that ran before an update even if the tag has moved on.
        $env = self::resolve($app->env) + [
            'PK95_CORE_MODE' => '1',
            // A host-network app cannot resolve "pk95core": it reaches Core's published port.
            'PK95_CORE_URL' => (string) config($app->networkMode === 'host' ? 'pk95.host_core_url' : 'pk95.core_url'),
            'PK95_CORE_TOKEN' => $token,
            // the display timezone (Core itself keeps UTC for stored times)
            'TZ' => Format::timezone(),
        ];

        $host = [
            'RestartPolicy' => ['Name' => 'unless-stopped'],
            'Mounts' => array_map(fn (array $v) => [
                'Type' => 'volume',
                'Source' => $app->volumeName($v['name']),
                'Target' => $v['target'],
            ], $app->volumes),
            'LogConfig' => ['Type' => 'json-file', 'Config' => ['max-size' => '10m', 'max-file' => '3']],
        ];
        if ($app->capAdd !== []) {
            $host['CapAdd'] = $app->capAdd;
        }
        if ($app->sysctls !== []) {
            $host['Sysctls'] = $app->sysctls;
        }

        $spec = [
            'Image' => $image ?? $app->imageRef($tag),
            'Env' => array_map(fn ($k, $v) => "{$k}={$v}", array_keys($env), array_values($env)),
            'Labels' => [
                'pk95.managed' => '1',
                'pk95.app' => $app->id,
            ],
        ];

        if ($app->networkMode === 'host') {
            $host['NetworkMode'] = 'host';
        } else {
            $network = (string) config('pk95.network');
            $host['NetworkMode'] = $network;
            $spec['NetworkingConfig'] = ['EndpointsConfig' => [$network => ['Aliases' => [$app->containerName()]]]];

            if ($app->ports !== []) {
                $spec['ExposedPorts'] = [];
                $host['PortBindings'] = [];
                foreach ($app->ports as $mapping) {
                    [$hostPort, $containerPort] = explode(':', $mapping, 2);
                    $key = str_contains($containerPort, '/') ? $containerPort : $containerPort.'/tcp';
                    $spec['ExposedPorts'][$key] = (object) [];
                    $host['PortBindings'][$key] = [['HostPort' => $hostPort]];
                }
            }
        }

        $spec['HostConfig'] = $host;

        return $spec;
    }

    /**
     * Catalog env values "{setting:<key>}" take the current value of that Core setting (e.g.
     * PUBLIC_URL of Uptime); an empty value leaves the variable out, so the app's default applies.
     *
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    public static function resolve(array $env): array
    {
        $out = [];
        foreach ($env as $key => $value) {
            if (preg_match('/^\{setting:([a-z0-9_]+)\}$/', $value, $m)) {
                $value = trim((string) Settings::get($m[1]));
                if ($value === '') {
                    continue;
                }
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
