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

    private function request(?string $bearer, ?string $packageName = null): Request
    {
        $body = $packageName === null ? [] : [
            'message' => [
                'data' => base64_encode((string) json_encode(['version' => '1.0', 'packageName' => $packageName, 'testNotification' => ['version' => '1.0']])),
                'messageId' => '1',
            ],
            'subscription' => 'projects/test/subscriptions/rtdn',
        ];

        $request = Request::create('/', 'POST', $body);

        if ($bearer !== null) {
            $request->headers->set('Authorization', 'Bearer '.$bearer);
        }

        return $request;
    }

    /**
     * A verifier that checks two apps and a default, telling them apart by
     * audience, with a fake token check that "signs" for whatever email the
     * token string names.
     */
    private function multiApp(): GoogleOidcPushVerifier
    {
        return new GoogleOidcPushVerifier(
            audience: 'https://default.example.com/cb',
            serviceAccountEmail: 'default@example.iam.gserviceaccount.com',
            apps: [
                'com.example.one' => [
                    'audience' => 'https://one.example.com/cb',
                    'service_account_email' => 'one@example.iam.gserviceaccount.com',
                ],
                'com.example.two' => [
                    'audience' => ['https://two.example.com/cb', 'https://two-alt.example.com/cb'],
                    'service_account_email' => 'two@example.iam.gserviceaccount.com',
                ],
                'com.example.open' => [
                    'audience' => null,
                ],
            ],
            // Token format: "<aud>|<email>" — verifies only against its own audience.
            verifier: function (string $token, array $options): array|false {
                [$aud, $email] = explode('|', $token);

                return $aud === $options['audience'] ? ['email' => $email] : false;
            },
        );
    }

    #[Test]
    public function it_is_bound_by_the_service_provider_from_config(): void
    {
        $this->app['config']->set('appstore-purchases.google_play.rtdn.audience', 'https://example.com/callback');
        $this->app['config']->set('appstore-purchases.google_play.rtdn.service_account_email', 'svc@example.iam.gserviceaccount.com');

        $verifier = $this->app->make(PubSubPushVerifier::class);

        $this->assertInstanceOf(GoogleOidcPushVerifier::class, $verifier);
        $this->assertSame(['https://example.com/callback'], $verifier->audiences());
        $this->assertSame(['svc@example.iam.gserviceaccount.com'], $verifier->serviceAccountEmails());
    }

    #[Test]
    public function it_accepts_lists_from_config(): void
    {
        $this->app['config']->set('appstore-purchases.google_play.rtdn.audience', ['https://one.example.com/cb', '', 'https://two.example.com/cb']);
        $this->app['config']->set('appstore-purchases.google_play.rtdn.service_account_email', ['one@example.iam.gserviceaccount.com', null, 'two@example.iam.gserviceaccount.com']);

        $verifier = $this->app->make(PubSubPushVerifier::class);

        $this->assertSame(['https://one.example.com/cb', 'https://two.example.com/cb'], $verifier->audiences());
        $this->assertSame(['one@example.iam.gserviceaccount.com', 'two@example.iam.gserviceaccount.com'], $verifier->serviceAccountEmails());
    }

    #[Test]
    public function it_builds_per_app_expectations_from_google_play_validator_entries(): void
    {
        $this->app['config']->set('appstore-purchases.google_play.rtdn.audience', 'https://default.example.com/cb');
        $this->app['config']->set('appstore-purchases.validators', [
            'apple' => ['validator' => 'apple-app-store', 'rtdn' => ['audience' => 'ignored: not a Play validator']],
            'one' => [
                'validator' => 'google-play',
                'package_name' => 'com.example.one',
                'rtdn' => ['audience' => 'https://one.example.com/cb', 'service_account_email' => 'one@example.iam.gserviceaccount.com'],
            ],
            'two' => [
                'validator' => 'play',
                'package_name' => 'com.example.two',
                'rtdn' => ['audience' => ['https://two.example.com/cb', '']],
            ],
            'no-rtdn' => ['validator' => 'google-play', 'package_name' => 'com.example.three'],
            'no-package' => ['validator' => 'google-play', 'rtdn' => ['audience' => 'x']],
        ]);

        $verifier = $this->app->make(PubSubPushVerifier::class);

        $this->assertInstanceOf(GoogleOidcPushVerifier::class, $verifier);
        $this->assertSame(['https://default.example.com/cb'], $verifier->audiences());
        $this->assertSame([
            'com.example.one' => ['audiences' => ['https://one.example.com/cb'], 'service_account_emails' => ['one@example.iam.gserviceaccount.com']],
            'com.example.two' => ['audiences' => ['https://two.example.com/cb'], 'service_account_emails' => []],
        ], $verifier->apps());
    }

    #[Test]
    public function it_checks_a_push_against_its_own_apps_expectations(): void
    {
        $verifier = $this->multiApp();

        $this->assertTrue($verifier->verify($this->request('https://one.example.com/cb|one@example.iam.gserviceaccount.com', 'com.example.one')));
        $this->assertTrue($verifier->verify($this->request('https://two.example.com/cb|two@example.iam.gserviceaccount.com', 'com.example.two')));
        $this->assertTrue($verifier->verify($this->request('https://two-alt.example.com/cb|two@example.iam.gserviceaccount.com', 'com.example.two')));
    }

    #[Test]
    public function it_rejects_a_push_signed_by_another_apps_subscription(): void
    {
        Log::spy();

        $verifier = $this->multiApp();

        // Right audience for app one, but signed by app two's account.
        $this->assertFalse($verifier->verify($this->request('https://one.example.com/cb|two@example.iam.gserviceaccount.com', 'com.example.one')));
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $m, array $c): bool => str_contains($m, 'unexpected service account') && $c['package_name'] === 'com.example.one');

        // App two's whole token, but the body claims app one: the audience doesn't match.
        $this->assertFalse($verifier->verify($this->request('https://two.example.com/cb|two@example.iam.gserviceaccount.com', 'com.example.one')));
    }

    #[Test]
    public function it_falls_back_to_the_defaults_for_a_package_without_its_own_entry(): void
    {
        $verifier = $this->multiApp();

        $this->assertTrue($verifier->verify($this->request('https://default.example.com/cb|default@example.iam.gserviceaccount.com', 'com.example.unlisted')));
        $this->assertFalse($verifier->verify($this->request('https://one.example.com/cb|one@example.iam.gserviceaccount.com', 'com.example.unlisted')));

        // No package name at all (undecodable or missing body) also uses the defaults.
        $this->assertTrue($verifier->verify($this->request('https://default.example.com/cb|default@example.iam.gserviceaccount.com')));
        $this->assertFalse($verifier->verify($this->request('https://one.example.com/cb|one@example.iam.gserviceaccount.com')));
    }

    #[Test]
    public function an_app_entry_without_an_audience_turns_verification_off_for_that_app_only(): void
    {
        $verifier = $this->multiApp();

        $this->assertTrue($verifier->verify($this->request(null, 'com.example.open')));
        $this->assertFalse($verifier->verify($this->request(null, 'com.example.one')));
        $this->assertFalse($verifier->verify($this->request(null, 'com.example.unlisted')));
    }

    #[Test]
    public function it_ignores_an_undecodable_body_when_picking_expectations(): void
    {
        $verifier = $this->multiApp();

        $request = Request::create('/', 'POST', ['message' => ['data' => 'not base64!!']]);
        $request->headers->set('Authorization', 'Bearer https://default.example.com/cb|default@example.iam.gserviceaccount.com');
        $this->assertTrue($verifier->verify($request));

        $request = Request::create('/', 'POST', ['message' => ['data' => base64_encode('{"packageName":""}')]]);
        $request->headers->set('Authorization', 'Bearer https://default.example.com/cb|default@example.iam.gserviceaccount.com');
        $this->assertTrue($verifier->verify($request));
    }

    #[Test]
    public function it_treats_empty_config_values_as_unset(): void
    {
        $this->app['config']->set('appstore-purchases.google_play.rtdn.audience', '');
        $this->app['config']->set('appstore-purchases.google_play.rtdn.service_account_email', '');

        $verifier = $this->app->make(PubSubPushVerifier::class);

        $this->assertSame([], $verifier->audiences());
        $this->assertSame([], $verifier->serviceAccountEmails());
        $this->assertTrue($verifier->verify($this->request(null)));
    }

    #[Test]
    public function it_treats_an_empty_list_as_unset(): void
    {
        $verifier = new GoogleOidcPushVerifier(audience: ['', null]);

        $this->assertSame([], $verifier->audiences());
        $this->assertTrue($verifier->verify($this->request(null)));
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
    public function it_tries_each_audience_until_one_verifies(): void
    {
        $tried = [];

        $verifier = new GoogleOidcPushVerifier(
            audience: ['https://one.example.com/cb', 'https://two.example.com/cb'],
            verifier: function (string $token, array $options) use (&$tried): array|false {
                $tried[] = $options['audience'];

                return $options['audience'] === 'https://two.example.com/cb'
                    ? ['email' => 'svc@example.iam.gserviceaccount.com']
                    : false;
            },
        );

        $this->assertTrue($verifier->verify($this->request('token')));
        $this->assertSame(['https://one.example.com/cb', 'https://two.example.com/cb'], $tried);
    }

    #[Test]
    public function it_rejects_when_no_audience_verifies(): void
    {
        Log::spy();

        $verifier = new GoogleOidcPushVerifier(
            audience: ['https://one.example.com/cb', 'https://two.example.com/cb'],
            verifier: fn () => false,
        );

        $this->assertFalse($verifier->verify($this->request('token')));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m): bool => str_contains($m, 'invalid bearer token'));
    }

    #[Test]
    public function it_accepts_any_listed_service_account(): void
    {
        Log::spy();

        $make = fn (string $email) => new GoogleOidcPushVerifier(
            audience: 'aud',
            serviceAccountEmail: ['one@example.iam.gserviceaccount.com', 'two@example.iam.gserviceaccount.com'],
            verifier: fn () => ['email' => $email],
        );

        $this->assertTrue($make('one@example.iam.gserviceaccount.com')->verify($this->request('token')));
        $this->assertTrue($make('two@example.iam.gserviceaccount.com')->verify($this->request('token')));
        $this->assertFalse($make('three@example.iam.gserviceaccount.com')->verify($this->request('token')));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m): bool => str_contains($m, 'unexpected service account'));
    }

    #[Test]
    public function it_rejects_when_verification_returns_false(): void
    {
        Log::spy();

        $verifier = new GoogleOidcPushVerifier(audience: 'aud', verifier: fn () => false);

        $this->assertFalse($verifier->verify($this->request('token')));
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $m, array $c): bool => str_contains($m, 'invalid bearer token')
                && $c['audiences_tried'] === ['aud']
                && $c['token_claims'] === ['malformed' => true, 'segments' => 1]);
    }

    #[Test]
    public function it_logs_the_tokens_unverified_claims_when_rejecting_it(): void
    {
        Log::spy();

        $exp = 1_700_000_000;
        $claims = [
            'iss' => 'https://accounts.google.com',
            'aud' => 'https://other.example.com/cb',
            'email' => 'svc@example.iam.gserviceaccount.com',
            'email_verified' => true,
            'sub' => '123',
            'iat' => $exp - 3600,
            'exp' => $exp,
            'at_hash' => 'not-logged',
        ];
        $token = 'eyJhbGciOiJSUzI1NiJ9.'.rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=').'.sig';

        $verifier = new GoogleOidcPushVerifier(audience: 'https://one.example.com/cb', verifier: fn () => false);

        $this->assertFalse($verifier->verify($this->request($token)));
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $m, array $c): bool {
                $claims = $c['token_claims'];

                return str_contains($m, 'invalid bearer token')
                    && $claims['aud'] === 'https://other.example.com/cb'
                    && $claims['email'] === 'svc@example.iam.gserviceaccount.com'
                    && $claims['exp'] === '2023-11-14T22:13:20+00:00'
                    && $claims['expired'] === true
                    && ! array_key_exists('at_hash', $claims);
            });
    }

    #[Test]
    public function it_logs_a_debug_line_when_a_push_verifies(): void
    {
        Log::spy();

        $verifier = new GoogleOidcPushVerifier(
            audience: 'https://one.example.com/cb',
            verifier: fn () => ['aud' => 'https://one.example.com/cb', 'email' => 'svc@example.iam.gserviceaccount.com'],
        );

        $this->assertTrue($verifier->verify($this->request('token', 'com.example.one')));
        Log::shouldHaveReceived('debug')
            ->once()
            ->withArgs(fn (string $m, array $c): bool => str_contains($m, 'push verified')
                && $c['package_name'] === 'com.example.one'
                && $c['email'] === 'svc@example.iam.gserviceaccount.com');
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
