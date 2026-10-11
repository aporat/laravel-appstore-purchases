<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Support\Psr6;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;

/**
 * A PSR-6 cache item handed out by {@see LaravelCacheItemPool}.
 *
 * @internal
 */
final class LaravelCacheItem implements CacheItemInterface
{
    private ?int $expiresAt = null;

    public function __construct(
        private readonly string $key,
        private mixed $value = null,
        private bool $hit = false,
    ) {}

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->hit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->hit = true;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->expiresAt = $expiration?->getTimestamp();

        return $this;
    }

    public function expiresAfter(int|DateInterval|null $time): static
    {
        $this->expiresAt = match (true) {
            $time === null => null,
            $time instanceof DateInterval => (new DateTimeImmutable)->add($time)->getTimestamp(),
            default => time() + $time,
        };

        return $this;
    }

    /**
     * Seconds until the item expires: null for "no expiry", 0 or less when it
     * has already expired.
     */
    public function ttl(): ?int
    {
        return $this->expiresAt === null ? null : $this->expiresAt - time();
    }
}
