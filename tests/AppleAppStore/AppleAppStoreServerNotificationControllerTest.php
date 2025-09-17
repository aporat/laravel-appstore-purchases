<?php

namespace Aporat\AppStorePurchases\Tests\AppleAppStore;

use Aporat\AppStorePurchases\AppStorePurchasesServiceProvider;
use Aporat\AppStorePurchases\Events\Test;
use Aporat\AppStorePurchases\Http\Controllers\AppleAppStoreServerNotificationController;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

class AppleAppStoreServerNotificationControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->post('/apple/notifications', AppleAppStoreServerNotificationController::class);

        Event::fake([Test::class]);
    }

    public function test_dispatches_test_event(): void
    {
        $json = file_get_contents(__DIR__.'/fixtures/test-notification-signed-payload.json');
        $this->assertNotFalse($json, 'Fixture missing or unreadable');

        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->postJson('/apple/notifications', $payload)->assertNoContent();

        Event::assertDispatched(Test::class);
    }

    protected function getPackageProviders($app): array
    {
        return [
            AppStorePurchasesServiceProvider::class,
        ];
    }
}
