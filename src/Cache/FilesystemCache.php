<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate\Cache;

final class FilesystemCache implements CacheInterface
{
    public function __construct(
        private string $dir,
        private string $prefix = 'stpl_'
    ) {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    private function path(string $key): string
    {
        $safe = hash('sha256', $key);
        return rtrim($this->dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->prefix . $safe . '.cache';
    }

    public function has(string $key): bool
    {
        $p = $this->path($key);
        if (!is_file($p)) return false;

        $data = @file_get_contents($p);
        if ($data === false) return false;

        $payload = @unserialize($data);
        if (!is_array($payload) || !isset($payload['e'])) return false;

        return $payload['e'] === 0 || $payload['e'] >= time();
    }

    public function get(string $key): mixed
    {
        $p = $this->path($key);
        $data = @file_get_contents($p);
        if ($data === false) return null;

        $payload = @unserialize($data);
        if (!is_array($payload) || !isset($payload['e'], $payload['v'])) return null;

        if ($payload['e'] !== 0 && $payload['e'] < time()) {
            @unlink($p);
            return null;
        }

        return $payload['v'];
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 0): bool
    {
        $p = $this->path($key);
        $exp = $ttlSeconds > 0 ? (time() + $ttlSeconds) : 0;

        $payload = serialize(['e' => $exp, 'v' => $value]);
        return (bool) @file_put_contents($p, $payload, LOCK_EX);
    }

    public function delete(string $key): bool
    {
        $p = $this->path($key);
        return !is_file($p) || @unlink($p);
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