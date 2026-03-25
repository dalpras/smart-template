<?php

declare(strict_types=1);

namespace DalPraS\SmartTemplate\Collection;

use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use OutOfBoundsException;
use Traversable;

/**
 * RenderCollection
 *
 * A lightweight, lazily-evaluated hierarchical container for template nodes.
 *
 * This class is the internal tree structure used by the template engine to:
 * - store template definitions
 * - lazily wrap nested arrays into RenderCollection instances
 * - lazily compile leaf nodes through an injected compiler
 * - resolve nested structures into plain PHP arrays
 *
 * ---------------------------------------------------------------------------
 * Core Responsibilities
 * ---------------------------------------------------------------------------
 *
 * 1) Lazy Wrapping of Nested Arrays
 *    --------------------------------
 *    Nested arrays are converted into RenderCollection instances only when
 *    accessed through offsetGet().
 *
 * 2) Lazy Compilation of Leaf Nodes
 *    --------------------------------
 *    If a lazy compiler is defined, leaf values are transformed on first access.
 *    This is typically used to compile template strings into render callables.
 *
 * 3) Hierarchical Lookup
 *    --------------------------------
 *    The collection supports direct key access and nested path traversal:
 *
 *      - getPath('a.b.c')   strict lookup, cached, throws if missing
 *      - find('a/b/c')      safe lookup, returns null if missing
 *
 * 4) Deep Resolution
 *    --------------------------------
 *    The resolve() method recursively evaluates:
 *      - nested RenderCollection instances
 *      - closures
 *      - arrays returned by closures
 *      - deeply nested structures
 *
 *    The result is a fully materialized plain PHP array with no closures and
 *    no RenderCollection objects remaining.
 *
 * ---------------------------------------------------------------------------
 * Design Philosophy
 * ---------------------------------------------------------------------------
 *
 * - Wrapping is lazy.
 * - Compilation is lazy.
 * - Memoization is local and explicit.
 * - Deep resolution is opt-in.
 * - The structure behaves like an array without losing template-specific behavior.
 *
 * ---------------------------------------------------------------------------
 * Performance Characteristics
 * ---------------------------------------------------------------------------
 *
 * - O(1) access for raw top-level keys.
 * - Nested lookup cost is proportional to path depth.
 * - Compilation cost is paid only once per accessed node.
 * - Wrapped/compiled values are memoized back into the collection.
 * - Frequently used dotted paths can be cached via getPath().
 *
 * ---------------------------------------------------------------------------
 * Mutability Notes
 * ---------------------------------------------------------------------------
 *
 * This class is mutable.
 *
 * The following operations may modify internal state:
 * - offsetGet()      memoizes wrapped/compiled values
 * - offsetSet()      changes stored items
 * - offsetUnset()    removes stored items
 * - merge()          recursively replaces values
 * - walk()           may mutate values in place
 *
 * Because of this, cached path lookups are automatically invalidated whenever
 * the structure is modified.
 *
 * ---------------------------------------------------------------------------
 * Usage Context
 * ---------------------------------------------------------------------------
 *
 * Internal structure for the SmartTemplate engine.
 * Not intended as a general-purpose collection library.
 */
final class RenderCollection implements ArrayAccess, IteratorAggregate, Countable
{
    private ?self $root = null;

    /** @var array<string, mixed> */
    private array $items;

    /** @var null|Closure(mixed $value, string|int $key, RenderCollection $self): mixed */
    private ?Closure $lazyCompiler = null;

    /** @var array<string, mixed> Cache for successful strict path lookups */
    private array $pathCache = [];

    private array $segmentCache = [];

    /**
     * Create a new RenderCollection instance.
     *
     * @param array<string, mixed> $items Initial template structure.
     *
     * Values are stored as-is. Nested wrapping and compilation only happen
     * later when nodes are accessed.
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Assign the root RenderCollection for this tree.
     *
     * The root is propagated to child collections so all nested scopes can
     * still access a shared root context.
     *
     * This assignment is idempotent: once a root is set, it is not replaced.
     */
    public function setRoot(self $root): void
    {
        $this->root ??= $root;
    }

    /**
     * Return the root collection of the current tree.
     *
     * If no explicit root has been assigned, the current instance is treated
     * as the root.
     */
    public function getRoot(): self
    {
        return $this->root ?? $this;
    }

    /**
     * Define the lazy compiler used when leaf nodes are accessed.
     *
     * The compiler receives:
     * - the raw value
     * - the current key
     * - the current RenderCollection scope
     *
     * It may return:
     * - a compiled closure
     * - a scalar
     * - an array, which will then be wrapped into a RenderCollection
     * - any other value the engine wishes to memoize
     *
     * @param null|Closure(mixed $value, string|int $key, RenderCollection $self): mixed $compiler
     */
    public function setLazyCompiler(?Closure $compiler): void
    {
        $this->lazyCompiler = $compiler;
    }

