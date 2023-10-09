<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate\Collection;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Traversable;

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
}