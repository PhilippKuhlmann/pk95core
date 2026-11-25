<?php

namespace App\Modules\File\Concerns;

use App\Http\Support\Format;
use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use App\Services\Apps\AppException;

/**
 * Shared by the PK95 File pages: reading with the 409 "not set up yet" told apart, PK95 AD
 * (groups, drive maps, the domain join) and formatting. Writing goes through TalksToApp
 * (422 fields to the form, every other error as a toast).
 */
trait FilePage
{
    use TalksToApp;

    public InstalledApp $installedApp;

    /** the file server has not joined the domain yet (the app answered 409 on a share/session route) */
    public bool $notReady = false;

    /**
     * GET for render(): data or null and the reason; a 409 sets $notReady instead.
     *
     * @param  array<string, mixed>|string  $query
     * @return array{0: mixed, 1: ?string}
     */
    protected function fetch(string $path, array|string $query = []): array
    {
        try {
            $response = $this->installedApp->client()->get($path, $query);
        } catch (AppException $e) {
            return [null, $e->getMessage()];
        }
        if ($response->status() === 409) {
            $this->notReady = true;

            return [null, null];
        }
        if (! $response->successful()) {
            return [null, (string) ($response->json('error') ?? "Die App antwortet mit HTTP {$response->status()}.")];
        }

        return [$response->json(), null];
    }

    /** Toast after a successful write. */
    protected function done(string $text): void
    {
        $this->dispatch('notice', kind: 'success', text: $text);
    }

    /** PK95 AD when it is installed (running or not). */
    public static function adApp(): ?InstalledApp
    {
        return InstalledApp::query()->where('app_id', 'ad')->first();
    }

    /** PK95 AD when it runs: groups, drive maps and the domain join need it. */
    public static function runningAd(): ?InstalledApp
    {
        $ad = self::adApp();

        return $ad?->status === InstalledApp::RUNNING ? $ad : null;
    }

    /** a share name in a URL path (case-insensitive in the app, "$" encoded) */
    protected static function shareUrl(string $name): string
    {
        return '/api/shares/'.rawurlencode($name);
    }

    /** Unix milliseconds → "14.11.2026, 08:14", "–" for 0. */
    public static function when(int|float|null $ms): string
    {
        return $ms ? Format::fromMs((int) $ms)->format('d.m.Y, H:i') : '–';
    }

    public static function bytes(mixed $bytes): string
    {
        return Format::bytes($bytes);
    }
}
