<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate\Cache;

final class ApcuCache implements CacheInterface
{
    public function __construct(
        private string $prefix = 'stpl:',
        private bool $enabled = true
    ) {}

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }

    public function has(string $key): bool
    {
        if (!$this->enabled || !function_exists('apcu_exists')) return false;
        return apcu_exists($this->k($key));
    }

    public function get(string $key): mixed
    {
        if (!$this->enabled || !function_exists('apcu_fetch')) return null;
        $ok = false;
        $v = apcu_fetch($this->k($key), $ok);
        return $ok ? $v : null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 0): bool
    {
        if (!$this->enabled || !function_exists('apcu_store')) return false;
        return apcu_store($this->k($key), $value, max(0, $ttlSeconds));
    }

    public function delete(string $key): bool
    {
        if (!$this->enabled || !function_exists('apcu_delete')) return false;
        return apcu_delete($this->k($key));
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