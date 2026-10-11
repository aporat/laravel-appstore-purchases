<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\GooglePlay;

use Aporat\AppStorePurchases\Contracts\PubSubPushVerifier;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
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
 * Google's signing certificates are cached in the PSR-6 pool passed as
 * $certificateCache for as long as Google's Cache-Control max-age allows, so
 * a push costs no certificate fetch. Google rotates its keys: a token signed
 * by a key the cached set doesn't have triggers one refetch, at most once a
 * minute however many such tokens arrive. Without a pool the certificates are
 * fetched for every push.
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

    /** The google/auth class that turns a Guzzle client into the HTTP handler it expects. */
    private const string HTTP_HANDLER_FACTORY_CLASS = 'Google\\Auth\\HttpHandler\\HttpHandlerFactory';

    /** Key the certificates are cached under in the pool. */
    public const string CERTS_CACHE_KEY = 'google-oidc-certs';

    /** Seconds within which an unknown key ID does not trigger another certificate refetch. */
    public const int REFETCH_INTERVAL_SECONDS = 60;

    /** Timeouts for the certificate fetch, in seconds. */
    private const int CERTS_CONNECT_TIMEOUT = 5;

    private const int CERTS_TIMEOUT = 10;

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

    /** @var callable|null */
    private $httpHandler;

    /**
     * @param  string|list<string>|null  $audience  Default accepted audience(s); null or empty disables verification for packages without their own entry.
     * @param  string|list<string>|null  $serviceAccountEmail  Default accepted signing account(s); null or empty skips the email check.
     * @param  array<int|string, array<string, mixed>>  $apps  Per-package overrides, keyed by Play package name, each with an
     *                                                         optional 'audience' and 'service_account_email' (string or list).
     * @param  (callable(string, array<string, mixed>): (array<string, mixed>|false))|null  $verifier
     *                                                                                                 Override the token verification call (primarily for testing). Defaults to google/auth.
     * @param  LoggerInterface|null  $logger  Where rejections and verifications are logged; the application's default logger when null.
     * @param  CacheItemPoolInterface|null  $certificateCache  Where Google's signing certificates are cached between requests; fetched per push when null.
     * @param  (callable(RequestInterface, array<string, mixed>=): ResponseInterface)|null  $httpHandler
     *                                                                                                    The google/auth HTTP handler the certificates are fetched with (primarily for testing).
     */
    public function __construct(
        string|array|null $audience,
        string|array|null $serviceAccountEmail = null,
        array $apps = [],
        ?callable $verifier = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?CacheItemPoolInterface $certificateCache = null,
        ?callable $httpHandler = null,
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
        $this->httpHandler = $httpHandler;
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
            // Accepted, but say so: a production endpoint that forgot its
            // audience must not look identical to one that verified the push.
            $this->log()->warning('Google Play RTDN push accepted without verification: no audience is configured.', [
                'package_name' => $packageName,
            ]);

            return true;
        }

        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            $this->log()->warning('Google Play RTDN rejected: missing bearer token.', [
                'package_name' => $packageName,
            ]);

            return false;
        }

        // Resolved outside the try: a missing google/auth package is a
        // deployment fault, not a forged push. Reporting it as a rejection
        // would 401 every notification while Pub/Sub retried for seven days
        // with nothing in the logs but "invalid token".
        $verify = $this->resolveVerifier();

        $payload = $this->verifyAgainst($verify, $token, $audiences, $packageName);

        if ($payload === null) {
            return false;
        }

        if ($payload === false && $this->refetchCertificatesFor($token)) {
            // Signed by a key the cached certificates don't have: Google may
            // have rotated its keys since they were cached. Try once more
            // against a fresh set.
            $payload = $this->verifyAgainst($verify, $token, $audiences, $packageName);

            if ($payload === null) {
                return false;
            }
        }

        if (! is_array($payload)) {
            // google/auth answers false for every failure alike, so the token's
            // own (unverified) claims are the only clue to which: an "aud" that
            // matches none of the tried audiences, an expired "exp", or a
            // signature problem when the claims all look right.
            $this->log()->warning('Google Play RTDN rejected: invalid bearer token.', [
                'package_name' => $packageName,
                'audiences_tried' => $audiences,
                'token_claims' => self::unverifiedClaims($token),
            ]);

            return false;
        }

        if ($emails !== [] && ! in_array($payload['email'] ?? null, $emails, true)) {
            $this->log()->warning('Google Play RTDN rejected: unexpected service account.', [
                'package_name' => $packageName,
                'email' => $payload['email'] ?? null,
                'expected' => $emails,
            ]);

            return false;
        }

        // Info rather than debug: a production stream typically drops debug,
        // and one line per accepted push is the cheapest audit trail there is.
        $this->log()->info('Google Play RTDN push verified.', [
            'package_name' => $packageName,
            'audience' => $payload['aud'] ?? null,
            'email' => $payload['email'] ?? null,
        ]);

        return true;
    }

    /**
     * Verify the token against each accepted audience in turn: google/auth
     * checks one audience per call. The signature is the same each time; only
     * the "aud" comparison differs.
     *
     * @param  callable(string, array<string, mixed>): (array<string, mixed>|false)  $verify
     * @param  list<string>  $audiences
     * @return array<string, mixed>|false|null The claims; false when no audience verified; null when verification threw (already logged).
     */
    private function verifyAgainst(callable $verify, string $token, array $audiences, ?string $packageName): array|false|null
    {
        foreach ($audiences as $audience) {
            try {
                $payload = $verify($token, [
                    'audience' => $audience,
                    'issuer' => self::ISSUER,
                ]);
            } catch (Throwable $e) {
                $this->log()->warning('Google Play RTDN rejected: token verification threw.', [
                    'package_name' => $packageName,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            if (is_array($payload)) {
                return $payload;
            }
        }

        return false;
    }

    /**
     * Drop the cached certificates when the token names a key ID they don't
     * contain, so the next verification fetches Google's current set. Rate
     * limited: the token is unauthenticated, and a stream of made-up key IDs
     * must not turn into a certificate fetch per request.
     */
    private function refetchCertificatesFor(string $token): bool
    {
        if ($this->certificateCache === null || $this->verifier !== null) {
            return false;
        }

        $kid = self::unverifiedKeyId($token);

        if ($kid === null) {
            return false;
        }

        try {
            $item = $this->certificateCache->getItem(self::CERTS_CACHE_KEY);
            $certs = $item->isHit() ? $item->get() : null;

            if (! is_array($certs) || ! is_array($certs['keys'] ?? null)) {
                // Nothing cached: the failed attempt already used a fresh set.
                return false;
            }

            foreach ($certs['keys'] as $key) {
                if (is_array($key) && ($key['kid'] ?? null) === $kid) {
                    // The key is known: the token failed for another reason.
                    return false;
                }
            }

            $marker = $this->certificateCache->getItem(self::CERTS_CACHE_KEY.'-refetched');
            if ($marker->isHit()) {
                return false;
            }

            $this->certificateCache->save($marker->set(true)->expiresAfter(self::REFETCH_INTERVAL_SECONDS));

            return $this->certificateCache->deleteItem(self::CERTS_CACHE_KEY);
        } catch (Throwable) {
            return false;
        }
    }

    /** The "kid" header of a JWT, read without checking anything. */
    private static function unverifiedKeyId(string $token): ?string
    {
        $header = explode('.', $token)[0];
        $decoded = base64_decode(strtr($header, '-_', '+/'), true);
        $header = $decoded === false ? null : json_decode($decoded, true);
        $kid = is_array($header) ? ($header['kid'] ?? null) : null;

        return is_string($kid) && $kid !== '' ? $kid : null;
    }

    /**
     * The claims a JWT carries, read without checking its signature, reduced
     * to the ones that explain a rejection. Diagnostic only: nothing here is
     * trusted, and the token itself is never logged.
     *
     * @return array<string, mixed>
     */
    private static function unverifiedClaims(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return ['malformed' => true, 'segments' => count($parts)];
        }

        $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($claims)) {
            return ['malformed' => true, 'segments' => 3];
        }

        $picked = array_intersect_key($claims, array_flip(['iss', 'aud', 'email', 'email_verified', 'azp', 'sub']));

        foreach (['iat', 'exp'] as $time) {
            if (isset($claims[$time]) && is_int($claims[$time])) {
                $picked[$time] = gmdate('c', $claims[$time]);
            }
        }

        if (isset($claims['exp']) && is_int($claims['exp'])) {
            $picked['expired'] = $claims['exp'] < time();
        }

        return $picked;
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
     * Accept a single value or a list, dropping nulls, empty strings and
     * anything that is not a string.
     *
     * @return list<string>
     */
    private static function normalise(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_filter(
            $values,
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        )));
    }

    private function log(): LoggerInterface
    {
        if ($this->logger !== null) {
            return $this->logger;
        }

        /** @var LoggerInterface $logger */
        $logger = Log::getFacadeRoot();

        return $logger;
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

        $cache = $this->certificateCache;
        $httpHandler = $this->httpHandler;

        if ($httpHandler === null) {
            // google/auth's default client has no timeouts: a stalled fetch
            // of Google's certificates would hold the request open.
            $factory = self::HTTP_HANDLER_FACTORY_CLASS;
            $httpHandler = $factory::build(new Client([
                'connect_timeout' => self::CERTS_CONNECT_TIMEOUT,
                'timeout' => self::CERTS_TIMEOUT,
            ]));
        }

        return static fn (string $token, array $options) => (new $class($httpHandler, $cache))
            ->verify($token, $options + ['cacheKey' => self::CERTS_CACHE_KEY]);
    }
}