    /**
     * Determine whether a top-level key exists.
     *
     * This checks only the immediate collection level.
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->items);
    }

    /**
     * Retrieve a value from the collection.
     *
     * Behavior:
     * - nested arrays are lazily wrapped into RenderCollection instances
     * - leaf values may be lazily compiled using the configured compiler
     * - wrapped/compiled values are memoized back into the collection
     *
     * Missing keys return null.
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

    /**
     * Set a top-level key.
     *
     * Invalidates cached path lookups because the tree may have changed.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->items[(string) $offset] = $value;
        $this->flushPathCache();
    }

    /**
     * Remove a top-level key.
     *
     * Invalidates cached path lookups because the tree may have changed.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[(string) $offset]);
        $this->flushPathCache();
    }

    /**
     * Return an iterator over raw internal items.
     *
     * Iteration itself does not force wrapping or compilation.
     * Consumers that want lazy behavior should access values through offsetGet().
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Return the number of top-level items in the collection.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Merge another array into the collection recursively.
     *
     * Existing values are replaced using array_replace_recursive().
     * Cached path lookups are invalidated after the merge.
     *
     * @param array<string, mixed> $data
     */
    public function merge(array $data): void
    {
        $this->items = array_replace_recursive($this->items, $data);
        $this->flushPathCache();
    }

    /**
     * Convert the collection into a plain PHP array.
     *
     * This forces lazy wrapping/compilation during traversal, but does not
     * execute closures. Use resolve() for full evaluation.
     *
     * @return array<string, mixed>
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
     * Walk through the collection recursively.
     *
     * Behavior:
     * - forces lazy wrapping/compilation via offsetGet()
     * - recursively visits nested RenderCollection instances
     * - allows in-place mutation of leaf values
     *
     * The callback receives:
     * - the value (by value)
     * - the current key
     *
     * Mutated values are written back into the collection.
     */
    public function walk(?callable $callback): bool
    {
        if (!$callback) {
            return true;
        }

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

        $this->flushPathCache();

        return true;
    }

    /**
     * Fully resolve the collection into a plain PHP array.
     *
     * Behavior:
     * - forces lazy compilation and wrapping
     * - executes closures
     * - recursively resolves nested collections and arrays
     *
     * @param array<string, mixed> $params Optional parameters passed to closures.
     * @return array<string, mixed>
     */
    public function resolve(array $params = []): array
    {
        // Force lazy compilation / wrapping at this level
        foreach (array_keys($this->items) as $k) {
            $this->offsetGet($k);
        }

        $call = static function (Closure $c) use ($params) {
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

    private function getSegments(string $path, string $delimiter): array
    {
        $cacheKey = $delimiter . "\0" . $path;

        return $this->segmentCache[$cacheKey]
            ??= array_values(array_filter(explode($delimiter, $path), 'strlen'));
    }

    /**
     * Retrieve a nested value using a strict path lookup.
     *
     * This method:
     * - supports dotted paths by default, such as "layout.menu.mobile"
     * - uses lazy wrapping/compilation during traversal
     * - caches successful lookups for repeated access
     * - throws OutOfBoundsException if the path cannot be resolved
     *
     * @throws OutOfBoundsException
     */
    public function getPath(string $path, string $separator = '.'): mixed
    {
        if (array_key_exists($path, $this->pathCache)) {
            return $this->pathCache[$path];
        }

        $found = false;
        $value = $this->resolvePath($path, $separator, $found);

        if (!$found) {
            throw new OutOfBoundsException("Path not found: {$path}");
        }

        return $this->pathCache[$path] = $value;
    }

    /**
     * Retrieve a nested value using a safe path lookup.
     *
     * This method:
     * - supports slash-separated paths by default, such as "layout/menu/mobile"
     * - uses lazy wrapping/compilation during traversal
     * - returns null if the path cannot be resolved
     *
     * Note:
     * A returned null may also mean the resolved value itself is null.
     * Use getPath() if strict missing-path detection is required.
     */
    public function find(string $path, string $delimiter = '/'): mixed
    {
        $found = false;

        return $this->resolvePath($path, $delimiter, $found);
    }

    /**
     * Resolve a nested path against the collection tree.
     *
     * Traverses RenderCollection instances and plain arrays uniformly.
     * Lazy wrapping/compilation is triggered when crossing collection nodes.
     *
     * @param bool $found Output flag indicating whether traversal completed successfully.
     */
    private function resolvePath(string $path, string $delimiter, bool &$found): mixed
    {
        $keys = $this->getSegments($path, $delimiter);
        $current = $this;

        foreach ($keys as $key) {
            if ($current instanceof self) {
                if (!$current->offsetExists($key)) {
                    $found = false;
                    return null;
                }

                $current = $current->offsetGet($key);
                continue;
            }

            if (is_array($current)) {
                if (!array_key_exists($key, $current)) {
                    $found = false;
                    return null;
                }

                $current = $current[$key];
                continue;
            }

            $found = false;
            return null;
        }

        $found = true;
        return $current;
    }

    /**
     * Invalidate cached strict path lookups.
     *
     * Needed because the collection is mutable and cached paths may become stale
     * after updates, merges, or in-place mutations.
     */
    private function flushPathCache(): void
    {
        $this->pathCache = [];
        $this->segmentCache = [];
    }
}
