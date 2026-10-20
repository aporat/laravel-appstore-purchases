<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions\Console;

use Aporat\AppStorePurchases\Subscriptions\Contracts\StoreSubscriber;
use Aporat\AppStorePurchases\Subscriptions\StoreSubscription;
use Aporat\AppStorePurchases\Subscriptions\SubscriptionLog;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Re-verify subscriptions nearing expiry against the store that sold them.
 * An app's `verify-subscriptions` command is a thin subclass naming its
 * Subscription model, and optionally a label and a config block of its own
 * (the defaults are `appstore-purchases.subscriptions.verification`).
 *
 * Both stores push webhooks (Apple's server notifications and Play's RTDN),
 * so this is a safety net rather than the primary path: it catches renewals
 * whose notification was dropped, never delivered, or arrived while the API
 * was down. That's why the window is narrow: a subscription is only examined
 * once it is close to lapsing, and given up on once it has lapsed for long.
 *
 * Both stores are re-read from the row's `store_subscription_id`: Play
 * rows keep the purchase token there, App Store rows Apple's original
 * transaction id. The Play path is authoritative and can shorten an expiry,
 * matching the webhook. Rows are never deleted on a store error; the only
 * deletion is a row whose account is gone.
 */
abstract class VerifyStoreSubscriptions extends Command
{
    public const string DEFAULTS_KEY = 'appstore-purchases.subscriptions.verification';

    /** Human label for output and logs; the Subscription model's short name by default. */
    protected function label(): string
    {
        return class_basename($this->subscriptionModel());
    }

    /**
     * The config block with the window settings. Its keys fall back to
     * `appstore-purchases.subscriptions.verification`, so an app only names
     * one when it wants different windows from the package defaults.
     */
    protected function configKey(): string
    {
        return self::DEFAULTS_KEY;
    }

    /**
     * The module's Subscription model.
     *
     * @return class-string<StoreSubscription>
     */
    abstract protected function subscriptionModel(): string;

    /**
     * Narrow the rows picked for a run. An app that serves several products
     * from one table gates on the account here, in the query, so rows it
     * would never verify don't take up the batch.
     *
     * @param  Builder<StoreSubscription>  $query
     */
    protected function scopeSubscriptionsToVerify(Builder $query): void {}

    /**
     * Whether this row is worth a store round-trip, for checks the query
     * can't express. A skipped row is still stamped as checked.
     */
    protected function shouldVerify(StoreSubscription $subscription, Model&StoreSubscriber $account): bool
    {
        return true;
    }

