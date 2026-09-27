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
 * Several audiences and service accounts may be listed, for an API that serves
 * more than one Play app whose push subscriptions live in different Cloud
 * projects (each with its own endpoint hostname and signing account). The
 * token is accepted when it verifies against any listed audience and, when
 * emails are listed, was signed by any listed account.
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

    /** The google/auth class used for verification, resolved at runtime so it can be swapped in tests. */
    private const string ACCESS_TOKEN_CLASS = 'Google\\Auth\\AccessToken';

    /** @var list<string> */
    private readonly array $audiences;

    /** @var list<string> */
    private readonly array $serviceAccountEmails;

    /**
     * @var (callable(string, array<string, mixed>): (array<string, mixed>|false))|null
     */
    private $verifier;

    /**
     * @param  string|list<string>|null  $audience  One or more accepted audiences; null or empty disables verification.
     * @param  string|list<string>|null  $serviceAccountEmail  One or more accepted signing accounts; null or empty skips the email check.
     * @param  (callable(string, array<string, mixed>): (array<string, mixed>|false))|null  $verifier
     *                                                                                                 Override the token verification call (primarily for testing). Defaults to google/auth.
     */
    public function __construct(
        string|array|null $audience,
        string|array|null $serviceAccountEmail = null,
        ?callable $verifier = null,
    ) {
        $this->audiences = self::normalise($audience);
        $this->serviceAccountEmails = self::normalise($serviceAccountEmail);
        $this->verifier = $verifier;
    }

    /**
     * The audiences this verifier accepts; empty when verification is off.
     *
     * @return list<string>
     */
    public function audiences(): array
    {
        return $this->audiences;
    }

    /**
     * The signing accounts this verifier accepts; empty when any is allowed.
     *
     * @return list<string>
     */
    public function serviceAccountEmails(): array
    {
        return $this->serviceAccountEmails;
    }

    public function verify(Request $request): bool
    {
        if ($this->audiences === []) {
            return true;
        }

        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            Log::warning('Google Play RTDN rejected: missing bearer token.');

            return false;
        }

        // Resolved outside the try: a missing google/auth package is a
        // deployment fault, not a forged push. Reporting it as a rejection
        // would 401 every notification while Pub/Sub retried for seven days
        // with nothing in the logs but "invalid token".
        $verify = $this->resolveVerifier();

        $payload = false;

        // google/auth checks one audience per call, so a token is tried
        // against each accepted audience until one verifies. The signature is
        // the same each time; only the "aud" comparison differs.
        foreach ($this->audiences as $audience) {
            try {
                $payload = $verify($token, [
                    'audience' => $audience,
                    'issuer' => self::ISSUER,
                ]);
            } catch (Throwable $e) {
                Log::warning('Google Play RTDN rejected: token verification threw.', [
                    'error' => $e->getMessage(),
                ]);

                return false;
            }

            if (is_array($payload)) {
                break;
            }
        }

        if (! is_array($payload)) {
            Log::warning('Google Play RTDN rejected: invalid bearer token.');

            return false;
        }

        if ($this->serviceAccountEmails !== [] && ! in_array($payload['email'] ?? null, $this->serviceAccountEmails, true)) {
            Log::warning('Google Play RTDN rejected: unexpected service account.', [
                'email' => $payload['email'] ?? null,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Accept a single value or a list, dropping nulls and empty strings.
     *
     * @param  string|array<int|string, mixed>|null  $value
     * @return list<string>
     */
    private static function normalise(string|array|null $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_filter(
            $values,
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        )));
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
