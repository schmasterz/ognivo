<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * In-memory Redis simulation for unit and feature testing without live Redis daemon.
 */
final class FakeRedis
{
    /** @var array<string, mixed> */
    private array $storage = [];

    /**
     * @return array<string, string>
     */
    public function hgetall(string $key): array
    {
        $val = $this->storage[$key] ?? [];
        if (! is_array($val)) {
            return [];
        }

        /** @var array<string, string> $val */
        return $val;
    }

    public function hset(string $key, string $field, string $value): int
    {
        if (! isset($this->storage[$key]) || ! is_array($this->storage[$key])) {
            $this->storage[$key] = [];
        }
        $isNew = ! isset($this->storage[$key][$field]);
        $this->storage[$key][$field] = $value;

        return $isNew ? 1 : 0;
    }

    public function hget(string $key, string $field): ?string
    {
        if (! isset($this->storage[$key]) || ! is_array($this->storage[$key])) {
            return null;
        }

        $val = $this->storage[$key][$field] ?? null;

        return is_string($val) ? $val : null;
    }

    public function hdel(string $key, string ...$fields): int
    {
        if (! isset($this->storage[$key]) || ! is_array($this->storage[$key])) {
            return 0;
        }

        $count = 0;
        foreach ($fields as $field) {
            if (isset($this->storage[$key][$field])) {
                unset($this->storage[$key][$field]);
                $count++;
            }
        }

        return $count;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->storage[$key] = $value;

        return true;
    }

    public function expire(string $key, int $ttl): bool
    {
        return isset($this->storage[$key]);
    }

    public function get(string $key): ?string
    {
        $val = $this->storage[$key] ?? null;

        return is_string($val) ? $val : null;
    }

    public function del(string ...$keys): int
    {
        $count = 0;
        foreach ($keys as $k) {
            if (isset($this->storage[$k])) {
                unset($this->storage[$k]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<int, mixed>  $args
     */
    public function command(string $name, array $args = []): mixed
    {
        if (strtolower($name) === 'getdel') {
            $key = (string) ($args[0] ?? '');
            $val = $this->storage[$key] ?? null;
            unset($this->storage[$key]);

            return is_string($val) ? $val : null;
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $args
     */
    public function eval(string $script, array $args = [], int $numKeys = 0): mixed
    {
        $key = (string) ($args[0] ?? '');
        $val = $this->storage[$key] ?? null;
        unset($this->storage[$key]);

        return is_string($val) ? $val : null;
    }

    public function flush(): void
    {
        $this->storage = [];
    }
}
