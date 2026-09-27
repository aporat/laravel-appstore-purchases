<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\Logging;

use Aporat\AppStorePurchases\AppStorePurchasesServiceProvider;
use Aporat\AppStorePurchases\Logging\NotificationLogger;
use Illuminate\Support\Facades\Log;
use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

class NotificationLoggerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AppStorePurchasesServiceProvider::class];
    }

    #[Test]
    public function it_writes_to_the_default_logger_when_no_channel_is_configured(): void
    {
        Log::spy();

        $this->app->make(NotificationLogger::class)->warning('hello', ['a' => 1]);

        Log::shouldHaveReceived('warning')->once()->with('hello', ['a' => 1]);
    }

    #[Test]
    public function it_writes_to_the_configured_channel(): void
    {
        $this->app['config']->set('appstore-purchases.logging.notifications_channel', 'rtdn');

        $channel = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('rtdn')->andReturn($channel);

        $this->app->make(NotificationLogger::class)->info('hello', ['a' => 1]);

        $channel->shouldHaveReceived('info')->once()->with('hello', ['a' => 1]);
    }

    #[Test]
    public function it_reads_the_channel_on_every_write(): void
    {
        $logger = $this->app->make(NotificationLogger::class);

        Log::spy();
        $logger->error('first');
        Log::shouldHaveReceived('error')->once()->with('first', []);

        $this->app['config']->set('appstore-purchases.logging.notifications_channel', 'later');
        $channel = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('later')->andReturn($channel);

        $logger->error('second');
        $channel->shouldHaveReceived('error')->once()->with('second', []);
    }
}
