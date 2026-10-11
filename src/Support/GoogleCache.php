<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Support;

use Aporat\AppStorePurchases\Support\Psr6\LaravelCacheItemPool;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Where Google Play's access tokens and Google's OIDC signing certificates are
 * cached between requests.
 *
 * Configured under `appstore-purchases.google_cache`: `enabled` (default
 * true), `store` (a cache store name, the default store when null) and
 * `token_refresh_margin` (seconds before a token's expiry at which it is
 * refreshed, default 300). Resolved on every call, never held, so it is safe
 * under Octane and follows config changes made at runtime.
 */
final class GoogleCache
{
    /** Prefix of every key this package writes for Google. */
    public const string KEY_PREFIX = 'appstore-purchases:google:';

    /** Default seconds before Google's expiry at which a cached token is refreshed. */
    public const int DEFAULT_REFRESH_MARGIN = 300;

    public function __construct(
        private readonly Container $app,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config()->get('appstore-purchases.google_cache.enabled', true);
    }

    public function store(): Repository
    {
        $store = $this->config()->get('appstore-purchases.google_cache.store');

        /** @var CacheFactory $cache */
        $cache = $this->app->make('cache');

        return $cache->store(is_string($store) && $store !== '' ? $store : null);
    }

    public function refreshMargin(): int
    {
        $margin = $this->config()->get('appstore-purchases.google_cache.token_refresh_margin');

        return is_numeric($margin) && (int) $margin >= 0 ? (int) $margin : self::DEFAULT_REFRESH_MARGIN;
    }

    /** A PSR-6 view of the store for google/auth's certificate cache, or null when caching is off. */
    public function certificatePool(): ?CacheItemPoolInterface
    {
        return $this->enabled() ? new LaravelCacheItemPool($this->store(), self::KEY_PREFIX.'certs:') : null;
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = $this->app->make('config');

        return $config;
    }
}
