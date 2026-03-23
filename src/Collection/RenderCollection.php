<?php

declare(strict_types=1);

namespace DalPraS\SmartTemplate\Collection;

use ArrayAccess;
use Closure;
use IteratorAggregate;
use ArrayIterator;
use Traversable;
use Countable;

/**
 * RenderCollection
 *
 * A lightweight, lazy-evaluated hierarchical container for template nodes.
 *
 * This class acts as the internal data structure used by the template engine
 * to store, organize, lazily compile, and resolve template definitions.
 *
 * ---------------------------------------------------------------------------
 * Core Responsibilities
 * ---------------------------------------------------------------------------
 *
 * 1) Lazy Wrapping of Nested Arrays
 *    --------------------------------
 *    Nested arrays are automatically wrapped into RenderCollection instances
 *    when accessed via offsetGet(). This allows the entire structure to behave
 *    uniformly, regardless of depth.
 *
 * 2) Lazy Compilation of Leaf Nodes
 *    --------------------------------
 *    When a string leaf is accessed, and a lazy compiler has been defined,
 *    the value is transformed into a compiled render callable (typically a Closure).
 *
 *    This avoids compiling all templates upfront and significantly improves
 *    performance when working with large template trees.
 *
 * 3) Transparent Array Access
 *    --------------------------------
 *    Implements:
 *      - ArrayAccess
 *      - IteratorAggregate
 *      - Countable
 *
 *    This allows the collection to behave like a native PHP array while
 *    retaining lazy evaluation and compilation behavior.
 *
 * 4) Deep Resolution
 *    --------------------------------
 *    The resolve() method evaluates:
 *      - Nested RenderCollection instances
 *      - Closures (with optional parameters)
 *      - Arrays returned by closures
 *      - Deeply nested structures
 *
 *    The result is a fully materialized plain PHP array containing only
 *    resolved scalar values (no closures, no RenderCollection objects).
 *
 * ---------------------------------------------------------------------------
 * Design Philosophy
 * ---------------------------------------------------------------------------
 *
 * - Compilation is lazy (only happens when accessed).
 * - Nested arrays are normalized to RenderCollection automatically.
 * - No upfront deep traversal.
 * - Resolution is explicit and controlled.
 * - Designed for high-performance template systems with thousands of nodes.
 *
 * ---------------------------------------------------------------------------
 * Typical Lifecycle
 * ---------------------------------------------------------------------------
 *
 * 1. Template arrays are loaded from filesystem.
 * 2. RenderCollection is instantiated with raw array data.
 * 3. Lazy compiler is injected by TemplateEngine.
 * 4. Accessing keys triggers:
 *      - Nested wrapping
 *      - Leaf compilation
 * 5. resolve() produces a fully evaluated deep array.
 *
 * ---------------------------------------------------------------------------
 * Performance Characteristics
 * ---------------------------------------------------------------------------
 *
 * - O(1) access cost for uncompiled nodes.
 * - Compilation cost paid only once per node.
 * - Memoized wrapping (offsetGet stores wrapped/compiled value back).
 * - No recursion during normal traversal unless resolve() is called.
 *
 * ---------------------------------------------------------------------------
 * Thread-Safety / Mutability
 * ---------------------------------------------------------------------------
 *
 * This class is mutable:
 * - offsetGet() may replace internal values (memoization).
 * - walk() mutates values.
 * - merge() modifies internal state.
 *
 * It is designed for single-request lifecycle usage.
 *
 * ---------------------------------------------------------------------------
 * Usage Context
 * ---------------------------------------------------------------------------
 *
 * Internal structure for SmartTemplate engine.
 * Not intended as a general-purpose collection library.
 */
final class RenderCollection implements ArrayAccess, IteratorAggregate, Countable
{
    private ?self $root = null;

    /** @var array<string, mixed> */
    private array $items;

    /** @var null|Closure(mixed $value, string|int $key, RenderCollection $self): mixed */
    private ?Closure $lazyCompiler = null;

    /**
     * Create a new RenderCollection instance.
     *
     * @param array<string, mixed> $items Initial template structure.
     *
     * The collection stores raw template data (strings, arrays, closures, etc.).
     * No wrapping or compilation happens at construction time — everything is
     * evaluated lazily when accessed.
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Assign the root RenderCollection reference.
     *
     * @param self $root The root collection of the entire template tree.
     *
     * Used to ensure nested collections share the same root context.
     * The root is set only once (idempotent).
     */
    public function setRoot(self $root): void
    {
        $this->root ??= $root;
    }

    public function getRoot(): self
    {
        return $this->root ?? $this;
    }

