<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures;

use Carbon\CarbonInterface;
use Closure;
use Mockery;
use Mockery\MockInterface;
use ReceiptValidator\AppleAppStore\LastTransactionItem;
use ReceiptValidator\AppleAppStore\ServerNotification as AppleServerNotification;
use ReceiptValidator\AppleAppStore\ServerNotificationSubtype;
use ReceiptValidator\AppleAppStore\ServerNotificationType;
use ReceiptValidator\AppleAppStore\SubscriptionGroupStatusItem;
use ReceiptValidator\AppleAppStore\SubscriptionStatus;
use ReceiptValidator\AppleAppStore\SubscriptionStatusResponse;
use ReceiptValidator\AppleAppStore\Transaction;
use ReceiptValidator\Environment;
use ReceiptValidator\GooglePlay\ServerNotification as PlayServerNotification;
use ReceiptValidator\GooglePlay\SubscriptionPurchase;
use ReflectionClass;

/**
 * Store payloads for the tests. Apple's response objects verify their JWS
 * against Apple's root on construction, so they are assembled directly
 * around a Transaction; Play's are plain arrays.
 */
final class StoreFixtures
{
    public const string APPLE_ORIGINAL_ID = '2000000000000001';

    public const string PLAY_TOKEN = 'play-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function transaction(CarbonInterface $expiresAt, array $overrides = []): Transaction
    {
        return new Transaction($overrides + [
            'appAccountToken' => null,
            'inAppOwnershipType' => 'PURCHASED',
            'transactionId' => '2000000000000042',
            'originalTransactionId' => self::APPLE_ORIGINAL_ID,
            'bundleId' => 'com.example.app',
            'productId' => 'com.example.plan.monthly',
            'environment' => 'Production',
            // Whole seconds, as Apple sends them: the rows store seconds, and
            // a sub-second difference would read as a (no-op) extension.
            'originalPurchaseDate' => now()->subDays(10)->getTimestamp() * 1000,
            'expiresDate' => $expiresAt->getTimestamp() * 1000,
        ]);
    }

    public static function statusResponse(Transaction ...$transactions): SubscriptionStatusResponse
    {
        $items = [];

        foreach ($transactions as $transaction) {
            $items[] = self::hydrate(LastTransactionItem::class, [
                'originalTransactionId' => (string) $transaction->getOriginalTransactionId(),
                'status' => SubscriptionStatus::Active,
                'transactionInfo' => $transaction,
                'renewalInfo' => null,
            ]);
        }

        $group = self::hydrate(SubscriptionGroupStatusItem::class, [
            'subscriptionGroupIdentifier' => '20000001',
            'lastTransactions' => $items,
        ]);

        return self::hydrate(SubscriptionStatusResponse::class, [
            'environment' => Environment::PRODUCTION,
            'bundleId' => 'com.example.app',
            'appAppleId' => null,
            'data' => [$group],
        ]);
    }

    /**
     * An App Store server notification carrying $transaction. The real class
     * needs Apple's signature, so the listeners get a double.
     */
    public static function appleNotification(?Transaction $transaction, ServerNotificationType $type = ServerNotificationType::DID_RENEW): AppleServerNotification&MockInterface
    {
        /** @var AppleServerNotification&MockInterface $notification */
        $notification = Mockery::mock(AppleServerNotification::class);
        $notification->shouldReceive('getTransaction')->andReturn($transaction);
        $notification->shouldReceive('getNotificationType')->andReturn($type);
        $notification->shouldReceive('getSubtype')->andReturn(null);
        $notification->shouldReceive('getNotificationUUID')->andReturn('uuid-1');

        return $notification;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function playPurchase(CarbonInterface $expiresAt, array $overrides = []): SubscriptionPurchase
    {
        return new SubscriptionPurchase(array_replace_recursive([
            'kind' => 'androidpublisher#subscriptionPurchaseV2',
            'latestOrderId' => 'GPA.0000-0001',
            'startTime' => now()->subDay()->toRfc3339String(),
            'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
            'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
            'externalAccountIdentifiers' => [],
            'lineItems' => [[
                'productId' => 'com.example.android.subscription',
                'expiryTime' => $expiresAt->toRfc3339String(),
                'offerDetails' => ['basePlanId' => 'monthly'],
            ]],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function playNotification(array $payload, string $packageName = 'com.example.android'): PlayServerNotification
    {
        return new PlayServerNotification([
            'version' => '1.0',
            'packageName' => $packageName,
            'eventTimeMillis' => (string) now()->getTimestampMs(),
        ] + $payload);
    }

    public static function playSubscriptionNotification(int $type, string $token = self::PLAY_TOKEN): PlayServerNotification
    {
        return self::playNotification(['subscriptionNotification' => [
            'version' => '1.0',
            'notificationType' => $type,
            'purchaseToken' => $token,
            'subscriptionId' => 'com.example.android.subscription',
        ]]);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $properties
     * @return T
     */
    public static function hydrate(string $class, array $properties): object
    {
        $object = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        Closure::bind(function () use ($properties): void {
            foreach ($properties as $name => $value) {
                $this->{$name} = $value;
            }
        }, $object, $class)();

        return $object;
    }

    public static function subtype(?string $value): ?ServerNotificationSubtype
    {
        return $value === null ? null : ServerNotificationSubtype::tryFrom($value);
    }
}
