<?php

use App\Modules\ProxApps\ProxAppsModule;
use App\Modules\Uptime\UptimeModule;

return [

    'version' => '0.1.0',

    /*
    | App protocol versions this Core speaks (see docs/app-protocol.md).
    */
    'protocols' => [1],

    /*
    | Directory holding the app catalog (catalog/<id>.yaml).
    */
    'catalog_dir' => env('PK95_CATALOG', base_path('catalog')),

    /*
    | Data root inside the LXC. It is mounted into the Core container under the SAME path,
    | so host paths Core hands to Docker (backups, bind mounts) are valid on both sides.
    */
    'data_dir' => env('PK95_DATA_DIR', '/opt/pk95'),

    /*
    | Docker Engine API over the mounted unix socket.
    */
    'docker' => [
        'socket' => env('PK95_DOCKER_SOCKET', '/var/run/docker.sock'),
        'api_version' => env('PK95_DOCKER_API', 'v1.44'),
        // Pulling a large image can take a while on a slow uplink.
        'pull_timeout' => (int) env('PK95_PULL_TIMEOUT', 900),
    ],

    /*
    | Bridge network shared by Core and all bridge-mode apps. Apps reach Core at core_url,
    | Core reaches an app at http://pk95-<id>:<port>.
    */
    'network' => env('PK95_NETWORK', 'pk95'),
    'core_url' => env('PK95_CORE_URL', 'http://pk95core:8080'),

    /*
    | Apps with network_mode "host" are reached through the Docker host (the LXC):
    | the Core container maps host.docker.internal to the host gateway (docker-compose.yml).
    */
    'host_address' => env('PK95_HOST_ADDRESS', 'host.docker.internal'),

    /*
    | Container and volume names: <prefix><app id>.
    */
    'container_prefix' => 'pk95-',

    /*
    | Seconds to wait for /core/health after starting an app, and between polls.
    */
    'health_timeout' => (int) env('PK95_HEALTH_TIMEOUT', 120),
    'poll_interval' => (float) env('PK95_POLL_INTERVAL', 2),

    /*
    | Timeout (seconds) for Core → app API calls.
    */
    'app_timeout' => (int) env('PK95_APP_TIMEOUT', 10),

    /*
    | Core modules: app id from the catalog → module class (App\Modules\Module).
    | A module renders the pages of one app inside Core.
    */
    'modules' => [
        'uptime' => UptimeModule::class,
        'proxapps' => ProxAppsModule::class,
    ],

    /*
    | Defaults for the settings stored in the "settings" table.
    */
    'defaults' => [
        'timezone' => 'Europe/Berlin',
        // Daily maintenance window (HH:MM): scheduled backups and automatic updates.
        'maintenance_time' => '03:30',
        'maintenance_last_run' => '',
        // Backups kept per app (older ones are deleted; failed ones do not count).
        'backup_keep' => 7,
    ],

    /*
    | Small image for backup/restore helper containers (needs sh + tar).
    */
    'helper_image' => env('PK95_HELPER_IMAGE', 'alpine:3.21'),
];
