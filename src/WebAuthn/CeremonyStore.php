<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\WebAuthn;

use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Config;

/**
 * Holds an in-flight WebAuthn challenge.
 *
 * Session-backed rather than a database table: a ceremony is single-browser and
 * single-use, so a table would only add a garbage-collection job. Single-use is
 * enforced by pulling rather than reading, and the stored purpose stops a
 * registration challenge from being replayed into an assertion.
 */
final class CeremonyStore
{
    private const KEY = 'nova_two_factor.webauthn';

    public function __construct(private readonly Session $session) {}

    public function put(string $challenge, ChallengePurpose $purpose, string $userHandle): void
    {
        $this->session->put(self::KEY, [
            'challenge' => $challenge,
            'purpose' => $purpose->value,
            'user_handle' => $userHandle,
            'expires_at' => now()->addSeconds($this->timeout())->getTimestamp(),
        ]);
    }

    /**
     * Consume the stored ceremony, or null when there is none, it has expired,
     * or it was created for a different purpose.
     *
     * @return array{challenge: string, user_handle: string}|null
     */
    public function pull(ChallengePurpose $purpose): ?array
    {
        /** @var array<string, mixed>|null $stored */
        $stored = $this->session->pull(self::KEY);

        if (! is_array($stored)) {
            return null;
        }

        if (($stored['purpose'] ?? null) !== $purpose->value) {
            return null;
        }

        if ((int) ($stored['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return [
            'challenge' => (string) ($stored['challenge'] ?? ''),
            'user_handle' => (string) ($stored['user_handle'] ?? ''),
        ];
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }

    public function timeout(): int
    {
        return max(15, (int) Config::get('nova-two-factor.methods.webauthn.timeout', 60));
    }
}
