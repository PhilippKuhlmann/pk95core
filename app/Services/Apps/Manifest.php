<?php

namespace App\Services\Apps;

/**
 * What an app tells Core about itself at GET /core/manifest (app protocol v1).
 */
final readonly class Manifest
{
    /**
     * @param  list<array{key: string, label: string, icon: string}>  $menu
     * @param  list<string>  $capabilities
     * @param  list<string>  $events
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public int $protocol,
        public string $icon,
        public array $menu,
        public array $capabilities,
        public array $events,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        foreach (['id', 'name', 'version'] as $key) {
            if (! is_string($raw[$key] ?? null) || trim($raw[$key]) === '') {
                throw new AppException("Manifest: {$key} fehlt");
            }
        }
        if (! is_int($raw['protocol'] ?? null)) {
            throw new AppException('Manifest: protocol fehlt');
        }

        $menu = [];
        foreach ((array) ($raw['menu'] ?? []) as $i => $item) {
            $key = is_array($item) ? (string) ($item['key'] ?? '') : '';
            $label = is_array($item) ? trim((string) ($item['label'] ?? '')) : '';
            if (! preg_match('/^[a-z0-9-]{1,40}$/', $key) || $label === '') {
                throw new AppException("Manifest: menu[{$i}] braucht key (a-z, 0-9, -) und label");
            }
            $menu[] = ['key' => $key, 'label' => $label, 'icon' => (string) ($item['icon'] ?? 'box')];
        }

        return new self(
            id: (string) $raw['id'],
            name: trim((string) $raw['name']),
            version: trim((string) $raw['version']),
            protocol: $raw['protocol'],
            icon: (string) ($raw['icon'] ?? 'box'),
            menu: $menu,
            capabilities: array_values(array_map('strval', (array) ($raw['capabilities'] ?? []))),
            events: array_values(array_map('strval', (array) ($raw['events'] ?? []))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'protocol' => $this->protocol,
            'icon' => $this->icon,
            'menu' => $this->menu,
            'capabilities' => $this->capabilities,
            'events' => $this->events,
        ];
    }

    public function has(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
