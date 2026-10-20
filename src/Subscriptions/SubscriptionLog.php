<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Where the subscription layer writes: the channel named by
 * `appstore-purchases.subscriptions.log_channel`, or the application's
 * default. Info for the routine facts (an event handled, a label moved),
 * warning for client input that was refused, error for a store call that
 * failed; the exception rides along in the context as `exception`.
 */
final class SubscriptionLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::logger()->info($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $message, array $context = [], ?Throwable $exception = null): void
    {
        self::logger()->warning($message, self::withException($context, $exception));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $message, array $context = [], ?Throwable $exception = null): void
    {
        self::logger()->error($message, self::withException($context, $exception));
    }

    private static function logger(): LoggerInterface
    {
        $channel = config('appstore-purchases.subscriptions.log_channel');

        return Log::channel(is_string($channel) && $channel !== '' ? $channel : null);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function withException(array $context, ?Throwable $exception): array
    {
        return $exception === null ? $context : $context + ['exception' => $exception];
    }
}
