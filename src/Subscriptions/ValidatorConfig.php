<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions;

/**
 * What the subscription layer needs from `appstore-purchases.validators`,
 * read straight from config: the products a validator entry sells, its
 * bundle id, and which Google Play entry serves a package name.
 *
 * Deliberately not routed through the manager facade, so an application
 * that mocks `AppStorePurchases::get()` in a test (the usual way to stand
 * in for the stores) does not have to stub these as well.
 */
final class ValidatorConfig
{
    /**
     * The raw config of one validator entry, or null when it is not defined.
     *
     * @return array<string, mixed>|null
     */
    public static function entry(string $name): ?array
    {
        $entry = config("appstore-purchases.validators.{$name}");

        if (! is_array($entry)) {
            return null;
        }

        /** @var array<string, mixed> $entry */
        return $entry;
    }

    /**
     * The subscription product ids a validator entry sells, from its
     * `subscription_products` list. A transaction or line item for any
     * other product is ignored by the layer; an empty list accepts none for
     * the App Store and every line item for Play.
     *
     * @return list<string>
     */
    public static function products(string $name): array
    {
        $products = self::entry($name)['subscription_products'] ?? [];

        if (! is_array($products)) {
            return [];
        }

        return array_values(array_filter($products, static fn (mixed $product): bool => is_string($product) && $product !== ''));
    }

    /**
     * The `bundle_id` of an Apple validator entry, or null.
     */
    public static function bundleId(string $name): ?string
    {
        $bundleId = self::entry($name)['bundle_id'] ?? null;

        return is_string($bundleId) && $bundleId !== '' ? $bundleId : null;
    }

    /**
     * The name of the Google Play validator entry serving a package, or null
     * when no entry declares that package name.
     */
    public static function playValidatorForPackage(string $packageName): ?string
    {
        if ($packageName === '') {
            return null;
        }

        $validators = config('appstore-purchases.validators');

        if (! is_array($validators)) {
            return null;
        }

        foreach ($validators as $name => $entry) {
            if (! is_array($entry) || ! is_string($entry['validator'] ?? null)) {
                continue;
            }

            if (self::isGooglePlayDriver($entry['validator']) && ($entry['package_name'] ?? null) === $packageName) {
                return (string) $name;
            }
        }

        return null;
    }

    /**
     * Whether a configured driver name is the Google Play one, in any of the
     * spellings the manager accepts (`google-play`, `googlePlay`, `google_play`).
     */
    private static function isGooglePlayDriver(string $validator): bool
    {
        $name = (string) preg_replace('/(?<!^)[A-Z]/', '-$0', trim($validator));
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name));
        $name = trim((string) preg_replace('/-+/', '-', $name), '-');

        return $name === 'google-play';
    }
}
