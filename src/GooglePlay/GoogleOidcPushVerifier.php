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
 * Expectations can be set per Play app, keyed by package name, on top of a
 * global default. The package name is read from the (unsigned) notification
 * body and picks which pair to check the token against, so a push that
 * claims to be for one app must have been signed by that app's own push
 * subscription — the account for another app, even a listed one, is rejected.
 * A package with no entry of its own falls back to the global pair. Each
 * setting may be a string or a list of strings.
 *
 * When neither the app nor the global default configures an audience,
 * verification is skipped and the request is accepted. This keeps local
 * development and unauthenticated test subscriptions working, but production
 * endpoints should always set an audience.
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
     * Per-app expectations keyed by package name.
     *
     * @var array<string, array{audiences: list<string>, service_account_emails: list<string>}>
     */
    private readonly array $apps;

    /**
     * @var (callable(string, array<string, mixed>): (array<string, mixed>|false))|null
     */
    private $verifier;

    /**
     * @param  string|list<string>|null  $audience  Default accepted audience(s); null or empty disables verification for packages without their own entry.
     * @param  string|list<string>|null  $serviceAccountEmail  Default accepted signing account(s); null or empty skips the email check.
     * @param  array<string, array{audience?: string|list<string>|null, service_account_email?: string|list<string>|null}>  $apps
     *                                                                                                                             Per-package overrides, keyed by Play package name.
     * @param  (callable(string, array<string, mixed>): (array<string, mixed>|false))|null  $verifier
     *                                                                                                 Override the token verification call (primarily for testing). Defaults to google/auth.
     */
    public function __construct(
        string|array|null $audience,
        string|array|null $serviceAccountEmail = null,
        array $apps = [],
        ?callable $verifier = null,
    ) {
        $this->audiences = self::normalise($audience);
        $this->serviceAccountEmails = self::normalise($serviceAccountEmail);

        $perApp = [];
        foreach ($apps as $packageName => $settings) {
            if (! is_string($packageName) || $packageName === '') {
                continue;
            }

            $perApp[$packageName] = [
                'audiences' => self::normalise($settings['audience'] ?? null),
                'service_account_emails' => self::normalise($settings['service_account_email'] ?? null),
            ];
        }
        $this->apps = $perApp;

        $this->verifier = $verifier;
    }

    /**
     * The default audiences, used for packages without their own entry; empty
     * when verification is off for those.
     *
     * @return list<string>
     */
    public function audiences(): array
    {
        return $this->audiences;
    }

    /**
     * The default signing accounts; empty when any is allowed.
     *
     * @return list<string>
     */
    public function serviceAccountEmails(): array
    {
        return $this->serviceAccountEmails;
    }

    /**
     * Per-package expectations, keyed by Play package name.
     *
     * @return array<string, array{audiences: list<string>, service_account_emails: list<string>}>
     */
    public function apps(): array
    {
        return $this->apps;
    }

    public function verify(Request $request): bool
    {
        $packageName = self::packageNameFrom($request);

        ['audiences' => $audiences, 'service_account_emails' => $emails] = $this->expectationsFor($packageName);

        if ($audiences === []) {
            return true;
        }

        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            Log::warning('Google Play RTDN rejected: missing bearer token.', [
                'package_name' => $packageName,
            ]);

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
        foreach ($audiences as $audience) {
            try {
                $payload = $verify($token, [
                    'audience' => $audience,
                    'issuer' => self::ISSUER,
                ]);
            } catch (Throwable $e) {
                Log::warning('Google Play RTDN rejected: token verification threw.', [
                    'package_name' => $packageName,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }

            if (is_array($payload)) {
                break;
            }
        }

        if (! is_array($payload)) {
            Log::warning('Google Play RTDN rejected: invalid bearer token.', [
                'package_name' => $packageName,
            ]);

            return false;
        }

        if ($emails !== [] && ! in_array($payload['email'] ?? null, $emails, true)) {
            Log::warning('Google Play RTDN rejected: unexpected service account.', [
                'package_name' => $packageName,
                'email' => $payload['email'] ?? null,
            ]);

            return false;
        }

        return true;
    }

    /**
     * The audience/email pair to check a push for this package against: the
     * package's own entry when it has one, otherwise the defaults.
     *
     * @return array{audiences: list<string>, service_account_emails: list<string>}
     */
    private function expectationsFor(?string $packageName): array
    {
        if ($packageName !== null && isset($this->apps[$packageName])) {
            return $this->apps[$packageName];
        }

        return [
            'audiences' => $this->audiences,
            'service_account_emails' => $this->serviceAccountEmails,
        ];
    }

    /**
     * The Play package name inside the Pub/Sub envelope's base64 `message.data`,
     * or null when the body isn't a decodable notification. The body is
     * unsigned, so this only selects which expectations apply; it grants nothing.
     */
    private static function packageNameFrom(Request $request): ?string
    {
        $data = $request->input('message.data');

        if (! is_string($data) || $data === '') {
            return null;
        }

        $decoded = base64_decode($data, true);

        if ($decoded === false) {
            return null;
        }

        $notification = json_decode($decoded, true);
        $packageName = is_array($notification) ? ($notification['packageName'] ?? null) : null;

        return is_string($packageName) && $packageName !== '' ? $packageName : null;
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
