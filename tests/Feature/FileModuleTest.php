<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\Dashboard\AppPanel;
use App\Livewire\Dashboard\ProblemList;
use App\Models\AppEvent;
use App\Models\InstalledApp;
use App\Modules\Ad\AdModule;
use App\Modules\Ad\Gpos;
use App\Modules\File\FileModule;
use App\Modules\File\Server;
use App\Modules\File\Sessions;
use App\Modules\File\Shares;
use App\Services\Catalog\Catalog;
use App\Services\Dashboard\AppFigures;
use App\Services\Dashboard\Problems;
use App\Services\Docker\ContainerSpec;
use App\Support\Settings;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * The PK95 File module against in-memory fakes of the app API (host network:
 * http://host.docker.internal:8445, pk95file docs/api.md) and of PK95 AD (8389: domain, ODJ
 * blob, DNS records, groups with SIDs, GPO drive maps).
 */
class FileModuleTest extends FeatureTestCase
{
    private InstalledApp $file;

    private InstalledApp $ad;

    /** @var list<array{app: string, method: string, path: string, query: array<string, mixed>, body: mixed}> */
    private array $calls = [];

    /** @var array<string, mixed> state of the fake PK95 File */
    private array $fs = [];

    /** @var array<string, mixed> state of the fake PK95 AD */
    private array $as = [];

    /** the provisioning blob PK95 AD hands out (a new one per request) */
    private string $blob = '';

