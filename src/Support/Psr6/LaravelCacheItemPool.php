<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Support\Psr6;

use Illuminate\Contracts\Cache\Repository;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

/**
 * A minimal PSR-6 pool over a Laravel cache store, for handing Laravel's cache
 * to libraries that expect PSR-6 (google/auth's certificate cache).
 *
 * Every key is namespaced under a prefix, so clear() is not supported: it
 * would have to flush the whole store. A failing store (Redis down, say)
 * reads as a miss and a failed write, never as an exception, so the caller
 * falls back to fetching what it needs.
 *
 * @internal
 */
final class LaravelCacheItemPool implements CacheItemPoolInterface
{
    /** @var array<string, LaravelCacheItem> */
    private array $deferred = [];

    public function __construct(
        private readonly Repository $store,
        private readonly string $prefix,
    ) {}

    public function getItem(string $key): CacheItemInterface
    {
        if (isset($this->deferred[$key])) {
            return clone $this->deferred[$key];
        }

        try {
            $value = $this->store->get($this->prefix.$key);
        } catch (Throwable) {
            $value = null;
        }

        return $value === null ? new LaravelCacheItem($key) : new LaravelCacheItem($key, $value, true);
    }

    /**
     * @param  string[]  $keys
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function clear(): bool
    {
        $this->deferred = [];

        return false;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->deferred[$key]);

        try {
            $this->store->forget($this->prefix.$key);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  string[]  $keys
     */
    public function deleteItems(array $keys): bool
    {
        $deleted = true;
        foreach ($keys as $key) {
            $deleted = $this->deleteItem($key) && $deleted;
        }

        return $deleted;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (! $item instanceof LaravelCacheItem) {
            return false;
        }

        $ttl = $item->ttl();

        try {
            if ($ttl !== null && $ttl <= 0) {
                $this->store->forget($this->prefix.$item->getKey());

                return true;
            }

            return $ttl === null
                ? $this->store->forever($this->prefix.$item->getKey(), $item->get())
                : $this->store->put($this->prefix.$item->getKey(), $item->get(), $ttl);
        } catch (Throwable) {
            return false;
        }
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (! $item instanceof LaravelCacheItem) {
            return false;
        }

        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    public function commit(): bool
    {
        $saved = true;
        foreach ($this->deferred as $item) {
            $saved = $this->save($item) && $saved;
        }
        $this->deferred = [];

        return $saved;
    }
}
