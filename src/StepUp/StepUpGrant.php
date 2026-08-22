<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\StepUp;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * Proof that this user re-authenticated, for this scope, recently.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class StepUpGrant implements Arrayable
{
    public function __construct(
        public string $scope,
        public string $factor,
        public int $grantedAt,
        public int $expiresAt,
        public string $signature,
    ) {}

    public static function issue(Request $request, Authenticatable $user, string $scope, string $factor): self
    {
        $ttl = max(30, (int) Config::get('nova-two-factor.step_up.ttl', 300));
        $expiresAt = now()->addSeconds($ttl)->getTimestamp();

        return new self(
            scope: $scope,
            factor: $factor,
            grantedAt: now()->getTimestamp(),
            expiresAt: $expiresAt,
            signature: self::sign($request, $user, $scope, $expiresAt),
        );
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): ?self
    {
        foreach (['scope', 'factor', 'granted_at', 'expires_at', 'signature'] as $key) {
            if (! array_key_exists($key, $stored)) {
                return null;
            }
        }

        return new self(
            scope: (string) $stored['scope'],
            factor: (string) $stored['factor'],
            grantedAt: (int) $stored['granted_at'],
            expiresAt: (int) $stored['expires_at'],
            signature: (string) $stored['signature'],
        );
    }

    public function isValidFor(Request $request, Authenticatable $user, string $scope): bool
    {
        if (! hash_equals($this->scope, $scope)) {
            return false;
        }

        if ($this->expiresAt <= now()->getTimestamp()) {
            return false;
        }

        return hash_equals(
            $this->signature,
            self::sign($request, $user, $this->scope, $this->expiresAt),
        );
    }

    public function secondsRemaining(): int
    {
        return max(0, $this->expiresAt - now()->getTimestamp());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'factor' => $this->factor,
            'granted_at' => $this->grantedAt,
            'expires_at' => $this->expiresAt,
            'signature' => $this->signature,
        ];
    }

    /**
     * Bind the grant to the session, the user, the scope and the expiry.
     *
     * The session already resists forgery, so why sign at all? Because the
     * `cookie` session driver is a legal choice and keeps the payload on the
     * client. Signing additionally defeats moving a grant between scopes,
     * between users on a shared machine, and across a session regeneration —
     * none of which encryption alone prevents.
     */
    private static function sign(Request $request, Authenticatable $user, string $scope, int $expiresAt): string
    {
        return hash_hmac('sha256', implode('|', [
            $request->hasSession() ? $request->session()->getId() : 'no-session',
            (string) $user->getAuthIdentifier(),
            $user->getMorphClass(),
            $scope,
            (string) $expiresAt,
        ]), (string) Config::get('app.key'));
    }
}
