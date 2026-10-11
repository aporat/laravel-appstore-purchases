<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\GooglePlay;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\InteractsWithTime;
use Psr\Log\LoggerInterface;
use ReceiptValidator\Exceptions\ValidationException;
use ReceiptValidator\GooglePlay\JWT\ServiceAccountCredentials;
use ReceiptValidator\GooglePlay\JWT\ServiceAccountTokenProvider;
use Throwable;

/**
 * An Android Publisher access token for one service account, shared through
 * a Laravel cache store so every worker and request reuses it until shortly
 * before Google says it expires, instead of minting a new one per process.
 *
 * Plugged into the receipt validator through its CallbackAccessTokenProvider.
 * Nothing is held in memory between calls (safe under Octane): every call
 * reads the store, and only a miss exchanges a signed assertion at Google's
 * token endpoint. When the store supports locks, concurrent misses wait for
 * the first refresh rather than each running their own; without locks, or
 * if the wait times out, a duplicate refresh is harmless.
 *
 * Failures are never cached, a store that is down falls back to a direct
 * exchange, and the token itself never reaches a log line or an exception.
 */
final class CachedServiceAccountToken
{
    use InteractsWithTime;

    /** Seconds the refresh lock is held at most. */
    private const int LOCK_SECONDS = 30;

    /** Seconds a request waits for another's refresh before doing its own. */
    private const int LOCK_WAIT_SECONDS = 10;

    /** The OAuth token request's timeouts, in seconds. */
    private const int CONNECT_TIMEOUT = 10;

    private const int TIMEOUT = 30;

    private readonly string $key;

    /**
     * @param  int  $refreshMargin  Seconds before Google's expiry at which a cached token is no longer used.
     */
    public function __construct(
        private readonly Repository $cache,
        private readonly ServiceAccountCredentials $credentials,
        string $prefix,
        private readonly int $refreshMargin = 300,
        private readonly string $scope = ServiceAccountTokenProvider::SCOPE_ANDROID_PUBLISHER,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->key = $prefix.'token:'.hash('sha256', $credentials->clientEmail."\n".$scope);
    }

    /** The cache key the token is stored under: per service account and scope. */
    public function cacheKey(): string
    {
        return $this->key;
    }

    /**
     * A currently valid access token, from the cache or freshly minted.
     *
     * @throws ValidationException If Google refuses the exchange or can't be reached.
     */
    public function get(): string
    {
        $cached = $this->cached();
        if ($cached !== null) {
            return $cached;
        }

        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $this->refresh();
        }

        try {
            $token = $store->lock($this->key.':lock', self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn (): string => $this->cached() ?? $this->refresh());

            return is_string($token) ? $token : $this->refresh();
        } catch (LockTimeoutException) {
            // Another refresh is taking too long: mint our own rather than fail.
            return $this->cached() ?? $this->refresh();
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            // The lock itself failed (the store is down): fall back to a direct exchange.
            $this->warn('lock', $e);

            return $this->refresh();
        }
    }

    /** The cached token, unless missing, malformed, or within the refresh margin. */
    private function cached(): ?string
    {
        try {
            $entry = $this->cache->get($this->key);
        } catch (Throwable $e) {
            $this->warn('read', $e);

            return null;
        }

        if (! is_array($entry)) {
            return null;
        }

        $token = $entry['access_token'] ?? null;
        $refreshAt = $entry['refresh_at'] ?? null;

        // The store's TTL should have dropped it already; checked again for
        // stores that keep entries past their TTL.
        if (! is_string($token) || $token === '' || ! is_int($refreshAt) || $refreshAt <= $this->currentTime()) {
            return null;
        }

        return $token;
    }

    /**
     * Mint a token and cache it until $refreshMargin seconds before it expires.
     *
     * @throws ValidationException
     */
    private function refresh(): string
    {
        [$token, $expiresIn] = $this->exchange();

        $ttl = $expiresIn - $this->refreshMargin;

        if ($ttl > 0) {
            try {
                $this->cache->put($this->key, [
                    'access_token' => $token,
                    'refresh_at' => $this->currentTime() + $ttl,
                ], $ttl);
            } catch (Throwable $e) {
                $this->warn('write', $e);
            }
        }

        return $token;
    }

    /**
     * Exchange a freshly signed assertion for a token at Google's endpoint.
     *
     * @return array{0: string, 1: int} The token and its lifetime in seconds.
     *
     * @throws ValidationException
     */
    private function exchange(): array
    {
        // The assertion is signed by the receipt validator's own provider;
        // its HTTP client is never used here.
        $assertion = (new ServiceAccountTokenProvider(
            $this->credentials,
            new Client,
            new HttpFactory,
            new HttpFactory,
            scope: $this->scope,
        ))->createAssertion();

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->post($this->credentials->tokenUri, [
                    'grant_type' => ServiceAccountTokenProvider::GRANT_TYPE,
                    'assertion' => $assertion,
                ]);
        } catch (ConnectionException $e) {
            throw new ValidationException('Unable to reach Google OAuth token endpoint - '.$e->getMessage(), 0, $e);
        }

        $decoded = $response->json();
        $decoded = is_array($decoded) ? $decoded : [];

        if ($response->status() !== 200) {
            $error = $decoded['error'] ?? 'unknown_error';
            $description = $decoded['error_description'] ?? 'No description provided.';

            throw new ValidationException(sprintf(
                'Google OAuth token request failed [%d] %s: %s',
                $response->status(),
                is_string($error) ? $error : 'unknown_error',
                is_string($description) ? $description : 'No description provided.',
            ), $response->status());
        }

        $token = $decoded['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new ValidationException('Google OAuth token response did not include an access_token.');
        }

        $expiresIn = $decoded['expires_in'] ?? null;

        return [$token, is_numeric($expiresIn) ? (int) $expiresIn : ServiceAccountTokenProvider::ASSERTION_LIFETIME_SECONDS];
    }

    private function warn(string $operation, Throwable $e): void
    {
        $this->logger?->warning("Google Play access token cache {$operation} failed; continuing without the cache.", [
            'service_account' => $this->credentials->clientEmail,
            'error' => $e::class.': '.$e->getMessage(),
        ]);
    }
}
