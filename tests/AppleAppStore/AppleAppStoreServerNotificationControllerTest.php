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
use RuntimeException;

class AppleAppStoreServerNotificationControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->post('/apple/notifications', AppleAppStoreServerNotificationController::class);
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
