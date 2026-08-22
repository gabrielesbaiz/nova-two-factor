<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\WebAuthn;

use Gabrielesbaiz\NovaTwoFactor\Exceptions\MethodUnavailableException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Resolves and validates the relying party a ceremony is bound to.
 */
final class RelyingParty
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        /** @var array<int, string> */
        public readonly array $origins,
    ) {}

    public static function resolve(): self
    {
        $appUrl = (string) Config::get('app.url');
        $appHost = parse_url($appUrl, PHP_URL_HOST);

        if (! is_string($appHost) || $appHost === '') {
            throw MethodUnavailableException::because('app_url_not_configured');
        }

        // Never `$request->getHost()`. The Host header is attacker-controlled,
        // and an RP ID taken from it lets a request through a rogue host bind
        // credentials that then work against the real one.
        $id = Config::get('nova-two-factor.methods.webauthn.relying_party.id');
        $id = is_string($id) && $id !== '' ? $id : $appHost;

        if (! self::isRegistrableSuffixOf($id, $appHost)) {
            throw MethodUnavailableException::because('relying_party_id_mismatch');
        }

        $origins = Config::get('nova-two-factor.methods.webauthn.origins', []);
        $origins = is_array($origins) && $origins !== []
            ? array_values(array_map('strval', $origins))
            : [rtrim($appUrl, '/')];

        $name = Config::get('nova-two-factor.methods.webauthn.relying_party.name');
        $name = is_string($name) && $name !== '' ? $name : (string) (Config::get('app.name') ?: $appHost);

        return new self($id, $name, $origins);
    }

    /**
     * Whether an origin is one we accept.
     *
     * Exact string comparison on scheme, host and port — not a prefix or
     * substring test, which is how origin checks usually fail open.
     */
    public function permitsOrigin(string $origin): bool
    {
        $candidate = rtrim($origin, '/');

        foreach ($this->origins as $allowed) {
            if (hash_equals(rtrim($allowed, '/'), $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * WebAuthn requires a secure context. Localhost is the one exception the
     * spec itself grants, so local development does not need a certificate.
     */
    public function requiresSecureContext(): bool
    {
        return ! in_array($this->id, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * The RP ID must be the origin's host or a registrable parent of it. A
     * mismatch means no credential will ever verify, so it is worth refusing
     * loudly at boot rather than debugging silent failures later.
     */
    private static function isRegistrableSuffixOf(string $id, string $host): bool
    {
        return $id === $host || Str::endsWith($host, '.'.$id);
    }
}
