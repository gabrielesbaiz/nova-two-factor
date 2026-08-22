<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor;

use Closure;
use Gabrielesbaiz\NovaTwoFactor\Contracts\TwoFactorMethodDriver;
use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Events\ChallengeFailed;
use Gabrielesbaiz\NovaTwoFactor\Events\ChallengeSucceeded;
use Gabrielesbaiz\NovaTwoFactor\Events\CounterRegressionDetected;
use Gabrielesbaiz\NovaTwoFactor\Events\RecoveryCodeConsumed;
use Gabrielesbaiz\NovaTwoFactor\Events\ReplayDetected;
use Gabrielesbaiz\NovaTwoFactor\Exceptions\MethodUnavailableException;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Recovery\RecoveryCodeManager;
use Gabrielesbaiz\NovaTwoFactor\Results\ChallengeContext;
use Gabrielesbaiz\NovaTwoFactor\Results\VerificationResult;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;

/**
 * The package's front door: a driver registry plus the verification entry point
 * every surface goes through.
 */
class TwoFactorManager
{
    /**
     * Host-app drivers registered through `TwoFactor::extend()`.
     *
     * @var array<string, Closure(Container): TwoFactorMethodDriver>
     */
    protected array $customDrivers = [];

    /** @var array<string, TwoFactorMethodDriver> */
    protected array $resolved = [];

    public function __construct(
        protected readonly Container $container,
        protected readonly RecoveryCodeManager $recoveryCodes,
        protected readonly Enforcement $enforcement,
    ) {}

    /**
     * Register an additional factor type.
     *
     * @param  Closure(Container): TwoFactorMethodDriver  $factory
     */
    public function extend(string $type, Closure $factory): void
    {
        $this->customDrivers[$type] = $factory;
        unset($this->resolved[$type]);
    }

    public function driver(MethodType|string $type): TwoFactorMethodDriver
    {
        $key = $type instanceof MethodType ? $type->value : $type;

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        if (isset($this->customDrivers[$key])) {
            return $this->resolved[$key] = ($this->customDrivers[$key])($this->container);
        }

        $builtIn = [
            MethodType::Totp->value => Drivers\TotpDriver::class,
            MethodType::WebAuthn->value => Drivers\WebAuthnDriver::class,
            MethodType::Email->value => Drivers\EmailOtpDriver::class,
        ];

        if (! isset($builtIn[$key]) || ! class_exists($builtIn[$key])) {
            throw MethodUnavailableException::because('unknown_method');
        }

        return $this->resolved[$key] = $this->container->make($builtIn[$key]);
    }

    /**
     * Drivers that are enabled and usable, strongest first.
     *
     * @return Collection<int, TwoFactorMethodDriver>
     */
    public function availableDrivers(): Collection
    {
        return Collection::make(MethodType::byStrength())
            ->map(function (MethodType $type): ?TwoFactorMethodDriver {
                try {
                    $driver = $this->driver($type);
                } catch (MethodUnavailableException) {
                    return null;
                }

                return $driver->isAvailable() ? $driver : null;
            })
            ->filter()
            ->values();
    }

    public function hasConfirmedMethods(Authenticatable $user): bool
    {
        return method_exists($user, 'hasTwoFactorEnabled') && $user->hasTwoFactorEnabled();
    }

    public function recoveryCodes(): RecoveryCodeManager
    {
        return $this->recoveryCodes;
    }

    public function enforcement(): Enforcement
    {
        return $this->enforcement;
    }

    /**
     * Verify a proof against one method.
     *
     * Every surface funnels through here so that the event trail, the replay
     * classification and the `last_used_at` bookkeeping cannot diverge between
     * the login challenge, a step-up and an enrollment confirmation.
     *
     * @param  array<string, mixed>  $input
     */
    public function verify(TwoFactorMethod $method, array $input, ChallengeContext $context): VerificationResult
    {
        // Note the driver is used even when `isAvailable()` is false: disabling
        // a method in config hides it from *enrollment*, but must never lock out
        // someone who already enrolled it.
        $result = $this->driver($method->type)->verify($method, $input, $context);

        $this->dispatchOutcome($result, $context, $method);

        return $result;
    }

    /**
     * Spend a recovery code.
     *
     * Note what this does *not* do: it never touches the user's methods. In 1.x
     * a recovery code deleted the entire 2FA record, which meant a backup code
     * doubled as a way to switch protection off permanently.
     */
    public function consumeRecoveryCode(Authenticatable $user, string $code, ChallengeContext $context): VerificationResult
    {
        $result = $this->recoveryCodes->consume($user, $code);

        if ($result->passed) {
            event(new RecoveryCodeConsumed($user, null, [
                'remaining' => $this->recoveryCodes->unusedCount($user),
                'purpose' => $context->purpose->value,
            ]));

            event(new ChallengeSucceeded($user, null, [
                'method_type' => 'recovery_code',
                'purpose' => $context->purpose->value,
            ]));

            return $result;
        }

        event(new ChallengeFailed($user, null, [
            'method_type' => 'recovery_code',
            'reason' => $result->failure,
            'purpose' => $context->purpose->value,
        ]));

        return $result;
    }

    /**
     * Build a context for the current request.
     */
    public function context(
        Authenticatable $user,
        ChallengePurpose $purpose,
        ?string $scope = null,
    ): ChallengeContext {
        $request = request();

        return new ChallengeContext(
            user: $user,
            purpose: $purpose,
            ip: $request?->ip(),
            userAgent: $request?->userAgent(),
            scope: $scope,
        );
    }

    /**
     * Turn a verification outcome into the right events.
     *
     * Replays and counter regressions get their own event as well as the generic
     * failure, because they mean something categorically different from a typo
     * and deserve to be alertable on their own.
     */
    protected function dispatchOutcome(
        VerificationResult $result,
        ChallengeContext $context,
        TwoFactorMethod $method,
    ): void {
        $base = [
            'method_type' => $method->type->value,
            'purpose' => $context->purpose->value,
        ];

        if ($result->passed) {
            event(new ChallengeSucceeded($context->user, $method, $base));

            return;
        }

        if ($result->failedBecause(VerificationResult::REPLAYED)) {
            event(new ReplayDetected($context->user, $method, $base));
        }

        if ($result->failedBecause(VerificationResult::COUNTER_REGRESSION)) {
            event(new CounterRegressionDetected($context->user, $method, $base));
        }

        event(new ChallengeFailed($context->user, $method, [
            ...$base,
            'reason' => $result->failure,
        ]));
    }
}
