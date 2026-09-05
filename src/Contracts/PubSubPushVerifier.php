<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Contracts;

use Aporat\AppStorePurchases\GooglePlay\GoogleOidcPushVerifier;
use Illuminate\Http\Request;

/**
 * Authenticates an incoming Cloud Pub/Sub push request.
 *
 * Google Play Real-time Developer Notifications arrive as Pub/Sub pushes. The
 * notification body itself is unsigned, so the only way to know a request came
 * from Google is to verify the OIDC bearer token Pub/Sub attaches when the push
 * subscription is configured with a service account.
 *
 * The package binds {@see GoogleOidcPushVerifier}
 * by default. Bind your own implementation to change how pushes are authenticated.
 */
interface PubSubPushVerifier
{
    /**
     * Whether the request is a genuine push from the configured Pub/Sub subscription.
     */
    public function verify(Request $request): bool;
}
