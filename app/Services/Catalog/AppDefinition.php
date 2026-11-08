<?php

namespace App\Services\Catalog;

/**
 * One app from the catalog (catalog/<id>.yaml): which image to run and how.
 */
final readonly class AppDefinition
{
    public const ID_PATTERN = '/^[a-z][a-z0-9-]{1,30}$/';

    /**
     * @param  list<array{name: string, target: string}>  $volumes  named Docker volumes pk95-<id>-<name>
     * @param  array<string, string>  $env  extra environment for the container
     * @param  list<string>  $capAdd  Linux capabilities (e.g. NET_ADMIN)
     * @param  list<string>  $ports  published ports "host:container[/proto]" (bridge mode only)
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $summary,
        public string $image,
        public string $tag,
        public int $port,
        public string $health,
        public int $protocol,
        public string $networkMode,
        public array $volumes,
        public array $env,
        public array $capAdd,
        public array $ports,
        public string $icon,
        public ?string $module,
        /** @var array<string, string> namespaced kernel settings of the container (bridge mode) */
        public array $sysctls = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw, string $source): self
    {
        $fail = fn (string $msg) => throw new CatalogException("{$source}: {$msg}");

        $id = (string) ($raw['id'] ?? '');
        if (! preg_match(self::ID_PATTERN, $id)) {
            $fail('id fehlt oder ist ungültig (a-z, 0-9, -; 2–31 Zeichen)');
        }
        foreach (['name', 'image'] as $key) {
            if (! is_string($raw[$key] ?? null) || trim($raw[$key]) === '') {
                $fail("{$key} fehlt");
            }
        }
        $port = $raw['port'] ?? null;
        if (! is_int($port) || $port < 1 || $port > 65535) {
            $fail('port fehlt oder ist ungültig');
        }
        $mode = (string) ($raw['network_mode'] ?? 'bridge');
        if (! in_array($mode, ['bridge', 'host'], true)) {
            $fail('network_mode muss bridge oder host sein');
        }
        $health = (string) ($raw['health'] ?? '/core/health');
        if (! str_starts_with($health, '/')) {
            $fail('health muss mit / beginnen');
        }

        $volumes = [];
        foreach ((array) ($raw['volumes'] ?? []) as $i => $volume) {
            $name = (string) ($volume['name'] ?? '');
            $target = (string) ($volume['target'] ?? '');
            if (! preg_match('/^[a-z0-9-]{1,30}$/', $name) || ! str_starts_with($target, '/')) {
                $fail("volumes[{$i}] braucht name (a-z, 0-9, -) und einen absoluten target-Pfad");
            }
            $volumes[] = ['name' => $name, 'target' => $target];
        }

        $env = [];
        foreach ((array) ($raw['env'] ?? []) as $key => $value) {
            if (! preg_match('/^[A-Z_][A-Z0-9_]*$/', (string) $key)) {
                $fail("env-Schlüssel {$key} ist ungültig");
            }
            if (str_starts_with((string) $key, 'PK95_CORE_')) {
                $fail("env {$key} ist für Core reserviert");
            }
            $env[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        $ports = array_values(array_map('strval', (array) ($raw['ports'] ?? [])));
        foreach ($ports as $p) {
            if (! preg_match('/^\d{1,5}:\d{1,5}(\/(tcp|udp))?$/', $p)) {
                $fail("ports-Eintrag {$p} ist ungültig (host:container[/tcp|udp])");
            }
        }

        $sysctls = [];
        foreach ((array) ($raw['sysctls'] ?? []) as $key => $value) {
            if (! preg_match('/^net\.[a-z0-9_.]+$/', (string) $key)) {
                $fail("sysctls: {$key} ist nicht erlaubt (nur net.*)");
            }
            if ($mode === 'host') {
                $fail('sysctls gehen nicht mit network_mode host');
            }
            $sysctls[(string) $key] = (string) $value;
        }

        $module = array_key_exists('module', $raw) ? $raw['module'] : $id;

        return new self(
            id: $id,
            name: trim((string) $raw['name']),
            summary: trim((string) ($raw['summary'] ?? '')),
            image: trim((string) $raw['image']),
            tag: (string) ($raw['tag'] ?? 'latest'),
            port: $port,
            health: $health,
            protocol: (int) ($raw['protocol'] ?? 1),
            networkMode: $mode,
            volumes: $volumes,
            env: $env,
            capAdd: array_values(array_map('strval', (array) ($raw['cap_add'] ?? []))),
            ports: $ports,
            icon: (string) ($raw['icon'] ?? 'box'),
            module: $module === null ? null : (string) $module,
            sysctls: $sysctls,
        );
    }

    public function containerName(): string
    {
        return config('pk95.container_prefix').$this->id;
    }

    public function volumeName(string $volume): string
    {
        return config('pk95.container_prefix').$this->id.'-'.$volume;
    }

    public function imageRef(?string $tag = null): string
    {
        return $this->image.':'.($tag ?? $this->tag);
    }

    /**
     * Where Core reaches the app's HTTP API.
     */
    public function baseUrl(): string
    {
        $host = $this->networkMode === 'host' ? config('pk95.host_address') : $this->containerName();

        return 'http://'.$host.':'.$this->port;
    }
}
