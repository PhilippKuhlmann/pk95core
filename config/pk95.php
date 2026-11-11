<?php

use App\Modules\Dns\DnsModule;
use App\Modules\ProxApps\ProxAppsModule;
use App\Modules\Proxy\ProxyModule;
use App\Modules\Uptime\UptimeModule;

return [

    // Release version baked into the image (Dockerfile ARG VERSION): 1.2.3, "main" or "dev".
    'version' => env('PK95_VERSION', 'dev'),

    /*
    | Timezone for everything shown to the user and handed to the apps as TZ. Times are
    | stored in UTC (app.timezone) and only converted for display (App\Http\Support\Format).
    | docker-compose.yml sets TZ; PK95_TIMEZONE overrides it.
    */
    'timezone' => env('PK95_TIMEZONE') ?: (env('TZ') ?: 'Europe/Berlin'),

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
    // ...and they reach Core through its published port on the LXC (docker-compose.yml: 8080).
    'host_core_url' => env('PK95_HOST_CORE_URL', 'http://127.0.0.1:8080'),

    /*
    | Core updating itself: Core cannot replace its own container, so a short-lived helper
    | container with the docker CLI runs "docker compose pull/up" for the compose project Core
    | was started from, checks /up of the new Core and goes back to the old image otherwise.
    | core_container is the fallback name when the hostname does not identify the container.
    */
    'core_container' => env('PK95_CORE_CONTAINER', 'pk95core'),
    'updater_image' => env('PK95_UPDATER_IMAGE', 'docker:28-cli'),

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
        'proxy' => ProxyModule::class,
        'dns' => DnsModule::class,
    ],

    /*
    | Defaults for the settings stored in the "settings" table.
    */
    'defaults' => [
        // Daily maintenance window (HH:MM): scheduled backups and automatic updates.
        'maintenance_time' => '03:30',
        'maintenance_last_run' => '',
        // Backups kept per app (older ones are deleted; failed ones do not count).
        'backup_keep' => 7,
        // Automation: a deployed app (ProxApps) gets a proxy host <subdomain>.<base domain>
        // and monitors in PK95 Uptime. Public IP empty = determined from the outside.
        'automation_base_domain' => '',
        'automation_proxy' => true,
        'automation_uptime' => true,
        'automation_public_ip' => '',
        // Local DNS record in PK95 DNS for a deployed app's domain: answer = LAN IP of the
        // proxy (the LXC). Empty = detected by a helper container in the host network.
        'automation_dns' => true,
        'automation_lan_ip' => '',
        // Base address of PK95 Uptime's public status pages (<address>/status/<slug>): Core's links
        // and a PK95 Proxy host for its domain (Uptime itself does not get it). Empty = not set up.
        'uptime_public_url' => 'https://status.kuhlmannit.de',
    ],

    /*
    | Small image for backup/restore helper containers (needs sh + tar).
    */
    'helper_image' => env('PK95_HELPER_IMAGE', 'alpine:3.21'),
];
