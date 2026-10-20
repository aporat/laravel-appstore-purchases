<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\AppleAppStore;

use Aporat\AppStorePurchases\AppStorePurchasesManager;
use Aporat\AppStorePurchases\AppStorePurchasesServiceProvider;
use Aporat\AppStorePurchases\Events\PurchaseEvent;
use Aporat\AppStorePurchases\Events\SubscriptionCreated;
use Aporat\AppStorePurchases\Events\UnhandledNotification;
use Aporat\AppStorePurchases\Http\Controllers\AppleAppStoreServerNotificationController;
use Aporat\AppStorePurchases\Logging\NotificationLogger;
use Aporat\AppStorePurchases\Support\NotificationReplayGuard;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test as TestAttr;
use ReceiptValidator\AppleAppStore\JWT\TokenVerifier;
use ReceiptValidator\AppleAppStore\Validator as AppleAppStoreValidator;
use ReceiptValidator\Environment;

/**
 * Correctly signed notifications of types this package has no event for, or
 * the installed receipt validator doesn't know, are acknowledged rather than
 * bounced back to Apple, which would retry them; a bad signature still is not.
 *
 * Signed with the test chain in certs/storekit (copied from
 * aporat/store-receipt-validator), which the controller and validators trust
 * here in place of Apple's.
 */
class AppleAppStoreNotificationTypesTest extends TestCase
{
    private const string CERTS = __DIR__.'/certs/storekit';

