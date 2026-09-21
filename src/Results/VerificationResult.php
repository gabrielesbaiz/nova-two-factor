<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Results;

use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;

/**
 * The outcome of one verification attempt.
 *
 * A struct rather than a bare bool so that replay bookkeeping, failure
 * classification and audit context all live in one place, and every driver
 * reports them the same way.
 */
final readonly class VerificationResult
{
    public const INVALID_CODE = 'invalid_code';

    public const REPLAYED = 'replayed';

    public const EXPIRED = 'expired';

    public const ALREADY_USED = 'already_used';

    /**
     * The code was correct, for a code we have since replaced.
     *
     * Issuing a new code invalidates the last one, so a user reading an older
     * mail types six digits that were right when they were sent. Reported as
     * "not correct" it is indistinguishable from a typo, and the user retypes
     * the same dead code until the attempt budget runs out.
     */
    public const SUPERSEDED = 'superseded';

    public const NO_METHOD = 'no_method';

    public const UNCONFIRMED_METHOD = 'unconfirmed_method';

    public const COUNTER_REGRESSION = 'counter_regression';

    public const ORIGIN_MISMATCH = 'origin_mismatch';

    public const USER_VERIFICATION_REQUIRED = 'user_verification_required';

    public const ATTEMPTS_EXHAUSTED = 'attempts_exhausted';

    public const CEREMONY_EXPIRED = 'ceremony_expired';

    private function __construct(
        public bool $passed,
        public ?string $failure = null,
        public ?TwoFactorMethod $method = null,
        public ?int $timestep = null,
        public ?int $signCount = null,
    ) {}

    public static function passed(
        ?TwoFactorMethod $method = null,
        ?int $timestep = null,
        ?int $signCount = null,
    ): self {
        return new self(true, null, $method, $timestep, $signCount);
    }

    /**
     * @param  non-empty-string  $failure  A stable machine code, never a user-facing sentence.
     */
    public static function failed(string $failure, ?TwoFactorMethod $method = null): self
    {
        return new self(false, $failure, $method);
    }

    public function failedBecause(string $failure): bool
    {
        return ! $this->passed && $this->failure === $failure;
    }

    /**
     * Whether the failure indicates an attack rather than a typo. These are
     * worth an audit row and an alert even in isolation.
     */
    public function isSuspicious(): bool
    {
        return in_array($this->failure, [
            self::REPLAYED,
            self::COUNTER_REGRESSION,
            self::ALREADY_USED,
        ], true);
    }
}
