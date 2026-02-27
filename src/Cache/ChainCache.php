<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate\Cache;

final class ChainCache implements CacheInterface
{
    /** @param CacheInterface[] $caches */
    public function __construct(
        private array $caches,
        private bool $promote = true
    ) {}

    public function has(string $key): bool
    {
        foreach ($this->caches as $c) {
            if ($c->has($key)) return true;
        }
        return false;
    }

    public function get(string $key): mixed
    {
        $hitIndex = null;
        $value = null;

        foreach ($this->caches as $i => $c) {
            $value = $c->get($key);
            if ($value !== null) {
                $hitIndex = $i;
                break;
            }
        }

        if ($value !== null && $this->promote && $hitIndex !== null && $hitIndex > 0) {
            // Promote to all earlier caches (best-effort)
            for ($j = 0; $j < $hitIndex; $j++) {
                $this->caches[$j]->set($key, $value, 0);
            }
        }

        return $value;
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 0): bool
    {
        $ok = true;
        foreach ($this->caches as $c) {
            $ok = $c->set($key, $value, $ttlSeconds) && $ok;
        }
        return $ok;
    }

    public function delete(string $key): bool
    {
        $ok = true;
        foreach ($this->caches as $c) {
            $ok = $c->delete($key) && $ok;
        }
        return $ok;
    }

    public function remember(string $key, int $ttlSeconds, callable $producer): mixed
    {
        $v = $this->get($key);
        if ($v !== null) return $v;

        $v = $producer();
        $this->set($key, $v, $ttlSeconds);
        return $v;
    }
}