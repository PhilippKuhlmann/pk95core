<?php

namespace App\Modules\Dns;

/**
 * Countries of PK95 DNS split into let through and blocked (PK95 DNS 1.3.0): "count" = answers
 * let through, "list_blocked" = queries stopped by block lists, own rules or services whose
 * domain points to the country, "blocked" = the country filter. Before 1.3.0 there is no
 * "list_blocked": only "let through" is known then (hasSplit() false).
 */
final class CountrySplit
{
    public const MIN_VERSION = '1.3.0';

    /**
     * @param  list<array<string, mixed>>  $countries  top_countries or /api/countries countries
     */
    public static function hasSplit(array $countries): bool
    {
        foreach ($countries as $c) {
            if (is_array($c) && array_key_exists('list_blocked', $c)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rows sorted by all queries (let through + blocked), the biggest first.
     *
     * @param  list<array<string, mixed>>  $countries
     * @return list<array{code: string, passed: int, list_blocked: int, filter_blocked: int, blocked: int, removed: int, total: int, clients: ?int}>
     */
    public static function rows(array $countries, ?int $limit = null): array
    {
        $rows = [];
        foreach ($countries as $c) {
            $code = strtoupper((string) ($c['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $passed = max(0, (int) ($c['count'] ?? 0));
            $list = max(0, (int) ($c['list_blocked'] ?? 0));
            $filter = max(0, (int) ($c['blocked'] ?? 0));
            $rows[] = ['code' => $code, 'passed' => $passed, 'list_blocked' => $list, 'filter_blocked' => $filter, 'blocked' => $list + $filter,
                'removed' => max(0, (int) ($c['removed'] ?? 0)), 'total' => $passed + $list + $filter, 'clients' => isset($c['clients']) ? (int) $c['clients'] : null];
        }
        usort($rows, fn ($a, $b) => [$b['total'], $a['code']] <=> [$a['total'], $b['code']]);

        return $limit === null ? $rows : array_slice($rows, 0, $limit);
    }

    /**
     * Widths of the stacked bar in % of the biggest row: let through (green), blocked (red).
     *
     * @param  array{passed: int, blocked: int}  $row
     * @return array{passed: float, blocked: float}
     */
    public static function bar(array $row, int $max): array
    {
        if ($max <= 0) {
            return ['passed' => 0.0, 'blocked' => 0.0];
        }

        return ['passed' => round($row['passed'] / $max * 100, 1), 'blocked' => round($row['blocked'] / $max * 100, 1)];
    }

    /**
     * Share of the blocked queries among all queries with a country, in %.
     *
     * @param  list<array{passed: int, blocked: int}>  $rows
     */
    public static function blockedPct(array $rows): float
    {
        $all = array_sum(array_column($rows, 'passed')) + array_sum(array_column($rows, 'blocked'));

        return $all > 0 ? array_sum(array_column($rows, 'blocked')) / $all * 100 : 0.0;
    }
}
