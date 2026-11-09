<?php

namespace App\Services\Docker;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Docker Engine API client over the unix socket mounted into the Core container.
 * No docker CLI needed; every call is a plain HTTP request (and fakeable with Http::fake).
 */
class DockerClient
{
    public function __construct(
        private readonly string $socket,
        private readonly string $apiVersion = 'v1.44',
        private readonly int $pullTimeout = 900,
    ) {}

    public function available(): bool
    {
        try {
            return $this->request(5)->get('/_ping')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function version(): array
    {
        return $this->send('GET', '/version')->json() ?? [];
    }

    /**
     * Pull image:tag. The API answers 200 even when the pull fails later on; the
     * failure is reported in the JSON progress stream, so that is checked as well.
     */
    public function pull(string $image, string $tag): void
    {
        $response = $this->send('POST', '/images/create', ['fromImage' => $image, 'tag' => $tag], timeout: $this->pullTimeout);

        foreach (preg_split('/\r?\n/', $response->body()) ?: [] as $line) {
            $event = json_decode($line, true);
            if (is_array($event) && isset($event['error'])) {
                throw new DockerException("Image {$image}:{$tag}: ".($event['errorDetail']['message'] ?? $event['error']));
            }
        }
    }

    /**
     * @return array<string, mixed>|null image details, null when not present locally
     */
    public function inspectImage(string $ref): ?array
    {
        return $this->sendOrNull('GET', '/images/'.rawurlencode($ref).'/json');
    }

    public function ensureNetwork(string $name): void
    {
        if ($this->sendOrNull('GET', '/networks/'.rawurlencode($name)) !== null) {
            return;
        }
        $this->send('POST', '/networks/create', json: [
            'Name' => $name,
            'Driver' => 'bridge',
            'Labels' => ['pk95.managed' => '1'],
        ]);
    }

    /**
     * @param  array<string, string>  $labels
     */
    public function ensureVolume(string $name, array $labels = []): void
    {
        if ($this->sendOrNull('GET', '/volumes/'.rawurlencode($name)) !== null) {
            return;
        }
        $this->send('POST', '/volumes/create', json: ['Name' => $name, 'Labels' => $labels + ['pk95.managed' => '1']]);
    }

    public function removeVolume(string $name): void
    {
        $this->sendOrNull('DELETE', '/volumes/'.rawurlencode($name));
    }

    /**
     * @param  array<string, mixed>  $spec  Docker "container create" body
     * @return string container id
     */
    public function createContainer(?string $name, array $spec): string
    {
        $response = $this->send('POST', '/containers/create', $name === null ? [] : ['name' => $name], json: $spec);

        return (string) $response->json('Id');
    }

    /**
     * Blocks until the container has stopped and returns its exit code.
     */
    public function waitContainer(string $id, int $timeout = 3600): int
    {
        return (int) $this->send('POST', '/containers/'.rawurlencode($id).'/wait', timeout: $timeout)->json('StatusCode', -1);
    }

    /**
     * Run a short-lived helper container to completion (backup, restore) and remove it.
     *
     * @param  list<string>  $cmd
     * @param  list<array<string, mixed>>  $mounts  Docker "Mounts" entries
     * @param  string  $network  "none", or "host" to look at the network of the Docker host
     * @return array{exit: int, output: string}
     */
    public function run(string $image, array $cmd, array $mounts, int $timeout = 3600, string $network = 'none'): array
    {
        if ($this->inspectImage($image) === null) {
            [$name, $tag] = str_contains($image, ':') ? explode(':', $image, 2) : [$image, 'latest'];
            $this->pull($name, $tag);
        }
        $id = $this->createContainer(null, [
            'Image' => $image,
            'Cmd' => $cmd,
            'Labels' => ['pk95.managed' => '1', 'pk95.helper' => '1'],
            'HostConfig' => ['Mounts' => $mounts, 'NetworkMode' => $network],
        ]);
        try {
            $this->startContainer($id);
            $exit = $this->waitContainer($id, $timeout);
            $output = implode("\n", array_column($this->logs($id, 50), 'line'));
        } finally {
            $this->removeContainer($id);
        }

        return ['exit' => $exit, 'output' => $output];
    }

    public function startContainer(string $id): void
    {
        // 304 = already running
        $this->send('POST', '/containers/'.rawurlencode($id).'/start', allow: [304]);
    }

    public function stopContainer(string $id, int $timeout = 20): void
    {
        // 304 = already stopped
        $this->send('POST', '/containers/'.rawurlencode($id).'/stop', ['t' => $timeout], timeout: $timeout + 15, allow: [304]);
    }

    public function restartContainer(string $id, int $timeout = 20): void
    {
        $this->send('POST', '/containers/'.rawurlencode($id).'/restart', ['t' => $timeout], timeout: $timeout + 15);
    }

    public function removeContainer(string $id, bool $force = true): void
    {
        $this->sendOrNull('DELETE', '/containers/'.rawurlencode($id), ['force' => $force ? 'true' : 'false']);
    }

    /**
     * @return array<string, mixed>|null null when the container does not exist
     */
    public function inspectContainer(string $idOrName): ?array
    {
        return $this->sendOrNull('GET', '/containers/'.rawurlencode($idOrName).'/json');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function containersWithLabel(string $label): array
    {
        $filters = json_encode(['label' => [$label]]);

        return $this->send('GET', '/containers/json', ['all' => 'true', 'filters' => $filters])->json() ?? [];
    }

    /**
     * Last lines of stdout + stderr, demultiplexed.
     *
     * @return list<array{stream: string, line: string}>
     */
    public function logs(string $id, int $tail = 200): array
    {
        $body = $this->send('GET', '/containers/'.rawurlencode($id).'/logs', [
            'stdout' => 'true', 'stderr' => 'true', 'tail' => $tail, 'timestamps' => 'true',
        ])->body();

        return self::demux($body);
    }

    /**
     * Docker multiplexes stdout/stderr of non-TTY containers into frames:
     * [stream type: 1 byte][3 zero bytes][payload size: uint32 big endian][payload].
     * A TTY container sends raw text instead.
     *
     * @return list<array{stream: string, line: string}>
     */
    public static function demux(string $raw): array
    {
        $chunks = [];
        $offset = 0;
        $length = strlen($raw);
        $framed = $length >= 8 && in_array(ord($raw[0]), [0, 1, 2], true) && substr($raw, 1, 3) === "\0\0\0";

        if (! $framed) {
            $chunks[] = ['stream' => 'stdout', 'text' => $raw];
        } else {
            while ($offset + 8 <= $length) {
                $type = ord($raw[$offset]);
                $size = unpack('N', substr($raw, $offset + 4, 4))[1];
                $chunks[] = ['stream' => $type === 2 ? 'stderr' : 'stdout', 'text' => substr($raw, $offset + 8, $size)];
                $offset += 8 + $size;
            }
        }

        $lines = [];
        foreach ($chunks as $chunk) {
            foreach (preg_split('/\r?\n/', rtrim($chunk['text'], "\r\n")) ?: [] as $line) {
                if ($line !== '') {
                    $lines[] = ['stream' => $chunk['stream'], 'line' => $line];
                }
            }
        }

        return $lines;
    }

    // ------------------------------------------------------------------------------------

    private function request(int $timeout = 30): PendingRequest
    {
        return Http::baseUrl('http://docker/'.$this->apiVersion)
            ->withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => $this->socket]])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout($timeout);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json
     * @param  list<int>  $allow  non-2xx status codes that are fine
     */
    private function send(string $method, string $path, array $query = [], ?array $json = null, int $timeout = 30, array $allow = []): Response
    {
        try {
            $request = $this->request($timeout);
            if ($query !== []) {
                $request = $request->withQueryParameters($query);
            }
            $response = $json === null
                ? $request->send($method, $path)
                : $request->send($method, $path, ['json' => $json]);
        } catch (ConnectionException $e) {
            throw new DockerException('Docker ist nicht erreichbar ('.$this->socket.'): '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful() && ! in_array($response->status(), $allow, true)) {
            $message = $response->json('message') ?? trim($response->body());
            throw new DockerException("Docker {$method} {$path}: {$message}", $response->status());
        }

        return $response;
    }

    /**
     * Like send(), but a 404 means "does not exist" and returns null.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function sendOrNull(string $method, string $path, array $query = []): ?array
    {
        try {
            return $this->send($method, $path, $query)->json() ?? [];
        } catch (DockerException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
    }
}