    private const string BUNDLE_ID = 'com.example.app';

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('appstore-purchases.validators', [
            'apple' => [
                'validator' => 'apple-app-store',
                'private_key' => __DIR__.'/certs/testSigningKey.p8',
                'key_id' => 'TESTKEY123',
                'issuer_id' => 'ISSUER123',
                'bundle_id' => self::BUNDLE_ID,
                'environment' => Environment::SANDBOX,
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->post('/apple/notifications', AppleAppStoreServerNotificationController::class);
    }

    private function trustTestChain(): void
    {
        $verifier = new TokenVerifier([self::fingerprint('intermediate.pem'), self::fingerprint('root.pem')]);

        $this->app->bind(AppleAppStoreServerNotificationController::class, fn ($app) => new AppleAppStoreServerNotificationController(
            $app->make(AppStorePurchasesManager::class),
            $app->make(NotificationLogger::class),
            $app->make(NotificationReplayGuard::class),
            $verifier,
        ));

        foreach ([Environment::SANDBOX, Environment::PRODUCTION] as $environment) {
            $validator = $this->app->make(AppStorePurchasesManager::class)->get('apple', $environment);
            $this->assertInstanceOf(AppleAppStoreValidator::class, $validator);
            $validator->setTokenVerifier($verifier);
        }
    }

    #[TestAttr]
    public function a_known_type_still_dispatches_its_event(): void
    {
        $this->trustTestChain();
        Event::fake();

        $this->postJson('/apple/notifications', ['signedPayload' => self::sign(self::claims('SUBSCRIBED', 'INITIAL_BUY'))])->assertNoContent();

        Event::assertDispatched(SubscriptionCreated::class);
        Event::assertNotDispatched(UnhandledNotification::class);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    #[TestAttr]
    #[DataProvider('unmappedNotifications')]
    public function a_signed_notification_without_a_dedicated_event_is_acknowledged(array $claims): void
    {
        $this->trustTestChain();
        Event::fake();

        $this->postJson('/apple/notifications', ['signedPayload' => self::sign($claims)])->assertNoContent();

        $this->assertNoDedicatedEventDispatched();

        // Parsed, verified against the configured app, and dispatched generically.
        Event::assertDispatched(UnhandledNotification::class, fn (UnhandledNotification $event): bool => $event->notification->getRawNotificationType() === $claims['notificationType']
            && $event->notification->getBundleId() === self::BUNDLE_ID);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unmappedNotifications(): iterable
    {
        yield 'a type Apple adds later' => [self::claims('SOMETHING_APPLE_ADDS_LATER', 'AND_A_SUBTYPE')];
        yield 'METADATA_UPDATE' => [self::claims('METADATA_UPDATE')];
        yield 'MIGRATION' => [self::claims('MIGRATION')];
        yield 'PRICE_CHANGE' => [self::claims('PRICE_CHANGE')];
        yield 'RESCIND_CONSENT (appData)' => [[
            'notificationType' => 'RESCIND_CONSENT',
            'notificationUUID' => 'b0f1c2d3-0000-4000-8000-00000000000a',
            'signedDate' => time() * 1000,
            'version' => '2.0',
            'appData' => ['bundleId' => self::BUNDLE_ID, 'environment' => 'Sandbox'],
        ]];
    }

    #[TestAttr]
    public function an_unknown_subtype_on_a_known_type_dispatches_the_known_event(): void
    {
        $this->trustTestChain();
        Event::fake();

        $this->postJson('/apple/notifications', ['signedPayload' => self::sign(self::claims('SUBSCRIBED', 'A_NEW_SUBTYPE'))])->assertNoContent();

        Event::assertDispatched(SubscriptionCreated::class, fn (SubscriptionCreated $event): bool => $event->notification->getSubtype() === null);
    }

    #[TestAttr]
    public function an_unknown_type_signed_by_an_untrusted_chain_is_rejected(): void
    {
        // The test chain is not trusted here, so the signature check fails
        // before the type is ever looked at.
        Event::fake();

        $response = $this->postJson('/apple/notifications', ['signedPayload' => self::sign(self::claims('SOMETHING_APPLE_ADDS_LATER'))]);

        // An untrusted chain is reported as 400 rather than 401 (the library's
        // message doesn't say "signature"); either way Apple is not answered 2xx.
        // Checked by range rather than assertClientError(), which Laravel 12's
        // earliest releases don't have.
        $status = $response->getStatusCode();
        self::assertTrue($status >= 400 && $status < 500, "Expected a 4xx response, got {$status}.");

        $this->assertNothingFromThePackageDispatched();
    }

    #[TestAttr]
    public function an_unknown_type_with_a_tampered_signature_is_rejected(): void
    {
        $this->trustTestChain();
        Event::fake();

        $parts = explode('.', self::sign(self::claims('SOMETHING_APPLE_ADDS_LATER')));
        $parts[2][-3] = $parts[2][-3] === 'A' ? 'B' : 'A';

        $this->postJson('/apple/notifications', ['signedPayload' => implode('.', $parts)])->assertUnauthorized();

        $this->assertNothingFromThePackageDispatched();
    }

    #[TestAttr]
    public function an_unknown_type_for_an_unconfigured_app_is_not_dispatched(): void
    {
        $this->trustTestChain();
        Event::fake();

        $claims = self::claims('SOMETHING_APPLE_ADDS_LATER');
        $claims['data']['bundleId'] = 'com.someone.else';

        $response = $this->postJson('/apple/notifications', ['signedPayload' => self::sign($claims)]);

        // Verified like any other notification: the bundle ID matches no
        // configured app, so it is rejected and nothing runs.
        $response->assertUnauthorized();
        $this->assertNothingFromThePackageDispatched();
    }

    private function assertNothingFromThePackageDispatched(): void
    {
        $this->assertNoDedicatedEventDispatched();
        Event::assertNotDispatched(UnhandledNotification::class);
    }

    private function assertNoDedicatedEventDispatched(): void
    {
        foreach ((array) glob(__DIR__.'/../../src/Events/*.php') as $file) {
            $class = 'Aporat\\AppStorePurchases\\Events\\'.basename((string) $file, '.php');
            if (in_array($class, [PurchaseEvent::class, UnhandledNotification::class], true)) {
                continue;
            }

            Event::assertNotDispatched($class);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function claims(string $type, ?string $subtype = null): array
    {
        return array_filter([
            'notificationType' => $type,
            'subtype' => $subtype,
            'notificationUUID' => 'b0f1c2d3-0000-4000-8000-'.str_pad(substr(md5($type.$subtype), 0, 12), 12, '0'),
            'signedDate' => time() * 1000,
            'version' => '2.0',
            'data' => ['bundleId' => self::BUNDLE_ID, 'environment' => 'Sandbox'],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private static function sign(array $claims): string
    {
        $builder = (new Builder(new JoseEncoder, ChainedFormatter::default()))
            ->withHeader('x5c', [self::der('leaf.pem'), self::der('intermediate.pem'), self::der('root.pem')]);

        foreach ($claims as $name => $value) {
            $builder = $builder->withClaim($name, $value);
        }

        return $builder->getToken(new Sha256, InMemory::file(self::CERTS.'/leaf.p8'))->toString();
    }

    private static function der(string $file): string
    {
        return (string) preg_replace('/-----[^-]+-----|\s+/', '', (string) file_get_contents(self::CERTS.'/'.$file));
    }

    private static function fingerprint(string $file): string
    {
        return (string) openssl_x509_fingerprint((string) file_get_contents(self::CERTS.'/'.$file));
    }

    protected function getPackageProviders($app): array
    {
        return [AppStorePurchasesServiceProvider::class];
    }
}
