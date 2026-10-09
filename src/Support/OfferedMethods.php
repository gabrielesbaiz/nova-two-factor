<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Support;

use Gabrielesbaiz\NovaTwoFactor\Contracts\ClientDependent;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Exceptions\MethodUnavailableException;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Illuminate\Support\Collection;

/**
 * What a proving screen should put in front of *this* client.
 *
 * The challenge and the step-up list a user's confirmed methods, which is not
 * the same question enrollment asks. Enrollment asks what may be set up;
 * proving asks what can be used right now, by whoever is holding the screen.
 *
 * Those diverge for exactly one reason: a factor the client cannot execute at
 * all — see `Contracts\ClientDependent`, and a passkey inside an embedded web
 * view, where the ceremony is refused by the engine and reported back as the
 * same error a dismissed prompt gives. The user is offered a button that
 * cannot work, under a message that says they changed their mind.
 *
 * Note what this deliberately does *not* react to: a method switched off in
 * config. That is policy, and policy must never retire a factor somebody is
 * standing in front of — the same rule `TwoFactorManager::verify()` follows.
 */
final class OfferedMethods
{
    /**
     * @param  Collection<int, TwoFactorMethod>  $methods
     */
    private function __construct(
        public readonly Collection $methods,
        public readonly ?TwoFactorMethod $default,
    ) {}

    /**
     * Narrow a user's confirmed methods to those this client can actually use.
     *
     * `$otherWaysIn` counts entry points on the screen that are not methods —
     * on the login challenge that is the user's remaining recovery codes; on a
     * step-up it is zero, because that screen offers no recovery route by
     * design.
     *
     * The guard is the whole point of this class: a factor is withdrawn only
     * while something else is left to sign in with. Hiding the last one would
     * turn a button that fails into a screen with nothing on it at all —
     * worse, because a failing passkey can still be worked around by moving to
     * a real browser, and an empty screen cannot be worked around by anybody.
     * Awkward beats locked out.
     */
    public static function for(mixed $user, int $otherWaysIn = 0): self
    {
        /** @var Collection<int, TwoFactorMethod> $methods */
        $methods = $user->confirmedTwoFactorMethods();
        $default = $user->defaultTwoFactorMethod();

        $unusable = self::unusableTypes($methods);

        if ($unusable === []) {
            return new self($methods, $default);
        }

        /** @var Collection<int, TwoFactorMethod> $usable */
        $usable = $methods->reject(
            static fn (TwoFactorMethod $method): bool => in_array($method->type, $unusable, true),
        )->values();

        if ($usable->count() + max(0, $otherWaysIn) < 1) {
            return new self($methods, $default);
        }

        return new self(
            $usable,
            $default !== null && in_array($default->type, $unusable, true)
                ? self::strongest($usable)
                : $default,
        );
    }

    /**
     * The enrolled types whose driver says this client cannot run them.
     *
     * @param  Collection<int, TwoFactorMethod>  $methods
     * @return array<int, MethodType>
     */
    private static function unusableTypes(Collection $methods): array
    {
        $manager = app(TwoFactorManager::class);

        return $methods
            ->map(static fn (TwoFactorMethod $method): MethodType => $method->type)
            ->unique()
            ->filter(static function (MethodType $type) use ($manager): bool {
                try {
                    $driver = $manager->driver($type);
                } catch (MethodUnavailableException) {
                    // An enrolled method whose driver has since been removed is
                    // not this class's problem to solve; leaving it listed
                    // keeps the behaviour it had before.
                    return false;
                }

                return $driver instanceof ClientDependent && ! $driver->supportsCurrentClient();
            })
            ->values()
            ->all();
    }

    /**
     * The best remaining method to lead with, once the chosen one is gone.
     *
     * Same preference order the user's own default follows, minus the explicit
     * `is_default` flag — the method carrying it is precisely the one that was
     * just withdrawn.
     *
     * @param  Collection<int, TwoFactorMethod>  $methods
     */
    private static function strongest(Collection $methods): ?TwoFactorMethod
    {
        foreach (MethodType::byStrength() as $type) {
            $match = $methods->firstWhere('type', $type);

            if ($match instanceof TwoFactorMethod) {
                return $match;
            }
        }

        return $methods->first();
    }
}