    /** @var list<string> every blob handed out */
    private array $blobs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['pk95.catalog_dir' => base_path('catalog'), 'pk95.modules' => ['file' => FileModule::class, 'ad' => AdModule::class]]);
        $this->signIn();
        $menu = [['key' => 'server', 'label' => 'Dateiserver', 'icon' => 'server'], ['key' => 'shares', 'label' => 'Freigaben', 'icon' => 'box'],
            ['key' => 'sessions', 'label' => 'Sitzungen', 'icon' => 'user']];
        $this->file = InstalledApp::factory()->create(['app_id' => 'file', 'version' => '1.0.0', 'tag' => '1.0.0',
            'manifest' => ['id' => 'file', 'name' => 'PK95 File', 'version' => '1.0.0', 'protocol' => 1, 'icon' => 'box', 'menu' => $menu, 'capabilities' => ['events', 'backup'], 'events' => []]]);
        $adMenu = array_map(fn ($k) => ['key' => $k, 'label' => $k, 'icon' => 'key'], array_keys((new AdModule)->pages()));
        $this->ad = InstalledApp::factory()->create(['app_id' => 'ad', 'version' => '1.5.0', 'tag' => '1.5.0',
            'manifest' => ['id' => 'ad', 'name' => 'PK95 AD', 'version' => '1.5.0', 'protocol' => 1, 'icon' => 'key', 'menu' => $adMenu, 'capabilities' => [], 'events' => []]]);

        $this->fs = [
            'setup' => ['state' => 'unconfigured', 'error' => '', 'log' => []],
            'defaults' => ['hostname' => 'files', 'ip' => '192.168.1.20', 'ip_source' => 'probe', 'interface' => 'eth0', 'subnet' => '192.168.1.0/24', 'host_ips' => ['192.168.1.10']],
            'storage' => ['path' => '/srv/files', 'fs_type' => 'zfs', 'total_bytes' => 53687091200, 'free_bytes' => 50000000000, 'on_system_disk' => false,
                'xattr' => true, 'hardlinks' => true, 'reflink' => false, 'problems' => [], 'warnings' => []],
            'taken' => ['192.168.1.30' => 'bc:24:11:aa:bb:cc'],
            // the app refuses the blob (422 odj)
            'rejectOdj' => false,
            'shares' => ['buchhaltung' => $this->share('Buchhaltung')],
            // left-over folders of deleted shares (adopt)
            'folders' => ['alt'],
            'snapshots' => ['buchhaltung' => [['id' => '@GMT-2026.11.25-11.00.00', 'created_at' => 1764068400000, 'manual' => false, 'files' => 81201,
                'unique_bytes' => 734003200, 'duration_ms' => 48211, 'kept_by' => ['recent', 'daily']]]],
            'sessions' => ['3121' => ['id' => '3121', 'pid' => 4711, 'user' => 'EXAMPLE\\max.muster', 'machine' => '192.168.1.112', 'hostname' => 'PC-01',
                'protocol' => 'SMB3_11', 'encrypted' => false, 'signed' => true, 'connected_at' => 1764090100000, 'shares' => ['Buchhaltung'], 'open_files' => 1]],
            'settings' => ['scan_interval_minutes' => 60, 'disk_warn_percent' => 20, 'disk_critical_percent' => 10, 'snapshot_min_free_percent' => 15,
                'smb_encryption' => 'if_required', 'min_protocol' => 'SMB2_10'],
            'warnings' => [['level' => 'warning', 'code' => 'soft_quota', 'message' => 'Freigabe „Buchhaltung“: 93 % des Kontingents belegt.']],
            'free_percent' => 60,
        ];
        $this->as = [
            'state' => 'ready',
            'accounts' => [],
            'odjError' => null,
            'records' => [
                ['id' => 's2', 'zone' => 'ad.example.com', 'name' => 'dc1.ad.example.com', 'type' => 'A', 'value' => '192.168.1.10', 'ttl' => 900, 'priority' => 0, 'weight' => 0, 'port' => 0, 'dynamic' => false, 'system' => true],
            ],
            'groups' => [
                ['id' => 'g-1', 'name' => 'Buchhaltung', 'type' => 'security', 'ou_path' => 'Firma', 'sid' => 'S-1-5-21-1-1107'],
                ['id' => 'g-2', 'name' => 'Geschäftsführung', 'type' => 'security', 'ou_path' => 'Firma', 'sid' => 'S-1-5-21-1-1110'],
                ['id' => 'g-3', 'name' => 'Vertrieb', 'type' => 'security', 'ou_path' => 'Firma', 'sid' => 'S-1-5-21-1-1111'],
            ],
            'drives' => [],
        ];

        Http::fake([
            'host.docker.internal:8445/*' => fn (Request $r) => $this->fileApi($r),
            'host.docker.internal:8389/*' => fn (Request $r) => $this->adApi($r),
        ]);
    }

    /** @return array<string, mixed> */
    private function share(string $name, array $extra = []): array
    {
        return array_replace_recursive([
            'name' => $name, 'description' => 'Rechnungen und Belege', 'enabled' => true, 'hidden' => str_ends_with($name, '$'), 'path' => 'shares/'.$name,
            'unc' => '\\\\files.ad.example.com\\'.$name, 'unc_short' => '\\\\FILES\\'.$name,
            'permissions' => [['sid' => 'S-1-5-21-1-1107', 'name' => 'EXAMPLE\\Buchhaltung', 'type' => 'group', 'access' => 'change'],
                ['sid' => 'S-1-5-21-1-1110', 'name' => 'EXAMPLE\\Geschäftsführung', 'type' => 'group', 'access' => 'read']],
            'fixed_permissions' => [['sid' => 'S-1-5-21-1-512', 'name' => 'EXAMPLE\\Domain Admins', 'type' => 'group', 'access' => 'full'],
                ['sid' => 'S-1-5-18', 'name' => 'NT AUTHORITY\\SYSTEM', 'type' => 'well_known', 'access' => 'full']],
            'abe' => true,
            'recycle' => ['enabled' => true, 'keep_days' => 30, 'max_file_mb' => 0, 'exclude' => ['*.tmp', '~$*']],
            'snapshots' => ['enabled' => true, 'schedule' => ['times' => ['07:00', '12:00'], 'weekdays' => [1, 2, 3, 4, 5]], 'keep' => ['recent' => 12, 'daily' => 14, 'weekly' => 8],
                'estimate_bytes' => 62914560000],
            'soft_quota' => ['size_bytes' => 107374182400, 'warn_percent' => 90, 'lock_when_exceeded' => false],
            'usage' => ['bytes' => 99857989632, 'files' => 81234, 'recycle_bytes' => 0, 'snapshot_bytes' => 0, 'scanned_at' => 1764093600000],
            'status' => 'soft_quota_warning', 'created_at' => 1764090000000,
        ], $extra);
    }

    // --- fake PK95 File ------------------------------------------------------------------

    private function fileApi(Request $r)
    {
        $url = parse_url($r->url());
        $path = rawurldecode($url['path']);
        parse_str($url['query'] ?? '', $query);
        $method = $r->method();
        $body = $r->data();
        $this->calls[] = ['app' => 'file', 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body];
        $s = &$this->fs;
        $invalid = fn (array $fields) => Http::response(['error' => 'Bitte Eingaben prüfen.', 'fields' => $fields], 422);
        $error = fn (int $status, string $msg) => Http::response(['error' => $msg], $status);
        $state = $s['setup']['state'];
        $server = ['hostname' => 'files', 'fqdn' => 'files.ad.example.com', 'netbios_name' => 'FILES', 'ip' => '192.168.1.20', 'realm' => 'ad.example.com',
            'workgroup' => 'EXAMPLE', 'dc' => ['hostname' => 'dc1', 'ip' => '192.168.1.10']];

        if ($path === '/api/setup') {
            if ($method === 'POST') {
                if (in_array($state, ['joining', 'ready'], true)) {
                    return $error(409, 'Die Einrichtung läuft schon.');
                }
                if (isset($body['odj']) && ($s['rejectOdj'] || $body['odj'] !== $this->blob)) {
                    return $invalid(['odj' => 'Die Beitrittsdaten gehören zu einem anderen Computer.']);
                }
                $s['setup'] = ['state' => 'joining', 'error' => '', 'log' => ['preflight ok', 'net offlinejoin requestodj']];

                return Http::response(['state' => 'joining'], 202);
            }

            return Http::response($s['setup'] + ['started_at' => 0, 'finished_at' => 0, 'defaults' => $s['defaults'], 'storage' => $s['storage'],
                'server' => $state === 'ready' ? $server : null]);
        }
        if ($path === '/api/setup/ip-check') {
            $ip = (string) ($query['ip'] ?? '');
            $mac = $s['taken'][$ip] ?? null;

            return Http::response(['ip' => $ip, 'ok' => $mac === null, 'message' => $mac ? "Die Adresse {$ip} ist im Netz bereits vergeben (MAC {$mac})." : '']);
        }
        if ($path === '/api/join') {
            if ($state !== 'ready') {
                return $error(409, 'Erst nach einer Einrichtung.');
            }
            $s['setup'] = ['state' => 'joining', 'error' => '', 'log' => ['rejoin']];

            return Http::response(['state' => 'joining'], 202);
        }
        if ($path === '/api/status') {
            return Http::response(['version' => '1.0.0', 'core_mode' => true, 'state' => $state,
                'smbd' => ['state' => 'running', 'pid' => 52, 'restarts' => 0, 'last_exit' => ''], 'winbindd' => ['state' => 'running', 'pid' => 48, 'restarts' => 0, 'last_exit' => ''],
                'trust' => ['ok' => true, 'checked_at' => 1764093600000, 'message' => ''],
                'ip' => ['address' => '192.168.1.20', 'mode' => 'managed', 'interface' => 'eth0', 'present' => true, 'conflict' => false],
                'idmap' => ['backend' => 'rid', 'range' => [10000, 64999], 'max_rid' => 54999, 'next_rid' => 1650, 'percent' => 3, 'warning' => ''],
                'server' => $state === 'ready' ? ['hostname' => 'files', 'fqdn' => 'files.ad.example.com', 'realm' => 'ad.example.com', 'workgroup' => 'EXAMPLE', 'dc' => 'dc1.ad.example.com'] : null]);
        }
        if ($path === '/api/settings') {
            if ($method === 'PUT') {
                if (($body['disk_critical_percent'] ?? 0) >= ($body['disk_warn_percent'] ?? 0)) {
                    return $invalid(['disk_critical_percent' => 'Muss kleiner als die Warnschwelle sein.']);
                }
                $s['settings'] = $body;
            }

            return Http::response($s['settings']);
        }
        if ($state !== 'ready') {
            return $error(409, 'Der Dateiserver ist noch nicht eingerichtet.');
        }
        if ($path === '/api/dashboard') {
            $total = 53687091200;
            $free = (int) round($total * $s['free_percent'] / 100);

            return Http::response(['shares' => count($s['shares']), 'sessions' => count($s['sessions']), 'open_files' => 1,
                'storage' => ['total_bytes' => $total, 'used_bytes' => $total - $free, 'free_bytes' => $free, 'free_percent' => $s['free_percent'],
                    'share_bytes' => 10737418240, 'snapshot_bytes' => 5368709120, 'recycle_bytes' => 1073741824, 'scanned_at' => 1764093600000],
                'snapshots' => ['last_run_at' => 1764082800000, 'last_ok' => true, 'next_run_at' => 1764100800000, 'failed_shares' => [], 'count' => 214],
                'warnings' => $s['warnings']]);
        }
        if ($path === '/api/shares') {
            if ($method === 'POST') {
                $key = strtolower((string) $body['name']);
                $fields = [];
                if (isset($s['shares'][$key])) {
                    $fields['name'] = 'Eine Freigabe mit diesem Namen gibt es bereits.';
                } elseif (in_array($key, $s['folders'], true) && empty($body['adopt'])) {
                    $fields['name'] = 'Der Ordner existiert bereits. Übernehmen?';
                }
                foreach ((array) ($body['permissions'] ?? []) as $i => $p) {
                    if (! in_array($p['sid'], array_column($this->as['groups'], 'sid'), true)) {
                        $fields["permissions.$i.sid"] = 'Unbekannte Gruppe oder unbekannter Benutzer.';
                    }
                }
                $fields += $this->shareFieldErrors($body);
                if ($fields) {
                    return $invalid($fields);
                }
                $s['shares'][$key] = $this->share((string) $body['name'], array_diff_key($body, ['permissions' => 0, 'adopt' => 0])
                    + ['status' => 'ok', 'usage' => ['bytes' => 0]]);
                $s['shares'][$key]['permissions'] = array_map(fn ($p) => $p + ['name' => '', 'type' => 'group'], (array) $body['permissions']);

                return Http::response($s['shares'][$key], 201);
            }

            return Http::response(['items' => array_values($s['shares']), 'total' => count($s['shares'])]);
        }
        if (preg_match('#^/api/shares/([^/]+)(/.*)?$#', $path, $m)) {
            $key = strtolower($m[1]);
            $rest = $m[2] ?? '';
            if (! isset($s['shares'][$key])) {
                return $error(404, 'Unbekannte Freigabe.');
            }
            if ($rest === '/permissions') {
                foreach ((array) $body['permissions'] as $i => $p) {
                    if (! in_array($p['sid'], array_column($this->as['groups'], 'sid'), true)) {
                        return $invalid(["permissions.$i.sid" => 'Unbekannte Gruppe oder unbekannter Benutzer.']);
                    }
                }
                $s['shares'][$key]['permissions'] = $body['permissions'];

                return ! empty($body['propagate']) ? Http::response(['job' => ['id' => 'j1', 'kind' => 'permissions', 'state' => 'queued']], 202) : Http::response($s['shares'][$key]);
            }
            if ($rest === '/snapshots') {
                return $method === 'POST' ? Http::response(['job' => ['id' => 'j2', 'kind' => 'snapshot', 'state' => 'queued']], 202)
                    : Http::response(['items' => $s['snapshots'][$key] ?? [], 'backend' => 'rsync']);
            }
            if (preg_match('#^/snapshots/(.+)$#', $rest, $sm) && $method === 'DELETE') {
                return Http::response(['job' => ['id' => 'j3', 'kind' => 'snapshot_delete', 'state' => 'queued', 'snapshot' => $sm[1]]], 202);
            }
            if ($method === 'PUT') {
                if ($fields = $this->shareFieldErrors($body)) {
                    return $invalid($fields);
                }
                $s['shares'][$key] = array_replace($s['shares'][$key], $body);

                return Http::response($s['shares'][$key]);
            }
            if ($method === 'DELETE') {
                unset($s['shares'][$key]);

                return ($query['delete_data'] ?? '0') === '1' ? Http::response(['job' => ['id' => 'j4', 'kind' => 'delete_share', 'state' => 'queued']], 202) : Http::response(null, 204);
            }

            return Http::response($s['shares'][$key]);
        }
        if ($path === '/api/sessions') {
            return Http::response(['items' => array_values($s['sessions']), 'total' => count($s['sessions'])]);
        }
        if (preg_match('#^/api/sessions/(.+)$#', $path, $m) && $method === 'DELETE') {
            unset($s['sessions'][$m[1]]);

            return Http::response(null, 204);
        }
        if ($path === '/api/open-files') {
            $items = [['share' => 'Buchhaltung', 'path' => '2026/Rechnungen.xlsx', 'user' => 'EXAMPLE\\max.muster', 'session_id' => '3121', 'mode' => 'write', 'oplock' => 'batch', 'opened_at' => 1764090200000],
                ['share' => 'Projekte', 'path' => 'Plan.docx', 'user' => 'EXAMPLE\\anna', 'session_id' => '3122', 'mode' => 'read', 'oplock' => 'none', 'opened_at' => 1764090200000]];
            $items = array_values(array_filter($items, fn ($f) => ($query['share'] ?? '') === '' || $f['share'] === $query['share']));

            return Http::response(['items' => $items, 'total' => count($items)]);
        }

        return $error(404, 'Nicht gefunden.');
    }

    /** @return array<string, string> */
    private function shareFieldErrors(array $body): array
    {
        $fields = [];
        if (isset($body['snapshots']['keep']['daily']) && (! is_int($body['snapshots']['keep']['daily']) || $body['snapshots']['keep']['daily'] > 365)) {
            $fields['snapshots.keep.daily'] = '0 bis 365.';
        }
        foreach ((array) ($body['snapshots']['schedule']['times'] ?? []) as $i => $t) {
            if (! preg_match('/^\d\d:\d\d$/', $t)) {
                $fields["snapshots.schedule.times.$i"] = 'Bitte HH:MM.';
            }
        }
        $size = $body['soft_quota']['size_bytes'] ?? 0;
        if (! is_int($size) || ($size > 0 && $size < 1073741824)) {
            $fields['soft_quota.size_bytes'] = '0 oder mindestens 1 GB.';
        }

        return $fields;
    }

    // --- fake PK95 AD --------------------------------------------------------------------

    private function adApi(Request $r)
    {
        $url = parse_url($r->url());
        $path = rawurldecode($url['path']);
        parse_str($url['query'] ?? '', $query);
        $method = $r->method();
        $body = $r->data();
        $this->calls[] = ['app' => 'ad', 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body];
        $a = &$this->as;
        $error = fn (int $status, string $msg) => Http::response(['error' => $msg], $status);

        if ($path === '/api/setup') {
            return Http::response(['state' => $a['state'], 'error' => '', 'log' => []]);
        }
        if ($a['state'] !== 'ready') {
            return $error(409, 'Die Domäne ist noch nicht eingerichtet.');
        }
        if ($path === '/api/domain') {
            return Http::response(['realm' => 'ad.example.com', 'kerberos_realm' => 'AD.EXAMPLE.COM', 'netbios_domain' => 'EXAMPLE',
                'dc' => ['hostname' => 'dc1', 'fqdn' => 'dc1.ad.example.com', 'ip' => '192.168.1.10']]);
        }
        if ($path === '/api/computers/odj' && $method === 'POST') {
            if ($a['odjError'] !== null) {
                return $error(500, $a['odjError']);
            }
            $name = strtoupper((string) $body['name']);
            if (in_array($name, $a['accounts'], true) && empty($body['reset'])) {
                return $error(409, "Das Computerkonto {$name} gibt es bereits.");
            }
            $a['accounts'][] = $name;
            $this->blob = base64_encode('ODJ-PROVISION-'.bin2hex(random_bytes(12)));
            $this->blobs[] = $this->blob;

            return Http::response(['computer' => ['id' => 'c-'.$name, 'name' => $name, 'dns_hostname' => $body['dns_hostname']], 'created' => true, 'odj' => $this->blob,
                'domain' => ['realm' => 'ad.example.com', 'netbios_domain' => 'EXAMPLE', 'dc' => ['hostname' => 'dc1', 'ip' => '192.168.1.10']]], 200, ['Cache-Control' => 'no-store']);
        }
        if ($path === '/api/dns/zones/ad.example.com/records') {
            if ($method === 'POST') {
                $record = ['id' => 'r'.count($a['records']), 'zone' => 'ad.example.com'] + $body + ['dynamic' => false, 'system' => false];
                $a['records'][] = $record;

                return Http::response($record, 201);
            }
            $q = (string) ($query['q'] ?? '');

            return Http::response(array_values(array_filter($a['records'], fn ($rec) => $q === '' || stripos($rec['name'], $q) !== false)));
        }
        if ($path === '/api/groups') {
            return Http::response(['items' => $a['groups'], 'total' => count($a['groups'])]);
        }
        // the GPO page with drive maps (for the shortcut)
        if ($path === '/api/ous') {
            return Http::response(['id' => 'd0', 'name' => 'ad.example.com', 'type' => 'domain', 'path' => '', 'parent_id' => '', 'protected' => true, 'block_inheritance' => false, 'children' => []]);
        }
        if ($path === '/api/ous/d0/gpo-inheritance') {
            return Http::response(['block_inheritance' => false, 'items' => []]);
        }
        if ($path === '/api/gpos') {
            return Http::response([['id' => 'gp-9', 'guid' => '{GP-9}', 'name' => 'Laufwerke', 'status' => 'enabled', 'builtin' => false, 'created_at' => 1762600000000,
                'changed_at' => 1762690000000, 'version' => ['computer' => 0, 'user' => 0], 'links' => [], 'sysvol_ok' => true]]);
        }
        if ($path === '/api/gpos/catalog') {
            return Http::response([['key' => 'deny_cmd', 'scope' => 'user', 'group' => 'System', 'label' => 'Eingabeaufforderung sperren', 'type' => 'bool']]);
        }
        if ($path === '/api/gpos/gp-9') {
            return Http::response(['id' => 'gp-9', 'name' => 'Laufwerke', 'status' => 'enabled', 'builtin' => false, 'settings' => [], 'other_settings' => []]);
        }
        if ($path === '/api/gpos/gp-9/drives') {
            if ($method === 'PUT') {
                $a['drives'] = $body['items'];
            }

            return Http::response(['items' => $a['drives']]);
        }

        return $error(404, 'Nicht gefunden.');
    }

    /** @return list<array<string, mixed>> */
    private function sent(string $app, string $method, string $path): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['app'] === $app && $c['method'] === $method && $c['path'] === $path));
    }

    private function ready(): void
    {
        $this->fs['setup'] = ['state' => 'ready', 'error' => '', 'log' => []];
    }

    // --- catalog -------------------------------------------------------------------------

    public function test_catalog_entry_runs_on_the_host_network_with_the_data_mount(): void
    {
        $app = (new Catalog(base_path('catalog')))->get('file');

        $this->assertSame('http://host.docker.internal:8445', $app->baseUrl());
        $this->assertSame(['file', 'ghcr.io/philippkuhlmann/pk95-file'], [$app->module, $app->image]);
        $this->assertSame(['NET_ADMIN', 'NET_RAW'], $app->capAdd);
        $this->assertSame([['source' => '/srv/pk95/files', 'target' => '/srv/files', 'create' => true]], $app->binds);
        $spec = ContainerSpec::for($app, 'latest', str_repeat('t', 48));
        $this->assertSame('host', $spec['HostConfig']['NetworkMode']);
        $this->assertSame(['/var/lib/samba', '/etc/samba', '/var/lib/pk95file', '/srv/files'], array_column($spec['HostConfig']['Mounts'], 'Target'));
        $this->assertContains('PK95FILE_DATA_ROOT=/srv/files', $spec['Env']);
        $this->assertSame(['1.0.0', '2.0.0'], [(new FileModule)->minVersion(), (new FileModule)->maxVersion()]);
    }

    public function test_the_default_ip_comes_from_cores_env_or_setting_only(): void
    {
        $app = (new Catalog(base_path('catalog')))->get('file');
        $env = fn () => ContainerSpec::for($app, 'latest', 't')['Env'];

        // not set: empty, the app probes an address itself
        config(['pk95.defaults.file_default_ip' => '']);
        $this->assertContains('PK95FILE_DEFAULT_IP=', $env());
        // PK95FILE_DEFAULT_IP of Core's .env is the setting's default
        config(['pk95.defaults.file_default_ip' => '192.168.1.20']);
        $this->assertContains('PK95FILE_DEFAULT_IP=192.168.1.20', $env());
        Settings::put('file_default_ip', '192.168.1.21');
        $this->assertContains('PK95FILE_DEFAULT_IP=192.168.1.21', $env());
        // an invalid value is no suggestion
        Settings::put('file_default_ip', 'files.example.com');
        $this->assertContains('PK95FILE_DEFAULT_IP=', $env());
    }

    public function test_public_files_carry_only_example_addresses(): void
    {
        $catalog = file_get_contents(base_path('catalog/file.yaml'));
        $docs = file_get_contents(base_path('docs/lxc-setup.md'));
        $section = substr($docs, strpos($docs, '## Dateiserver (PK95 File)'));
        $section = substr($section, 0, strpos($section, "\n## ", 5) ?: null);
        $this->assertStringContainsString('backup=1', $section);
        $this->assertStringContainsString('PK95FILE_DEFAULT_IP', $section);

        foreach (['catalog/file.yaml' => $catalog, 'lxc-setup.md (Dateiserver)' => $section] as $source => $text) {
            preg_match_all('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', $text, $ips);
            foreach ($ips[0] as $ip) {
                $this->assertTrue($ip === '0.0.0.0' || str_starts_with($ip, '192.168.1.'), "{$source}: {$ip} is not an example address");
            }
            preg_match_all('/\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:de|com|net|org|local|lan|intern)\b/i', $text, $hosts);
            foreach ($hosts[0] as $host) {
                $this->assertMatchesRegularExpression('/(^|\.)example\.(com|de)$/', $host, "{$source}: {$host} is not an example domain");
            }
        }
    }

    // --- setup wizard --------------------------------------------------------------------

    public function test_the_wizard_needs_pk95_ad_set_up(): void
    {
        $this->ad->delete();
        $this->get(route('apps.page', [$this->file, 'server']))->assertOk()
            ->assertSee('PK95 AD ist nicht installiert')->assertSee('data-ad-required="missing"', false)
            ->assertSee(route('apps.index'), false)->assertDontSee('Dateiserver einrichten</button>', false);

        $this->ad = InstalledApp::factory()->create(['app_id' => 'ad', 'version' => '1.5.0']);
        $this->as['state'] = 'unconfigured';
        $this->get(route('apps.page', [$this->file, 'server']))->assertOk()
            ->assertSee('Die Domäne in PK95 AD ist noch nicht eingerichtet')
            ->assertSee(route('apps.page', [$this->ad, 'domain']), false)->assertDontSee('data-start-setup', false);

        $this->ad->update(['status' => InstalledApp::STOPPED]);
        $this->get(route('apps.page', [$this->file, 'server']))->assertOk()->assertSee('PK95 AD läuft gerade nicht.');
    }

    public function test_the_wizard_shows_the_suggestion_the_preflight_and_checks_the_ip(): void
    {
        $this->fs['storage']['warnings'] = ['Weniger als 20 % frei.'];
        $html = $this->get(route('apps.page', [$this->file, 'server']))->assertOk()
            ->assertSee('Dateiserver einrichten')->assertSee('Vorschlag: die erste freie Adresse nach der des LXC')
            ->assertSee('Netz 192.168.1.0/24 auf eth0')->assertSee('Weniger als 20 % frei.')->assertSee('zfs')
            ->assertDontSee('Mit Admin-Kennwort beitreten')->getContent();
        $this->assertStringContainsString('\\\\files.ad.example.com\\…', html_entity_decode($html));

        $this->fs['defaults']['ip_source'] = 'env';
        $page = Livewire::test(Server::class, ['installedApp' => $this->file])
            ->assertSet('setupForm.hostname', 'files')->assertSet('setupForm.ip', '192.168.1.20')
            ->assertSee('Vorschlag aus der Einstellung PK95FILE_DEFAULT_IP')
            ->call('checkIp')->assertSet('ipCheck.ok', true)->assertSee('Die Adresse 192.168.1.20 ist frei.')
            ->set('setupForm.ip', '192.168.1.30')->assertSet('ipCheck', null)
            ->call('checkIp')->assertSet('ipCheck.ok', false)->assertSee('ist im Netz bereits vergeben');
        $this->assertSame(['ip' => '192.168.1.30'], last($this->sent('file', 'GET', '/api/setup/ip-check'))['query']);

        // an address that is taken stops before PK95 AD is touched
        $page->call('startSetup')->assertHasErrors('setupForm.ip');
        $this->assertSame([], array_filter($this->calls, fn ($c) => $c['app'] === 'ad' && $c['method'] !== 'GET'));

        // existing: only the LXC's addresses without the DC's, checked with mode=existing
        $this->fs['defaults']['host_ips'] = ['192.168.1.10', '192.168.1.25'];
        $page->set('setupForm.ip_mode', 'existing')->assertSet('setupForm.ip', '192.168.1.25')->call('checkIp');
        $this->assertSame(['ip' => '192.168.1.25', 'mode' => 'existing'], last($this->sent('file', 'GET', '/api/setup/ip-check'))['query']);

        // a bad hostname and a missing confirmation for the system disk
        $this->fs['storage']['on_system_disk'] = true;
        Livewire::test(Server::class, ['installedApp' => $this->file])->set('setupForm.hostname', '1234')->call('startSetup')
            ->assertHasErrors(['setupForm.hostname', 'setupForm.allow_system_disk'])->assertSee('Trotzdem auf der Systemplatte des LXC ablegen');

        // problems of the data folder block the start
        $this->fs['storage']['problems'] = ['Das Dateisystem unterstützt keine user.*-Attribute.'];
        Livewire::test(Server::class, ['installedApp' => $this->file])->assertSeeHtml('data-storage-problem')
            ->set('setupForm.allow_system_disk', true)->call('startSetup')->assertSet('joinError', 'Der Datenordner ist nicht bereit (siehe oben).');
        $this->assertSame([], $this->sent('file', 'POST', '/api/setup'));
    }

    public function test_the_join_passes_the_blob_unchanged_and_never_keeps_it(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        $cached = [];
        Event::listen(KeyWritten::class, function (KeyWritten $e) use (&$cached) {
            $cached[] = serialize($e->value);
        });

        $page = Livewire::test(Server::class, ['installedApp' => $this->file])
            ->set('setupForm.hostname', 'Files')->assertSet('setupForm.hostname', 'files')
            ->call('startSetup')->assertHasNoErrors()->assertSet('joinError', null)->assertSet('watching', true)
            ->assertDispatched('notice', kind: 'success', text: 'Der Dateiserver tritt der Domäne bei. Das dauert etwa eine halbe Minute.')
            ->assertSee('Der Dateiserver tritt der Domäne bei (etwa 10 bis 30 Sekunden)')->assertSee('net offlinejoin requestodj');

        // AD: the account, then the blob straight to the file server
        $this->assertSame(['name' => 'files', 'dns_hostname' => 'files.ad.example.com', 'description' => 'PK95 File', 'reset' => false],
            $this->sent('ad', 'POST', '/api/computers/odj')[0]['body']);
        $this->assertSame(['name' => 'files.ad.example.com', 'type' => 'A', 'value' => '192.168.1.20', 'ttl' => 900],
            $this->sent('ad', 'POST', '/api/dns/zones/ad.example.com/records')[0]['body']);
        $this->assertSame(['hostname' => 'files', 'ip' => '192.168.1.20', 'ip_mode' => 'managed', 'realm' => 'ad.example.com', 'workgroup' => 'EXAMPLE',
            'dc' => ['hostname' => 'dc1', 'ip' => '192.168.1.10'], 'odj' => $this->blob, 'allow_system_disk' => false], $this->sent('file', 'POST', '/api/setup')[0]['body']);
        // DNS before the blob: a retry finds the record instead of a half-made account
        $order = array_map(fn ($c) => $c['method'].' '.$c['path'], array_values(array_filter($this->calls, fn ($c) => $c['method'] === 'POST')));
        $this->assertSame(['POST /api/dns/zones/ad.example.com/records', 'POST /api/computers/odj', 'POST /api/setup'], $order);

        // polling until ready
        $this->ready();
        $page->call('$refresh')->assertSet('watching', false)->assertDispatched('notice', kind: 'success', text: 'Der Dateiserver ist in der Domäne.')
            ->assertSee('files.ad.example.com')->assertSee('Speicherplatz');
        $this->get(route('apps.page', [$this->file, 'server']))->assertOk();

        // the blob is nowhere: component state, rendered page, database, session, cache, logs, events
        $blob = $this->blob;
        $this->assertNotSame('', $blob);
        $this->assertStringNotContainsString($blob, json_encode($page->snapshot));
        $this->assertStringNotContainsString($blob, $page->html());
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table'") as $table) {
            foreach (DB::table($table->name)->get() as $row) {
                $this->assertStringNotContainsString($blob, json_encode($row), "table {$table->name}");
            }
        }
        $this->assertStringNotContainsString($blob, json_encode(session()->all()));
        $this->assertStringNotContainsString($blob, implode("\n", $cached));
        $this->assertStringNotContainsString($blob, implode("\n", $logged));
        $this->assertStringNotContainsString($blob, json_encode(AppEvent::all()));
        $this->assertStringNotContainsString($blob, (string) Cache::get('dashboard.figures.file'));
    }

    public function test_an_existing_computer_account_is_reset_on_request(): void
    {
        $this->as['accounts'] = ['FILES'];
        $page = Livewire::test(Server::class, ['installedApp' => $this->file])->call('startSetup')
            ->assertSet('offerReset', true)->assertSet('joinError', 'Das Computerkonto FILES gibt es bereits.')
            ->assertSee('Neu verbinden')->assertSet('odjFailed', false)->assertDontSee('Mit Admin-Kennwort beitreten');
        $this->assertSame([], $this->sent('file', 'POST', '/api/setup'));

        $page->call('startSetup', true)->assertSet('offerReset', false)->assertSet('watching', true);
        $this->assertTrue(last($this->sent('ad', 'POST', '/api/computers/odj'))['body']['reset']);
        $this->assertSame($this->blob, $this->sent('file', 'POST', '/api/setup')[0]['body']['odj']);
        // the record from the first try is found again, not created twice
        $this->assertCount(1, $this->sent('ad', 'POST', '/api/dns/zones/ad.example.com/records'));
    }

    public function test_rejoin_sends_a_new_blob_to_join(): void
    {
        $this->ready();
        $this->as['accounts'] = ['FILES'];
        $this->get(route('apps.page', [$this->file, 'server']))->assertOk()->assertSee('Neu beitreten');

        Livewire::test(Server::class, ['installedApp' => $this->file])->call('rejoin')
            ->assertDispatched('notice', kind: 'success', text: 'Der Dateiserver tritt der Domäne neu bei.')->assertSet('watching', true);
        $this->assertSame(['name' => 'files', 'dns_hostname' => 'files.ad.example.com', 'description' => 'PK95 File', 'reset' => true],
            $this->sent('ad', 'POST', '/api/computers/odj')[0]['body']);
        $this->assertSame(['odj' => $this->blob], $this->sent('file', 'POST', '/api/join')[0]['body']);
        $this->assertSame([], $this->sent('ad', 'POST', '/api/dns/zones/ad.example.com/records'));
    }

    public function test_the_password_fallback_only_after_a_failed_offline_join_and_not_kept(): void
    {
        $page = Livewire::test(Server::class, ['installedApp' => $this->file])->assertSet('odjFailed', false)->assertDontSeeHtml('data-password-join');

        // PK95 AD fails to compose the blob
        $this->as['odjError'] = 'net offlinejoin composeodj ist fehlgeschlagen.';
        $page->call('startSetup')->assertSet('odjFailed', true)->assertSet('offerReset', false)
            ->assertSet('joinError', 'PK95 AD hat das Computerkonto FILES nicht angelegt: net offlinejoin composeodj ist fehlgeschlagen.')
            ->assertSeeHtml('data-password-join')->assertSee('Mit Admin-Kennwort beitreten');
        $this->assertSame([], $this->sent('file', 'POST', '/api/setup'));

        $password = 'Admin-Geheim-'.bin2hex(random_bytes(6));
        $page->set('joinPassword', $password)->call('joinWithPassword')->assertSet('joinPassword', '')->assertSet('watching', true);
        $body = $this->sent('file', 'POST', '/api/setup')[0]['body'];
        $this->assertSame(['Administrator', $password], [$body['join_user'], $body['join_password']]);
        $this->assertArrayNotHasKey('odj', $body);
        $this->assertStringNotContainsString($password, json_encode($page->snapshot));
        $this->assertStringNotContainsString($password, $page->html());
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table'") as $table) {
            $this->assertStringNotContainsString($password, json_encode(DB::table($table->name)->get()));
        }

        // empty password: nothing sent
        Livewire::test(Server::class, ['installedApp' => $this->file])->set('odjFailed', true)->set('joinPassword', '')->call('joinWithPassword')->assertHasErrors('joinPassword');
    }

    public function test_the_app_refusing_the_blob_opens_the_fallback(): void
    {
        $this->fs['rejectOdj'] = true;
        Livewire::test(Server::class, ['installedApp' => $this->file])->call('startSetup')
            ->assertSet('odjFailed', true)->assertHasErrors('setupForm.odj')->assertSee('Die Beitrittsdaten gehören zu einem anderen Computer.')
            ->assertSee('Mit Admin-Kennwort beitreten');

        // a failed setup on the next visit offers it as well
        $this->fs['rejectOdj'] = false;
        $this->fs['setup'] = ['state' => 'failed', 'error' => 'wbinfo -t ist fehlgeschlagen.', 'log' => ['ERROR: trust']];
        $this->get(route('apps.page', [$this->file, 'server']))->assertOk()
            ->assertSee('Der Beitritt ist fehlgeschlagen')->assertSee('wbinfo -t ist fehlgeschlagen.')->assertSee('ERROR: trust')
            ->assertSee('Erneut einrichten')->assertSee('Mit Admin-Kennwort beitreten');
    }

    public function test_dns_record_present_with_the_ip_is_kept_and_another_one_stops_the_join(): void
    {
        $record = ['id' => 'f1', 'zone' => 'ad.example.com', 'name' => 'FILES.ad.example.com', 'type' => 'A', 'value' => '192.168.1.20', 'ttl' => 900, 'dynamic' => false, 'system' => false];
        $this->as['records'][] = $record;
        Livewire::test(Server::class, ['installedApp' => $this->file])->call('startSetup')->assertSet('joinError', null)->assertSet('watching', true);
        $this->assertSame([], $this->sent('ad', 'POST', '/api/dns/zones/ad.example.com/records'), 'same name and address: skipped');
        $this->assertSame(['q' => 'files.ad.example.com'], $this->sent('ad', 'GET', '/api/dns/zones/ad.example.com/records')[0]['query']);

        // the name points elsewhere: no account, no setup, nothing overwritten
        $this->calls = [];
        $this->fs['setup'] = ['state' => 'unconfigured', 'error' => '', 'log' => []];
        $this->as['records'][1]['value'] = '192.168.1.99';
        Livewire::test(Server::class, ['installedApp' => $this->file])->call('startSetup')
            ->assertSet('joinError', 'Der DNS-Name files.ad.example.com zeigt schon auf 192.168.1.99 (A). Wähle einen anderen Servernamen oder ändere den Eintrag in PK95 AD unter DNS.')
            ->assertSet('odjFailed', false)->assertSee('Der Beitritt ist nicht gestartet');
        $this->assertSame([], $this->sent('ad', 'POST', '/api/computers/odj'));
        $this->assertSame([], $this->sent('ad', 'POST', '/api/dns/zones/ad.example.com/records'));
        $this->assertSame([], $this->sent('file', 'POST', '/api/setup'));
    }

    // --- server page when ready ----------------------------------------------------------

    public function test_server_page_shows_storage_status_warnings_and_saves_settings(): void
    {
        $this->ready();
        $this->get(route('apps.page', [$this->file, 'server']))->assertOk()
            ->assertSee('Speicherplatz')->assertSee('data-tone="ok"', false)->assertSee('Vorgängerversionen 5,0 GB')->assertSee('214')
            ->assertSee('Freigabe „Buchhaltung“: 93 % des Kontingents belegt.')->assertSee('Vertrauensstellung in Ordnung')->assertSee('192.168.1.20')
            ->assertSee('Einstellungen')->assertSee('SMB-Verschlüsselung');

        // free space: orange from the disk warning, red when critical
        $this->fs['free_percent'] = 15;
        $this->fs['warnings'][] = ['level' => 'warning', 'code' => 'disk', 'message' => 'Nur noch 15 % frei.'];
        $this->get(route('apps.page', [$this->file, 'server']))->assertSee('data-tone="warn"', false);
        $this->fs['warnings'][1]['level'] = 'critical';
        $this->get(route('apps.page', [$this->file, 'server']))->assertSee('data-tone="bad"', false);
        $this->assertSame('bad', Server::storageBar(['storage' => ['total_bytes' => 100, 'free_bytes' => 5, 'free_percent' => 5]])['tone'], 'no warning: from free_percent');

        Livewire::test(Server::class, ['installedApp' => $this->file])
            ->set('settings.disk_critical_percent', '25')->call('saveSettings')->assertHasErrors('settings.disk_critical_percent')
            ->set('settings.disk_critical_percent', '8')->set('settings.smb_encryption', 'desired')->call('saveSettings')->assertHasNoErrors()
            ->assertDispatched('notice', kind: 'success', text: 'Einstellungen gespeichert. Samba lädt sie neu, die Sitzungen bleiben.');
        $this->assertSame(['scan_interval_minutes' => 60, 'disk_warn_percent' => 20, 'disk_critical_percent' => 8, 'snapshot_min_free_percent' => 15,
            'smb_encryption' => 'desired', 'min_protocol' => 'SMB2_10'], last($this->sent('file', 'PUT', '/api/settings'))['body']);
    }

    public function test_shares_and_sessions_point_to_the_wizard_until_ready(): void
    {
        foreach (['shares', 'sessions'] as $page) {
            $this->get(route('apps.page', [$this->file, $page]))->assertOk()
                ->assertSee('Der Dateiserver ist noch nicht eingerichtet')
                ->assertSee(route('apps.page', [$this->file, 'server']), false)->assertDontSee('PK95 File antwortet nicht');
        }
    }

    // --- shares --------------------------------------------------------------------------

    public function test_shares_list_with_status_usage_and_drive_map_link(): void
    {
        $this->ready();
        $html = $this->get(route('apps.page', [$this->file, 'shares']))->assertOk()
            ->assertSee('Buchhaltung')->assertSee('Kontingent fast voll')->assertSee('93,0 GB')->assertSee('von 100,0 GB')
            ->assertSee('Als Netzlaufwerk verteilen')->getContent();
        $link = route('apps.page', ['installedApp' => $this->ad, 'page' => 'gpo', 'laufwerk' => '\\\\files.ad.example.com\\Buchhaltung']);
        $this->assertStringContainsString(e($link), $html);

        // without PK95 AD no shortcut
        $this->ad->update(['status' => InstalledApp::STOPPED]);
        $this->get(route('apps.page', [$this->file, 'shares']))->assertOk()->assertDontSee('Als Netzlaufwerk verteilen');
    }

    public function test_shares_render_as_cards_below_md_and_as_a_table_above(): void
    {
        $this->ready();
        $html = $this->get(route('apps.page', [$this->file, 'shares']))->assertOk()->getContent();

        // one card (md:hidden) and one table row (hidden md:block) per share, both with the actions
        $this->assertMatchesRegularExpression('/class="[^"]*\bmd:hidden\b[^"]*" data-share-cards/', $html);
        $this->assertMatchesRegularExpression('/class="[^"]*\bhidden\b[^"]*\bmd:block\b[^"]*"[^>]*>\s*<table[^>]*data-shares/', $html);
        $this->assertSame(1, substr_count($html, 'data-share-card="Buchhaltung"'));
        $this->assertSame(1, substr_count($html, 'data-share="Buchhaltung"'));
        $this->assertSame(2, substr_count($html, 'wire:click="confirmDelete('));
        $this->assertStringContainsString('Buchhaltung: Ändern, Geschäftsführung: Lesen', $html);
        $this->assertStringContainsString(e('\\\\files.ad.example.com\\Buchhaltung'), $html);

        $this->assertSame('Nur Administratoren', Shares::permissionSummary(['permissions' => []]));
        $this->assertSame('S-1-5-21-1-1111: Vollzugriff', Shares::permissionSummary(['permissions' => [['sid' => 'S-1-5-21-1-1111', 'access' => 'full']]]));
    }

    public function test_create_share_with_permissions_from_ad_groups_and_nested_errors(): void
    {
        $this->ready();
        $page = Livewire::test(Shares::class, ['installedApp' => $this->file])->call('create')
            ->assertSee('Vorgängerversionen brauchen etwa das Doppelte an Platz.')
            ->set('form.name', 'Projekte')->set('form.description', 'Laufende Projekte')
            ->call('addPermission')->call('save')->assertHasErrors('form.permissions.0.sid')
            ->assertSeeHtml('data-options=')->assertSeeHtml('S-1-5-21-1-1111')->assertSee('Vertrieb');
        $this->assertSame([], $this->sent('file', 'POST', '/api/shares'));
        $this->assertSame(['type' => 'security'], $this->sent('ad', 'GET', '/api/groups')[0]['query']);

        // nested 422 fields land on their form fields
        $page->set('form.permissions.0.sid', 'S-1-5-21-1-1111')->set('form.permissions.0.access', 'read')
            ->set('form.snapshots.keep.daily', '400')->set('form.snapshots.times', '07:00, 7 Uhr')->set('form.soft_quota.size_gb', '0,5')
            ->call('save')->assertHasErrors(['form.snapshots.keep.daily', 'form.snapshots.times', 'form.soft_quota.size_gb'])->assertSet('editing', 'new');

        $page->set('form.snapshots.keep.daily', '14')->set('form.snapshots.times', '07:00, 12:00')->set('form.soft_quota.size_gb', '50')
            ->set('form.snapshots.weekdays', ['1', '3', '5'])->call('save')->assertHasNoErrors()->assertSet('editing', null)
            ->assertDispatched('notice', kind: 'success', text: 'Freigabe Projekte angelegt.');
        $this->assertSame([
            'name' => 'Projekte', 'description' => 'Laufende Projekte', 'enabled' => true, 'abe' => true,
            'recycle' => ['enabled' => true, 'keep_days' => 30, 'max_file_mb' => 0, 'exclude' => ['*.tmp', '~$*', '*.~*']],
            'snapshots' => ['enabled' => true, 'schedule' => ['times' => ['07:00', '12:00'], 'weekdays' => [1, 3, 5]], 'keep' => ['recent' => 12, 'daily' => 14, 'weekly' => 8]],
            'soft_quota' => ['size_bytes' => 53687091200, 'warn_percent' => 90, 'lock_when_exceeded' => false],
            'permissions' => [['sid' => 'S-1-5-21-1-1111', 'access' => 'read']],
        ], last($this->sent('file', 'POST', '/api/shares'))['body']);

        // an unknown SID: the error lands on its row
        $page->call('create')->set('form.name', 'Technik')->call('addPermission')->set('form.permissions.0.sid', 'S-1-5-21-1-9999')
            ->call('save')->assertHasErrors('form.permissions.0.sid');
    }

    public function test_a_left_over_folder_is_adopted_on_request(): void
    {
        $this->ready();
        Livewire::test(Shares::class, ['installedApp' => $this->file])->call('create')->set('form.name', 'Alt')->call('save')
            ->assertHasErrors('form.name')->assertSet('offerAdopt', true)->assertSee('Der Ordner existiert bereits')
            ->call('adopt')->assertSet('editing', null)->assertSet('offerAdopt', false);
        $posts = $this->sent('file', 'POST', '/api/shares');
        $this->assertArrayNotHasKey('adopt', $posts[0]['body']);
        $this->assertTrue($posts[1]['body']['adopt']);
    }

    public function test_edit_share_and_its_permissions(): void
    {
        $this->ready();
        $page = Livewire::test(Shares::class, ['installedApp' => $this->file])->call('edit', 'Buchhaltung')
            ->assertSet('editing', 'Buchhaltung')->assertSet('form.soft_quota.size_gb', '100')->assertSet('form.recycle.exclude', '*.tmp, ~$*')
            ->assertSet('form.snapshots.weekdays', ['1', '2', '3', '4', '5'])->assertSee('EXAMPLE\\Domain Admins')->assertSee('58,6 GB')
            ->set('form.description', 'Belege')->call('save')->assertSet('editing', null)
            ->assertDispatched('notice', kind: 'success', text: 'Freigabe Buchhaltung gespeichert.');
        $put = last($this->sent('file', 'PUT', '/api/shares/Buchhaltung'))['body'];
        $this->assertSame('Belege', $put['description']);
        $this->assertArrayNotHasKey('name', $put);
        $this->assertArrayNotHasKey('permissions', $put);
        $this->assertSame([], $this->sent('file', 'PUT', '/api/shares/Buchhaltung/permissions'), 'rights unchanged: not sent');

        // a changed right, propagated: a job
        $page->call('edit', 'Buchhaltung')->call('removePermission', 1)->set('form.permissions.0.access', 'full')->set('form.propagate', true)->call('save')
            ->assertDispatched('notice', kind: 'success', text: 'Freigabe Buchhaltung gespeichert. Die Rechte werden im Hintergrund auf alle Unterordner übertragen.');
        $this->assertSame(['permissions' => [['sid' => 'S-1-5-21-1-1107', 'access' => 'full']], 'propagate' => true],
            last($this->sent('file', 'PUT', '/api/shares/Buchhaltung/permissions'))['body']);

        // 422 of the rights on the row; a group of the share the AD list lacks stays selectable
        $this->fs['shares']['buchhaltung']['permissions'][] = ['sid' => 'S-1-5-21-1-4040', 'name' => '', 'type' => 'group', 'access' => 'read', 'unresolved' => true];
        $page->call('edit', 'Buchhaltung')->assertSeeHtml('Unbekannt (S-1-5-21-1-4040)')
            ->set('form.permissions.0.access', 'read')->call('save')->assertHasErrors('form.permissions.1.sid')->assertSet('editing', 'Buchhaltung');
    }

    public function test_hidden_share_names_are_encoded_in_the_path(): void
    {
        $this->ready();
        $this->fs['shares']['admin$'] = $this->share('Admin$');
        Livewire::test(Shares::class, ['installedApp' => $this->file])->call('edit', 'Admin$')->call('save')->assertSet('editing', null);
        $this->assertNotEmpty($this->sent('file', 'PUT', '/api/shares/Admin$'));
        $this->assertTrue(Http::recorded()->contains(fn ($pair) => $pair[0]->method() === 'PUT' && str_ends_with($pair[0]->url(), '/api/shares/Admin%24')));
    }

    public function test_delete_share_without_and_with_data(): void
    {
        $this->ready();
        $this->fs['shares']['projekte'] = $this->share('Projekte');
        Livewire::test(Shares::class, ['installedApp' => $this->file])
            ->call('confirmDelete', 'Buchhaltung')->call('deleteShare')->assertSet('deleting', null)
            ->assertDispatched('notice', kind: 'success', text: 'Freigabe Buchhaltung entfernt. Der Ordner, der Papierkorb und die Vorgängerversionen bleiben erhalten.')
            ->call('confirmDelete', 'Projekte')->set('deleteData', true)->set('deleteConfirm', 'projekte')->call('deleteShare')
            ->assertHasErrors('deleteConfirm')->assertSet('deleting', 'Projekte')
            ->set('deleteConfirm', 'Projekte')->call('deleteShare')->assertSet('deleting', null)
            ->assertDispatched('notice', kind: 'success', text: 'Freigabe Projekte wird mit allen Daten gelöscht.');
        $this->assertSame([['delete_data' => '0'], ['delete_data' => '1']], array_column(array_values(array_filter($this->calls, fn ($c) => $c['method'] === 'DELETE')), 'query'));
    }

    public function test_snapshots_dialog_lists_creates_and_deletes(): void
    {
        $this->ready();
        Livewire::test(Shares::class, ['installedApp' => $this->file])->call('openSnapshots', 'Buchhaltung')
            ->assertSee('Vorgängerversionen von Buchhaltung')->assertSee('25.11.2025, 12:00')->assertSee('700,0 MB')->assertSee('letzte, täglich')
            ->call('snapshotNow')->assertDispatched('notice', kind: 'success', text: 'Die Sicherung von Buchhaltung läuft. Sie erscheint hier, sobald sie fertig ist.')
            ->call('deleteSnapshot', '@GMT-2026.11.25-11.00.00')->assertDispatched('notice', kind: 'success', text: 'Die Vorgängerversion wird gelöscht.')
            ->call('cancel')->assertSet('snapshotsFor', null);
        $this->assertCount(1, $this->sent('file', 'POST', '/api/shares/Buchhaltung/snapshots'));
        $this->assertCount(1, $this->sent('file', 'DELETE', '/api/shares/Buchhaltung/snapshots/@GMT-2026.11.25-11.00.00'));
    }

    // --- sessions ------------------------------------------------------------------------

    public function test_sessions_disconnect_and_open_files(): void
    {
        $this->ready();
        $this->get(route('apps.page', [$this->file, 'sessions']))->assertOk()
            ->assertSee('EXAMPLE\\max.muster', false)->assertSee('PC-01')->assertSee('SMB3.11')->assertSee('Sitzung trennen')
            ->assertSee('2026/Rechnungen.xlsx')->assertSee('Plan.docx');

        Livewire::test(Sessions::class, ['installedApp' => $this->file])
            ->set('share', 'Buchhaltung')->assertSee('2026/Rechnungen.xlsx')->assertDontSee('Plan.docx')
            ->call('disconnect', '3121')->assertDispatched('notice', kind: 'success', text: 'Sitzung getrennt. Windows verbindet beim nächsten Zugriff neu.')
            ->assertSee('Gerade ist niemand verbunden.');
        $this->assertCount(1, $this->sent('file', 'DELETE', '/api/sessions/3121'));
    }

    // --- drive map shortcut --------------------------------------------------------------

    public function test_the_gpo_page_adds_a_drive_row_for_the_share(): void
    {
        $unc = '\\\\files.ad.example.com\\Buchhaltung';
        $this->get(route('apps.page', ['installedApp' => $this->ad, 'page' => 'gpo', 'laufwerk' => $unc]))->assertOk()
            ->assertSee('Netzlaufwerk verteilen')->assertSee($unc);

        $page = Livewire::withQueryParams(['laufwerk' => $unc])->test(Gpos::class, ['installedApp' => $this->ad])
            ->assertSet('driveMap', $unc)->assertSeeHtml('data-drive-map-hint')
            ->call('openSettings', 'gp-9')->assertSet('settingsTab', 'user')->assertSet('driveMap', '')
            ->assertSet('gppEditing', 'drives.0')->assertSet('gpp.drives.0.path', $unc)->assertSet('gpp.drives.0.label', 'Buchhaltung')
            ->assertSet('gpp.drives.0.letter', 'H')->assertSee('1 Änderung');
        $page->call('saveSettings')->assertSet('settingsFor', null);
        $this->assertSame(['letter' => 'H', 'first_available' => false, 'path' => $unc, 'label' => 'Buchhaltung', 'action' => 'update', 'reconnect' => true,
            'visibility' => 'unchanged', 'target' => null], $this->as['drives'][0]);

        // opened without the parameter: no row
        Livewire::withQueryParams([])->test(Gpos::class, ['installedApp' => $this->ad])->assertSet('driveMap', '')->call('openSettings', 'gp-9')->assertCount('gpp.drives', 1)->assertSet('gppEditing', null);
    }

    // --- dashboard -----------------------------------------------------------------------

    public function test_dashboard_tile_and_problems(): void
    {
        Livewire::withoutLazyLoading()->test(AppPanel::class, ['appId' => 'file'])
            ->assertSee('Der Dateiserver ist noch nicht eingerichtet.')->assertSee(route('apps.page', [$this->file, 'server']), false);
        $this->assertContains(['tone' => 'warn', 'text' => 'PK95 File: Dateiserver noch nicht eingerichtet', 'href' => route('apps.page', [$this->file, 'server'])],
            app(Problems::class)->collect());

        Cache::flush();
        $this->ready();
        $this->fs['free_percent'] = 8;
        $this->fs['warnings'][] = ['level' => 'critical', 'code' => 'disk', 'message' => 'Nur noch 8 % frei.'];
        $f = app(AppFigures::class)->for($this->file);
        $this->assertSame([true, 1, 1, 'bad', 'files.ad.example.com'], [$f['ready'], $f['shares'], $f['sessions'], $f['diskTone'], $f['fqdn']]);
        Livewire::withoutLazyLoading()->test(AppPanel::class, ['appId' => 'file'])
            ->assertSee('files.ad.example.com')->assertSee('Freigaben')->assertSee('Offene Dateien')->assertSee('214 Vorgängerversionen')
            ->assertSeeHtml('data-tone="bad"')->assertSee('92 % belegt');

        $problems = app(Problems::class)->collect();
        $this->assertSame(['tone' => 'bad', 'text' => 'PK95 File: Nur noch 8 % frei.', 'href' => route('apps.page', [$this->file, 'server'])], $problems[0]);
        $this->assertContains(['tone' => 'warn', 'text' => 'PK95 File: Freigabe „Buchhaltung“: 93 % des Kontingents belegt.', 'href' => route('apps.page', [$this->file, 'shares'])], $problems);
        Livewire::withoutLazyLoading()->test(ProblemList::class)->assertSee('PK95 File: Nur noch 8 % frei.');
        $this->assertContains('file', Dashboard::PANELS);
    }
}
