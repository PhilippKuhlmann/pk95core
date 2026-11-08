<?php

namespace App\Modules\ProxApps;

use ArrayIterator;
use Countable;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;
use IteratorAggregate;
use Traversable;

/**
 * View data from the ProxApps bridge: JSON objects become this (property and array access,
 * count, foreach), JSON lists become Collections - so the ported views read like the
 * originals ($app->name, $profiles->isEmpty(), $app->resources['cores']).
 *
 * @extends Fluent<string, mixed>
 */
class BridgeData extends Fluent implements Countable, IteratorAggregate
{
    public static function wrap(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! empty($value['__paginator'])) {
            return new self([
                'items' => self::wrap($value['data'] ?? []),
                'current_page' => (int) ($value['current_page'] ?? 1),
                'last_page' => (int) ($value['last_page'] ?? 1),
                'total' => (int) ($value['total'] ?? 0),
            ]);
        }
        $wrapped = array_map(fn ($v) => self::wrap($v), $value);

        return array_is_list($value) ? new Collection($wrapped) : new self($wrapped);
    }

    public function count(): int
    {
        return count($this->attributes);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->attributes);
    }

    /** Plain nested array, e.g. for array_column() in a view. */
    public function plain(): array
    {
        return array_map(fn ($v) => $v instanceof self ? $v->plain() : ($v instanceof Collection ? $v->map(fn ($x) => $x instanceof self ? $x->plain() : $x)->all() : $v), $this->attributes);
    }

    public function isEmpty(): bool
    {
        return $this->attributes === [];
    }

    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }
}
