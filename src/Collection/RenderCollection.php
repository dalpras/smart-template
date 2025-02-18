<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate\Collection;

use ArrayObject;
use Closure;

class RenderCollection extends ArrayObject
{
    public function __construct(array $items = [])
    {
        // Initialize the parent ArrayObject. 
        // Optionally pass flags or an iterator class, e.g.:
        // parent::__construct($items, ArrayObject::ARRAY_AS_PROPS, "ArrayIterator");
        parent::__construct($items);
    }
    
    /**
     * Retrieves a nested value from the internal array using a path 
     * such as "foo/bar/baz" (or "foo.bar.baz", depending on the delimiter).
     *
     * @param  string $path      The path string, e.g. "foo/bar/baz".
     * @param  string $delimiter The delimiter to split the path (default '/').
     * @return mixed             The found value or null if any segment doesn't exist.
     */
    public function find(string $path, string $delimiter = '/'): mixed
    {
        $keys    = explode($delimiter, $path);
        $current = $this; // Start from this ArrayObject instance.

        foreach ($keys as $key) {
            if ($current instanceof \ArrayObject) {
                // Navigate via ArrayObject methods
                if (!$current->offsetExists($key)) {
                    return null;
                }
                $current = $current->offsetGet($key);
            } elseif (is_array($current)) {
                // Navigate a plain array
                if (!array_key_exists($key, $current)) {
                    return null;
                }
                $current = $current[$key];
            } else {
                // Once we land on something not array-like, we cannot go deeper
                return null;
            }
        }

        return $current;
    }

    /**
     * Recursively apply $callback to each element (similar to array_walk_recursive).
     */
    public function walk(?callable $callback): bool
    {
        if (!$callback) {
            return true;
        }
        return $this->arrayWalkRecursive($this, $callback);
    }

    /**
     * Merge the given data array into this collection (recursive).
     */
    public function merge(array $data): void
    {
        $merged = array_replace_recursive($this->getArrayCopy(), $data);
        $this->exchangeArray($merged);
    }

    /**
     * Return a plain PHP array representation.
     */
    public function toArray(): array
    {
        return $this->getArrayCopy();
    }

    /**
     * Resolve all Closure values by passing $params, then replace them in place.
     */
    public function resolve(array $params = []): array
    {
        $this->walk(function (&$value) use ($params) {
            if ($value instanceof Closure) {
                $value = $value($params);
            }
        });
        return $this->toArray();
    }

    /**
     * --- PRIVATE HELPERS ---
     */

    /**
     * A recursive array-walk-like method for ArrayObject.
     */
    private function arrayWalkRecursive(ArrayObject $array, callable $callback): bool
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                // Convert nested arrays to ArrayObject for consistency, 
                // or walk them directly
                $tmp = new ArrayObject($value);
                $this->arrayWalkRecursive($tmp, $callback);
                // Re-inject the possibly changed array
                $array[$key] = $tmp->getArrayCopy();
            } elseif ($value instanceof ArrayObject) {
                // Recursively walk nested ArrayObjects
                $this->arrayWalkRecursive($value, $callback);
            } else {
                $callback($value, $key);
                $array[$key] = $value; // Re-assign in case callback mutated $value
            }
        }
        return true;
    }
}
