<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\GooglePlay;

use Aporat\AppStorePurchases\Contracts\PubSubPushVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Verifies the Google-signed OIDC token that Cloud Pub/Sub attaches to push requests.
 *
 * Pub/Sub sets the token's audience to the push endpoint URL (or a custom
 * audience configured on the subscription) and its email to the service account
 * the subscription signs with. Both are checked when configured.
 *
 * When no audience is configured, verification is skipped and every request is
 * accepted. This keeps local development and unauthenticated test subscriptions
 * working, but production endpoints should always set an audience.
 *
 * Requires the google/auth package.
 *
 * @see https://cloud.google.com/pubsub/docs/authenticate-push-subscriptions
 */
final class GoogleOidcPushVerifier implements PubSubPushVerifier
{
    /** Issuer Google uses for OIDC tokens. */
    public const string ISSUER = 'https://accounts.google.com';

    /** The google/auth class used for verification, resolved at runtime so the dependency stays optional. */
    private const string ACCESS_TOKEN_CLASS = 'Google\\Auth\\AccessToken';

    /**
     * @param  callable(string, array<string, mixed>): (array<string, mixed>|false)|null  $verifier
     *                                                                                               Override the token verification call (primarily for testing). Defaults to google/auth.
     */
    public function __construct(
        private readonly ?string $audience,
        private readonly ?string $serviceAccountEmail = null,
        private $verifier = null,
    ) {}

    public function verify(Request $request): bool
    {
        if ($this->audience === null) {
            return true;
        }

        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            Log::warning('Google Play RTDN rejected: missing bearer token.');

            return false;
        }

        try {
            $payload = ($this->resolveVerifier())($token, [
                'audience' => $this->audience,
                'issuer' => self::ISSUER,
            ]);
        } catch (Throwable $e) {
            Log::warning('Google Play RTDN rejected: token verification threw.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! is_array($payload)) {
            Log::warning('Google Play RTDN rejected: invalid bearer token.');

            return false;
        }

        if ($this->serviceAccountEmail !== null && ($payload['email'] ?? null) !== $this->serviceAccountEmail) {
            Log::warning('Google Play RTDN rejected: unexpected service account.', [
                'email' => $payload['email'] ?? null,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return callable(string, array<string, mixed>): (array<string, mixed>|false)
     */
    private function resolveVerifier(): callable
    {
        if ($this->verifier !== null) {
            return $this->verifier;
        }

        $class = self::ACCESS_TOKEN_CLASS;

        if (! class_exists($class)) {
            throw new RuntimeException(
                'Verifying Google Play RTDN pushes requires the google/auth package: composer require google/auth'
            );
        }

        return static fn (string $token, array $options) => (new $class)->verify($token, $options);
    }
}
