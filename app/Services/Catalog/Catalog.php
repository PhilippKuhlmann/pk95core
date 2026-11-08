<?php

namespace App\Services\Catalog;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The app catalog: one YAML file per app in catalog/<id>.yaml.
 */
class Catalog
{
    /** @var array<string, AppDefinition>|null */
    private ?array $apps = null;

    public function __construct(private readonly string $dir) {}

    /**
     * @return array<string, AppDefinition> keyed by id, sorted by name
     */
    public function all(): array
    {
        if ($this->apps !== null) {
            return $this->apps;
        }

        $apps = [];
        foreach (glob(rtrim($this->dir, '/').'/*.yaml') ?: [] as $file) {
            try {
                $raw = Yaml::parseFile($file);
            } catch (ParseException $e) {
                throw new CatalogException(basename($file).': '.$e->getMessage(), previous: $e);
            }
            if (! is_array($raw)) {
                throw new CatalogException(basename($file).': kein YAML-Objekt');
            }
            $app = AppDefinition::fromArray($raw, basename($file));
            if (basename($file, '.yaml') !== $app->id) {
                throw new CatalogException(basename($file).": Dateiname passt nicht zur id {$app->id}");
            }
            $apps[$app->id] = $app;
        }
        uasort($apps, fn (AppDefinition $a, AppDefinition $b) => strcasecmp($a->name, $b->name));

        return $this->apps = $apps;
    }

    public function find(string $id): ?AppDefinition
    {
        return $this->all()[$id] ?? null;
    }

    public function get(string $id): AppDefinition
    {
        return $this->find($id) ?? throw new CatalogException("App {$id} ist nicht im Katalog");
    }
}