    /**
     * Define a lazy compiler callback.
     *
     * @param null|Closure(mixed $value, string|int $key, RenderCollection $self): mixed $compiler
     *
     * The lazy compiler transforms leaf values (typically strings)
     * into compiled render callables when accessed via offsetGet().
     *
     * If null, no compilation occurs.
     */
    public function setLazyCompiler(?Closure $compiler): void
    {
        $this->lazyCompiler = $compiler;
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string)$offset, $this->items);
    }

    /**
     * Retrieve a value from the collection.
     *
     * @param mixed $offset
     * @return mixed
     *
     * Behavior:
     * - Nested arrays are automatically wrapped into RenderCollection instances.
     * - String leaf nodes are lazily compiled using the defined compiler.
     * - Compilation/wrapping is memoized (stored back into the collection).
     *
     * This method is the core of the lazy evaluation mechanism.
     */
    public function offsetGet(mixed $offset): mixed
    {
        $key = (string) $offset;

        if (!array_key_exists($key, $this->items)) {
            return null;
        }

        $value = $this->items[$key];

        // Already wrapped collection
        if ($value instanceof self) {
            return $value;
        }

        // Wrap nested arrays
        if (is_array($value)) {
            $child = new self($value);
            $child->setLazyCompiler($this->lazyCompiler);
            $child->setRoot($this->getRoot());

            return $this->items[$key] = $child;
        }

        // Let compiler resolve strings, lazy wrappers, or other deferred nodes
        if ($this->lazyCompiler !== null) {
            $compiled = ($this->lazyCompiler)($value, $key, $this);

            // If compiler returned a plain array, wrap it immediately so chained access works
            if (is_array($compiled)) {
                $child = new self($compiled);
                $child->setLazyCompiler($this->lazyCompiler);
                $child->setRoot($this->getRoot());

                return $this->items[$key] = $child;
            }

            return $this->items[$key] = $compiled;
        }

        return $value;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->items[(string)$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[(string)$offset]);
    }

    /**
     * Retrieve an iterator for the collection.
     *
     * @return Traversable
     *
     * Returns an ArrayIterator over raw internal items.
     * Iteration does NOT trigger lazy compilation unless offsetGet()
     * is explicitly used.
     */
    public function getIterator(): Traversable
    {
        // Iterating doesn't force compilation unless caller does offsetGet()
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Merge another array into the collection.
     *
     * @param array<string, mixed> $data
     *
     * Performs a recursive array replacement using array_replace_recursive().
     *
     * Existing keys are overwritten by new values.
     */
    public function merge(array $data): void
    {
        // faster than array_replace_recursive in many cases, but semantics differ.
        // If you require deep merge, keep a recursive merge here.
        $this->items = array_replace_recursive($this->items, $data);
    }

    /**
     * Convert the collection into a plain PHP array.
     *
     * @return array<string, mixed>
     *
     * - Forces lazy wrapping/compilation via offsetGet().
     * - Recursively converts nested RenderCollection instances to arrays.
     *
     * Closures are NOT executed here — use resolve() for full evaluation.
     */
    public function toArray(): array
    {
        $out = [];
        foreach (array_keys($this->items) as $k) {
            // Use offsetGet() so nested arrays become RenderCollection and lazy compilation can happen if needed
            $v = $this->offsetGet($k);
            if ($v instanceof self) {
                $out[$k] = $v->toArray();     // deep convert
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Recursively walk through all items in the collection.
     *
     * @param callable|null $callback function (&$value, $key): void
     * @return bool
     *
     * - Forces lazy compilation via offsetGet().
     * - Recursively visits nested collections.
     * - Allows in-place mutation of values.
     *
     * The callback receives values by reference.
     */
    public function walk(?callable $callback): bool
    {
        if (!$callback) return true;

        foreach (array_keys($this->items) as $k) {
            $v = $this->offsetGet($k); // <-- forces lazy compiler + wraps arrays to RenderCollection

            if ($v instanceof self) {
                $v->walk($callback);    // recurse (still via offsetGet inside child)
                // keep it stored (offsetGet already memoizes)
                continue;
            }

            $callback($v, $k);
            $this->items[$k] = $v;      // persist mutations
        }

        return true;
    }

    /**
     * Fully resolve the collection into a plain array.
     *
     * @param array<string, mixed> $params Optional parameters passed to closures.
     * @return array<string, mixed>
     *
     * Behavior:
     * - Forces lazy compilation at all levels.
     * - Executes all closures (with or without parameters).
     * - Recursively resolves:
     *     - Nested RenderCollection instances
     *     - Closures returned by other closures
     *     - Arrays containing closures or collections
     *
     * Returns a fully materialized array containing only resolved values.
     */
    public function resolve(array $params = []): array
    {
        // Force lazy compilation / wrapping at this level
        foreach (array_keys($this->items) as $k) {
            $this->offsetGet($k);
        }

        $call = static function (\Closure $c) use ($params) {
            try {
                return $c($params);
            } catch (\ArgumentCountError) {
                return $c();
            }
        };

        $resolveValue = function ($v) use (&$resolveValue, $call, $params) {
            // Resolve nested collections
            if ($v instanceof self) {
                return $v->resolve($params);
            }

            // Resolve closures, then resolve what they returned (CRITICAL)
            if ($v instanceof \Closure) {
                return $resolveValue($call($v));
            }

            // Resolve arrays deeply (arrays can contain closures or collections)
            if (is_array($v)) {
                foreach ($v as $k => $item) {
                    $v[$k] = $resolveValue($item);
                }
                return $v;
            }

            return $v;
        };

        $out = [];
        foreach (array_keys($this->items) as $k) {
            // Use offsetGet so lazy compiler + wrapping is applied
            $out[$k] = $resolveValue($this->offsetGet($k));
        }

        return $out;
    }

    /**
     * Retrieve a nested value using a path expression.
     *
     * @param string $path Path using delimiter-separated keys (default "/").
     * @param string $delimiter
     * @return mixed|null
     *
     * Traverses nested collections and arrays safely.
     *
     * Example:
     *   $collection->find('layout/menu/mobile');
     *
     * Returns null if any segment does not exist.
     */
    public function find(string $path, string $delimiter = '/'): mixed
    {
        $keys = explode($delimiter, $path);
        $current = $this;

        foreach ($keys as $key) {

            if ($current instanceof self) {
                if (!$current->offsetExists($key)) {
                    return null;
                }
                $current = $current->offsetGet($key);
                continue;
            }

            if (is_array($current)) {
                if (!array_key_exists($key, $current)) {
                    return null;
                }
                $current = $current[$key];
                continue;
            }

            return null;
        }

        return $current;
    }
}
