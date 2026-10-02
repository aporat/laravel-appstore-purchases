<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\Support;

use Aporat\AppStorePurchases\AppStorePurchasesServiceProvider;
use Aporat\AppStorePurchases\Support\NotificationReplayGuard;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class NotificationReplayGuardTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AppStorePurchasesServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.secondary', ['driver' => 'array']);
    }

    private function guard(): NotificationReplayGuard
    {
        return $this->app->make(NotificationReplayGuard::class);
    }

    #[Test]
    public function it_is_a_singleton_enabled_by_default(): void
    {
        $this->assertSame($this->guard(), $this->guard());
        $this->assertTrue($this->guard()->enabled());
    }

    #[Test]
    public function it_claims_an_id_once_and_reports_a_repeat_as_a_replay(): void
    {
        $guard = $this->guard();

        $this->assertTrue($guard->claim('apple', 'uuid-1'));
        $this->assertFalse($guard->claim('apple', 'uuid-1'));
        $this->assertTrue($guard->claim('apple', 'uuid-2'));
    }

    #[Test]
    public function it_keeps_sources_apart(): void
    {
        $guard = $this->guard();

        $this->assertTrue($guard->claim('apple', 'same-id'));
        $this->assertTrue($guard->claim('google-play', 'same-id'));
    }

    #[Test]
    public function it_processes_a_released_id_again(): void
    {
        $guard = $this->guard();

        $this->assertTrue($guard->claim('apple', 'uuid-1'));
        $guard->release('apple', 'uuid-1');
        $this->assertTrue($guard->claim('apple', 'uuid-1'));
    }

    #[Test]
    public function it_accepts_every_delivery_when_disabled(): void
    {
        config()->set('appstore-purchases.replay_protection.enabled', false);

        $guard = $this->guard();

        $this->assertFalse($guard->enabled());
        $this->assertTrue($guard->claim('apple', 'uuid-1'));
        $this->assertTrue($guard->claim('apple', 'uuid-1'));
        $this->assertSame([], Cache::store('array')->get('__never__', []));
    }

    #[Test]
    public function it_cannot_deduplicate_an_empty_id(): void
    {
        $guard = $this->guard();

        $this->assertTrue($guard->claim('apple', ''));
        $this->assertTrue($guard->claim('apple', ''));
    }

    #[Test]
    public function it_records_ids_in_the_configured_store_with_the_configured_ttl(): void
    {
        config()->set('appstore-purchases.replay_protection.store', 'secondary');
        config()->set('appstore-purchases.replay_protection.ttl', 60);

        $this->assertTrue($this->guard()->claim('google-play', 'msg-1'));

        $this->assertTrue(Cache::store('secondary')->has('appstore-purchases:notification:google-play:msg-1'));
        $this->assertFalse(Cache::store('array')->has('appstore-purchases:notification:google-play:msg-1'));

        $this->travel(61)->seconds();

        $this->assertTrue($this->guard()->claim('google-play', 'msg-1'), 'The record expires with the TTL');
    }

    #[Test]
    public function it_falls_back_to_a_seven_day_ttl_for_an_invalid_value(): void
    {
        config()->set('appstore-purchases.replay_protection.ttl', 'soon');

        $this->assertTrue($this->guard()->claim('apple', 'uuid-1'));

        $this->travel(6)->days();
        $this->assertFalse($this->guard()->claim('apple', 'uuid-1'));

        $this->travel(2)->days();
        $this->assertTrue($this->guard()->claim('apple', 'uuid-1'));
    }
}
