<?php

namespace App\Modules\Ad\Gpp;

/**
 * The Group Policy Preferences lists the GPO dialog edits, in the order of their sections
 * (within a tab).
 */
final class Kinds
{
    /** @var array<string, Kind>|null */
    private static ?array $all = null;

    /** @return array<string, Kind> key → kind */
    public static function all(): array
    {
        if (self::$all === null) {
            self::$all = [];
            foreach ([new LocalGroups, new DriveMaps, new Printers, new Shortcuts] as $kind) {
                self::$all[$kind->key()] = $kind;
            }
        }

        return self::$all;
    }

    public static function get(string $key): ?Kind
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string, Kind> the kinds of a tab (computer | user) */
    public static function of(string $scope): array
    {
        return array_filter(self::all(), fn (Kind $kind) => $kind->scope() === $scope);
    }
}
