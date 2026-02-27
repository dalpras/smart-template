<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate\Cache;

interface CacheInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds = 0): bool;

    public function delete(string $key): bool;

    public function has(string $key): bool;

    /**
     * Read-through cache helper.
     * If missing -> computes via $producer, stores, returns.
     */
    public function remember(string $key, int $ttlSeconds, callable $producer): mixed;
}