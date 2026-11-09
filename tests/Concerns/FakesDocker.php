<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * In-memory stand-in for the Docker Engine API (host "docker") and the demo app
 * (host "pk95-demo"), wired through Http::fake. Tests change the state directly:
 * $this->appHealthy = false, $this->docker['pullError'] = '…', etc.
 */
trait FakesDocker
{
    /**
     * images: ref → true or extra inspect fields (RepoDigests); helperRuns: commands of helper
     * containers; brokenImage: image whose app never gets healthy (rollback tests);
     * helperOutput: what helper containers print (null = the demo log lines).
     *
     * @var array<string, mixed>
     */
    protected array $docker = [
        'containers' => [], 'volumes' => [], 'networks' => [], 'images' => [], 'pullError' => null, 'reachable' => true,
        'helperExit' => 0, 'helperRuns' => [], 'brokenImage' => null, 'helperOutput' => null,
    ];

    protected bool $appHealthy = true;

    /** The manifest reports the tag of the running image as version (update tests). */
    protected bool $versionFromImage = false;

    /** @var array<string, mixed> */
    protected array $appManifest = [
        'id' => 'demo',
        'name' => 'Demo-App',
        'version' => '1.2.0',
        'protocol' => 1,
        'icon' => 'list',
        'menu' => [
            ['key' => 'items', 'label' => 'Einträge', 'icon' => 'list'],
            ['key' => 'unknown', 'label' => 'Ohne Modulseite', 'icon' => 'box'],
        ],
        'capabilities' => ['events'],
        'events' => ['item.created'],
    ];

    /** @var list<array{method: string, path: string, auth: ?string}> */
    protected array $appRequests = [];

