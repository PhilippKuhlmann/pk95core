<?php

namespace App\Services\Apps;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for one app's API inside the Docker network, authenticated with the
 * per-app service token Core handed to the container (PK95_CORE_TOKEN).
 * Core modules use it for their pages: $installedApp->client()->get('/api/monitors').
 */
class AppClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly ?int $timeout = null,
    ) {}

    public function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout($this->timeout ?? (int) config('pk95.app_timeout', 10));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): Response
    {
        return $this->call(fn () => $this->request()->get($path, $query));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function post(string $path, array $data = []): Response
    {
        return $this->call(fn () => $this->request()->post($path, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(string $path, array $data = []): Response
    {
        return $this->call(fn () => $this->request()->put($path, $data));
    }

    public function delete(string $path): Response
    {
        // Without a body Laravel sends no Content-Type; apps that guard every writing request
        // against CSRF with "application/json only" (PK95 Uptime) would answer 415.
        return $this->call(fn () => $this->request()->contentType('application/json')->delete($path));
    }

    public function healthy(string $path = '/core/health'): bool
    {
        try {
            return $this->request()->timeout(5)->get($path)->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function manifest(): Manifest
    {
        $response = $this->get('/core/manifest');
        if (! $response->successful() || ! is_array($response->json())) {
            throw new AppException('Manifest nicht abrufbar (HTTP '.$response->status().')');
        }

        return Manifest::fromArray($response->json());
    }

    private function call(callable $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $e) {
            throw new AppException('App nicht erreichbar ('.$this->baseUrl.'): '.$e->getMessage(), previous: $e);
        }
    }
}
