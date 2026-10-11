<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\GooglePlay;

use Aporat\AppStorePurchases\AppStorePurchasesServiceProvider;
use Aporat\AppStorePurchases\Contracts\PubSubPushVerifier;
use Aporat\AppStorePurchases\GooglePlay\GoogleOidcPushVerifier;
use Aporat\AppStorePurchases\Support\GoogleCache;
use Aporat\AppStorePurchases\Support\Psr6\LaravelCacheItemPool;
use Firebase\JWT\JWT;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Real google/auth verification of real RS256 tokens, with Google's
 * certificate endpoint mocked at the HTTP layer.
 */
class GoogleOidcCertificateCacheTest extends TestCase
{
    private const string AUDIENCE = 'https://api.example.com/google/notifications';

    private const string EMAIL = 'pubsub-push@example.iam.gserviceaccount.com';

    /** @var array<string, \OpenSSLAsymmetricKey> */
    private static array $keys = [];

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    private MockHandler $responses;

    protected function getPackageProviders($app): array
    {
        return [AppStorePurchasesServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->history = [];
        $this->responses = new MockHandler;
    }

    private static function key(string $kid): \OpenSSLAsymmetricKey
    {
        return self::$keys[$kid] ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA])
            ?: throw new \RuntimeException('openssl_pkey_new failed');
    }

    /** Google's certificate endpoint answering with the given key IDs. */
    private function certs(array $kids, string $cacheControl = 'public, max-age=3600, must-revalidate, no-transform'): void
    {
        $keys = [];
        foreach ($kids as $kid) {
            $rsa = openssl_pkey_get_details(self::key($kid))['rsa'];
            $keys[] = [
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => $kid,
                'n' => rtrim(strtr(base64_encode($rsa['n']), '+/', '-_'), '='),
                'e' => rtrim(strtr(base64_encode($rsa['e']), '+/', '-_'), '='),
            ];
        }

        $this->responses->append(new Response(200, ['Cache-Control' => $cacheControl, 'Content-Type' => 'application/json'], (string) json_encode(['keys' => $keys])));
    }

    private static function token(string $kid, string $audience = self::AUDIENCE): string
    {
        openssl_pkey_export(self::key($kid), $pem);

        return JWT::encode([
            'iss' => GoogleOidcPushVerifier::ISSUER,
            'aud' => $audience,
            'email' => self::EMAIL,
            'email_verified' => true,
            'sub' => '1234567890',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $pem, 'RS256', $kid);
    }

    private function verifier(?CacheItemPoolInterface $pool): GoogleOidcPushVerifier
    {
        $stack = HandlerStack::create($this->responses);
        $stack->push(Middleware::history($this->history));

        return new GoogleOidcPushVerifier(
            audience: self::AUDIENCE,
            serviceAccountEmail: self::EMAIL,
            certificateCache: $pool,
            httpHandler: HttpHandlerFactory::build(new Client(['handler' => $stack])),
        );
    }

    private static function pool(): LaravelCacheItemPool
    {
        return new LaravelCacheItemPool(Cache::store(), GoogleCache::KEY_PREFIX.'certs:');
    }

    private static function push(string $token): Request
    {
        $request = Request::create('/', 'POST', [
            'message' => ['data' => base64_encode('{"packageName":"com.example.app"}'), 'messageId' => '1'],
        ]);
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }

    #[Test]
    public function the_certificates_are_fetched_once_and_reused_across_requests(): void
    {
        $this->certs(['key-a']);

        // A new verifier per push, as the container builds one per request.
        $this->assertTrue($this->verifier(self::pool())->verify(self::push(self::token('key-a'))));
        $this->assertTrue($this->verifier(self::pool())->verify(self::push(self::token('key-a'))));

        $this->assertCount(1, $this->history);
        $this->assertSame('https://www.googleapis.com/oauth2/v3/certs', (string) $this->history[0]['request']->getUri());
    }

    #[Test]
    public function the_certificates_are_refetched_when_googles_max_age_runs_out(): void
    {
        $this->certs(['key-a'], 'public, max-age=600');
        $this->certs(['key-a'], 'public, max-age=600');

        $this->assertTrue($this->verifier(self::pool())->verify(self::push(self::token('key-a'))));

        $this->travel(601)->seconds();

        $this->assertTrue($this->verifier(self::pool())->verify(self::push(self::token('key-a'))));
        $this->assertCount(2, $this->history);
    }

    #[Test]
    public function a_token_signed_by_a_key_missing_from_the_cache_triggers_one_refetch(): void
    {
        $this->certs(['key-a']);
        $this->certs(['key-a', 'key-b']);

        $this->assertTrue($this->verifier(self::pool())->verify(self::push(self::token('key-a'))));

        // Google rotated in key-b after the set was cached.
        $this->assertTrue($this->verifier(self::pool())->verify(self::push(self::token('key-b'))));
        $this->assertCount(2, $this->history);

        // The refreshed set is cached in turn.
        $this->assertTrue($this->verifier(self::pool())->verify(self::push(self::token('key-b'))));
        $this->assertCount(2, $this->history);
    }

    #[Test]
    public function unknown_key_ids_cannot_force_a_refetch_per_request(): void
    {
        $this->certs(['key-a']);
        $this->certs(['key-a']);

        $this->assertTrue($this->verifier(self::pool())->verify(self::push(self::token('key-a'))));

        // A forged key ID gets one refetch, then nothing for a minute.
        $this->assertFalse($this->verifier(self::pool())->verify(self::push(self::token('forged-1'))));
        $this->assertFalse($this->verifier(self::pool())->verify(self::push(self::token('forged-2'))));
        $this->assertFalse($this->verifier(self::pool())->verify(self::push(self::token('forged-3'))));

        $this->assertCount(2, $this->history);
    }

    #[Test]
    public function a_token_that_fails_for_another_reason_does_not_refetch(): void
    {
        $this->certs(['key-a']);

        $this->assertTrue($this->verifier(self::pool())->verify(self::push(self::token('key-a'))));
        $this->assertFalse($this->verifier(self::pool())->verify(self::push(self::token('key-a', 'https://someone-else.example.com'))));

        $this->assertCount(1, $this->history);
    }

    #[Test]
    public function a_failed_certificate_fetch_is_not_cached(): void
    {
        $this->responses->append(new Response(503, [], 'unavailable'));
        $this->certs(['key-a']);

        $this->assertFalse($this->verifier(self::pool())->verify(self::push(self::token('key-a'))));
        $this->assertTrue($this->verifier(self::pool())->verify(self::push(self::token('key-a'))));

        $this->assertCount(2, $this->history);
    }

    #[Test]
    public function without_a_cache_the_certificates_are_fetched_for_every_push(): void
    {
        $this->certs(['key-a']);
        $this->certs(['key-a']);

        $this->assertTrue($this->verifier(null)->verify(self::push(self::token('key-a'))));
        $this->assertTrue($this->verifier(null)->verify(self::push(self::token('key-a'))));

        $this->assertCount(2, $this->history);
    }

    #[Test]
    public function the_container_hands_the_verifier_the_configured_cache(): void
    {
        $this->app['config']->set('appstore-purchases.google_play.rtdn.audience', self::AUDIENCE);

        $certificateCache = fn (GoogleOidcPushVerifier $verifier): mixed => (fn () => $this->certificateCache)->call($verifier);

        $verifier = $this->app->make(PubSubPushVerifier::class);
        $this->assertInstanceOf(GoogleOidcPushVerifier::class, $verifier);
        $this->assertInstanceOf(LaravelCacheItemPool::class, $certificateCache($verifier));

        $this->app['config']->set('appstore-purchases.google_cache.enabled', false);

        $verifier = $this->app->make(PubSubPushVerifier::class);
        $this->assertInstanceOf(GoogleOidcPushVerifier::class, $verifier);
        $this->assertNull($certificateCache($verifier));
    }
}
