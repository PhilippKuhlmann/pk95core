<?php

namespace Tests\Feature;

use App\Http\Support\Format;
use App\Models\InstalledApp;
use App\Modules\Ad\AdModule;
use App\Modules\Ad\Computers;
use App\Modules\Ad\DnsForward;
use App\Modules\Ad\DnsRecords;
use App\Modules\Ad\Domain;
use App\Modules\Ad\Gpos;
use App\Modules\Ad\Groups;
use App\Modules\Ad\Ous;
use App\Modules\Ad\Scripts;
use App\Modules\Ad\Users;
use App\Services\Catalog\Catalog;
use App\Services\Docker\ContainerSpec;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
            ],
            'members' => ['g-da' => ['a-1'], 'g-v' => []],
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
        if ($path === '/api/status') {
            return Http::response(['version' => '1.0.0', 'state' => $s['setup']['state'],
                'samba' => ['state' => 'running', 'last_exit' => '', 'services' => ['ldap' => true, 'kerberos' => true, 'smb' => true, 'dns' => false]], 'ntp' => ['running' => true]]);
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
                'dns_forwarder' => '127.0.0.1', 'dns_port' => 5300]);
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
            $members = array_map(fn ($mid) => ['id' => $mid, 'name' => $s['users'][$mid]['username'] ?? $mid, 'kind' => 'user', 'builtin' => false], $s['members'][$id] ?? []);

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

            return Http::response(array_values($s['gpos']));
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

            return Http::response($gpo + ['settings' => $settings, 'other_settings' => ['computer' => $id === 'gp-1' ? 4 : 0, 'user' => 0, 'extensions' => $id === 'gp-1' ? ['Sicherheit'] : []]]);
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

    private function dns(Request $r)
    {
        $this->dnsCalls[] = ['method' => $r->method(), 'path' => parse_url($r->url(), PHP_URL_PATH), 'body' => $r->data()];
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

    public function test_gpo_settings_form_is_built_from_the_catalog(): void
    {
        $page = Livewire::test(Gpos::class, ['installedApp' => $this->installed])
            ->call('openSettings', 'gp-2')
            ->assertSet('settings.screen_lock_minutes', '10')->assertSet('settings.deny_cmd', '')->assertSet('settings.logon_scripts', [])
            ->assertSee('Benutzerkonfiguration')->assertSee('Computerkonfiguration')->assertSee('Bildschirmsperre nach Inaktivität (Minuten)')
            ->assertSee('1 bis 600 Minuten.')->assertSee('Automatisch installieren')->assertSeeHtml('<textarea')->assertSee('Skript hinzufügen');

        // 422 on the field
        $page->set('settings.screen_lock_minutes', '900')->call('saveSettings')->assertHasErrors('settings.screen_lock_minutes')->assertSet('settingsFor', 'gp-2');

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

        // settings the Windows tools wrote are reported
        $page->call('openSettings', 'gp-1')->assertSee('Weitere Einstellungen')->assertSee('Sicherheit');
        $this->assertNull(Gpos::toApi(['type' => 'bool'], ''));
        $this->assertFalse(Gpos::toApi(['type' => 'bool'], '0'));
        $this->assertSame(5, Gpos::toApi(['type' => 'int'], ' 5 '));
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

    public function test_unreachable_app_is_reported_on_the_page(): void
    {
        $this->state['down'] = true;
        $this->get(route('apps.page', [$this->installed, 'users']))->assertOk()
            ->assertSee('PK95 AD antwortet nicht')->assertSee('Samba startet neu.')->assertDontSee('data-ad-not-ready', false);
    }
}
