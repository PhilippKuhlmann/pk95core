<?php

namespace Tests\Feature;

use App\Http\Support\Format;
use App\Jobs\EnsureAdDnsForward;
use App\Models\AppEvent;
use App\Models\InstalledApp;
use App\Modules\Ad\AdModule;
use App\Modules\Ad\Computers;
use App\Modules\Ad\DnsForward;
use App\Modules\Ad\DnsRecords;
use App\Modules\Ad\Domain;
use App\Modules\Ad\Gpos;
use App\Modules\Ad\Gpp\Kinds;
use App\Modules\Ad\Groups;
use App\Modules\Ad\Ous;
use App\Modules\Ad\Scripts;
use App\Modules\Ad\Users;
use App\Services\Catalog\Catalog;
use App\Services\Docker\ContainerSpec;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * The PK95 AD module against an in-memory fake of the app API (host network:
 * http://host.docker.internal:8389, see pk95ad docs/api.md) and of PK95 DNS (8053) for the
 * conditional forwarding of the realm.
 */
class AdModuleTest extends FeatureTestCase
{
    private InstalledApp $installed;

    /** @var list<array{method: string, path: string, query: array<string, mixed>, body: mixed}> */
    private array $calls = [];

    /** @var list<array{method: string, path: string, body: mixed}> */
    private array $dnsCalls = [];

    /** @var array<string, mixed> */
    private array $state = [];

    /** @var list<array{domain: string, upstream: string}> conditional forwarding of the fake PK95 DNS */
    private array $conditional = [['domain' => 'fritz.box', 'upstream' => '192.168.178.1']];

    /** the fake PK95 DNS answers PUT /api/settings with 422 */
    private bool $dnsRefuses = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pk95.catalog_dir' => base_path('catalog'), 'pk95.modules' => ['ad' => AdModule::class]]);
        $this->signIn();
        $menu = array_map(fn ($k) => ['key' => $k, 'label' => $k, 'icon' => 'key'], array_keys((new AdModule)->pages()));
        $this->installed = InstalledApp::factory()->create([
            'app_id' => 'ad', 'version' => '1.0.0',
            'manifest' => ['id' => 'ad', 'name' => 'PK95 AD', 'version' => '1.0.0', 'protocol' => 1, 'icon' => 'key',
                'menu' => $menu, 'capabilities' => ['events', 'backup'], 'events' => ['ad.setup.done']],
        ]);

        $node = fn (string $id, string $name, string $type, string $path, string $parent, array $children = []) => [
            'id' => $id, 'name' => $name, 'dn' => '', 'type' => $type, 'description' => '', 'path' => $path, 'parent_id' => $parent,
            'protected' => $type !== 'ou' || $name === 'Domain Controllers', 'block_inheritance' => false, 'children' => $children,
        ];
        $this->state = [
            'setup' => ['state' => 'ready', 'error' => '', 'log' => []],
            'tree' => $node('d0', 'ad.example.com', 'domain', '', '', [
                $node('b1', 'Builtin', 'builtin', 'Builtin', 'd0'),
                $node('c1', 'Computers', 'container', 'Computers', 'd0'),
                $node('dc', 'Domain Controllers', 'ou', 'Domain Controllers', 'd0'),
                $node('f1', 'Firma', 'ou', 'Firma', 'd0', [$node('v1', 'Vertrieb', 'ou', 'Firma/Vertrieb', 'f1')]),
                $node('u1', 'Users', 'container', 'Users', 'd0'),
            ]),
            'ou_objects' => ['v1' => 1],
            'users' => [
                'a-1' => $this->user('a-1', 'Administrator', 'Administrator', 'u1', 'Users', ['builtin' => true, 'protected' => true]),
                'm-1' => $this->user('m-1', 'max', 'Max Muster', 'v1', 'Firma/Vertrieb', ['locked' => true, 'email' => 'max@example.com']),
            ],
            'groups' => [
                'g-da' => $this->group('g-da', 'Domain Admins', ['builtin' => true, 'protected' => true, 'member_count' => 1]),
                'g-v' => $this->group('g-v', 'Vertrieb', ['ou_id' => 'f1', 'ou_path' => 'Firma']),
                'g-du' => $this->group('g-du', 'Domain Users', ['builtin' => true, 'protected' => true, 'member_count' => 2]),
            ],
            'members' => ['g-da' => ['a-1'], 'g-v' => [], 'g-du' => []],
            // accounts whose primary group it is (members[].primary: true, untouched by PUT /members)
            'primary' => ['g-du' => ['a-1', 'm-1']],
            'ntp' => ['running' => true, 'available' => true, 'message' => ''],
            // the clock (PK95 AD 1.4.0, GET/PUT /api/time); null: an older app (404, no ntp.time)
            'time' => null,
            // null: PK95 AD 1.0.0 without the field idmap in /api/domain
            'idmap' => null,
            'computers' => [
                'pc-dc' => ['id' => 'pc-dc', 'name' => 'DC1', 'dns_hostname' => 'dc1.ad.example.com', 'os' => 'Samba', 'os_version' => '4.22', 'description' => '',
                    'enabled' => true, 'is_dc' => true, 'ou_id' => 'dc', 'ou_path' => 'Domain Controllers', 'last_logon' => 0, 'created_at' => 1762000000000],
                'pc-1' => ['id' => 'pc-1', 'name' => 'PC-01', 'dns_hostname' => 'pc-01.ad.example.com', 'os' => 'Windows 11 Pro', 'os_version' => '10.0 (26100)', 'description' => '',
                    'enabled' => true, 'is_dc' => false, 'ou_id' => 'c1', 'ou_path' => 'Computers', 'last_logon' => 1762690000000, 'created_at' => 1762000000000],
            ],
            'gpos' => [
                'gp-1' => $this->gpo('gp-1', 'Default Domain Policy', true, [['target_id' => 'd0', 'target_name' => 'ad.example.com', 'target_type' => 'domain', 'target_path' => '', 'enabled' => true, 'enforced' => false, 'order' => 1]]),
                'gp-2' => $this->gpo('gp-2', 'Bildschirmsperre', false, [['target_id' => 'v1', 'target_name' => 'Vertrieb', 'target_type' => 'ou', 'target_path' => 'Firma/Vertrieb', 'enabled' => true, 'enforced' => false, 'order' => 1]]),
            ],
            'settings' => ['gp-2' => ['screen_lock_minutes' => 10]],
            // drive maps (PK95 AD 1.1.0); drives404: an app before 1.1.0
            'drives' => ['gp-2' => [
                ['uid' => '{A1}', 'letter' => 'V', 'first_available' => false, 'path' => '\\\\nas.example.com\\vertrieb', 'label' => 'Vertrieb', 'action' => 'update',
                    'reconnect' => true, 'visibility' => 'unchanged', 'target' => ['group_id' => 'g-v', 'group_name' => 'Vertrieb', 'not' => false],
                    'changed_at' => 1762900000000, 'readonly' => false, 'warning' => ''],
                ['uid' => '{B2}', 'letter' => 'P', 'first_available' => false, 'path' => '\\\\nas\\privat', 'label' => '', 'action' => 'update',
                    'reconnect' => true, 'visibility' => 'unchanged', 'target' => null, 'changed_at' => 1762800000000, 'readonly' => true,
                    'warning' => 'Enthält ein gespeichertes Kennwort (unsicher, MS14-025). Bitte in der GPMC entfernen.'],
            ]],
            'drives404' => false,
            // the other preferences lists (PK95 AD 1.3.0) on gp-1; gpp404: an app before 1.3.0
            'gpp' => [
                'local-groups' => ['gp-1' => [
                    ['uid' => '{L1}', 'group' => 'administrators', 'members' => [['id' => 'g-v', 'name' => 'Vertrieb', 'kind' => 'group', 'action' => 'add']],
                        'remove_other_users' => false, 'remove_other_groups' => false, 'target' => null, 'changed_at' => 1762900000000, 'readonly' => false, 'warning' => ''],
                ]],
                'printers' => ['gp-1' => [
                    ['uid' => '{P1}', 'path' => '\\\\print\\buero', 'action' => 'update', 'default' => true,
                        'target' => ['group_id' => 'g-v', 'group_name' => 'Vertrieb', 'not' => false], 'changed_at' => 1762900000000, 'readonly' => false, 'warning' => ''],
                ]],
                'shortcuts' => [],
            ],
            'gpp404' => false,
            'records' => [
                's1' => ['id' => 's1', 'zone' => 'ad.example.com', 'name' => 'ad.example.com', 'type' => 'SOA', 'value' => 'dc1.ad.example.com', 'ttl' => 3600, 'priority' => 0, 'weight' => 0, 'port' => 0, 'dynamic' => false, 'system' => true],
                's2' => ['id' => 's2', 'zone' => 'ad.example.com', 'name' => 'dc1.ad.example.com', 'type' => 'A', 'value' => '192.168.1.10', 'ttl' => 900, 'priority' => 0, 'weight' => 0, 'port' => 0, 'dynamic' => false, 'system' => true],
                'n1' => ['id' => 'n1', 'zone' => 'ad.example.com', 'name' => 'nas.ad.example.com', 'type' => 'A', 'value' => '192.168.1.20', 'ttl' => 900, 'priority' => 0, 'weight' => 0, 'port' => 0, 'dynamic' => true, 'system' => false],
            ],
            'scripts' => ['logon.bat' => "@echo off\r\nnet use H: \\\\nas\\home\r\n"],
        ];

        Http::fake([
            'host.docker.internal:8389/*' => fn (Request $r) => $this->ad($r),
            'host.docker.internal:8053/*' => fn (Request $r) => $this->dns($r),
        ]);
    }

    /** @return array<string, mixed> */
    private function user(string $id, string $username, string $display, string $ou, string $path, array $extra = []): array
    {
        return array_merge(['id' => $id, 'username' => $username, 'upn' => strtolower($username).'@ad.example.com', 'display_name' => $display,
            'given_name' => '', 'surname' => '', 'email' => '', 'description' => '', 'ou_id' => $ou, 'ou_path' => $path, 'enabled' => true,
            'locked' => false, 'must_change_password' => false, 'password_never_expires' => false, 'expires_at' => 0, 'last_logon' => 1762690000000,
            'builtin' => false, 'protected' => false], $extra);
    }

    /** @return array<string, mixed> */
    private function group(string $id, string $name, array $extra = []): array
    {
        return array_merge(['id' => $id, 'name' => $name, 'description' => '', 'email' => '', 'type' => 'security', 'scope' => 'global',
            'ou_id' => 'u1', 'ou_path' => 'Users', 'member_count' => 0, 'builtin' => false, 'protected' => false], $extra);
    }

    /** @return array<string, mixed> */
    private function gpo(string $id, string $name, bool $builtin, array $links): array
    {
        return ['id' => $id, 'guid' => '{'.strtoupper($id).'}', 'name' => $name, 'status' => 'enabled', 'builtin' => $builtin,
            'created_at' => 1762600000000, 'changed_at' => 1762690000000, 'version' => ['computer' => 1, 'user' => 0], 'links' => $links, 'sysvol_ok' => true];
    }

    /** @return list<array<string, mixed>> */
    private function catalog(): array
    {
        return [
            ['key' => 'screen_lock_minutes', 'scope' => 'user', 'group' => 'Desktop', 'label' => 'Bildschirmsperre nach Inaktivität', 'type' => 'int', 'min' => 1, 'max' => 600, 'unit' => 'Minuten', 'help' => 'Startet den Bildschirmschoner mit Kennwortschutz.'],
            ['key' => 'wallpaper_path', 'scope' => 'user', 'group' => 'Desktop', 'label' => 'Hintergrundbild', 'type' => 'string', 'max' => 260],
            ['key' => 'deny_cmd', 'scope' => 'user', 'group' => 'System', 'label' => 'Eingabeaufforderung sperren', 'type' => 'bool'],
            // editions (PK95 AD 1.2.0) on an entry and on an enum option
            ['key' => 'store_off', 'editions' => ['enterprise', 'education'], 'scope' => 'computer', 'group' => 'Datenschutz', 'label' => 'Microsoft Store abschalten', 'type' => 'bool'],
            ['key' => 'telemetry_level', 'scope' => 'computer', 'group' => 'Datenschutz', 'label' => 'Diagnosedaten', 'type' => 'enum',
                'options' => [['value' => 0, 'label' => 'Sicherheit', 'editions' => ['enterprise', 'education']], ['value' => 1, 'label' => 'Erforderlich'],
                    ['value' => 2, 'label' => 'Alle Editionen', 'editions' => ['home', 'pro', 'enterprise', 'education']]]],
            ['key' => 'logon_scripts', 'scope' => 'user', 'group' => 'Skripte', 'label' => 'Anmeldeskripte', 'type' => 'scripts'],
            ['key' => 'windows_update_mode', 'scope' => 'computer', 'group' => 'Windows Update', 'label' => 'Automatische Updates', 'type' => 'enum',
                'options' => [['value' => 'off', 'label' => 'Aus'], ['value' => 'notify', 'label' => 'Nur melden'], ['value' => 'auto', 'label' => 'Automatisch installieren']]],
            ['key' => 'logon_banner_text', 'scope' => 'computer', 'group' => 'Anmeldung', 'label' => 'Hinweistext vor der Anmeldung', 'type' => 'text', 'max' => 2000],
        ];
    }

    /** @return array<string, mixed>|null */
    private function findNode(array $node, string $id): ?array
    {
        if ($node['id'] === $id) {
            return $node;
        }
        foreach ($node['children'] as $child) {
            if ($found = $this->findNode($child, $id)) {
                return $found;
            }
        }

        return null;
    }

    private function changeNode(array &$node, string $id, callable $change): void
    {
        if ($node['id'] === $id) {
            $change($node);

            return;
        }
        foreach ($node['children'] as &$child) {
            $this->changeNode($child, $id, $change);
        }
    }

    private function ad(Request $r)
    {
        $url = parse_url($r->url());
        $path = $url['path'];
        parse_str($url['query'] ?? '', $query);
        $method = $r->method();
        $body = $r->data();
        $this->calls[] = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => $body];
        $s = &$this->state;
        $invalid = fn (string $field, string $msg) => Http::response(['error' => 'Bitte Eingaben prüfen.', 'fields' => [$field => $msg]], 422);
        $error = fn (int $status, string $msg) => Http::response(['error' => $msg], $status);
        if (! empty($s['down'])) {
            return $error(503, 'Samba startet neu.');
        }

        if ($path === '/api/setup') {
            if ($method === 'POST') {
                if ($s['setup']['state'] === 'provisioning') {
                    return $error(409, 'Die Einrichtung läuft schon.');
                }
                if (str_ends_with((string) $body['realm'], '.local')) {
                    return $invalid('realm', '.local ist für mDNS reserviert.');
                }
                $s['setup'] = ['state' => 'provisioning', 'error' => '', 'log' => ['samba-tool domain provision …']];

                return Http::response(['state' => 'provisioning'], 202);
            }

            return Http::response($s['setup'] + ['started_at' => 0, 'finished_at' => 0,
                'defaults' => ['hostname' => 'dc1', 'host_ips' => ['192.168.1.10', '10.0.0.5'], 'dns_forwarder' => '127.0.0.1'], 'domain' => null]);
        }
        // ntp with the compact clock (1.4.0)
        $ntp = $s['time'] === null ? $s['ntp'] : $s['ntp'] + ['time' => array_intersect_key($s['time'], array_flip(['now', 'offset_ms', 'source', 'synchronized', 'status', 'message']))];
        if ($path === '/api/status') {
            return Http::response(['version' => '1.0.0', 'state' => $s['setup']['state'],
                'samba' => ['state' => 'running', 'last_exit' => '', 'services' => ['ldap' => true, 'kerberos' => true, 'smb' => true, 'dns' => false]], 'ntp' => $ntp]);
        }
        // the clock, as in docs/api.md of PK95 AD 1.4.0
        if ($path === '/api/time') {
            if ($s['time'] === null) {
                return $error(404, 'Nicht gefunden.');
            }
            if ($method === 'PUT') {
                $servers = $body['servers'] ?? null;
                if (! is_array($servers) || count($servers) > 5) {
                    return Http::response(['error' => 'Bitte Eingaben prüfen.', 'fields' => ['servers' => 'Höchstens 5 Zeitserver.']], 422);
                }
                $fields = [];
                foreach (array_values($servers) as $n => $server) {
                    if (! is_string($server) || ! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$|^[0-9a-f:.]+$/i', $server)) {
                        $fields["servers.$n"] = 'Bitte einen Hostnamen oder eine IP-Adresse angeben.';
                    }
                }
                if ($fields) {
                    return Http::response(['error' => 'Bitte Eingaben prüfen.', 'fields' => $fields], 422);
                }
                $s['time']['servers'] = array_values($servers);
                if ($servers === []) {
                    $s['time'] = ['sources' => [], 'synchronized' => false, 'offset_ms' => null, 'source' => null, 'status' => 'unknown', 'message' => ''] + $s['time'];
                }
            }

            return Http::response($s['time']);
        }
        if ($s['setup']['state'] !== 'ready') {
            return $error(409, 'Die Domäne ist noch nicht eingerichtet.');
        }

        // domain
        if ($path === '/api/domain') {
            return Http::response(['realm' => 'ad.example.com', 'kerberos_realm' => 'AD.EXAMPLE.COM', 'netbios_domain' => 'EXAMPLE',
                'dc' => ['hostname' => 'dc1', 'fqdn' => 'dc1.ad.example.com', 'ip' => '192.168.1.10'], 'domain_level' => '2008_R2', 'forest_level' => '2008_R2',
                'samba_version' => '4.22.3-Debian', 'fsmo' => ['schema' => 'dc1', 'pdc' => 'dc1'], 'upn_suffixes' => ['ad.example.com'],
                'counts' => ['users' => count($s['users']), 'groups' => count($s['groups']), 'computers' => count($s['computers']), 'ous' => 3, 'gpos' => count($s['gpos'])],
                'dns_forwarder' => '127.0.0.1', 'dns_port' => 5300, 'ntp' => $ntp] + ($s['idmap'] !== null ? ['idmap' => $s['idmap']] : []));
        }
        if ($path === '/api/domain/dbcheck') {
            return $method === 'POST' ? Http::response(['running' => true], 202)
                : Http::response(['running' => false, 'fix' => false, 'started_at' => 1, 'finished_at' => 1762700012000, 'ok' => true, 'errors' => 0, 'output' => 'Checked 412 objects (0 errors)']);
        }
        if ($path === '/api/domain/password-policy') {
            $policy = ['min_length' => 7, 'complexity' => true, 'history_length' => 24, 'max_age_days' => 42, 'min_age_days' => 1,
                'lockout_threshold' => 0, 'lockout_duration_minutes' => 30, 'lockout_window_minutes' => 30];

            return Http::response($method === 'PUT' ? $body + $policy : $policy);
        }
        if ($path === '/api/domain/settings') {
            return Http::response(['dns_forwarder' => $body['dns_forwarder']]);
        }

        // OUs
        if ($path === '/api/ous') {
            if ($method === 'POST') {
                if (str_contains((string) $body['name'], ',')) {
                    return $invalid('name', 'Der Name darf kein Komma enthalten.');
                }
                $this->changeNode($s['tree'], $body['parent_id'], function (&$n) use ($body) {
                    $n['children'][] = ['id' => 'new-ou', 'name' => $body['name'], 'type' => 'ou', 'description' => $body['description'], 'path' => ltrim($n['path'].'/'.$body['name'], '/'),
                        'parent_id' => $n['id'], 'protected' => false, 'block_inheritance' => false, 'children' => [], 'dn' => ''];
                });

                return Http::response(['id' => 'new-ou'], 201);
            }

            return Http::response($s['tree']);
        }
        if (preg_match('#^/api/ous/([\w-]+)/gpo-inheritance$#', $path, $m)) {
            $node = $this->findNode($s['tree'], $m[1]);
            $items = [['precedence' => 1, 'gpo_id' => 'gp-1', 'name' => 'Default Domain Policy', 'from_id' => 'd0', 'from_name' => 'ad.example.com', 'enforced' => false, 'enabled' => true]];
            if ($m[1] === 'v1') {
                array_unshift($items, ['precedence' => 1, 'gpo_id' => 'gp-2', 'name' => 'Bildschirmsperre', 'from_id' => 'v1', 'from_name' => 'Vertrieb', 'enforced' => false, 'enabled' => true]);
                $items[1]['precedence'] = 2;
            }

            return Http::response(['block_inheritance' => $node['block_inheritance'], 'items' => $node['block_inheritance'] ? array_slice($items, 0, 1) : $items]);
        }
        if (preg_match('#^/api/ous/([\w-]+)$#', $path, $m)) {
            $node = $this->findNode($s['tree'], $m[1]);
            if ($node === null) {
                return $error(404, 'Nicht gefunden.');
            }
            if ($node['protected']) {
                return $error(403, 'Dieser Container ist geschützt.');
            }
            if ($method === 'DELETE') {
                if (($node['children'] || isset($s['ou_objects'][$m[1]])) && ($query['recursive'] ?? '') !== '1') {
                    return $error(409, 'Die Organisationseinheit ist nicht leer.');
                }
                $this->changeNode($s['tree'], $node['parent_id'], function (&$n) use ($m) {
                    $n['children'] = array_values(array_filter($n['children'], fn ($c) => $c['id'] !== $m[1]));
                });

                return Http::response(null, 204);
            }
            $this->changeNode($s['tree'], $m[1], function (&$n) use ($body) {
                $n = array_merge($n, array_intersect_key($body, array_flip(['name', 'description', 'block_inheritance'])));
            });

            return Http::response($this->findNode($s['tree'], $m[1]));
        }
        if ($path === '/api/move') {
            foreach ($body['ids'] as $id) {
                if (($s['users'][$id]['protected'] ?? false) || ($s['computers'][$id]['is_dc'] ?? false)) {
                    return $error(403, 'Eingebaute Objekte lassen sich nicht verschieben.');
                }
                if (isset($s['users'][$id])) {
                    $s['users'][$id]['ou_id'] = $body['target_id'];
                }
            }

            return Http::response(['moved' => count($body['ids'])]);
        }

        // users
        if ($path === '/api/users') {
            if ($method === 'POST') {
                if (strlen((string) $body['password']) < 7) {
                    return $invalid('password', 'Das Kennwort erfüllt die Kennwortrichtlinie nicht: mindestens 7 Zeichen.');
                }
                $s['users']['new-u'] = $this->user('new-u', $body['username'], $body['display_name'] ?: trim($body['given_name'].' '.$body['surname']), $body['ou_id'] ?? 'u1', 'Users');

                return Http::response($s['users']['new-u'], 201);
            }
            $items = array_values(array_filter($s['users'], fn ($u) => ($query['search'] ?? '') === '' || str_contains(strtolower($u['username'].' '.$u['display_name']), strtolower($query['search']))));

            return Http::response(['items' => $items, 'total' => count($items)]);
        }
        if (preg_match('#^/api/users/([\w-]+)(/(\w+))?$#', $path, $m)) {
            $id = $m[1];
            if (! isset($s['users'][$id])) {
                return $error(404, 'Nicht gefunden.');
            }
            $u = &$s['users'][$id];
            $action = $m[3] ?? '';
            if ($action === 'password') {
                return strlen((string) $body['password']) < 7 ? $invalid('password', 'Zu kurz.') : Http::response(null, 204);
            }
            if ($action === 'unlock') {
                $u['locked'] = false;

                return Http::response(null, 204);
            }
            if ($action === 'groups') {
                foreach ($s['members'] as $g => $members) {
                    $s['members'][$g] = array_values(array_diff($members, [$id]));
                    if (in_array($g, $body['group_ids'], true)) {
                        $s['members'][$g][] = $id;
                    }
                }

                return Http::response(['groups' => []]);
            }
            if ($method === 'DELETE') {
                if ($u['protected']) {
                    return $error(403, 'Das eingebaute Konto Administrator kann nicht gelöscht werden.');
                }
                unset($s['users'][$id]);

                return Http::response(null, 204);
            }
            if ($method === 'PUT') {
                if ($u['username'] === 'Administrator' && array_key_exists('enabled', $body) && ! $body['enabled']) {
                    return $error(403, 'Der Administrator kann nicht deaktiviert werden.');
                }
                $u = array_merge($u, $body);
            }
            $groups = array_values(array_map(fn ($g) => ['id' => $g, 'name' => $s['groups'][$g]['name'], 'scope' => 'global', 'type' => 'security', 'builtin' => false],
                array_keys(array_filter($s['members'], fn ($members) => in_array($id, $members, true)))));

            return Http::response($u + ['dn' => 'CN='.$u['display_name'], 'sid' => 'S-1-5-21-1-'.$id, 'created_at' => 1762600000000, 'changed_at' => 1, 'password_last_set' => 1762600000000,
                'bad_password_count' => 2, 'locked_at' => 0, 'profile_path' => '', 'home_directory' => '', 'home_drive' => '', 'script_path' => '',
                'primary_group' => ['id' => 'g-du', 'name' => 'Domain Users'], 'groups' => $groups]);
        }

        // groups
        if ($path === '/api/groups') {
            if ($method === 'POST') {
                $s['groups']['new-g'] = $this->group('new-g', $body['name']);
                $s['members']['new-g'] = $body['member_ids'];

                return Http::response($s['groups']['new-g'], 201);
            }

            return Http::response(['items' => array_values($s['groups']), 'total' => count($s['groups'])]);
        }
        if (preg_match('#^/api/groups/([\w-]+)(/members)?$#', $path, $m)) {
            $id = $m[1];
            $g = &$s['groups'][$id];
            if ($method === 'DELETE') {
                if ($g['protected']) {
                    return $error(403, 'Die eingebaute Gruppe Domain Admins kann nicht gelöscht werden.');
                }
                unset($s['groups'][$id]);

                return Http::response(null, 204);
            }
            if (! empty($m[2])) {
                if (in_array($id, $body['member_ids'], true)) {
                    return $invalid('member_ids.1', 'Das würde eine Schleife ergeben.');
                }
                $s['members'][$id] = $body['member_ids'];
            } elseif ($method === 'PUT') {
                $g = array_merge($g, $body);
            }
            $member = fn ($mid, $primary) => ['id' => $mid, 'name' => $s['users'][$mid]['display_name'] ?? $mid, 'username' => $s['users'][$mid]['username'] ?? '',
                'kind' => 'user', 'builtin' => false] + ($primary ? ['primary' => true] : []);
            $members = [...array_map(fn ($mid) => $member($mid, false), $s['members'][$id] ?? []), ...array_map(fn ($mid) => $member($mid, true), $s['primary'][$id] ?? [])];

            return Http::response($g + ['dn' => 'CN='.$g['name'], 'sid' => 'S-1-5-21-1-'.$id, 'members' => $members, 'member_of' => [], 'effective_users' => $members]);
        }

        // computers
        if ($path === '/api/computers') {
            return Http::response(['items' => array_values($s['computers']), 'total' => count($s['computers'])]);
        }
        if (preg_match('#^/api/computers/([\w-]+)$#', $path, $m)) {
            $c = &$s['computers'][$m[1]];
            if ($method !== 'GET' && $c['is_dc'] && ($method === 'DELETE' || array_key_exists('enabled', $body))) {
                return $error(403, 'Ein Domänencontroller kann nicht deaktiviert oder gelöscht werden.');
            }
            if ($method === 'DELETE') {
                unset($s['computers'][$m[1]]);

                return Http::response(null, 204);
            }
            if ($method === 'PUT') {
                $c = array_merge($c, $body);
            }

            return Http::response($c + ['dn' => '', 'sid' => 'S-1-5-21-1-'.$m[1], 'groups' => [['id' => 'g-dc', 'name' => 'Domain Computers']]]);
        }

        // GPOs
        if ($path === '/api/gpos/catalog') {
            return Http::response($this->catalog());
        }
        if ($path === '/api/gpos') {
            if ($method === 'POST') {
                $s['gpos']['gp-new'] = $this->gpo('gp-new', $body['name'], false, []);

                return Http::response($s['gpos']['gp-new'], 201);
            }

            return Http::response(array_values(array_map(fn ($g) => $this->withDriveCount($g), $s['gpos'])));
        }
        if (preg_match('#^/api/gpos/([\w-]+)/drives$#', $path, $m)) {
            return $this->drives($m[1], $method, $body);
        }
        if (preg_match('#^/api/gpos/([\w-]+)/(local-groups|printers|shortcuts)$#', $path, $m)) {
            return $this->preferenceList($m[2], $m[1], $method, $body);
        }
        if (preg_match('#^/api/gpos/([\w-]+)(/(settings|links)(/([\w-]+))?)?$#', $path, $m)) {
            $id = $m[1];
            if (! isset($s['gpos'][$id])) {
                return $error(404, 'Nicht gefunden.');
            }
            $gpo = &$s['gpos'][$id];
            $sub = $m[3] ?? '';
            if ($sub === 'settings') {
                $lock = $body['settings']['screen_lock_minutes'] ?? null;
                if ($lock !== null && (! is_int($lock) || $lock > 600)) {
                    return $invalid('settings.screen_lock_minutes', 'Bitte 1 bis 600 Minuten.');
                }
                $s['settings'][$id] = array_merge($s['settings'][$id] ?? [], $body['settings']);
            } elseif ($sub === 'links') {
                $target = $m[5] ?? $body['target_id'];
                if ($method === 'POST') {
                    $node = $this->findNode($s['tree'], $target);
                    $gpo['links'][] = ['target_id' => $target, 'target_name' => $node['name'], 'target_type' => $node['type'], 'target_path' => $node['path'], 'enabled' => true, 'enforced' => false, 'order' => 2];

                    return Http::response($gpo, 201);
                }
                foreach ($gpo['links'] as $i => $link) {
                    if ($link['target_id'] === $target) {
                        if ($method === 'DELETE') {
                            unset($gpo['links'][$i]);
                            $gpo['links'] = array_values($gpo['links']);

                            return Http::response(null, 204);
                        }
                        $gpo['links'][$i] = array_merge($link, $body);
                    }
                }
            } elseif ($method === 'DELETE') {
                if ($gpo['builtin']) {
                    return $error(403, 'Die Default Domain Policy kann nicht gelöscht werden.');
                }
                unset($s['gpos'][$id]);

                return Http::response(null, 204);
            } elseif ($method === 'PUT') {
                $gpo = array_merge($gpo, $body);
            }
            $settings = array_merge(array_fill_keys(array_column($this->catalog(), 'key'), null), $s['settings'][$id] ?? []);

            return Http::response($this->withDriveCount($gpo) + ['settings' => $settings, 'other_settings' => ['computer' => $id === 'gp-1' ? 4 : 0, 'user' => 0, 'extensions' => $id === 'gp-1' ? ['Sicherheit'] : []]]);
        }

        // DNS of the AD zones
        if ($path === '/api/dns/zones') {
            return Http::response([
                ['name' => '_msdcs.ad.example.com', 'partition' => 'forest', 'reverse' => false, 'record_count' => 1, 'system' => true],
                ['name' => 'ad.example.com', 'partition' => 'domain', 'reverse' => false, 'record_count' => count($s['records']), 'system' => false],
            ]);
        }
        if (preg_match('#^/api/dns/zones/([\w.-]+)/records(/(\w+))?$#', $path, $m)) {
            if ($m[1] === '_msdcs.ad.example.com') {
                return Http::response([['id' => 'ms1', 'zone' => $m[1], 'name' => 'gc._msdcs.ad.example.com', 'type' => 'A', 'value' => '192.168.1.10', 'ttl' => 900, 'priority' => 0, 'weight' => 0, 'port' => 0, 'dynamic' => false, 'system' => true]]);
            }
            $id = $m[3] ?? null;
            if ($id !== null && ($s['records'][$id]['system'] ?? false)) {
                return $error(403, 'Diesen Eintrag braucht der Domänencontroller.');
            }
            if ($method === 'DELETE') {
                unset($s['records'][$id]);

                return Http::response(null, 204);
            }
            if ($method === 'POST' || $method === 'PUT') {
                if (filter_var($body['value'], FILTER_VALIDATE_IP) === false && in_array($body['type'], ['A', 'AAAA'], true)) {
                    return $invalid('value', 'Bitte eine IP-Adresse angeben.');
                }
                unset($s['records'][$id]);
                $new = substr(md5(json_encode($body)), 0, 8);
                $s['records'][$new] = $body + ['id' => $new, 'zone' => $m[1], 'priority' => 0, 'weight' => 0, 'port' => 0, 'dynamic' => false, 'system' => false];

                return Http::response($s['records'][$new], $method === 'POST' ? 201 : 200);
            }

            return Http::response(array_values(array_filter($s['records'], fn ($rec) => ($query['type'] ?? '') === '' || $rec['type'] === $query['type'])));
        }

        // NETLOGON scripts
        if ($path === '/api/scripts') {
            return Http::response(array_values(array_map(fn ($name) => ['name' => $name, 'size' => strlen($s['scripts'][$name]), 'changed_at' => 1762690000000], array_keys($s['scripts']))));
        }
        if (preg_match('#^/api/scripts/(.+)$#', $path, $m)) {
            $name = rawurldecode($m[1]);
            if ($method === 'PUT') {
                if (! preg_match('/\.(bat|cmd|ps1|vbs|kix)$/', $name)) {
                    return $invalid('name', 'Erlaubt sind .bat, .cmd, .ps1, .vbs und .kix.');
                }
                $s['scripts'][$name] = $body['content'];

                return Http::response(['name' => $name]);
            }
            if ($method === 'DELETE') {
                unset($s['scripts'][$name]);

                return Http::response(null, 204);
            }

            return Http::response(['name' => $name, 'content' => $s['scripts'][$name], 'size' => strlen($s['scripts'][$name]), 'changed_at' => 1]);
        }

        return $error(404, 'Unbekannte Route '.$path);
    }

    /** @return array<string, mixed> the GPO with drive_count (PK95 AD 1.1.0) */
    private function withDriveCount(array $gpo): array
    {
        $gpo = $this->state['drives404'] ? $gpo : $gpo + ['drive_count' => count($this->state['drives'][$gpo['id']] ?? [])];
        if (! $this->state['gpp404']) {
            $count = fn (string $list) => count($this->state['gpp'][$list][$gpo['id']] ?? []);
            $gpo['preference_counts'] = ['drives' => $gpo['drive_count'] ?? 0, 'local_groups' => $count('local-groups'), 'printers' => $count('printers'), 'shortcuts' => $count('shortcuts')];
        }

        return $gpo;
    }

    /**
     * GET/PUT /api/gpos/{id}/local-groups|printers|shortcuts as in pk95ad docs/api.md → "Preferences: local groups,
     * printers, shortcuts" (1.3.0, a918dc9), with the field names and German messages of pk95ad/gpp.py. All field
     * errors of a PUT come back together, like the app's Fields.
     */
    private function preferenceList(string $list, string $id, string $method, array $body)
    {
        $s = &$this->state;
        if ($s['gpp404'] || ! isset($s['gpos'][$id])) {
            return Http::response(['error' => 'Nicht gefunden.'], 404);
        }
        if ($method !== 'PUT') {
            return Http::response(['items' => array_values($s['gpp'][$list][$id] ?? [])]);
        }
        $items = (array) ($body['items'] ?? []);
        $fields = [];
        $add = function (string $field, string $msg) use (&$fields) {
            $fields[$field] ??= $msg;
        };
        if (count($items) > 50) {
            return Http::response(['error' => 'Bitte Eingaben prüfen.', 'fields' => ['items' => 'Höchstens 50 Einträge.']], 422);
        }
        $stored = collect($s['gpp'][$list][$id] ?? [])->keyBy('uid');
        $accounts = array_column($s['users'], 'display_name', 'id') + array_column($s['groups'], 'name', 'id');
        $builtin = ['administrators', 'users', 'backup_operators', 'remote_desktop_users', 'network_configuration_operators', 'event_log_readers', 'remote_management_users'];
        [$uniqueField, $uniqueError, $unique] = match ($list) {
            'local-groups' => ['group', 'Diese lokale Gruppe steht schon in der Richtlinie.', fn ($v) => $v['group'] ?? null],
            'printers' => ['path', 'Dieser Drucker steht schon in der Richtlinie.', fn ($v) => mb_strtolower((string) ($v['path'] ?? ''))],
            'shortcuts' => ['name', 'Eine Verknüpfung mit diesem Namen gibt es hier schon.', fn ($v) => ($v['location'] ?? '').'|'.mb_strtolower((string) ($v['name'] ?? ''))],
        };
        $keys = [];
        $out = [];
        foreach ($items as $n => $item) {
            $name = "items.$n";
            $old = isset($item['uid']) ? $stored->get($item['uid']) : null;
            if ($old && $old['readonly']) {
                $out[] = $old;

                continue;
            }
            $target = $item['target'] ?? null;
            if ($target !== null) {
                if (! isset($s['groups'][$target['group_id'] ?? ''])) {
                    $add("$name.target.group_id", 'Die Gruppe gibt es nicht.');
                } else {
                    $target = ['group_id' => $target['group_id'], 'group_name' => $s['groups'][$target['group_id']]['name'], 'not' => (bool) ($target['not'] ?? false)];
                }
            }
            if ($list === 'local-groups') {
                if (! in_array($item['group'] ?? null, $builtin, true)) {
                    $add("$name.group", 'Erlaubt: '.implode(', ', $builtin).'.');
                }
                if (count($item['members'] ?? []) > 100) {
                    $add("$name.members", 'Höchstens 100 Mitglieder.');
                }
                $seen = [];
                $members = [];
                foreach ((array) ($item['members'] ?? []) as $j => $m) {
                    $mname = "$name.members.$j";
                    $action = $m['action'] ?? 'add';
                    if (! isset($accounts[$m['id'] ?? ''])) {
                        $add("$mname.id", 'Das Konto gibt es nicht.');
                    } elseif (! in_array($action, ['add', 'remove'], true)) {
                        $add("$mname.action", 'Erlaubt: add, remove.');
                    } elseif (in_array($m['id'], $seen, true)) {
                        $add("$mname.id", 'Dieses Konto steht schon in der Liste.');
                    } elseif ($item['group'] === 'administrators' && $m['id'] === 'g-da' && $action === 'remove') {
                        $seen[] = $m['id'];
                        $add("$mname.action", 'Domain Admins kann nicht aus den lokalen Administratoren entfernt werden.');
                    } else {
                        $seen[] = $m['id'];
                        $members[] = ['id' => $m['id'], 'name' => $accounts[$m['id']], 'kind' => isset($s['groups'][$m['id']]) ? 'group' : 'user', 'action' => $action];
                    }
                }
                // never lock out: with a switch on, the administrators keep Domain Admins (first, like the app)
                if ($item['group'] === 'administrators' && (($item['remove_other_users'] ?? false) || ($item['remove_other_groups'] ?? false)) && ! in_array('g-da', $seen, true)) {
                    array_unshift($members, ['id' => 'g-da', 'name' => 'Domain Admins', 'kind' => 'group', 'action' => 'add']);
                }
                $item = ['group' => $item['group'], 'members' => $members,
                    'remove_other_users' => (bool) ($item['remove_other_users'] ?? false), 'remove_other_groups' => (bool) ($item['remove_other_groups'] ?? false)];
            }
            if ($list === 'printers') {
                if (! preg_match('#^\\\\\\\\[^\\\\]+\\\\[^\\\\]+$#', (string) ($item['path'] ?? ''))) {
                    $add("$name.path", 'Bitte die Freigabe angeben: \\\\server\\drucker.');
                }
                $item = ['path' => (string) $item['path'], 'action' => $item['action'] ?? 'update', 'default' => (bool) ($item['default'] ?? false)];
            }
            if ($list === 'shortcuts') {
                if (! preg_match('/^[^\\\\\/:*?"<>|\x00-\x1f]{1,100}$/u', (string) ($item['name'] ?? ''))) {
                    $add("$name.name", 'Name mit 1–100 Zeichen, ohne \\ / : * ? " < > |.');
                }
                $isUrl = ($item['target_type'] ?? '') === 'url';
                if ($isUrl && ! preg_match('#^https?://\S+$#', (string) ($item['target_path'] ?? ''))) {
                    $add("$name.target_path", 'Bitte eine Adresse angeben (https://…).');
                } elseif (! $isUrl && ! str_starts_with((string) ($item['target_path'] ?? ''), '\\\\')) {
                    $add("$name.target_path", 'Bitte einen UNC-Pfad angeben, z. B. \\\\server\\freigabe\\datei.');
                }
                if (! in_array($item['location'] ?? '', ['desktop', 'start_menu', 'programs'], true)) {
                    $add("$name.location", 'Erlaubt: desktop, start_menu, programs.');
                }
                if ($isUrl && ($item['arguments'] ?? '') !== '') {
                    $add("$name.arguments", 'Nur bei Verknüpfungen auf Dateien.');
                }
                if (! is_int($item['icon_index'] ?? 0) || $item['icon_index'] < 0 || $item['icon_index'] > 255) {
                    $add("$name.icon_index", 'Bitte eine Zahl von 0 bis 255.');
                }
                $item = array_intersect_key($item, array_flip(['name', 'target_type', 'target_path', 'location', 'arguments', 'icon_path', 'icon_index', 'action']));
            }
            $key = $unique($item);
            if ($key !== null && in_array($key, $keys, true)) {
                $add("$name.$uniqueField", $uniqueError);
            }
            $keys[] = $key;
            $out[] = ['uid' => $old ? $old['uid'] : '{NEW-'.$n.'}'] + $item + ['target' => $target, 'changed_at' => 1763000000000, 'readonly' => false, 'warning' => ''];
        }
        if ($fields) {
            return Http::response(['error' => 'Bitte Eingaben prüfen.', 'fields' => $fields], 422);
        }
        $s['gpp'][$list][$id] = $out;
        $s['gpos'][$id]['version'][$list === 'local-groups' ? 'computer' : 'user']++;

        return Http::response(['items' => $out, 'version' => $s['gpos'][$id]['version']]);
    }

    /** GET/PUT /api/gpos/{id}/drives with the rules of docs/api.md -> Drive maps */
    private function drives(string $id, string $method, array $body)
    {
        $s = &$this->state;
        if ($s['drives404'] || ! isset($s['gpos'][$id])) {
            return Http::response(['error' => 'Nicht gefunden.'], 404);
        }
        $stored = collect($s['drives'][$id] ?? [])->keyBy('uid');
        if ($method === 'PUT') {
            $items = (array) ($body['items'] ?? []);
            $invalid = fn (string $field, string $msg) => Http::response(['error' => 'Bitte Eingaben prüfen.', 'fields' => [$field => $msg]], 422);
            if (count($items) > 50) {
                return $invalid('items', 'Höchstens 50 Netzlaufwerke.');
            }
            $letters = [];
            $out = [];
            foreach ($items as $n => $item) {
                $old = isset($item['uid']) ? $stored->get($item['uid']) : null;
                if ($old && $old['readonly']) {
                    $out[] = $old; // read-only items stay as they are

                    continue;
                }
                if (! str_starts_with((string) ($item['path'] ?? ''), '\\\\')) {
                    return $invalid("items.$n.path", 'Bitte einen UNC-Pfad wie \\\\server\\freigabe angeben.');
                }
                if (! $item['first_available']) {
                    if (in_array($item['letter'], $letters, true)) {
                        return $invalid("items.$n.letter", 'Der Buchstabe '.$item['letter'].': ist in dieser Gruppenrichtlinie schon vergeben.');
                    }
                    $letters[] = $item['letter'];
                }
                $target = $item['target'];
                if ($target !== null) {
                    if (! isset($s['groups'][$target['group_id']])) {
                        return $invalid("items.$n.target.group_id", 'Die Gruppe gibt es nicht.');
                    }
                    $target['group_name'] = $s['groups'][$target['group_id']]['name'];
                }
                $out[] = ['uid' => $old ? $old['uid'] : '{NEW-'.$n.'}'] + $item + ['changed_at' => 1763000000000, 'readonly' => false, 'warning' => ''];
                $out[array_key_last($out)]['target'] = $target;
            }
            $s['drives'][$id] = $out;
            $s['gpos'][$id]['version']['user']++;

            return Http::response(['items' => $out, 'version' => $s['gpos'][$id]['version']]);
        }

        return Http::response(['items' => array_values($s['drives'][$id] ?? [])]);
    }

    private function dns(Request $r)
    {
        $this->dnsCalls[] = ['method' => $r->method(), 'path' => parse_url($r->url(), PHP_URL_PATH), 'body' => $r->data()];
        if ($r->method() === 'PUT' && $this->dnsRefuses) {
            return Http::response(['error' => 'Bitte Eingaben prüfen.', 'fields' => ['conditional.1.upstream' => 'Upstream nicht erlaubt.']], 422);
        }
        if ($r->method() === 'PUT') {
            $this->conditional = $r->data()['conditional'];
        }

        return Http::response(['upstreams' => ['9.9.9.9'], 'conditional' => $this->conditional]);
    }

    /** @return list<array<string, mixed>> */
    private function sent(string $method, string $path): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === $method && $c['path'] === $path));
    }

    private function dnsPuts(): int
    {
        return count(array_filter($this->dnsCalls, fn ($c) => $c['method'] === 'PUT'));
    }

    private function installDns(): InstalledApp
    {
        return InstalledApp::factory()->create(['app_id' => 'dns', 'version' => '1.1.0']);
    }

    public function test_catalog_entry_runs_on_the_host_network(): void
    {
        $app = (new Catalog(base_path('catalog')))->get('ad');

        $this->assertSame('http://host.docker.internal:8389', $app->baseUrl());
        $this->assertSame('ad', $app->module);
        $spec = ContainerSpec::for($app, 'latest', str_repeat('t', 48));
        $this->assertSame('host', $spec['HostConfig']['NetworkMode']);
        $this->assertContains('PK95AD_DNS_PORT=5300', $spec['Env']);
        $this->assertSame(['/var/lib/samba', '/etc/samba', '/var/lib/pk95ad'], array_column($spec['HostConfig']['Mounts'], 'Target'));
        $this->assertSame(['1.0.0', '2.0.0'], [(new AdModule)->minVersion(), (new AdModule)->maxVersion()]);
    }

    public function test_every_page_renders_with_app_data(): void
    {
        $this->installDns();
        $expect = [
            'domain' => ['ad.example.com', 'dc1.ad.example.com', 'Kennwortrichtlinie', 'Checked 412 objects'],
            'users' => ['Max Muster', 'max@example.com', 'Gesperrt', 'Entsperren', 'Built-in', 'Firma/Vertrieb'],
            'groups' => ['Domain Admins', 'Vertrieb', 'Sicherheitsgruppe', 'Global'],
            'computers' => ['PC-01', 'Windows 11 Pro', 'pc-01.ad.example.com', 'DC'],
            'ous' => ['Firma', 'Vertrieb', 'Domain Controllers', 'Container'],
            'gpo' => ['Default Domain Policy', 'Bildschirmsperre', 'Firma/Vertrieb', 'Vererbung', '{GP-1}'],
            'dns' => ['nas.ad.example.com', '192.168.1.20', 'System', 'dynamisch', '_msdcs.ad.example.com'],
            'scripts' => ['logon.bat', '\\\\ad.example.com\\NETLOGON'],
        ];
        foreach ($expect as $page => $texts) {
            $response = $this->get(route('apps.page', [$this->installed, $page]))->assertOk();
            foreach ($texts as $text) {
                $response->assertSee($text, false);
            }
            $response->assertDontSee('data-ad-not-ready', false)->assertDontSee('@js(', false);
        }
    }

    public function test_pages_point_to_the_wizard_until_the_domain_is_ready(): void
    {
        $this->state['setup']['state'] = 'unconfigured';

        foreach (['users', 'groups', 'computers', 'ous', 'gpo', 'dns', 'scripts'] as $page) {
            $this->get(route('apps.page', [$this->installed, $page]))->assertOk()
                ->assertSee('Die Domäne ist noch nicht eingerichtet')
                ->assertSee(route('apps.page', [$this->installed, 'domain']), false)
                ->assertDontSee('PK95 AD antwortet nicht');
        }
        $this->get(route('apps.page', [$this->installed, 'domain']))->assertOk()
            ->assertSee('Domäne einrichten')->assertSee('192.168.1.10')->assertSee('10.0.0.5');
    }

    public function test_setup_wizard_posts_polls_and_adds_the_dns_forwarding(): void
    {
        $this->installDns();
        $this->state['setup']['state'] = 'unconfigured';

        $page = Livewire::test(Domain::class, ['installedApp' => $this->installed])
            ->assertSet('setupForm.hostname', 'dc1')->assertSet('setupForm.host_ip', '192.168.1.10')
            ->set('setupForm.realm', ' AD.Example.COM ')->assertSet('setupForm.realm', 'ad.example.com')->assertSet('setupForm.netbios_domain', 'AD')
            ->set('setupForm.netbios_domain', 'example')->set('setupForm.hostname', 'DC1')->set('setupForm.admin_password', 'Geheim-2026!')
            ->call('startSetup')
            ->assertDispatched('notice', kind: 'success', text: 'Die Domäne wird eingerichtet. Das dauert etwa eine Minute.')
            ->assertSet('watching', true)->assertSet('setupForm.admin_password', '')
            ->assertSee('Die Domäne wird eingerichtet (etwa 30 bis 90 Sekunden)')->assertSee('samba-tool domain provision');
        $this->assertSame(['realm' => 'ad.example.com', 'netbios_domain' => 'EXAMPLE', 'hostname' => 'dc1', 'host_ip' => '192.168.1.10',
            'dns_forwarder' => '127.0.0.1', 'admin_password' => 'Geheim-2026!'], $this->sent('POST', '/api/setup')[0]['body']);
        $this->assertSame([], $this->dnsCalls, 'no forwarding before the domain is ready');

        // the poll finds it ready: the realm goes to PK95 DNS once, the existing rule stays
        $this->state['setup']['state'] = 'ready';
        $page->call('$refresh')->assertSet('watching', false)->assertSee('dc1.ad.example.com')->assertSee('127.0.0.1:5300');
        $this->assertSame([['domain' => 'fritz.box', 'upstream' => '192.168.178.1'], ['domain' => 'ad.example.com', 'upstream' => '127.0.0.1:5300']], $this->conditional);
        $this->assertSame(1, $this->dnsPuts());

        // idempotent: the button and the next polls do not write again
        $page->call('$refresh')->call('addDnsForward')->assertDispatched('notice', kind: 'success', text: 'In PK95 DNS eingetragen: ad.example.com → 127.0.0.1:5300');
        $this->assertSame(1, $this->dnsPuts());
        $this->assertCount(2, $this->conditional);
    }

    public function test_setup_errors_and_failed_state(): void
    {
        $this->state['setup']['state'] = 'unconfigured';
        Livewire::test(Domain::class, ['installedApp' => $this->installed])
            ->set('setupForm.realm', 'firma.local')->set('setupForm.admin_password', 'x')->call('startSetup')
            ->assertHasErrors('setupForm.realm')->assertSet('watching', false)->assertSet('setupForm.admin_password', '');

        $this->state['setup'] = ['state' => 'failed', 'error' => 'Der Hostname ist schon vergeben.', 'log' => ['ERROR: dc1 exists']];
        $this->get(route('apps.page', [$this->installed, 'domain']))->assertOk()
            ->assertSee('Die Einrichtung ist fehlgeschlagen')->assertSee('Der Hostname ist schon vergeben.')->assertSee('ERROR: dc1 exists')
            ->assertSee('Domäne einrichten');
    }

    public function test_domain_page_links_to_the_forwarding_in_pk95_dns(): void
    {
        $this->get(route('apps.page', [$this->installed, 'domain']))->assertOk()->assertDontSee('data-dns-forward-link', false);

        $dns = $this->installDns();
        $this->conditional[] = ['domain' => 'ad.example.com', 'upstream' => '127.0.0.1:5300'];
        $this->get(route('apps.page', [$this->installed, 'domain']))->assertOk()
            ->assertSee('Eingetragen:')->assertSee('In PK95 DNS ansehen')
            ->assertSee(route('apps.page', [$dns, 'records']).'#weiterleitungen', false);
    }

    public function test_dns_forwarding_states(): void
    {
        // no PK95 DNS: the needed entry is shown as a hint
        $this->assertSame('no_dns', DnsForward::state('ad.example.com', 5300)['state']);
        $this->get(route('apps.page', [$this->installed, 'domain']))->assertOk()
            ->assertSee('PK95 DNS ist nicht installiert')->assertSee('192.168.1.10:5300');

        $this->installDns();
        $this->assertSame('missing', DnsForward::state('ad.example.com', 5300)['state']);

        // a rule the user set up for the realm stays as it is (also written with a trailing dot)
        $this->conditional[] = ['domain' => 'AD.example.com.', 'upstream' => '192.168.1.10:5300'];
        $state = DnsForward::ensure('ad.example.com', 5300);
        $this->assertSame(['other', '192.168.1.10:5300'], [$state['state'], $state['current']]);
        $this->assertSame(0, $this->dnsPuts());
        $this->get(route('apps.page', [$this->installed, 'domain']))->assertOk()->assertSee('schon eine Weiterleitung');
    }

    public function test_users_create_edit_password_and_protected_builtins(): void
    {
        $page = Livewire::test(Users::class, ['installedApp' => $this->installed])
            ->call('create')->assertSet('form.must_change_password', true)
            ->set('form.username', 'erika')->set('form.given_name', 'Erika')->set('form.surname', 'Muster')
            ->set('form.ou_id', 'v1')->set('form.group_ids', ['g-v'])->set('form.password', 'kurz')->call('save')
            ->assertHasErrors('form.password')->assertSet('form.password', '')
            ->set('form.password', 'Lang-genug-1')->set('form.expires', '2027-01-31')->call('save')
            ->assertSet('editing', null)->assertDispatched('notice', kind: 'success', text: 'Benutzer erika angelegt.');
        $body = last($this->sent('POST', '/api/users'))['body'];
        $this->assertSame(['erika', 'v1', ['g-v'], true, 'Lang-genug-1'], [$body['username'], $body['ou_id'], $body['group_ids'], $body['must_change_password'], $body['password']]);
        $this->assertSame(Carbon::parse('2027-01-31', Format::timezone())->endOfDay()->getTimestampMs(), $body['expires_at']);

        // edit sends only what changed, moves with /api/move and replaces the groups
        $page->call('edit', 'm-1')->assertSet('form.username', 'max')->assertSet('form.ou_id', 'v1')
            ->set('form.description', 'Außendienst')->set('form.ou_id', 'f1')->set('form.group_ids', ['g-v'])->call('save')
            ->assertSet('editing', null)->assertDispatched('notice', kind: 'success', text: 'Benutzer gespeichert.');
        $this->assertSame(['description' => 'Außendienst'], last($this->sent('PUT', '/api/users/m-1'))['body']);
        $this->assertSame(['ids' => ['m-1'], 'target_id' => 'f1'], last($this->sent('POST', '/api/move'))['body']);
        $this->assertSame(['group_ids' => ['g-v']], last($this->sent('PUT', '/api/users/m-1/groups'))['body']);

        // nothing changed: nothing written
        $writes = count($this->sent('PUT', '/api/users/m-1')) + count($this->sent('PUT', '/api/users/m-1/groups'));
        $page->call('edit', 'm-1')->call('save')->assertSet('editing', null);
        $this->assertSame($writes, count($this->sent('PUT', '/api/users/m-1')) + count($this->sent('PUT', '/api/users/m-1/groups')));

        $page->call('unlock', 'm-1')->assertDispatched('notice', kind: 'success', text: 'Konto entsperrt.');
        $page->call('password', 'm-1', 'max')->set('passwordForm.password', 'abc')->call('savePassword')->assertHasErrors('passwordForm.password')
            ->set('passwordForm.password', 'Neu-und-lang-1')->call('savePassword')->assertSet('passwordFor', null);
        $this->assertSame(['password' => 'Neu-und-lang-1', 'must_change_password' => true], last($this->sent('POST', '/api/users/m-1/password'))['body']);

        // 403 for the built-in administrator: the app's message as a toast
        $page->call('delete', 'a-1')->assertDispatched('notice', kind: 'error', text: 'Das eingebaute Konto Administrator kann nicht gelöscht werden.');
        $page->call('toggle', 'a-1', true)->assertDispatched('notice', kind: 'error', text: 'Der Administrator kann nicht deaktiviert werden.');
        $this->assertArrayHasKey('a-1', $this->state['users']);
        $page->call('edit', 'a-1')->assertSee('Ein eingebautes Konto');
    }

    public function test_groups_members_and_protected_builtins(): void
    {
        $page = Livewire::test(Groups::class, ['installedApp' => $this->installed])
            ->call('edit', 'g-v')->assertSet('form.member_ids', [])
            ->set('form.member_ids', ['m-1', 'g-v'])->call('save')->assertHasErrors('form.member_ids')
            ->set('form.member_ids', ['m-1'])->set('form.description', 'Team Vertrieb')->call('save')->assertSet('editing', null);
        $this->assertSame(['description' => 'Team Vertrieb'], last($this->sent('PUT', '/api/groups/g-v'))['body']);
        $this->assertSame(['m-1'], $this->state['members']['g-v']);

        // built-in: name locked in the form, delete refused by the app
        $page->call('edit', 'g-da')->assertSee('Eine eingebaute Gruppe')->call('cancel')
            ->call('delete', 'g-da')->assertDispatched('notice', kind: 'error', text: 'Die eingebaute Gruppe Domain Admins kann nicht gelöscht werden.');

        $page->call('create')->set('form.name', 'Technik')->set('form.member_ids', ['m-1'])->call('save')->assertSet('editing', null);
        $this->assertSame(['name' => 'Technik', 'description' => '', 'email' => '', 'type' => 'security', 'scope' => 'global', 'member_ids' => ['m-1']],
            last($this->sent('POST', '/api/groups'))['body']);
    }

    public function test_computers_and_dc_lock(): void
    {
        $page = Livewire::test(Computers::class, ['installedApp' => $this->installed])
            ->call('toggle', 'pc-1', true)->assertDispatched('notice', kind: 'success', text: 'Computer deaktiviert.')
            ->call('edit', 'pc-1')->set('form.description', 'Empfang')->call('save')->assertSet('editing', null);
        $this->assertSame(['description' => 'Empfang'], last($this->sent('PUT', '/api/computers/pc-1'))['body']);
        $page->call('delete', 'pc-dc')->assertDispatched('notice', kind: 'error', text: 'Ein Domänencontroller kann nicht deaktiviert oder gelöscht werden.');
        $page->call('edit', 'pc-dc')->assertSee('Ein Domänencontroller bleibt aktiv');
    }

    public function test_ous_create_edit_inheritance_and_delete_with_conflict(): void
    {
        $page = Livewire::test(Ous::class, ['installedApp' => $this->installed])
            ->call('create', 'f1')->set('form.name', 'Ein,Komma')->call('save')->assertHasErrors('form.name')
            ->set('form.name', 'Technik')->call('save')->assertSet('editing', null);
        $this->assertSame(['name' => 'Technik', 'parent_id' => 'f1', 'description' => ''], last($this->sent('POST', '/api/ous'))['body']);

        $page->call('edit', 'v1')->assertSet('form.parent_id', 'f1')->set('form.block_inheritance', true)->call('save')->assertSet('editing', null);
        $this->assertSame(['block_inheritance' => true], last($this->sent('PUT', '/api/ous/v1'))['body']);
        $page->call('inheritance', 'v1')->assertSee('Vererbung blockiert')->assertSee('Bildschirmsperre')->call('cancel');

        // 409 "not empty": the dialog offers the recursive delete
        $page->call('confirmDelete', 'v1')->call('delete')->assertHasErrors('recursive')->assertSet('deleting', 'v1')
            ->set('recursive', true)->call('delete')->assertSet('deleting', null);
        $this->assertSame(['recursive' => '1'], last($this->sent('DELETE', '/api/ous/v1'))['query']);

        // a protected container: 403 from the app
        $page->call('confirmDelete', 'u1')->call('delete')->assertDispatched('notice', kind: 'error', text: 'Dieser Container ist geschützt.');
    }

    public function test_gpo_settings_dialog_has_tabs_sections_and_compact_rows(): void
    {
        $page = Livewire::test(Gpos::class, ['installedApp' => $this->installed])
            ->call('openSettings', 'gp-2')
            // opens where something is configured; tabs count the configured entries
            ->assertSet('settingsTab', 'user')
            // two tabs; "Benutzer" counts its settings (1) and the drive maps (2)
            ->assertSeeHtml('data-tab="computer"')->assertSeeHtml('data-tab="user"')->assertDontSeeHtml('data-tab="drives"')
            ->assertSeeInOrder(['Computer', '0', 'Benutzer', '3'])
            // sections by catalog group with "n von m konfiguriert", rows with a status badge and the editor
            ->assertSee('Desktop')->assertSee('1 von 2 konfiguriert')->assertSee('0 von 1 konfiguriert')
            ->assertSee('Bildschirmsperre nach Inaktivität')->assertSee('10 Minuten')->assertSee('Nicht konfiguriert')
            ->assertSee('Startet den Bildschirmschoner mit Kennwortschutz. 1 bis 600 Minuten.')
            ->assertSeeHtml('data-segments')->assertSee('Aktiviert')->assertSee('Deaktiviert')->assertSee('Skript hinzufügen')
            ->assertDontSee('Automatische Updates')->assertSee('Keine Änderungen');

        $page->set('settingsTab', 'computer')->assertSee('Windows Update')->assertSee('Automatische Updates')->assertSee('Automatisch installieren')
            ->assertSeeHtml('<textarea')->assertDontSee('Bildschirmsperre nach Inaktivität')
            ->set('settingsTab', 'bogus')->assertSet('settingsTab', 'computer');

        // filter: search in label and help, only configured
        $page->set('settingsTab', 'user')->set('settingsSearch', 'eingabe')
            ->assertSee('Eingabeaufforderung sperren')->assertDontSee('Bildschirmsperre nach Inaktivität')
            ->set('settingsSearch', 'kennwortschutz')->assertSee('Bildschirmsperre nach Inaktivität')
            ->set('settingsSearch', '')->set('onlyConfigured', true)->assertSee('Bildschirmsperre nach Inaktivität')->assertDontSee('Eingabeaufforderung sperren')
            ->set('onlyConfigured', false)->set('settingsSearch', 'gibt es nicht')->assertSee('Keine Einstellung passt zum Filter.')
            ->set('settingsSearch', '');

        // changes are counted and marked; Verwerfen goes back
        $page->set('settings.deny_cmd', '1')->assertSee('1 Änderung')->assertSeeHtml('data-changed')
            ->set('settings.screen_lock_minutes', '')->assertSee('2 Änderungen')->assertSee('Verwerfen')
            ->call('discardSettings')->assertSet('settings.deny_cmd', '')->assertSet('settings.screen_lock_minutes', '10')
            ->assertSee('Keine Änderungen')->assertDontSeeHtml('data-changed');

        $item = ['type' => 'int', 'unit' => 'Uhr'];
        $this->assertSame(['3 Uhr', 'blue'], Gpos::settingStatus($item, '3'));
        $this->assertSame(['Nicht konfiguriert', 'gray'], Gpos::settingStatus($item, ''));
        $this->assertSame(['Deaktiviert', 'red'], Gpos::settingStatus(['type' => 'bool'], '0'));
        $this->assertSame(['Füllen', 'blue'], Gpos::settingStatus(['type' => 'enum', 'options' => [['value' => 'fill', 'label' => 'Füllen']]], 'fill'));
        $this->assertSame(['Dienstag', 'blue'], Gpos::settingStatus(['type' => 'enum', 'options' => [['value' => 2, 'label' => 'Dienstag']]], '2'));
        $this->assertSame(['2 Skripte', 'blue'], Gpos::settingStatus(['type' => 'scripts'], [['path' => 'a.bat'], ['path' => 'b.bat'], ['path' => ' ']]));
    }

    public function test_gpo_settings_show_the_windows_editions_of_pk95_ad_1_2(): void
    {
        $this->assertSame('nur Enterprise/Education', Gpos::editionsLabel(['education', 'enterprise']));
        $this->assertSame('nur Pro', Gpos::editionsLabel(['pro', 'unknown']));
        $this->assertSame('ab Pro', Gpos::editionsLabel(['education', 'pro', 'enterprise']));
        $this->assertSame('nur Home/Pro', Gpos::editionsLabel(['pro', 'home']), 'in edition order');
        $this->assertNull(Gpos::editionsLabel(null), 'missing: all editions');
        $this->assertNull(Gpos::editionsLabel([]));
        $this->assertNull(Gpos::editionsLabel(['home', 'pro', 'enterprise', 'education']));
        $this->assertSame('Sicherheit (nur Enterprise)', Gpos::optionLabel(['label' => 'Sicherheit', 'editions' => ['enterprise']]));
        $this->assertSame('Sicherheit (nur Enterprise)', Gpos::optionLabel(['label' => 'Sicherheit (nur Enterprise)', 'editions' => ['enterprise']]), 'not twice');

        $page = Livewire::test(Gpos::class, ['installedApp' => $this->installed])->call('openSettings', 'gp-2')->set('settingsTab', 'computer');
        // a badge on the restricted entry only (the rendered HTML: the JSON of the response escapes "/")
        $html = $page->html();
        $this->assertSame(1, substr_count($html, 'data-editions '));
        $this->assertMatchesRegularExpression('#Microsoft Store abschalten(\s|<!--.*?-->)*<span[^>]*data-editions[^>]*>nur Enterprise/Education</span>#', $html);
        $this->assertStringContainsString('>Sicherheit (nur Enterprise/Education)</option>', $html);
        $this->assertStringContainsString('>Erforderlich</option>', $html);
        $this->assertStringContainsString('>Alle Editionen</option>', $html);
        // a hint under the select while a restricted option is chosen
        $page->assertDontSeeHtml('data-option-editions')
            ->set('settings.telemetry_level', '0')->assertSeeHtml('data-option-editions')->assertSee('Dieser Wert wirkt nicht auf allen Windows-Editionen (nur Enterprise/Education)')
            ->set('settings.telemetry_level', '1')->assertDontSeeHtml('data-option-editions');
    }

    public function test_gpo_settings_save_sends_only_the_changes(): void
    {
        // a 422 on a field of the other tab switches there
        $page = Livewire::test(Gpos::class, ['installedApp' => $this->installed])
            ->call('openSettings', 'gp-2')->set('settings.screen_lock_minutes', '900')->set('settingsTab', 'computer')
            ->call('saveSettings')->assertHasErrors('settings.screen_lock_minutes')->assertSet('settingsFor', 'gp-2')->assertSet('settingsTab', 'user')
            ->assertSee('Bitte 1 bis 600 Minuten.');

        $page->set('settings.screen_lock_minutes', '')->set('settings.deny_cmd', '1')->set('settings.windows_update_mode', 'auto')
            ->set('settings.logon_banner_text', "Nur für Mitarbeiter.\nDanke.")
            ->call('addScript', 'logon_scripts')->call('addScript', 'logon_scripts')
            ->set('settings.logon_scripts.0.path', 'logon.bat')->set('settings.logon_scripts.0.parameters', '/q')
            ->call('saveSettings')->assertHasNoErrors()->assertSet('settingsFor', null);
        $this->assertSame(['settings' => [
            'screen_lock_minutes' => null,
            'deny_cmd' => true,
            'logon_scripts' => [['path' => 'logon.bat', 'parameters' => '/q']],
            'windows_update_mode' => 'auto',
            'logon_banner_text' => "Nur für Mitarbeiter.\nDanke.",
        ]], last($this->sent('PUT', '/api/gpos/gp-2/settings'))['body'], 'only changed keys; the empty script row is dropped; empty = null');

        // settings the Windows tools wrote are reported (gp-1: nothing configured, computer tab)
        $page->call('openSettings', 'gp-1')->assertSet('settingsTab', 'computer')->assertSee('Weitere Einstellungen')->assertSee('Sicherheit');
        $this->assertNull(Gpos::toApi(['type' => 'bool'], ''));
        $this->assertFalse(Gpos::toApi(['type' => 'bool'], '0'));
        $this->assertSame(5, Gpos::toApi(['type' => 'int'], ' 5 '));
    }

    public function test_drive_maps_tab_lists_audience_hint_and_read_only_items(): void
    {
        $this->get(route('apps.page', [$this->installed, 'gpo']))->assertOk()->assertSee('2 Netzlaufwerke');

        Livewire::test(Gpos::class, ['installedApp' => $this->installed])
            ->call('openSettings', 'gp-2')->assertSet('gppSupported.drives', true)->assertCount('gpp.drives', 2)
            // a section of "Benutzer", open with entries, not on "Computer"
            ->assertSet('settingsTab', 'user')->assertSeeHtml('data-gpp-table')->assertSee('Einstellungen (Laufwerkszuordnungen, Drucker, Verknüpfungen)')->assertSee('2 Laufwerke')
            ->set('settingsTab', 'computer')->assertDontSeeHtml('data-gpp-section="drives"')->assertSeeHtml('data-gpp-section="local_groups"')
            ->set('settingsTab', 'user')->assertSeeHtml('data-gpp-table')
            ->assertSeeInOrder(['Laufwerk', 'Pfad', 'Bezeichnung', 'Aktion'])->assertSee('Gilt für')
            ->assertSee('Mitglieder von Vertrieb')->assertSee('Alle Benutzer')
            // the hint is a tooltip now
            ->assertSeeHtml('data-gpp-hint')->assertSee('Fileserver oder NAS')->assertSee('Kennwörter für Laufwerke speichert PK95 AD bewusst nicht')
            ->assertSee('\\nas.example.com\vertrieb')
            ->assertSee('Enthält ein gespeichertes Kennwort (unsicher, MS14-025). Bitte in der GPMC entfernen.')->assertSeeHtml('data-gpp-readonly')
            ->call('editGpp', 'drives', 1)->assertSet('gppEditing', null)
            ->call('saveSettings')->assertSet('settingsFor', null);
        $this->assertSame([], $this->sent('PUT', '/api/gpos/gp-2/drives'));
        $this->assertSame([], $this->sent('PUT', '/api/gpos/gp-2/settings'));
    }

    public function test_drive_maps_section_follows_search_and_only_configured(): void
    {
        $page = Livewire::test(Gpos::class, ['installedApp' => $this->installed])->call('openSettings', 'gp-2')
            ->set('settingsSearch', 'laufwerk')->assertSeeHtml('data-gpp-section')->assertDontSee('Bildschirmsperre nach Inaktivität')
            ->set('settingsSearch', 'netz')->assertSeeHtml('data-gpp-section')
            ->set('settingsSearch', 'vertrieb')->assertSeeHtml('data-gpp-section')
            ->set('settingsSearch', 'v:')->assertSeeHtml('data-gpp-section')
            ->set('settingsSearch', 'kennwortschutz')->assertDontSeeHtml('data-gpp-section')->assertSee('Bildschirmsperre nach Inaktivität')
            ->set('settingsSearch', '')->set('onlyConfigured', true)->assertSeeHtml('data-gpp-section');

        // without drive maps: "Nur konfigurierte" hides the section, the count says 0
        $this->state['drives']['gp-2'] = [];
        $page->call('openSettings', 'gp-2')->assertSet('onlyConfigured', false)->assertSee('0 Laufwerke')->assertSeeInOrder(['Benutzer', '1'])
            ->set('onlyConfigured', true)->assertDontSeeHtml('data-gpp-section');
        // gp-1 has nothing configured: it opens on Computer
        $page->call('openSettings', 'gp-1')->assertSet('settingsTab', 'computer');
    }

    public function test_preferences_lists_of_pk95_ad_1_3_are_sections_of_their_tab(): void
    {
        $this->get(route('apps.page', [$this->installed, 'gpo']))->assertOk()
            ->assertSee('2 Netzlaufwerke')->assertSee('1 lokale Gruppe')->assertSee('1 Drucker');

        Livewire::test(Gpos::class, ['installedApp' => $this->installed])
            ->call('openSettings', 'gp-1')
            // the local group is computer configuration: gp-1 opens there, each tab counts its lists
            ->assertSet('settingsTab', 'computer')->assertSeeInOrder(['Computer', '1', 'Benutzer', '1'])
            ->assertSee('Einstellungen (Lokale Benutzer und Gruppen)')->assertSeeHtml('data-gpp-section="local_groups"')->assertSee('1 Gruppe')
            ->assertSee('Administratoren')->assertSee('Vertrieb')->assertSee('Alle Computer')
            ->assertDontSeeHtml('data-gpp-section="printers"')
            ->set('settingsTab', 'user')
            ->assertSee('Einstellungen (Laufwerkszuordnungen, Drucker, Verknüpfungen)')
            ->assertSeeHtml('data-gpp-section="printers"')->assertSee('1 Drucker')->assertSee('\\print\buero')->assertSee('Standard')
            ->assertSee('Mitglieder von Vertrieb')->assertSee('PrintNightmare')
            ->assertSeeHtml('data-gpp-section="shortcuts"')->assertSee('0 Verknüpfungen')->assertSee('Noch keine Verknüpfungen.')
            // the search finds a list by its words and its rows
            ->set('settingsSearch', 'drucker')->assertSeeHtml('data-gpp-section="printers"')->assertDontSeeHtml('data-gpp-section="shortcuts"')
            ->set('settingsSearch', 'buero')->assertSeeHtml('data-gpp-section="printers"')
            ->set('settingsSearch', '')->set('onlyConfigured', true)->assertSeeHtml('data-gpp-section="printers"')->assertDontSeeHtml('data-gpp-section="shortcuts"');
    }

    public function test_local_groups_members_remove_others_and_errors(): void
    {
        $page = Livewire::test(Gpos::class, ['installedApp' => $this->installed])->call('openSettings', 'gp-1')
            ->call('editGpp', 'local_groups', 0)->assertSet('gpp.local_groups.0.add_ids', ['g-v'])
            ->assertSee('Hinzufügen')->assertSee('Entfernen')->assertSeeHtml('data-domain-admins-hint')->assertDontSeeHtml('data-remove-others-warning')
            ->set('gpp.local_groups.0.add_ids', ['g-v', 'm-1'])->set('gpp.local_groups.0.remove_other_users', true)
            ->assertSeeHtml('data-remove-others-warning')->assertSee('Achtung: alle anderen Benutzer werden entfernt')->assertSee('Domain Admins nimmt PK95 AD dabei immer auf.')
            ->set('gpp.local_groups.0.remove_other_groups', true)->assertSee('Achtung: alle anderen Benutzer und Gruppen werden entfernt')
            ->call('closeGpp')->assertSeeHtml('data-remove-others')->assertSee('Alle anderen Benutzer und Gruppen werden entfernt')
            // a new row takes the first local group not used yet
            ->call('addGpp', 'local_groups')->assertSet('gpp.local_groups.1.local_group', 'remote_desktop_users')
            ->assertSee('Administratoren (schon in dieser Gruppenrichtlinie)')
            // the condition group is "group", the local group "local_group": choosing "all" keeps the local group
            ->set('gpp.local_groups.1.add_ids', ['g-du'])->set('gpp.local_groups.1.condition', 'member')->set('gpp.local_groups.1.group', 'g-gl')
            ->set('gpp.local_groups.1.condition', 'all')->assertSet('gpp.local_groups.1.group', '')->assertSet('gpp.local_groups.1.local_group', 'remote_desktop_users');
        $page->call('saveSettings')->assertHasNoErrors()->assertSet('settingsFor', null);
        $items = last($this->sent('PUT', '/api/gpos/gp-1/local-groups'))['body']['items'];
        $this->assertSame(['uid' => '{L1}', 'group' => 'administrators', 'members' => [['id' => 'g-v', 'action' => 'add'], ['id' => 'm-1', 'action' => 'add']],
            'remove_other_users' => true, 'remove_other_groups' => true, 'target' => null], $items[0]);
        $this->assertSame(['group' => 'remote_desktop_users', 'members' => [['id' => 'g-du', 'action' => 'add']],
            'remove_other_users' => false, 'remove_other_groups' => false, 'target' => null], $items[1]);
        $this->assertSame('Max Muster', $this->state['gpp']['local-groups']['gp-1'][0]['members'][2]['name']);
        // the app adds Domain Admins (first) to the administrators when a switch is on: the row shows it on reopening
        $page->call('openSettings', 'gp-1')->assertSet('gpp.local_groups.0.add_ids', ['g-da', 'g-v', 'm-1'])->assertSee('Domain Admins');

        // 422 on members: mapped to the selection, the computer tab and the row open
        $page->call('openSettings', 'gp-1')->set('settingsTab', 'user')
            ->call('editGpp', 'local_groups', 0)->set('gpp.local_groups.0.add_ids', ['g-v'])->set('gpp.local_groups.0.remove_ids', ['g-da'])->call('closeGpp')
            ->call('saveSettings')->assertHasErrors('gpp.local_groups.0.members')->assertSet('settingsTab', 'computer')
            ->assertSet('gppEditing', 'local_groups.0')->assertSee('Domain Admins kann nicht aus den lokalen Administratoren entfernt werden.');
        // checked before sending: an account in both lists, more than 100 members
        $sent = count($this->sent('PUT', '/api/gpos/gp-1/local-groups'));
        $page->set('gpp.local_groups.0.remove_ids', ['g-v'])->call('saveSettings')->assertHasErrors('gpp.local_groups.0.members')
            ->assertSee('Ein Konto kann nicht zugleich hinzugefügt und entfernt werden.')
            ->set('gpp.local_groups.0.remove_ids', array_map(fn ($i) => 'x-'.$i, range(1, 100)))->call('saveSettings')
            ->assertHasErrors('gpp.local_groups.0.members')->assertSee('Höchstens 100 Mitglieder je lokaler Gruppe.');
        $this->assertCount($sent, $this->sent('PUT', '/api/gpos/gp-1/local-groups'));
    }

    public function test_printers_and_shortcuts_round_trip(): void
    {
        $page = Livewire::test(Gpos::class, ['installedApp' => $this->installed])->call('openSettings', 'gp-1')->set('settingsTab', 'user')
            ->call('addGpp', 'printers')->assertSet('gppEditing', 'printers.1')->assertSee('Neuer Drucker')
            ->set('gpp.printers.1.path', '10.0.0.5')->set('gpp.printers.1.condition', 'not_member')->set('gpp.printers.1.group', 'g-gl-unknown')
            ->call('addGpp', 'shortcuts')->assertSet('gppEditing', 'shortcuts.0')->assertSet('gpp.shortcuts.0.target_type', 'url')
            ->set('gpp.shortcuts.0.name', 'Intranet')->set('gpp.shortcuts.0.target_path', 'ftp://intranet')->set('gpp.shortcuts.0.arguments', '-x')
            ->set('settingsTab', 'computer')->call('saveSettings')
            // the first list with an error: the printer (unknown group, no share), on the user tab; all its errors at once
            ->assertHasErrors(['gpp.printers.1.group', 'gpp.printers.1.path'])->assertSet('settingsTab', 'user')->assertSet('gppEditing', 'printers.1')
            ->assertSee('Die Gruppe gibt es nicht.')->assertSee('Bitte die Freigabe angeben')
            // a printer only once per GPO
            ->set('gpp.printers.1.group', 'g-v')->set('gpp.printers.1.path', '\\\\PRINT\\Buero')->call('saveSettings')->assertHasErrors('gpp.printers.1.path')
            ->assertSee('Dieser Drucker steht schon in der Richtlinie.')
            ->set('gpp.printers.1.path', '\\\\print\\lager')->call('saveSettings')->assertHasErrors('gpp.shortcuts.0.target_path')->assertSet('gppEditing', 'shortcuts.0')
            ->set('gpp.shortcuts.0.target_path', 'https://intranet.example.de')
            // the icon index is an unsigned byte
            ->set('gpp.shortcuts.0.icon_index', '256')->call('saveSettings')->assertHasErrors('gpp.shortcuts.0.icon_index')->assertSee('Der Index ist eine Zahl von 0 bis 255.')
            ->set('gpp.shortcuts.0.icon_index', '3')->set('gpp.shortcuts.0.location', 'start_menu')
            ->call('saveSettings')->assertHasNoErrors()->assertSet('settingsFor', null);

        $printers = last($this->sent('PUT', '/api/gpos/gp-1/printers'))['body']['items'];
        $this->assertSame('{P1}', $printers[0]['uid']);
        $this->assertSame(['path' => '\\\\print\\lager', 'action' => 'update', 'default' => false, 'target' => ['group_id' => 'g-v', 'not' => true]], $printers[1]);
        $this->assertSame(['name' => 'Intranet', 'target_type' => 'url', 'target_path' => 'https://intranet.example.de', 'location' => 'start_menu',
            'arguments' => '', 'icon_path' => '', 'icon_index' => 3, 'action' => 'update', 'target' => null],
            last($this->sent('PUT', '/api/gpos/gp-1/shortcuts'))['body']['items'][0], 'arguments only for files');

        // a file shortcut keeps its arguments
        $page->call('openSettings', 'gp-1')->set('settingsTab', 'user')->call('editGpp', 'shortcuts', 0)
            ->set('gpp.shortcuts.0.target_type', 'file')->assertSee('Argumente')->set('gpp.shortcuts.0.target_path', '\\\\srv\\apps\\tool.exe')->set('gpp.shortcuts.0.arguments', '/silent')
            ->call('saveSettings')->assertSet('settingsFor', null);
        $this->assertSame(['file', '/silent'], [last($this->sent('PUT', '/api/gpos/gp-1/shortcuts'))['body']['items'][0]['target_type'], last($this->sent('PUT', '/api/gpos/gp-1/shortcuts'))['body']['items'][0]['arguments']]);

        // a name only once per location: the same name in the start menu is taken, on the desktop it is free
        $page->call('openSettings', 'gp-1')->set('settingsTab', 'user')->call('addGpp', 'shortcuts')
            ->set('gpp.shortcuts.1.name', 'intranet')->set('gpp.shortcuts.1.target_path', 'https://intranet.example.de')->set('gpp.shortcuts.1.location', 'start_menu')
            ->call('saveSettings')->assertHasErrors('gpp.shortcuts.1.name')->assertSet('gppEditing', 'shortcuts.1')
            ->assertSee('Eine Verknüpfung mit diesem Namen gibt es hier schon.')
            ->set('gpp.shortcuts.1.location', 'desktop')->call('saveSettings')->assertHasNoErrors()->assertSet('settingsFor', null);
        $this->assertCount(2, $this->state['gpp']['shortcuts']['gp-1']);
    }

    public function test_preferences_lists_before_pk95_ad_1_3(): void
    {
        $this->state['gpp404'] = true;
        // the GPO list falls back to drive_count
        $this->get(route('apps.page', [$this->installed, 'gpo']))->assertOk()->assertSee('2 Netzlaufwerke')->assertDontSee('1 Drucker');
        Livewire::test(Gpos::class, ['installedApp' => $this->installed])->call('openSettings', 'gp-1')
            ->assertSet('gppSupported.local_groups', false)->assertSet('gppSupported.drives', true)
            ->assertSee('ab PK95 AD 1.3.0')->assertSee('Lokale Gruppen per Gruppenrichtlinie gibt es ab PK95 AD 1.3.0.')
            ->call('addGpp', 'printers')->assertSet('gppEditing', null)
            ->set('settingsTab', 'user')->assertSee('Drucker per Gruppenrichtlinie gibt es ab PK95 AD 1.3.0.')
            ->set('onlyConfigured', true)->assertDontSeeHtml('data-gpp-section="printers"');
        $this->assertSame([], $this->sent('GET', '/api/users'), 'no member list without local groups');
    }

    public function test_drive_maps_are_hidden_before_pk95_ad_1_1(): void
    {
        $this->state['drives404'] = true;
        $this->get(route('apps.page', [$this->installed, 'gpo']))->assertOk()->assertDontSee('Netzlaufwerke');
        Livewire::test(Gpos::class, ['installedApp' => $this->installed])
            ->call('openSettings', 'gp-2')->assertSet('gppSupported.drives', false)
            ->set('settingsTab', 'user')->assertSee('ab PK95 AD 1.1.0')->assertSee('Netzlaufwerke per Gruppenrichtlinie gibt es ab PK95 AD 1.1.0.')->assertDontSee('Netzlaufwerk hinzufügen')
            ->set('settings.deny_cmd', '1')->call('saveSettings')->assertSet('settingsFor', null);
        $this->assertCount(1, $this->sent('PUT', '/api/gpos/gp-2/settings'));
        $this->assertSame([], $this->sent('PUT', '/api/gpos/gp-2/drives'));
    }

    public function test_drive_maps_condition_add_edit_order_remove_and_save(): void
    {
        $readonly = $this->state['drives']['gp-2'][1];
        $page = Livewire::test(Gpos::class, ['installedApp' => $this->installed])
            ->call('openSettings', 'gp-2')->set('settingsTab', 'user')
            ->call('editGpp', 'drives', 0)->assertSet('gpp.drives.0.condition', 'member')->assertSeeHtml('data-gpp-condition')
            ->assertSee('Bedingung')->assertSee('Nur Mitglieder der Gruppe')->assertSee('Alle außer Mitgliedern der Gruppe')->assertDontSee('Sicherheitsgruppe …')->assertSeeHtml('data-group-link')
            ->assertSeeHtml(route('apps.page', [$this->installed, 'groups']).'?q=Vertrieb')
            ->call('addGpp', 'drives')->assertSet('gppEditing', 'drives.2')->assertSet('gpp.drives.2.letter', 'H')->assertSet('gpp.drives.2.condition', 'all')
            ->assertSet('gpp.drives.2.reconnect', true)->assertSeeHtml('placeholder="\\\\nas\\freigabe"')
            ->assertSee('Alle Benutzer, für die diese Gruppenrichtlinie gilt, bekommen dieses Laufwerk.')
            ->set('gpp.drives.2.path', '\\\\nas\\technik')->set('gpp.drives.2.label', 'Technik')
            ->set('gpp.drives.2.condition', 'not_member')->assertSee('Wähle die Sicherheitsgruppe.')
            ->set('gpp.drives.2.group', 'g-v')->assertSee('Alle außer Mitgliedern von Vertrieb bekommen dieses Laufwerk.')
            ->call('closeGpp')->assertSet('gppEditing', null)->assertSee('Alle außer Mitgliedern von Vertrieb')->assertSee('neu')
            ->call('moveGpp', 'drives', 2, -1)->assertSet('gpp.drives.1.label', 'Technik')
            ->call('moveGpp', 'drives', 0, -1)->assertSet('gpp.drives.0.uid', '{A1}')
            ->call('editGpp', 'drives', 0)->set('gpp.drives.0.label', 'Verkauf')->set('gpp.drives.0.visibility', 'hide')
            ->assertSee('2 Änderungen')
            ->call('saveSettings')->assertHasNoErrors()->assertSet('settingsFor', null)
            ->assertDispatched('notice', kind: 'success', text: 'Einstellungen gespeichert. Windows-Rechner übernehmen sie bei der nächsten Aktualisierung (etwa alle 90 Minuten oder mit gpupdate).');

        $items = last($this->sent('PUT', '/api/gpos/gp-2/drives'))['body']['items'];
        $this->assertSame(['uid' => '{A1}', 'letter' => 'V', 'first_available' => false, 'path' => '\\\\nas.example.com\\vertrieb', 'label' => 'Verkauf',
            'action' => 'update', 'reconnect' => true, 'visibility' => 'hide', 'target' => ['group_id' => 'g-v', 'not' => false]], $items[0]);
        $this->assertSame(['letter' => 'H', 'first_available' => false, 'path' => '\\\\nas\\technik', 'label' => 'Technik', 'action' => 'update',
            'reconnect' => true, 'visibility' => 'unchanged', 'target' => ['group_id' => 'g-v', 'not' => true]], $items[1], 'a new item has no uid');
        $this->assertSame($readonly, $items[2], 'the read-only item goes back exactly as it came');
        $this->assertSame([], $this->sent('PUT', '/api/gpos/gp-2/settings'), 'only the drives changed');
        $this->assertSame(1, $this->state['gpos']['gp-2']['version']['user']);

        // "Alle Benutzer" drops the group; removing the read-only item deletes it (leaving it out)
        $page->call('openSettings', 'gp-2')->assertCount('gpp.drives', 3)->set('settingsTab', 'user')
            ->call('editGpp', 'drives', 0)->set('gpp.drives.0.condition', 'all')->assertSet('gpp.drives.0.group', '')
            ->call('removeGpp', 'drives', 2)->assertSee('2 Änderungen')->call('saveSettings')->assertSet('settingsFor', null);
        $sent = last($this->sent('PUT', '/api/gpos/gp-2/drives'))['body']['items'];
        $this->assertSame(['{A1}', '{NEW-1}'], array_column($sent, 'uid'));
        $this->assertNull($sent[0]['target']);
    }

    public function test_drive_map_errors_are_shown_per_row(): void
    {
        // a condition without a group is caught before anything is sent, also from another tab
        $page = Livewire::test(Gpos::class, ['installedApp' => $this->installed])
            ->call('openSettings', 'gp-2')->set('settingsTab', 'user')->call('addGpp', 'drives')->call('closeGpp')
            ->set('gpp.drives.2.path', '\\\\nas\\x')->set('gpp.drives.2.condition', 'member')->set('settings.deny_cmd', '1')
            ->set('settingsTab', 'computer')->set('settingsSearch', 'update')
            ->call('saveSettings')->assertHasErrors('gpp.drives.2.group')->assertSet('settingsTab', 'user')->assertSet('settingsSearch', '')->assertSet('gppEditing', 'drives.2')
            ->assertSeeHtml('data-gpp-editor')
            ->assertSee('Wähle die Sicherheitsgruppe für die Bedingung.');
        $this->assertSame([], $this->sent('PUT', '/api/gpos/gp-2/settings'));
        $this->assertSame([], $this->sent('PUT', '/api/gpos/gp-2/drives'));

        $page->set('gpp.drives.2.condition', 'all')->set('settings.deny_cmd', '')
            ->set('gpp.drives.2.letter', 'V')->call('saveSettings')
            ->assertHasErrors('gpp.drives.2.letter')->assertSet('settingsFor', 'gp-2')->assertSet('gppEditing', 'drives.2')
            ->assertSee('Der Buchstabe V: ist in dieser Gruppenrichtlinie schon vergeben.');

        $page->set('gpp.drives.2.first_available', true)->set('gpp.drives.2.path', 'nas\\x')->call('saveSettings')->assertHasErrors('gpp.drives.2.path')
            ->set('gpp.drives.2.path', '\\\\nas\\x')->set('gpp.drives.2.condition', 'member')->set('gpp.drives.2.group', 'g-weg')->call('saveSettings')->assertHasErrors('gpp.drives.2.group')
            ->assertSee('Die Gruppe gibt es nicht.')
            ->set('gpp.drives.2.condition', 'all')->call('saveSettings')->assertHasNoErrors()->assertSet('settingsFor', null);
        $this->assertSame(['letter' => 'V', 'first_available' => true, 'target' => null],
            array_intersect_key(last($this->sent('PUT', '/api/gpos/gp-2/drives'))['body']['items'][2], ['letter' => 0, 'first_available' => 0, 'target' => 0]));

        // at most 50
        $page->call('openSettings', 'gp-2')->set('settingsTab', 'user')->set('gpp.drives', array_fill(0, 50, Kinds::get('drives')->toForm(['letter' => 'X', 'first_available' => true, 'path' => '\\\\nas\\x'])))
            ->call('addGpp', 'drives')->assertHasErrors('gpp.drives')->assertCount('gpp.drives', 50)->assertSee('Höchstens 50 Netzlaufwerke je Gruppenrichtlinie.');
    }

    public function test_gpo_create_links_enforce_order_and_block_inheritance(): void
    {
        $page = Livewire::test(Gpos::class, ['installedApp' => $this->installed])
            ->assertSet('target', 'd0')
            ->call('create')->set('form.name', 'USB sperren')->call('save')->assertSet('editing', null);
        $this->assertSame(['name' => 'USB sperren'], last($this->sent('POST', '/api/gpos'))['body']);

        $page->call('links', 'gp-2')->assertSee('Verknüpfungen von Bildschirmsperre')->assertSee('Firma/Vertrieb')
            ->call('addLink')->assertHasErrors('linkTarget')
            ->set('linkTarget', 'f1')->call('addLink')->assertHasNoErrors()->assertDispatched('notice', kind: 'success', text: 'Verknüpft.');
        $this->assertSame(['target_id' => 'f1', 'enabled' => true, 'enforced' => false], last($this->sent('POST', '/api/gpos/gp-2/links'))['body']);

        $page->call('updateLink', 'gp-2', 'v1', 'enforced', true)->assertDispatched('notice', kind: 'success', text: 'Verknüpfung erzwungen.')
            ->call('updateLink', 'gp-2', 'v1', 'order', 2)
            ->call('updateLink', 'gp-2', 'v1', 'bogus', 1)
            ->call('removeLink', 'gp-2', 'f1');
        $this->assertSame([['enforced' => true], ['order' => 2]], array_column($this->sent('PUT', '/api/gpos/gp-2/links/v1'), 'body'));
        $this->assertCount(1, $this->sent('DELETE', '/api/gpos/gp-2/links/f1'));

        // block inheritance from the GPO page
        $page->call('cancel')->set('target', 'v1')->assertSee('Vererbung blockieren')
            ->call('blockInheritance', 'v1', false)->assertDispatched('notice', kind: 'success', text: 'Vererbung blockiert.');
        $this->assertSame(['block_inheritance' => true], last($this->sent('PUT', '/api/ous/v1'))['body']);

        $page->call('delete', 'gp-1')->assertDispatched('notice', kind: 'error', text: 'Die Default Domain Policy kann nicht gelöscht werden.');
    }

    public function test_dns_records_with_read_only_system_records(): void
    {
        $page = Livewire::test(DnsRecords::class, ['installedApp' => $this->installed])
            ->assertSet('zone', 'ad.example.com')->assertSee('nas.ad.example.com')
            ->call('create')->assertSet('form.ttl', '900')->assertDontSeeHtml('value="PTR"')
            ->set('form.name', 'drucker.ad.example.com')->set('form.value', 'drucker')->call('save')->assertHasErrors('form.value')
            ->set('form.value', '192.168.1.30')->call('save')->assertSet('editing', null);
        $this->assertSame(['name' => 'drucker.ad.example.com', 'type' => 'A', 'value' => '192.168.1.30', 'ttl' => 900],
            last($this->sent('POST', '/api/dns/zones/ad.example.com/records'))['body']);

        $page->call('edit', 'n1')->assertSee('Nach dem Speichern ist er statisch')->call('cancel')
            ->call('delete', 's2')->assertDispatched('notice', kind: 'error', text: 'Diesen Eintrag braucht der Domänencontroller.');

        $html = $this->get(route('apps.page', [$this->installed, 'dns']))->getContent();
        $this->assertSame(2, substr_count($html, 'data-read-only'), 'SOA and the DC record have no edit buttons');
        $this->get(route('apps.page', [$this->installed, 'dns']).'?zone=_msdcs.ad.example.com')->assertSee('Systemzone')->assertDontSee('Eintrag hinzufügen');
    }

    public function test_scripts_edit_create_and_delete(): void
    {
        $page = Livewire::test(Scripts::class, ['installedApp' => $this->installed])
            ->call('edit', 'logon.bat')->assertSet('form.content', "@echo off\r\nnet use H: \\\\nas\\home\r\n")
            ->set('form.content', "@echo off\r\n")->call('save')->assertSet('editing', null);
        $this->assertSame(['content' => "@echo off\r\n"], last($this->sent('PUT', '/api/scripts/logon.bat'))['body']);

        $page->call('create')->set('form.name', 'mein skript.bat')->call('save')->assertHasErrors('form.name')
            ->set('form.name', 'notes.txt')->call('save')->assertHasErrors('form.name')
            ->set('form.name', 'drives.ps1')->set('form.content', 'New-PSDrive')->call('save')->assertSet('editing', null);
        $this->assertSame('New-PSDrive', $this->state['scripts']['drives.ps1']);

        $page->call('delete', 'drives.ps1')->assertDispatched('notice', kind: 'success', text: 'drives.ps1 gelöscht.');
        $this->assertArrayNotHasKey('drives.ps1', $this->state['scripts']);
    }

    public function test_setup_done_event_queues_the_dns_forwarding(): void
    {
        Queue::fake();
        $event = ['type' => 'ad.setup.done', 'severity' => 'info', 'title' => 'Domäne eingerichtet', 'data' => ['realm' => 'ad.example.com', 'netbios_domain' => 'EXAMPLE', 'dc' => 'dc1']];
        $this->withToken($this->installed->token)->postJson('/api/v1/events', $event)->assertStatus(202);
        Queue::assertPushed(EnsureAdDnsForward::class, fn ($job) => $job->appId === 'ad' && $job->data['realm'] === 'ad.example.com');

        // other events of AD and the same event from another app do not
        $this->withToken($this->installed->token)->postJson('/api/v1/events', ['type' => 'ad.samba.down', 'severity' => 'critical', 'title' => 'x'])->assertStatus(202);
        $other = InstalledApp::factory()->create(['app_id' => 'uptime']);
        $this->withToken($other->token)->postJson('/api/v1/events', $event)->assertStatus(202);
        Queue::assertPushed(EnsureAdDnsForward::class, 1);
    }

    public function test_setup_done_job_adds_the_forwarding_once(): void
    {
        // without PK95 DNS: nothing to do, no warning (the domain page shows the entry)
        (new EnsureAdDnsForward('ad', ['realm' => 'ad.example.com']))->handle();
        $this->assertSame([], $this->dnsCalls);
        $this->assertSame(0, AppEvent::query()->count());

        $this->installDns();
        (new EnsureAdDnsForward('ad', ['realm' => 'ad.example.com']))->handle();
        (new EnsureAdDnsForward('ad', ['realm' => 'ad.example.com']))->handle();
        $this->assertSame(1, $this->dnsPuts(), 'idempotent');
        $this->assertSame([['domain' => 'fritz.box', 'upstream' => '192.168.178.1'], ['domain' => 'ad.example.com', 'upstream' => '127.0.0.1:5300']], $this->conditional);

        // the domain page now only shows it, the button is the fallback
        $this->get(route('apps.page', [$this->installed, 'domain']))->assertOk()->assertSee('Eingetragen:');
    }

    public function test_setup_done_job_uses_the_event_realm_and_warns_when_dns_refuses(): void
    {
        $this->installDns();
        $this->state['down'] = true; // /api/domain does not answer: realm from the event, port 5300
        $this->dnsRefuses = true;
        (new EnsureAdDnsForward('ad', ['realm' => 'AD.example.com']))->handle();

        $put = collect($this->dnsCalls)->firstWhere('method', 'PUT');
        $this->assertSame(['domain' => 'ad.example.com', 'upstream' => '127.0.0.1:5300'], last($put['body']['conditional']));
        $event = AppEvent::query()->where('type', 'core.dns_forward.failed')->sole();
        $this->assertSame(['ad', 'warning'], [$event->app_id, $event->severity]);
        $this->assertStringContainsString('Upstream nicht erlaubt.', $event->body);
    }

    public function test_idmap_problem_is_a_warning_on_the_domain_page(): void
    {
        // PK95 AD 1.0.0 has no idmap: no hint
        $this->get(route('apps.page', [$this->installed, 'domain']))->assertOk()->assertDontSee('data-idmap-warning', false);

        $idmap = ['xid_range' => [20000, 59999], 'rfc2307_range' => [10000, 19999], 'next_xid' => 20123, 'allocated' => 42, 'ok' => true, 'message' => ''];
        $this->state['idmap'] = $idmap;
        $this->get(route('apps.page', [$this->installed, 'domain']))->assertOk()->assertDontSee('data-idmap-warning', false)->assertDontSee('Unix-IDs der Domäne');

        $this->state['idmap'] = ['next_xid' => 3000012, 'allocated' => 12, 'ok' => false,
            'message' => 'Die Domäne vergibt Unix-IDs ab 3000000; im LXC gibt es nur 0 bis 65535. Bitte die Domäne neu einrichten.'] + $idmap;
        $this->get(route('apps.page', [$this->installed, 'domain']))->assertOk()
            ->assertSee('data-idmap-warning', false)->assertSee('Unix-IDs der Domäne')
            ->assertSee('Die Domäne vergibt Unix-IDs ab 3000000; im LXC gibt es nur 0 bis 65535. Bitte die Domäne neu einrichten.')
            ->assertSee('Bereich 20.000 bis 59.999, vergeben 12, nächste ID 3.000.012.');
    }

    public function test_ntp_unavailable_is_a_warning_on_the_domain_page(): void
    {
        $this->get(route('apps.page', [$this->installed, 'domain']))->assertOk()
            ->assertDontSee('data-ntp-warning', false)->assertDontSee('Zeit (NTP): nicht verfügbar');

        $this->state['ntp'] = ['running' => false, 'available' => false, 'message' => 'NTP nicht verfügbar: Port 123/udp ist im LXC belegt.'];
        $this->get(route('apps.page', [$this->installed, 'domain']))->assertOk()
            ->assertSee('data-ntp-warning', false)->assertSee('Zeitdienst (NTP) nicht verfügbar')
            ->assertSee('NTP nicht verfügbar: Port 123/udp ist im LXC belegt.')->assertSee('Zeit (NTP): nicht verfügbar');
    }

    /** @return array<string, mixed> GET /api/time of PK95 AD 1.4.0 */
    private function adTime(string $status = 'ok', string $message = '', ?float $offset = 1.8): array
    {
        return ['now' => now()->addSeconds(3)->format('Y-m-d\TH:i:s.v\Z'), 'servers' => ['de.pool.ntp.org'],
            'sources' => [
                ['address' => '1.2.3.4', 'name' => 'de.pool.ntp.org', 'stratum' => 2, 'reachable' => true, 'selected' => true, 'offset_ms' => $offset, 'last_rx_s' => 33],
                ['address' => '5.6.7.8', 'name' => '', 'stratum' => 3, 'reachable' => false, 'selected' => false, 'offset_ms' => null, 'last_rx_s' => null],
            ],
            'synchronized' => $status === 'ok', 'offset_ms' => $offset, 'source' => 'de.pool.ntp.org', 'status' => $status, 'message' => $message,
            'serving' => ['running' => true, 'available' => true, 'message' => '']];
    }

    public function test_time_section_says_from_which_version_on_older_apps(): void
    {
        // PK95 AD before 1.4.0: /api/time answers 404, the rest of the page works
        Livewire::test(Domain::class, ['installedApp' => $this->installed])
            ->assertSeeHtml('data-time-unsupported')->assertSee('Ab PK95 AD 1.4.0 verfügbar')
            ->assertDontSeeHtml('data-ad-clock')->assertDontSee('Zeitserver (Referenz)')->assertSee('Kennwortrichtlinie');
    }

    public function test_time_section_shows_the_clock_and_saves_the_reference_servers(): void
    {
        $this->travelTo(now()->setTime(6, 21, 27));
        $this->state['time'] = $this->adTime();

        $page = Livewire::test(Domain::class, ['installedApp' => $this->installed])
            ->assertSeeHtml('data-ad-clock')->assertSee('07:21:30')   // AD time (3 s ahead in the fake) in Europe/Berlin
            ->assertSeeHtml('data-time-status="ok"')->assertSee('synchron mit de.pool.ntp.org · Abweichung 2 ms')
            ->assertSee('Abweichung +2 ms')->assertSee('wird verteilt')
            ->assertSeeHtml('data-selected')->assertSeeInOrder(['de.pool.ntp.org', 'ausgewählt', '1.2.3.4', '5.6.7.8', 'nein'])->assertSee('vor 33 s')
            ->assertSee('Die Uhrzeit kommt vom Proxmox-Host')->assertSee('Aktualisieren')
            ->assertSet('servers', ['de.pool.ntp.org']);

        // up to five rows
        foreach (range(1, 6) as $_) {
            $page->call('addServer');
        }
        $this->assertCount(5, $page->get('servers'));

        // 422 "servers.N" lands on row N
        $page->set('servers', ['de.pool.ntp.org', 'kein server!', 'ptbtime1.ptb.de'])->call('saveServers')
            ->assertHasErrors('servers.1')->assertHasNoErrors('servers.0')->assertSee('Bitte einen Hostnamen oder eine IP-Adresse angeben.');

        // round trip, empty rows left out
        $page->set('servers', ['ptbtime1.ptb.de', ' ', '192.168.1.1'])->call('saveServers')->assertHasNoErrors()
            ->assertSet('servers', ['ptbtime1.ptb.de', '192.168.1.1'])->assertDispatched('notice');
        $this->assertSame(['servers' => ['ptbtime1.ptb.de', '192.168.1.1']], last($this->sent('PUT', '/api/time'))['body']);

        // no reference at all: status unknown, "keine Referenz"
        $page->call('removeServer', 1)->call('removeServer', 0)->call('saveServers')->assertHasNoErrors()
            ->assertSet('servers', [])->assertSee('keine Referenz')->assertSeeHtml('data-no-servers')->assertSeeHtml('data-time-status="unknown"');
    }

    public function test_time_section_colours_warnings_and_errors(): void
    {
        $this->state['time'] = $this->adTime('warn', 'Die Uhr weicht um 2,5 s ab.', 2500);
        Livewire::test(Domain::class, ['installedApp' => $this->installed])
            ->assertSeeHtml('data-time-status="warn"')->assertSee('Die Uhr weicht um 2,5 s ab.')->assertSee('Abweichung 2,5 s (Uhr geht vor)');

        $this->state['time'] = $this->adTime('error', 'Die Uhr weicht um 6 min ab, Kerberos scheitert.', -360000);
        Livewire::test(Domain::class, ['installedApp' => $this->installed])
            ->assertSeeHtml('data-time-status="error"')->assertSee('Kerberos scheitert.')->assertSee('Abweichung −6 min 0 s');
    }

    public function test_primary_group_members_are_shown_apart_and_never_sent(): void
    {
        $page = Livewire::test(Groups::class, ['installedApp' => $this->installed])
            ->call('edit', 'g-du')->assertSet('form.member_ids', [])
            ->assertSee('Mitglied über die primäre Gruppe')->assertSee('Max Muster')->assertSee('nicht entfernbar');
        // the primary members are no options of the selection
        $options = collect($page->instance()->render()->getData()['memberOptions'])->pluck('value')->all();
        $this->assertNotContains('m-1', $options);
        $this->assertNotContains('a-1', $options);

        // nothing changed: no PUT /members; a direct member added: only that one is sent
        $page->call('save')->assertSet('editing', null);
        $this->assertSame([], $this->sent('PUT', '/api/groups/g-du/members'));
        $this->state['users']['e-1'] = $this->user('e-1', 'erika', 'Erika Muster', 'u1', 'Users');
        $page->call('edit', 'g-du')->set('form.member_ids', ['e-1'])->call('save')->assertSet('editing', null);
        $this->assertSame(['member_ids' => ['e-1']], last($this->sent('PUT', '/api/groups/g-du/members'))['body']);
    }

    public function test_display_name_is_checked_in_the_form(): void
    {
        $posts = count($this->sent('POST', '/api/users'));
        Livewire::test(Users::class, ['installedApp' => $this->installed])
            ->call('create')->set('form.username', 'lang')->set('form.password', 'Lang-genug-1')
            ->set('form.display_name', str_repeat('x', 65))->call('save')->assertHasErrors('form.display_name')
            ->set('form.display_name', 'Vertrieb/Nord')->call('save')->assertHasErrors('form.display_name')
            ->assertSeeHtml('maxlength="64"');
        $this->assertCount($posts, $this->sent('POST', '/api/users'), 'nothing sent');
    }

    public function test_unreachable_app_is_reported_on_the_page(): void
    {
        $this->state['down'] = true;
        $this->get(route('apps.page', [$this->installed, 'users']))->assertOk()
            ->assertSee('PK95 AD antwortet nicht')->assertSee('Samba startet neu.')->assertDontSee('data-ad-not-ready', false);
    }
}
