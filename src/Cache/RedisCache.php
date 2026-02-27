<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate\Cache;

final class RedisCache implements CacheInterface
{
    public function __construct(
        private \Redis $redis,
        private string $prefix = 'stpl:',
        private int $database = -1 // -1 => don't select
    ) {
        if ($this->database >= 0) {
            $this->redis->select($this->database);
        }
    }

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->k($key));
    }

    public function get(string $key): mixed
    {
        $raw = $this->redis->get($this->k($key));
        if ($raw === false || $raw === null) return null;

        $v = @unserialize($raw);
        return ($v === false && $raw !== serialize(false)) ? null : $v;
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 0): bool
    {
        $raw = serialize($value);
        $k = $this->k($key);

        if ($ttlSeconds > 0) {
            return (bool) $this->redis->setEx($k, $ttlSeconds, $raw);
        }
        return (bool) $this->redis->set($k, $raw);
    }

    public function delete(string $key): bool
    {
        return (bool) $this->redis->del($this->k($key));
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