<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Logging;

use Illuminate\Contracts\Container\Container;
use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * The logger the notification endpoints and the Pub/Sub push verifier write to.
 *
 * Writes go to the channel named by 'appstore-purchases.logging.notifications_channel',
 * or to the application's default logger when none is set. The channel is
 * looked up on every write rather than at construction, so a test that swaps
 * the log manager after boot (Log::spy()) still sees the entries, and a
 * config change takes effect without rebinding.
 *
 * Each level method forwards to the same-named method on the channel, rather
 * than funnelling through log(), so a spy on the manager sees warning() as a
 * warning() call.
 */
final class NotificationLogger implements LoggerInterface
{
    public function __construct(
        private readonly Container $app,
    ) {}

    /** @param array<string, mixed> $context */
    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->channel()->emergency($message, $context);
    }

    /** @param array<string, mixed> $context */
    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->channel()->alert($message, $context);
    }

    /** @param array<string, mixed> $context */
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->channel()->critical($message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->channel()->error($message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->channel()->warning($message, $context);
    }

    /** @param array<string, mixed> $context */
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->channel()->notice($message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->channel()->info($message, $context);
    }

    /** @param array<string, mixed> $context */
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->channel()->debug($message, $context);
    }

    /**
     * @param  mixed  $level
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->channel()->log($level, $message, $context);
    }

    private function channel(): LoggerInterface
    {
        /** @var LogManager $manager */
        $manager = $this->app->make('log');

        $channel = $this->app->make('config')->get('appstore-purchases.logging.notifications_channel');

        if (is_string($channel) && $channel !== '') {
            return $manager->channel($channel);
        }

        return $manager;
    }
}
