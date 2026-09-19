<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\GooglePlay;

use Aporat\AppStorePurchases\AppStorePurchasesServiceProvider;
use Aporat\AppStorePurchases\Contracts\PubSubPushVerifier;
use Aporat\AppStorePurchases\GooglePlay\GoogleOidcPushVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class GoogleOidcPushVerifierTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AppStorePurchasesServiceProvider::class];
    }

    private function request(?string $bearer): Request
    {
        $request = Request::create('/', 'POST');

        if ($bearer !== null) {
            $request->headers->set('Authorization', 'Bearer '.$bearer);
        }

        return $request;
    }

    #[Test]
    public function it_is_bound_by_the_service_provider_from_config(): void
    {
        $this->app['config']->set('appstore-purchases.google_play.rtdn.audience', 'https://example.com/callback');
        $this->app['config']->set('appstore-purchases.google_play.rtdn.service_account_email', 'svc@example.iam.gserviceaccount.com');

        $verifier = $this->app->make(PubSubPushVerifier::class);

        $this->assertInstanceOf(GoogleOidcPushVerifier::class, $verifier);
        $this->assertSame('https://example.com/callback', (new \ReflectionProperty($verifier, 'audience'))->getValue($verifier));
        $this->assertSame('svc@example.iam.gserviceaccount.com', (new \ReflectionProperty($verifier, 'serviceAccountEmail'))->getValue($verifier));
    }

    #[Test]
    public function it_treats_empty_config_values_as_unset(): void
    {
        $this->app['config']->set('appstore-purchases.google_play.rtdn.audience', '');
        $this->app['config']->set('appstore-purchases.google_play.rtdn.service_account_email', '');

        $verifier = $this->app->make(PubSubPushVerifier::class);

        $this->assertNull((new \ReflectionProperty($verifier, 'audience'))->getValue($verifier));
        $this->assertNull((new \ReflectionProperty($verifier, 'serviceAccountEmail'))->getValue($verifier));
    }

    #[Test]
    public function it_accepts_everything_when_no_audience_is_configured(): void
    {
        $verifier = new GoogleOidcPushVerifier(audience: null);

        $this->assertTrue($verifier->verify($this->request(null)));
    }

    #[Test]
    public function it_rejects_a_missing_bearer_token(): void
    {
        Log::spy();

        $verifier = new GoogleOidcPushVerifier(audience: 'https://example.com/callback', verifier: fn () => ['email' => 'x']);

        $this->assertFalse($verifier->verify($this->request(null)));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m): bool => str_contains($m, 'missing bearer token'));
    }

    #[Test]
    public function it_passes_audience_and_issuer_to_the_verifier(): void
    {
        $captured = null;

        $verifier = new GoogleOidcPushVerifier(
            audience: 'https://example.com/callback',
            verifier: function (string $token, array $options) use (&$captured): array {
                $captured = [$token, $options];

                return ['email' => 'svc@example.iam.gserviceaccount.com'];
            },
        );

        $this->assertTrue($verifier->verify($this->request('abc.def.ghi')));
        $this->assertSame(
            ['abc.def.ghi', ['audience' => 'https://example.com/callback', 'issuer' => GoogleOidcPushVerifier::ISSUER]],
            $captured
        );
    }

    #[Test]
    public function it_rejects_when_verification_returns_false(): void
    {
        Log::spy();

        $verifier = new GoogleOidcPushVerifier(audience: 'aud', verifier: fn () => false);

        $this->assertFalse($verifier->verify($this->request('token')));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m): bool => str_contains($m, 'invalid bearer token'));
    }

    #[Test]
    public function it_rejects_when_verification_throws(): void
    {
        Log::spy();

        $verifier = new GoogleOidcPushVerifier(audience: 'aud', verifier: function (): array {
            throw new RuntimeException('bad signature');
        });

        $this->assertFalse($verifier->verify($this->request('token')));
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $m, array $c): bool => str_contains($m, 'verification threw') && $c['error'] === 'bad signature');
    }

    #[Test]
    public function it_checks_the_service_account_email_when_configured(): void
    {
        Log::spy();

        $verifier = new GoogleOidcPushVerifier(
            audience: 'aud',
            serviceAccountEmail: 'expected@example.iam.gserviceaccount.com',
            verifier: fn () => ['email' => 'other@example.iam.gserviceaccount.com'],
        );

        $this->assertFalse($verifier->verify($this->request('token')));
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $m, array $c): bool => str_contains($m, 'unexpected service account')
                && $c['email'] === 'other@example.iam.gserviceaccount.com');

        $matching = new GoogleOidcPushVerifier(
            audience: 'aud',
            serviceAccountEmail: 'expected@example.iam.gserviceaccount.com',
            verifier: fn () => ['email' => 'expected@example.iam.gserviceaccount.com'],
        );

        $this->assertTrue($matching->verify($this->request('token')));
    }

    #[Test]
    public function it_raises_rather_than_rejects_when_google_auth_is_absent(): void
    {
        if (class_exists('Google\Auth\AccessToken')) {
            $this->markTestSkipped('google/auth is installed; the fallback path is exercised in real apps.');
        }

        $verifier = new GoogleOidcPushVerifier(audience: 'aud');

        // A missing dependency must not look like a forged push: 401ing here
        // would silently drop every notification for seven days of retries.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires the google/auth package');

        $verifier->verify($this->request('token'));
    }
}
