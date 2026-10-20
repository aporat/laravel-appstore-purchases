<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two columns Aporat\AppStorePurchases\Subscriptions\ManagesStoreSubscriptions
 * keeps on the account: the entitlement, and the uuid the apps label
 * purchases with (StoreKit's appAccountToken, Play's obfuscatedAccountId).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->timestamp('subscription_end')->nullable()->index();
            $table->string('store_account_token')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropIndex(['subscription_end']);
            $table->dropIndex(['store_account_token']);
            $table->dropColumn(['subscription_end', 'store_account_token']);
        });
    }
};
