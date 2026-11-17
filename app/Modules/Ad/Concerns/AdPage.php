<?php

namespace App\Modules\Ad\Concerns;

use App\Http\Support\Format;
use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use App\Services\Apps\AppException;

/**
 * Shared by the PK95 AD pages: reading with the 409 "not set up yet" told apart, and the OU
 * tree as a flat list for selection fields. Writing goes through TalksToApp (422 fields to the
 * form, every other error, 403 for protected built-ins and 409 for conflicts, as a toast).
 */
trait AdPage
{
    use TalksToApp;

    public InstalledApp $installedApp;

    /** the domain is not set up yet (the app answered 409 on a directory route) */
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

    /**
     * GET /api/ous flat (parents before children); [] when it fails.
     *
     * @return list<array{id: string, name: string, path: string, type: string, depth: int, protected: bool, block_inheritance: bool, description: string}>
     */
    protected function ous(): array
    {
        [$root] = $this->fetch('/api/ous');

        return self::flattenOus(is_array($root) ? $root : null);
    }

    /** the list filters of the page back to empty (search q, OU filter ou) */
    public function resetFilters(): void
    {
        foreach (['q', 'ou', 'type', 'scope'] as $filter) {
            if (property_exists($this, $filter)) {
                $this->{$filter} = '';
            }
        }
    }

    /** Toast after a successful write. */
    protected function done(string $text): void
    {
        $this->dispatch('notice', kind: 'success', text: $text);
    }

    /**
     * The OU tree (GET /api/ous) flat, parents before children.
     *
     * @param  array<string, mixed>|null  $root
     * @return list<array{id: string, name: string, path: string, type: string, depth: int, protected: bool, block_inheritance: bool, description: string}>
     */
    protected static function flattenOus(?array $root): array
    {
        $out = [];
        $walk = function (array $node, int $depth) use (&$walk, &$out): void {
            $out[] = [
                'id' => (string) ($node['id'] ?? ''), 'name' => (string) ($node['name'] ?? ''), 'path' => (string) ($node['path'] ?? ''),
                'type' => (string) ($node['type'] ?? 'ou'), 'depth' => $depth, 'protected' => (bool) ($node['protected'] ?? false),
                'block_inheritance' => (bool) ($node['block_inheritance'] ?? false), 'description' => (string) ($node['description'] ?? ''),
            ];
            foreach ((array) ($node['children'] ?? []) as $child) {
                if (is_array($child)) {
                    $walk($child, $depth + 1);
                }
            }
        };
        if ($root) {
            $walk($root, 0);
        }

        return $out;
    }

    /**
     * Options for a container selection: the domain root, OUs and containers (not Builtin);
     * $linkTargets: only what a GPO can be linked to (domain and OUs).
     *
     * @param  list<array<string, mixed>>  $ous
     * @return list<array{value: string, label: string}>
     */
    protected static function ouOptions(array $ous, bool $linkTargets = false): array
    {
        $options = [];
        foreach ($ous as $o) {
            if ($o['type'] === 'builtin' || ($linkTargets && ! in_array($o['type'], ['domain', 'ou'], true))) {
                continue;
            }
            $options[] = ['value' => $o['id'], 'label' => $o['path'] === '' ? $o['name'].' (Domäne)' : $o['path']];
        }

        return $options;
    }

    /** Unix milliseconds → "14.11.2026, 08:14", "–" for 0. */
    public static function when(int|float|null $ms): string
    {
        return $ms ? Format::fromMs((int) $ms)->format('d.m.Y, H:i') : '–';
    }
}
