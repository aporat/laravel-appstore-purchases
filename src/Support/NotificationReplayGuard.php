<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;

/**
 * Remembers which server notifications have already been handled, so a
 * replayed or duplicated delivery does not fire its event twice.
 *
 * Apple gives every notification a `notificationUUID` and Pub/Sub gives every
 * push a `messageId`; both stores redeliver on a non-2xx response and may
 * occasionally deliver the same message twice. The guard records each ID in
 * the configured cache store for `replay_protection.ttl` seconds. A delivery
 * whose ID is already recorded is acknowledged without dispatching anything,
 * which both deduplicates genuine retries and blunts a captured notification
 * being replayed at the endpoint.
 *
 * The ID is claimed before the event fires and released again when a listener
 * throws, so the store's retry of that same notification is processed rather
 * than mistaken for a replay.
 *
 * Configured under `appstore-purchases.replay_protection`: `enabled` (default
 * true), `store` (a cache store name, default store when null) and `ttl` in
 * seconds. With a per-server store such as `file` or `array` the protection
 * is per server; use a shared store (Redis, database) behind a load balancer.
 */
final class NotificationReplayGuard
{
    private const string KEY_PREFIX = 'appstore-purchases:notification:';

    /** Seven days: the longest either store keeps retrying a notification. */
    private const int DEFAULT_TTL = 604800;

    public function __construct(
        private readonly Container $app,
    ) {}

    /**
     * Record the notification as handled.
     *
     * Returns true when this is the first sighting of the ID (or when replay
     * protection is disabled), false when the same ID was already claimed
     * within the TTL and the delivery should be acknowledged without
     * dispatching.
     */
    public function claim(string $source, string $id): bool
    {
        if (! $this->enabled() || $id === '') {
            return true;
        }

        return $this->store()->add($this->key($source, $id), true, $this->ttl());
    }

    /**
     * Forget a claimed ID so the store's retry of that notification is
     * processed. Called when a listener failed and the delivery was answered
     * with an error.
     */
    public function release(string $source, string $id): void
    {
        if (! $this->enabled() || $id === '') {
            return;
        }

        $this->store()->forget($this->key($source, $id));
    }

    public function enabled(): bool
    {
        return (bool) $this->config()->get('appstore-purchases.replay_protection.enabled', true);
    }

    private function ttl(): int
    {
        $ttl = $this->config()->get('appstore-purchases.replay_protection.ttl');

        return is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : self::DEFAULT_TTL;
    }

    private function store(): Repository
    {
        $store = $this->config()->get('appstore-purchases.replay_protection.store');

        /** @var CacheFactory $cache */
        $cache = $this->app->make('cache');

        return $cache->store(is_string($store) && $store !== '' ? $store : null);
    }

    private function key(string $source, string $id): string
    {
        return self::KEY_PREFIX.$source.':'.$id;
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = $this->app->make('config');

        return $config;
    }
}
