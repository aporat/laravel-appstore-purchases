<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Events;

/**
 * A verified App Store Server Notification whose type has no dedicated event:
 * a type Apple documents that this package doesn't map yet (for example
 * RESCIND_CONSENT, METADATA_UPDATE, MIGRATION or PRICE_CHANGE), or one Apple
 * added after the installed aporat/store-receipt-validator was released, in
 * which case `$notification->getNotificationType()` is UNKNOWN.
 *
 * Read the type Apple sent from `$notification->getRawNotificationType()`
 * (aporat/store-receipt-validator 11.0.1 or later).
 *
 * The notification passed the same verification as every other event (Apple's
 * signature, bundle ID, environment, app Apple ID), and Apple has been
 * answered 2xx so it won't retry.
 */
class UnhandledNotification extends PurchaseEvent {}
