<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\GooglePlay;

use Aporat\AppStorePurchases\AppStorePurchasesManager;
use Aporat\AppStorePurchases\AppStorePurchasesServiceProvider;
use Aporat\AppStorePurchases\GooglePlay\CachedServiceAccountToken;
use Aporat\AppStorePurchases\Support\GoogleCache;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use ReceiptValidator\Environment;
use ReceiptValidator\Exceptions\ValidationException;
use ReceiptValidator\GooglePlay\JWT\CallbackAccessTokenProvider;
use ReceiptValidator\GooglePlay\JWT\ServiceAccountCredentials;
use ReceiptValidator\GooglePlay\JWT\ServiceAccountTokenProvider;
use ReceiptValidator\GooglePlay\Validator as GooglePlayValidator;
use RuntimeException;
use Stringable;

class CachedServiceAccountTokenTest extends TestCase
{
    private const string TOKEN_URI = 'https://oauth2.googleapis.com/token';

    protected function getPackageProviders($app): array
    {
        return [AppStorePurchasesServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('appstore-purchases.validators.play', [
            'validator' => 'google-play',
            'package_name' => 'app.example',
            'service_account_key_path' => __DIR__.'/certs/testServiceAccount.json',
            'environment' => Environment::PRODUCTION,
        ]);
    }

    private static function credentials(string $email = 'iap-verifier@test-project.iam.gserviceaccount.com'): ServiceAccountCredentials
    {
        $json = json_decode((string) file_get_contents(__DIR__.'/certs/testServiceAccount.json'), true);
        $json['client_email'] = $email;

        return ServiceAccountCredentials::fromArray($json);
    }

    private function token(?ServiceAccountCredentials $credentials = null, ?Repository $cache = null, ?AbstractLogger $logger = null): CachedServiceAccountToken
    {
        return new CachedServiceAccountToken(
            cache: $cache ?? Cache::store(),
            credentials: $credentials ?? self::credentials(),
            prefix: GoogleCache::KEY_PREFIX,
            refreshMargin: 300,
            logger: $logger,
        );
    }

    private static function granted(string $token, int $expiresIn = 3600): PromiseInterface
    {
        return Http::response(['access_token' => $token, 'expires_in' => $expiresIn, 'token_type' => 'Bearer']);
    }

    #[Test]
    public function a_second_request_reuses_the_cached_token(): void
    {
        Http::fake([self::TOKEN_URI => Http::sequence()->pushResponse(self::granted('ya29.first'))->pushResponse(self::granted('ya29.second'))]);

        // Two instances stand in for two requests (or two workers): nothing is shared but the cache.
        $this->assertSame('ya29.first', $this->token()->get());
        $this->assertSame('ya29.first', $this->token()->get());

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::TOKEN_URI
            && $request['grant_type'] === ServiceAccountTokenProvider::GRANT_TYPE
            && is_string($request['assertion']) && substr_count($request['assertion'], '.') === 2);
    }

    #[Test]
    public function a_token_is_refreshed_once_within_the_margin_of_its_expiry(): void
    {
        Http::fake([self::TOKEN_URI => Http::sequence()->pushResponse(self::granted('ya29.first'))->pushResponse(self::granted('ya29.second'))]);

        $this->assertSame('ya29.first', $this->token()->get());

        // 3600s token, 300s margin: still used just before the 3300s mark...
        $this->travel(3299)->seconds();
        $this->assertSame('ya29.first', $this->token()->get());
        Http::assertSentCount(1);

        // ...and replaced after it.
        $this->travel(2)->seconds();
        $this->assertSame('ya29.second', $this->token()->get());
        Http::assertSentCount(2);
    }

