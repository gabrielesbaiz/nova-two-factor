<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Support;

use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;

/**
 * Tracks whether the current session has cleared its second factor.
 *
 * 1.x delegated this to google2fa-laravel, whose shipped defaults are
 * `lifetime => 0 // eternal` with `keep_alive => true`. The package never
 * overrode them, so one code entry marked a session as verified for as long as
 * it existed, and nothing cleared the flag on logout.
 */
class TwoFactorSession
{
    private const PASSED_AT = 'nova_two_factor.passed_at';

    private const METHOD_ID = 'nova_two_factor.method_id';

    /**
     * Who the verification belongs to.
     *
     * A flag that says only "this session passed" is a flag that keeps saying
     * it after the session changes hands — a logout that leaves the data
     * behind, or a second login in the same session, inherits a cleared second
     * factor without ever presenting one.
     */
    private const USER = 'nova_two_factor.user';

    public function __construct(private readonly Session $session) {}

    /**
     * Mark this session as verified.
     *
     * The session id is regenerated first. Without that, an attacker who fixed
     * a session id before login holds a session that is now second-factor
     * verified — the 2FA boundary is exactly where fixation has to be broken.
     */
    public function markPassed(?TwoFactorMethod $method = null, ?Authenticatable $user = null): void
    {
        $this->session->regenerate();

        $this->session->put(self::PASSED_AT, now()->getTimestamp());
        $this->session->put(self::USER, $this->stamp($user));

        if ($method instanceof TwoFactorMethod) {
            $this->session->put(self::METHOD_ID, $method->getKey());
        }
    }

    /**
     * Whether this session has cleared its second factor — for this user.
     */
    public function hasPassed(?Authenticatable $user = null): bool
    {
        if (! $this->session->has(self::PASSED_AT)) {
            return false;
        }

        if ($user === null) {
            return true;
        }

        $owner = $this->session->get(self::USER);

        // Older sessions carry no owner. Rather than trusting them, make them
        // prove the factor once more: a single extra challenge on upgrade is
        // cheaper than one inherited verification.
        return is_string($owner) && hash_equals($owner, $this->stamp($user));
    }

    public function passedAt(): ?int
    {
        $value = $this->session->get(self::PASSED_AT);

        return is_numeric($value) ? (int) $value : null;
    }

    public function methodId(): ?int
    {
        $value = $this->session->get(self::METHOD_ID);

        return is_numeric($value) ? (int) $value : null;
    }

    public function clear(): void
    {
        $this->session->forget([self::PASSED_AT, self::METHOD_ID, self::USER]);
    }

    protected function stamp(?Authenticatable $user): string
    {
        return $user === null
            ? ''
            : $user->getMorphClass().'|'.$user->getAuthIdentifier();
    }
}