    protected function fakeDocker(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $parts = parse_url($request->url());
            parse_str($parts['query'] ?? '', $query);
            $path = $parts['path'] ?? '/';

            return match ($parts['host'] ?? '') {
                'docker' => $this->dockerResponse($request->method(), preg_replace('#^/v[\d.]+#', '', $path), $query, $request->data()),
                'pk95-demo' => $this->appResponse($request, $path),
                // other hosts: left to further Http::fake stubs of the test (or refused as stray)
                default => null,
            };
        });
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     */
    private function dockerResponse(string $method, string $path, array $query, array $body)
    {
        if (! $this->docker['reachable']) {
            return Http::failedConnection();
        }
        $notFound = Http::response(['message' => 'No such object'], 404);
        $segments = explode('/', trim($path, '/'));

        if ($path === '/_ping') {
            return Http::response('OK');
        }
        if ($method === 'POST' && $path === '/images/create') {
            if (! $this->docker['pullError']) {
                $this->docker['images'][$query['fromImage'].':'.$query['tag']] ??= true;
            }

            return Http::response($this->docker['pullError']
                ? '{"status":"Pulling"}'."\n".'{"errorDetail":{"message":"'.$this->docker['pullError'].'"},"error":"'.$this->docker['pullError'].'"}'
                : '{"status":"Pulling"}'."\n".'{"status":"Downloaded newer image"}');
        }
        if ($segments[0] === 'images' && ($segments[2] ?? '') === 'json') {
            $ref = urldecode($segments[1]);

            $image = $this->docker['images'][$ref] ?? null;

            return $image !== null ? Http::response((is_array($image) ? $image : []) + ['Id' => 'sha256:'.md5($ref)]) : $notFound;
        }
        if ($segments[0] === 'networks' || $segments[0] === 'volumes') {
            $kind = $segments[0];
            if ($method === 'POST' && ($segments[1] ?? '') === 'create') {
                $this->docker[$kind][$body['Name']] = true;

                return Http::response(['Name' => $body['Name']], 201);
            }
            $name = urldecode($segments[1] ?? '');
            if ($method === 'DELETE') {
                unset($this->docker[$kind][$name]);

                return Http::response('', 204);
            }

            return isset($this->docker[$kind][$name]) ? Http::response(['Name' => $name]) : $notFound;
        }
        if ($segments[0] === 'containers') {
            if ($method === 'POST' && ($segments[1] ?? '') === 'create') {
                $name = $query['name'] ?? 'helper-'.count($this->docker['helperRuns']).'-'.bin2hex(random_bytes(3));
                $id = 'cid-'.$name;
                $this->docker['containers'][$id] = ['Id' => $id, 'Name' => $name, 'running' => false, 'spec' => $body];

                return Http::response(['Id' => $id], 201);
            }
            if (($segments[1] ?? '') === 'json') {
                return Http::response(array_values(array_map(fn ($c) => ['Id' => $c['Id'], 'Names' => ['/'.$c['Name']]], $this->docker['containers'])));
            }
            $ref = urldecode($segments[1] ?? '');
            $id = collect($this->docker['containers'])->search(fn ($c) => $c['Id'] === $ref || $c['Name'] === $ref);
            if ($id === false) {
                return $notFound;
            }
            $action = $segments[2] ?? null;

            return match (true) {
                $method === 'DELETE' => (function () use ($id) {
                    unset($this->docker['containers'][$id]);

                    return Http::response('', 204);
                })(),
                $action === 'start', $action === 'restart' => (function () use ($id) {
                    $this->docker['containers'][$id]['running'] = true;

                    return Http::response('', 204);
                })(),
                $action === 'stop' => (function () use ($id) {
                    $this->docker['containers'][$id]['running'] = false;

                    return Http::response('', 204);
                })(),
                $action === 'json' => Http::response([
                    'Id' => $id,
                    'Image' => $this->imageId((string) ($this->docker['containers'][$id]['spec']['Image'] ?? '')),
                    'State' => ['Running' => $this->docker['containers'][$id]['running'], 'ExitCode' => 0],
                ]),
                $action === 'wait' => Http::response(['StatusCode' => $this->runHelper($this->docker['containers'][$id]['spec'])]),
                $action === 'logs' && $this->docker['helperOutput'] !== null && isset($this->docker['containers'][$id]['spec']['Labels']['pk95.helper']) => Http::response(self::frame(1, '2026-11-08T10:00:00Z '.$this->docker['helperOutput']."\n")),
                $action === 'logs' => Http::response(self::frame(1, "2026-11-08T10:00:00Z server listening on :8080\n").self::frame(2, "2026-11-08T10:00:01Z warn: demo mode\n")),
                default => Http::response(['message' => 'unhandled'], 500),
            };
        }

        return Http::response(['message' => "unhandled {$method} {$path}"], 500);
    }

    private function appResponse(Request $request, string $path)
    {
        $this->appRequests[] = ['method' => $request->method(), 'path' => $path, 'auth' => $request->header('Authorization')[0] ?? null];

        $image = (string) ($this->container('pk95-demo')['spec']['Image'] ?? '');
        $healthy = $this->appHealthy && ($this->docker['brokenImage'] === null || $image !== $this->docker['brokenImage']);
        $manifest = $this->appManifest;
        if ($this->versionFromImage && preg_match('/:v?(\d+\.\d+\.\d+)$/', $image, $m)) {
            $manifest['version'] = $m[1];
        }

        return match ($path) {
            '/core/health' => $healthy ? Http::response(['ok' => true]) : Http::response(['ok' => false], 503),
            '/core/manifest' => Http::response($manifest),
            '/core/backup/prepare' => Http::response('', 204),
            '/api/items' => Http::response([['id' => 1, 'title' => 'Erster Eintrag']]),
            default => Http::response(['error' => 'not found'], 404),
        };
    }

    /** Image id the fake reports for a reference (sha256:… refs stay as they are). */
    protected function imageId(string $ref): string
    {
        return str_starts_with($ref, 'sha256:') ? $ref : 'sha256:'.md5($ref);
    }

    /**
     * A helper container "runs": tar czf writes a small file into the bound backup dir.
     *
     * @param  array<string, mixed>  $spec
     */
    private function runHelper(array $spec): int
    {
        $cmd = (array) ($spec['Cmd'] ?? []);
        $mounts = (array) ($spec['HostConfig']['Mounts'] ?? []);
        $this->docker['helperRuns'][] = ['cmd' => $cmd, 'mounts' => $mounts, 'network' => $spec['HostConfig']['NetworkMode'] ?? null];
        if ($this->docker['helperExit'] === 0 && ($cmd[1] ?? null) === 'czf') {
            $bind = collect($mounts)->firstWhere('Type', 'bind');
            file_put_contents($bind['Source'].'/'.basename((string) $cmd[2]), str_repeat('x', 2048));
        }

        return $this->docker['helperExit'];
    }

    protected static function frame(int $stream, string $payload): string
    {
        return chr($stream)."\0\0\0".pack('N', strlen($payload)).$payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function container(string $name): ?array
    {
        return collect($this->docker['containers'])->first(fn ($c) => $c['Name'] === $name);
    }
}
