<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `subscriptions` table of Aporat\AppStorePurchases\Subscriptions\StoreSubscription:
 * one row per (account, store). Point it at the connection your accounts
 * live on, and add the two account columns from the companion migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            // 'app_store' or 'play'.
            $table->string('store', 20)->default('app_store');
            // 'production' or 'sandbox'.
            $table->string('environment', 20)->default('production');
            // Apple's original transaction id, or the Play purchase token.
            $table->string('store_subscription_id')->nullable();
            // The latest transaction id (Apple) or order id (Play).
            $table->string('store_transaction_id')->nullable();
            $table->dateTime('last_checked_at')->nullable();
            // 'client', 'webhook' or 'worker'.
            $table->string('last_checked_by', 50)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'store']);
            $table->index('store_subscription_id');
            $table->index(['expires_at', 'last_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