    public function handle(): int
    {
        try {
            $config = $this->settings();

            $subscriptions = $this->findSubscriptionsToVerify($config);

            $this->info("Found {$subscriptions->count()} {$this->label()} subscriptions to verify.");

            foreach ($subscriptions as $subscription) {
                // Space out the outbound store calls so a large batch doesn't
                // burst against Apple's or Google's rate limits.
                usleep(random_int($config['min_sleep_microseconds'], $config['max_sleep_microseconds']));

                $this->verify($subscription);
            }

            $this->info("{$this->label()} subscription verification complete.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            SubscriptionLog::error("Scheduled command {$this->getName()} failed", [], $e);
            $this->error("Command failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Rows worth a store round-trip: close enough to expiry to matter, not checked recently, and not so long lapsed that
     * they're never coming back.
     *
     * @param  array<string, int>  $config
     * @return Collection<int, StoreSubscription>
     */
    private function findSubscriptionsToVerify(array $config): Collection
    {
        return $this->subscriptionModel()::query()
            ->with('account')
            ->whereNotNull('store_subscription_id')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->addDays($config['check_within_days']))
            // Stop re-checking subscriptions that lapsed long ago; a returning
            // user posts a fresh credential from the app anyway.
            ->where('expires_at', '>', now()->subDays($config['abandon_after_days']))
            // A never-checked row has a null last_checked_at, which a plain
            // `<` comparison would silently exclude.
            ->where(fn (Builder $query) => $query
                ->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<', now()->subHours($config['recheck_after_hours'])))
            ->tap(fn (Builder $query) => $this->scopeSubscriptionsToVerify($query))
            ->orderByRaw('last_checked_at IS NULL DESC')
            ->orderBy('last_checked_at')
            ->take($config['batch_limit'])
            ->get();
    }

    private function verify(StoreSubscription $subscription): void
    {
        $account = $subscription->account;

        if ($account === null) {
            $this->warn("Subscription {$subscription->id} has no account and is being deleted.");
            $subscription->delete();

            return;
        }

        $logPrefix = "Account {$this->label()}/{$account->getKey()}:";

        if (! $this->shouldVerify($subscription, $account)) {
            $this->line("{$logPrefix} subscription {$subscription->id} skipped.");
            $this->stampChecked($subscription);

            return;
        }

        $token = (string) $subscription->store_subscription_id;

        try {
            $updated = $subscription->store === StoreSubscription::STORE_PLAY
                ? $account->syncPlaySubscription($token, 'worker')
                : $account->verifyAppStoreSubscription($token, $subscription->environment, 'worker');

            if ($updated) {
                $this->info("{$logPrefix} {$subscription->store} subscription now ends {$updated->toIso8601String()}.");

                return;
            }

            $this->line("{$logPrefix} {$subscription->store} subscription unchanged.");

            // Also covers a store that had nothing for the row, where nothing
            // was written; a row the store answered for is already stamped.
            $this->stampChecked($subscription);
        } catch (Throwable $e) {
            // One bad row shouldn't abort the batch, and a store error is not
            // a reason to lose the row: the next run retries it.
            SubscriptionLog::error("Failed to verify {$this->label()} subscription.", [
                'subscription_id' => $subscription->id,
                'account_id' => $account->getKey(),
                'store' => $subscription->store,
            ], $e);
            $this->warn("{$logPrefix} verification failed: {$e->getMessage()}");

            // Stamped as if checked `retry_failed_after_hours` short of the
            // recheck window, so the row drops behind the batch instead of
            // heading every run, yet is picked up again soon: a row this
            // close to expiry can't wait the full recheck_after_hours for a
            // transient store error to clear.
            $this->stampChecked($subscription, $this->failedCheckStamp());
        }
    }

    /**
     * The window settings: the app's block over the package defaults.
     *
     * @return array<string, int>
     */
    private function settings(): array
    {
        $defaults = config(self::DEFAULTS_KEY, []);
        $own = $this->configKey() === self::DEFAULTS_KEY ? [] : config($this->configKey(), []);

        /** @var array<string, int> $settings */
        $settings = array_filter((is_array($own) ? $own : []) + (is_array($defaults) ? $defaults : []), 'is_numeric');

        return array_map('intval', $settings);
    }

    /**
     * The `last_checked_at` a failed row gets: eligible again after
     * `retry_failed_after_hours` (default 1) rather than `recheck_after_hours`,
     * and sorted after every row that was last checked earlier.
     */
    private function failedCheckStamp(): CarbonInterface
    {
        $config = $this->settings();
        $retryAfterHours = max(0, (int) ($config['retry_failed_after_hours'] ?? 1));

        return now()->subHours((int) $config['recheck_after_hours'])->addHours($retryAfterHours);
    }

    /**
     * Mark a row the worker looked at but did not get a store answer for, so
     * the oldest-checked-first ordering moves on to the rows behind it. `$at`
     * backdates the stamp (see failedCheckStamp()). Only the two check
     * columns are written, and a failure here doesn't abort the batch either.
     */
    private function stampChecked(StoreSubscription $subscription, ?CarbonInterface $at = null): void
    {
        try {
            $subscription->newQuery()
                ->whereKey($subscription->getKey())
                ->update([
                    'last_checked_at' => $at ?? now(),
                    'last_checked_by' => 'worker',
                ]);
        } catch (Throwable $e) {
            SubscriptionLog::error("Failed to stamp {$this->label()} subscription as checked.", [
                'subscription_id' => $subscription->id,
            ], $e);
        }
    }
}