    #[Test]
    public function a_failed_exchange_is_not_cached(): void
    {
        Http::fake([self::TOKEN_URI => Http::sequence()
            ->push(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400)
            ->pushResponse(self::granted('ya29.after-failure'))]);

        try {
            $this->token()->get();
            $this->fail('A refused exchange must throw.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('[400] invalid_grant: Invalid JWT Signature.', $e->getMessage());
        }

        $this->assertSame('ya29.after-failure', $this->token()->get());
        Http::assertSentCount(2);
    }

    #[Test]
    public function a_response_without_a_token_is_an_error_and_not_cached(): void
    {
        Http::fake([self::TOKEN_URI => Http::sequence()->push(['expires_in' => 3600])->pushResponse(self::granted('ya29.ok'))]);

        try {
            $this->token()->get();
            $this->fail('A response without access_token must throw.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('did not include an access_token', $e->getMessage());
        }

        $this->assertSame('ya29.ok', $this->token()->get());
    }

    #[Test]
    public function a_token_that_expires_within_the_margin_is_used_but_not_cached(): void
    {
        Http::fake([self::TOKEN_URI => Http::sequence()->pushResponse(self::granted('ya29.short', 120))->pushResponse(self::granted('ya29.next'))]);

        $this->assertSame('ya29.short', $this->token()->get());
        $this->assertSame('ya29.next', $this->token()->get());
    }

    #[Test]
    public function each_service_account_has_its_own_entry(): void
    {
        Http::fake([self::TOKEN_URI => Http::sequence()->pushResponse(self::granted('ya29.one'))->pushResponse(self::granted('ya29.two'))]);

        $one = $this->token(self::credentials('one@test-project.iam.gserviceaccount.com'));
        $two = $this->token(self::credentials('two@test-project.iam.gserviceaccount.com'));

        $this->assertNotSame($one->cacheKey(), $two->cacheKey());
        $this->assertSame('ya29.one', $one->get());
        $this->assertSame('ya29.two', $two->get());
        $this->assertSame('ya29.one', $one->get());
        Http::assertSentCount(2);
    }

    #[Test]
    public function the_cache_key_names_neither_the_account_nor_the_token(): void
    {
        $key = $this->token()->cacheKey();

        $this->assertStringStartsWith(GoogleCache::KEY_PREFIX.'token:', $key);
        $this->assertStringNotContainsString('iap-verifier', $key);
    }

    #[Test]
    public function a_failing_cache_store_falls_back_to_an_exchange_without_logging_the_token(): void
    {
        Http::fake([self::TOKEN_URI => self::granted('ya29.secret-token')]);

        $cache = $this->createStub(Repository::class);
        $cache->method('get')->willThrowException(new RuntimeException('Connection refused [tcp://redis:6379]'));
        $cache->method('put')->willThrowException(new RuntimeException('Connection refused [tcp://redis:6379]'));
        $cache->method('getStore')->willReturn($this->createStub(Store::class));

        $logger = new class extends AbstractLogger
        {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = $message.' '.json_encode($context);
            }
        };

        $this->assertSame('ya29.secret-token', $this->token(cache: $cache, logger: $logger)->get());

        $this->assertCount(2, $logger->lines);
        foreach ($logger->lines as $line) {
            $this->assertStringContainsString('Connection refused', $line);
            $this->assertStringNotContainsString('ya29', $line);
        }
    }

    #[Test]
    public function the_manager_shares_the_token_across_requests_through_the_cache(): void
    {
        Http::fake([self::TOKEN_URI => Http::sequence()->pushResponse(self::granted('ya29.shared'))->pushResponse(self::granted('ya29.unexpected'))]);

        $provider = (new AppStorePurchasesManager($this->app))->get('play');
        $this->assertInstanceOf(GooglePlayValidator::class, $provider);
        $this->assertInstanceOf(CallbackAccessTokenProvider::class, $provider->getAccessTokenProvider());
        $this->assertSame('ya29.shared', $provider->getAccessTokenProvider()->getAccessToken());

        // A fresh manager is a fresh request (or worker): no validator in memory.
        $next = (new AppStorePurchasesManager($this->app))->get('play');
        $this->assertInstanceOf(GooglePlayValidator::class, $next);
        $this->assertSame('ya29.shared', $next->getAccessTokenProvider()->getAccessToken());

        Http::assertSentCount(1);
    }

    #[Test]
    public function the_manager_uses_the_configured_store(): void
    {
        $this->app['config']->set('cache.stores.google-tokens', ['driver' => 'array']);
        $this->app['config']->set('appstore-purchases.google_cache.store', 'google-tokens');
        Http::fake([self::TOKEN_URI => self::granted('ya29.elsewhere')]);

        $validator = (new AppStorePurchasesManager($this->app))->get('play');
        $this->assertInstanceOf(GooglePlayValidator::class, $validator);
        $validator->getAccessTokenProvider()->getAccessToken();

        $key = $this->token()->cacheKey();
        $this->assertNotNull(Cache::store('google-tokens')->get($key));
        $this->assertNull(Cache::store()->get($key));
    }

    #[Test]
    public function a_failure_reaches_the_validator_as_a_validation_exception(): void
    {
        Http::fake([self::TOKEN_URI => Http::response(['error' => 'unauthorized_client'], 401)]);

        $validator = (new AppStorePurchasesManager($this->app))->get('play');
        $this->assertInstanceOf(GooglePlayValidator::class, $validator);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unauthorized_client');

        $validator->getAccessTokenProvider()->getAccessToken();
    }

    #[Test]
    public function disabling_the_cache_restores_the_per_process_provider(): void
    {
        $this->app['config']->set('appstore-purchases.google_cache.enabled', false);

        $validator = (new AppStorePurchasesManager($this->app))->get('play');

        $this->assertInstanceOf(GooglePlayValidator::class, $validator);
        $this->assertInstanceOf(ServiceAccountTokenProvider::class, $validator->getAccessTokenProvider());
    }
}
