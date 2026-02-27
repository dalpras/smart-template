<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate\Cache;

/**
 * Simple in-process (per-request) memory cache.
 *
 * Notes:
 * - This cache is NOT shared between PHP-FPM workers or requests.
 * - TTL is supported (relative seconds), but most apps use it mainly as an L1 cache
 *   in front of Redis/APCu/Filesystem (e.g. via ChainCache).
 * - Values are stored "as-is" (no serialization). Perfect for closures/objects too.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value:mixed, expiresAt:int}> */
    private array $store = [];

    public function __construct(
        private string $prefix = ''
    ) {}

    private function k(string $key): string
    {
        return $this->prefix === '' ? $key : $this->prefix . $key;
    }

    private function now(): int
    {
        return time();
    }

    private function isExpired(int $expiresAt, int $now): bool
    {
        // expiresAt === 0 means "no expiration"
        return $expiresAt !== 0 && $expiresAt <= $now;
    }

    public function get(string $key): mixed
    {
        $k = $this->k($key);

        if (!isset($this->store[$k])) {
            return null;
        }

        $now = $this->now();
        $expiresAt = $this->store[$k]['expiresAt'];

        if ($this->isExpired($expiresAt, $now)) {
            unset($this->store[$k]);
            return null;
        }

        return $this->store[$k]['value'];
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 0): bool
    {
        $k = $this->k($key);

        $expiresAt = 0;
        if ($ttlSeconds > 0) {
            $expiresAt = $this->now() + $ttlSeconds;
        }

        $this->store[$k] = [
            'value'     => $value,
            'expiresAt' => $expiresAt,
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        $k = $this->k($key);

        if (isset($this->store[$k])) {
            unset($this->store[$k]);
        }
        return true;
    }

    public function has(string $key): bool
    {
        $k = $this->k($key);

        if (!isset($this->store[$k])) {
            return false;
        }

        $now = $this->now();
        $expiresAt = $this->store[$k]['expiresAt'];

        if ($this->isExpired($expiresAt, $now)) {
            unset($this->store[$k]);
            return false;
        }

        return true;
    }

    public function remember(string $key, int $ttlSeconds, callable $producer): mixed
    {
        $hit = $this->get($key);
        if ($hit !== null) {
            return $hit;
        }

        $value = $producer();
        $this->set($key, $value, $ttlSeconds);

        return $value;
    }

    /**
     * Optional helper: clear everything (useful in tests).
     */
    public function clear(): void
    {
        $this->store = [];
    }
}