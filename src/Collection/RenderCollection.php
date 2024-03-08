<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate\Collection;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * This class allows you to capture and navigate an array of data within templates.
 */
class RenderCollection implements Countable, IteratorAggregate, ArrayAccess
{

    public function __construct(
        private array $items = []
    ) {}

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->items[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        yield from $this->items;
    }

    /**
     * Applies a callback function to each element of the inner array to alter its value.
     * The callback function must refer to the value as a reference (&$value).
     */
    public function walk(callable $callback = null): bool
    {
        return array_walk_recursive($this->items, $callback);
    }

    public function merge(array $data): void
    {
        $this->items = array_replace_recursive($this->items, $data);
    }

    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * It loops through arrays by automatically resolving any callbacks that are present by passing the same parameters.
     */
    public function resolve(array $params = []): array
    {
        $this->walk(function(&$value) use ($params) {
            if (is_callable($value)) {
                $value = $value($params);
            }
        });
        return $this->toArray();
    }
}