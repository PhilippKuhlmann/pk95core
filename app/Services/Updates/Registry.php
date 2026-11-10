<?php

namespace App\Services\Updates;

use App\Services\Apps\AppException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Read-only access to an OCI registry (GHCR, Docker Hub, …) with anonymous pull tokens:
 * which tags exist, and which digest a tag points to right now.
 */
class Registry
{
    private const MANIFEST_TYPES = [
        'application/vnd.oci.image.index.v1+json',
        'application/vnd.docker.distribution.manifest.list.v2+json',
        'application/vnd.oci.image.manifest.v1+json',
        'application/vnd.docker.distribution.manifest.v2+json',
    ];

    /**
     * "ghcr.io/owner/app" → [ghcr.io, owner/app]; "nginx" → [registry-1.docker.io, library/nginx].
     *
     * @return array{registry: string, repo: string}
     */
    public static function parse(string $image): array
    {
        $parts = explode('/', $image);
        if (count($parts) > 1 && (str_contains($parts[0], '.') || str_contains($parts[0], ':') || $parts[0] === 'localhost')) {
            $registry = array_shift($parts);
        } else {
            $registry = 'registry-1.docker.io';
            if (count($parts) === 1) {
                array_unshift($parts, 'library');
            }
        }

        return ['registry' => $registry === 'docker.io' ? 'registry-1.docker.io' : $registry, 'repo' => implode('/', $parts)];
    }

    /**
     * @return list<string>
     */
    public function tags(string $image): array
    {
        ['registry' => $registry, 'repo' => $repo] = self::parse($image);
        $response = $this->request('GET', "https://{$registry}/v2/{$repo}/tags/list", ['n' => 1000]);

        return array_values(array_map('strval', (array) $response->json('tags')));
    }

    /**
     * Digest (sha256:…) the tag points to in the registry.
     */
    public function digest(string $image, string $tag): string
    {
        ['registry' => $registry, 'repo' => $repo] = self::parse($image);
        $response = $this->request('HEAD', "https://{$registry}/v2/{$repo}/manifests/".rawurlencode($tag), [], ['Accept' => implode(', ', self::MANIFEST_TYPES)]);
        $digest = (string) $response->header('Docker-Content-Digest');
        if ($digest === '') {
            throw new AppException("Die Registry nennt keinen Digest für {$image}:{$tag}.");
        }

        return $digest;
    }

    /**
     * The released version a moving tag (latest) points to: its version label
     * (org.opencontainers.image.version, set by docker/metadata-action from the git tag), but
     * only when the registry also has that version as a tag. Null for main builds, whose
     * label is "latest" or "main".
     *
     * @param  array<string, string>|null  $labels  labels of $tag when already fetched
     */
    public function release(string $image, string $tag, ?array $labels = null): ?string
    {
        $version = ltrim((string) (($labels ?? $this->labels($image, $tag))['org.opencontainers.image.version'] ?? ''), 'v');
        if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            return null;
        }
        $tags = $this->tags($image);
        foreach ([$version, 'v'.$version] as $candidate) {
            if (in_array($candidate, $tags, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Labels of image:tag in the registry (org.opencontainers.image.revision, ….created): manifest
     * (for an index the linux/amd64 entry) → config blob. Empty when the image has none.
     *
     * @return array<string, string>
     */
    public function labels(string $image, string $tag): array
    {
        ['registry' => $registry, 'repo' => $repo] = self::parse($image);
        $accept = ['Accept' => implode(', ', self::MANIFEST_TYPES)];
        $manifest = $this->request('GET', "https://{$registry}/v2/{$repo}/manifests/".rawurlencode($tag), [], $accept)->json();

        if (isset($manifest['manifests'])) {
            $entry = collect($manifest['manifests'])->first(fn ($m) => ($m['platform']['os'] ?? '') === 'linux' && ($m['platform']['architecture'] ?? '') === 'amd64');
            if ($entry === null) {
                return [];
            }
            $manifest = $this->request('GET', "https://{$registry}/v2/{$repo}/manifests/{$entry['digest']}", [], $accept)->json();
        }
        $config = $manifest['config']['digest'] ?? null;
        if (! is_string($config)) {
            return [];
        }

        return array_map('strval', (array) ($this->request('GET', "https://{$registry}/v2/{$repo}/blobs/{$config}")->json('config.Labels') ?? []));
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    private function request(string $method, string $url, array $query = [], array $headers = []): Response
    {
        try {
            $response = Http::timeout(20)->withHeaders($headers)->send($method, $url, ['query' => $query]);
            if ($response->status() === 401 && ($token = $this->token((string) $response->header('WWW-Authenticate')))) {
                $response = Http::timeout(20)->withHeaders($headers)->withToken($token)->send($method, $url, ['query' => $query]);
            }
        } catch (ConnectionException $e) {
            throw new AppException('Registry nicht erreichbar: '.$e->getMessage(), previous: $e);
        }
        if (in_array($response->status(), [401, 403, 404], true)) {
            $repo = explode('/v2/', (string) parse_url($url, PHP_URL_PATH), 2)[1] ?? $url;
            $repo = preg_replace('#/(tags|manifests)/.*$#', '', $repo);
            throw new AppException("Kein Zugriff auf das Image {$repo} (HTTP {$response->status()}): Es ist nicht öffentlich oder existiert nicht. Bei GHCR das Paket auf „Public“ stellen.");
        }
        if (! $response->successful()) {
            throw new AppException("Registry antwortet mit HTTP {$response->status()} für ".parse_url($url, PHP_URL_PATH));
        }

        return $response;
    }

    /**
     * Anonymous token from the realm named in 'Bearer realm="…",service="…",scope="…"'.
     */
    private function token(string $challenge): ?string
    {
        if (! str_starts_with(strtolower($challenge), 'bearer ')) {
            return null;
        }
        preg_match_all('/(\w+)="([^"]*)"/', $challenge, $matches, PREG_SET_ORDER);
        $params = [];
        foreach ($matches as $m) {
            $params[$m[1]] = $m[2];
        }
        if (empty($params['realm'])) {
            return null;
        }
        $response = Http::timeout(20)->get($params['realm'], array_filter(['service' => $params['service'] ?? null, 'scope' => $params['scope'] ?? null]));

        return $response->successful() ? ($response->json('token') ?? $response->json('access_token')) : null;
    }
}
