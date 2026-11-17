<?php

namespace App\Services\Docker;

use App\Http\Support\Format;
use App\Services\Catalog\AppDefinition;
use App\Support\Settings;
use Illuminate\Support\Facades\Validator;

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
        $env = array_map(self::envValue(...), $app->env) + [
            'PK95_CORE_MODE' => '1',
            // A host-network app cannot resolve "pk95core": it reaches Core's published port.
            'PK95_CORE_URL' => (string) config($app->networkMode === 'host' ? 'pk95.host_core_url' : 'pk95.core_url'),
            'PK95_CORE_TOKEN' => $token,
            // the display timezone (Core itself keeps UTC for stored times)
            'TZ' => Format::timezone(),
        ];

        $host = [
            'RestartPolicy' => ['Name' => 'unless-stopped'],
            'Mounts' => [
                ...array_map(fn (array $v) => [
                    'Type' => 'volume',
                    'Source' => $app->volumeName($v['name']),
                    'Target' => $v['target'],
                ], $app->volumes),
                // host folders (below pk95.bind_root); a bind inside a volume target lies on top of it,
                // Docker mounts by depth. create: Docker makes the folder on the host when it is missing.
                ...array_map(fn (array $b) => ['Type' => 'bind', 'Source' => $b['source'], 'Target' => $b['target']]
                    + ($b['create'] ? ['BindOptions' => ['CreateMountpoint' => true]] : []), $app->binds),
            ],
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
     * A catalog env value; "{setting:key|default}" comes from Core's settings, the default when
     * the setting is empty (e.g. PROXYPANEL_CERTS_GID: "{setting:proxy_certs_gid|2001}").
     */
    public static function envValue(string $value): string
    {
        if (! preg_match('/^\{setting:([a-z0-9_]+)\|([^}]*)\}$/', $value, $m)) {
            return $value;
        }
        $setting = trim((string) (Settings::get($m[1]) ?? ''));
        $rules = config('pk95.setting_rules.'.$m[1]);
        if ($setting !== '' && $rules && Validator::make(['v' => $setting], ['v' => $rules])->fails()) {
            return $m[2];
        }

        return $setting !== '' ? $setting : $m[2];
    }
}
