<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\AppleAppStore;

use Aporat\AppStorePurchases\AppStorePurchasesServiceProvider;
use Aporat\AppStorePurchases\Events\Test;
use Aporat\AppStorePurchases\Http\Controllers\AppleAppStoreServerNotificationController;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test as TestAttr;
use ReceiptValidator\Environment;
use RuntimeException;

class AppleAppStoreServerNotificationControllerTest extends TestCase
{
    /** Bundle ID and environment of the Apple-signed fixture notification. */
    private const FIXTURE_BUNDLE_ID = 'com.Abilities';

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('appstore-purchases.validators', [
            'apple' => $this->appleConfig(self::FIXTURE_BUNDLE_ID),
            // Entries for other stores never take part in Apple verification.
            'itunes' => [
                'validator' => 'itunes',
                'shared_secret' => 'SHARED_SECRET',
                'environment' => Environment::SANDBOX,
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->post('/apple/notifications', AppleAppStoreServerNotificationController::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function appleConfig(string $bundleId, Environment $environment = Environment::SANDBOX): array
    {
        return [
            'validator' => 'apple-app-store',
            'key_path' => __DIR__.'/certs/testSigningKey.p8',
            'key_id' => 'TESTKEY123',
            'issuer_id' => 'ISSUER123',
            'bundle_id' => $bundleId,
            'environment' => $environment,
        ];
    }

    #[TestAttr]
    public function it_accepts_a_notification_for_a_configured_bundle_id_in_any_environment(): void
    {
        // The fixture is a sandbox notification; the entry names production.
        config()->set('appstore-purchases.validators.apple', $this->appleConfig(self::FIXTURE_BUNDLE_ID, Environment::PRODUCTION));

        Event::fake([Test::class]);

        $this->postJson('/apple/notifications', $this->loadFixturePayload())->assertNoContent();

        Event::assertDispatched(Test::class, function (Test $event): bool {
            return $event->notification->getEnvironment() === Environment::SANDBOX
                && $event->notification->getBundleId() === self::FIXTURE_BUNDLE_ID;
        });
    }

    #[TestAttr]
    public function it_rejects_a_notification_for_an_unconfigured_bundle_id(): void
    {
        config()->set('appstore-purchases.validators.apple', $this->appleConfig('com.example.other'));

        Event::fake([Test::class]);
        Log::spy();

        $this->postJson('/apple/notifications', $this->loadFixturePayload())->assertUnauthorized();

        Event::assertNotDispatched(Test::class);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'no validator configured for bundle ID')
                    && ($context['bundle_id'] ?? null) === self::FIXTURE_BUNDLE_ID
                    && ($context['environment'] ?? null) === 'sandbox'
                    && ($context['notification_type'] ?? null) === 'TEST'
                    && array_key_exists('notification_uuid', $context);
            });
    }

    #[TestAttr]
    public function it_rejects_every_notification_when_no_apple_validator_is_configured(): void
    {
        config()->set('appstore-purchases.validators', [
            'itunes' => [
                'validator' => 'itunes',
                'shared_secret' => 'SHARED_SECRET',
                'environment' => Environment::SANDBOX,
            ],
        ]);

        Event::fake([Test::class]);

        $this->postJson('/apple/notifications', $this->loadFixturePayload())->assertUnauthorized();

        Event::assertNotDispatched(Test::class);
    }

    #[TestAttr]
    public function it_ignores_apple_entries_without_a_bundle_id(): void
    {
        // The unpublished default config ships an Apple entry with an empty
        // bundle ID; it must not match anything.
        config()->set('appstore-purchases.validators.apple', $this->appleConfig(''));

        Event::fake([Test::class]);

        $this->postJson('/apple/notifications', $this->loadFixturePayload())->assertUnauthorized();

        Event::assertNotDispatched(Test::class);
    }

    #[TestAttr]
    public function it_matches_the_bundle_id_across_several_apple_entries(): void
    {
        config()->set('appstore-purchases.validators', [
            'first' => $this->appleConfig('com.example.first'),
            'second' => $this->appleConfig(self::FIXTURE_BUNDLE_ID),
        ]);

        Event::fake([Test::class]);

        $this->postJson('/apple/notifications', $this->loadFixturePayload())->assertNoContent();

        Event::assertDispatched(Test::class);
    }

    #[TestAttr]
    public function it_dispatches_test_event(): void
    {
        Event::fake([Test::class]);

        $this->postJson('/apple/notifications', $this->loadFixturePayload())->assertNoContent();

        Event::assertDispatched(Test::class);
    }

    #[TestAttr]
    public function it_returns_400_when_payload_is_empty(): void
    {
        Log::spy();

        $this->postJson('/apple/notifications', [])->assertStatus(400);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'Failed to decode')
                    && array_key_exists('payload_size', $context)
                    && ! array_key_exists('request', $context);
            });
    }

    #[TestAttr]
    public function it_returns_400_when_signed_payload_is_malformed(): void
    {
        $this->postJson('/apple/notifications', ['signedPayload' => 'not-a-jws'])->assertStatus(400);
    }

    #[TestAttr]
    public function it_returns_401_when_the_signature_does_not_verify(): void
    {
        Event::fake([Test::class]);
        Log::spy();

        $payload = $this->loadFixturePayload();
        $parts = explode('.', (string) $payload['signedPayload']);
        // Flip one character of the signature segment: well-formed JWS, wrong signature.
        $parts[2][-3] = $parts[2][-3] === 'A' ? 'B' : 'A';
        $payload['signedPayload'] = implode('.', $parts);

        $this->postJson('/apple/notifications', $payload)->assertUnauthorized();

        Event::assertNotDispatched(Test::class);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'signature verification failed')
                && array_key_exists('payload_size', $context))
            ->once();
    }

    #[TestAttr]
    public function it_acknowledges_a_redelivered_notification_without_dispatching_again(): void
    {
        Event::fake([Test::class]);
        Log::spy();

        $payload = $this->loadFixturePayload();

        $this->postJson('/apple/notifications', $payload)->assertNoContent();
        $this->postJson('/apple/notifications', $payload)->assertNoContent();

        Event::assertDispatchedTimes(Test::class, 1);
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'acknowledging duplicate')
                && ($context['bundle_id'] ?? null) === self::FIXTURE_BUNDLE_ID
                && ($context['notification_type'] ?? null) === 'TEST'
                && is_string($context['notification_uuid'] ?? null))
            ->once();
    }

    #[TestAttr]
    public function it_processes_the_retry_of_a_notification_whose_listener_failed(): void
    {
        $attempts = 0;
        Event::listen(Test::class, function () use (&$attempts): void {
            if (++$attempts === 1) {
                throw new RuntimeException('listener boom');
            }
        });

        Log::spy();

        $payload = $this->loadFixturePayload();

        // Apple retries after a 500 with the same notificationUUID; the claim
        // made before the failed dispatch must have been released.
        $this->postJson('/apple/notifications', $payload)->assertStatus(500);
        $this->postJson('/apple/notifications', $payload)->assertNoContent();

        $this->assertSame(2, $attempts);
    }

    #[TestAttr]
    public function it_dispatches_every_delivery_when_replay_protection_is_disabled(): void
    {
        config()->set('appstore-purchases.replay_protection.enabled', false);

        Event::fake([Test::class]);

        $payload = $this->loadFixturePayload();

        $this->postJson('/apple/notifications', $payload)->assertNoContent();
        $this->postJson('/apple/notifications', $payload)->assertNoContent();

        Event::assertDispatchedTimes(Test::class, 2);
    }

    #[TestAttr]
    public function it_returns_500_when_event_listener_throws(): void
    {
        Event::listen(Test::class, function (): void {
            throw new RuntimeException('listener boom');
        });

        Log::spy();

        $this->postJson('/apple/notifications', $this->loadFixturePayload())->assertStatus(500);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'listener threw an exception')
                    && ($context['notification_type'] ?? null) === 'TEST'
                    && array_key_exists('notification_uuid', $context);
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixturePayload(): array
    {
        $json = file_get_contents(__DIR__.'/fixtures/test-notification-signed-payload.json');
        $this->assertNotFalse($json, 'Fixture missing or unreadable');

        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    protected function getPackageProviders($app): array
    {
        return [
            AppStorePurchasesServiceProvider::class,
        ];
    }
}
