<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Support;

use Carbon\CarbonInterface;
use Closure;
use Gabrielesbaiz\NovaTwoFactor\Enums\EnforcementMode;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;

/**
 * Decides who must enrol, by when, and which requests stay reachable while they
 * have not.
 *
 * Every mistake 1.x made lived here: a hardcoded `admin/` redirect target, an
 * except-list compared with `in_array($request->path(), ...)` so no wildcard
 * ever matched, and enforcement wired only into the page middleware so any XHR
 * endpoint sailed past it.
 */
class Enforcement
{
    /**
     * Host-app override, registered on the Tool. Takes precedence over both the
     * configured gate and the mode, so an app can always carve out a service
     * account without editing config.
     *
     * @var (Closure(Authenticatable): bool)|null
     */
    protected static ?Closure $appliesToCallback = null;

    public static function requireUsing(?Closure $callback): void
    {
        static::$appliesToCallback = $callback;
    }

    public function mode(): EnforcementMode
    {
        return EnforcementMode::tryFrom(
            (string) Config::get('nova-two-factor.enforcement.mode', EnforcementMode::Optional->value),
        ) ?? EnforcementMode::Optional;
    }

    public function enabled(): bool
    {
        return (bool) Config::get('nova-two-factor.enabled', true);
    }

    /**
     * Whether this user is in scope for enforcement at all — independent of
     * whether their grace window has expired.
     */
    public function appliesTo(Authenticatable $user): bool
    {
        if (! $this->enabled() || ! $this->mode()->prompts()) {
            return false;
        }

        if (static::$appliesToCallback instanceof Closure) {
            return (bool) call_user_func(static::$appliesToCallback, $user);
        }

        $gate = Config::get('nova-two-factor.enforcement.gate');

        if (is_string($gate) && $gate !== '') {
            return Gate::forUser($user)->allows($gate);
        }

        return true;
    }

    /**
     * Whether this user must be blocked right now: in scope, not enrolled, mode
     * is `required`, and grace has run out.
     */
    public function blocks(Authenticatable $user): bool
    {
        if (! $this->mode()->blocks() || ! $this->appliesTo($user)) {
            return false;
        }

        if (method_exists($user, 'hasTwoFactorEnabled') && $user->hasTwoFactorEnabled()) {
            return false;
        }

        $graceEndsAt = $this->graceEndsAt($user);

        return $graceEndsAt === null || $graceEndsAt->isPast();
    }

    /**
     * When this user's grace window closes.
     *
     * Measured from the configured cutover date when there is one — "everybody
     * must comply by 1 October" — and otherwise from the user's own created_at,
     * which gives every new account the same runway. No extra column either way.
     */
    public function graceEndsAt(Authenticatable $user): ?CarbonInterface
    {
        if (! $this->appliesTo($user)) {
            return null;
        }

        $days = (int) Config::get('nova-two-factor.enforcement.grace_days', 0);
        $configured = Config::get('nova-two-factor.enforcement.enforced_from');

        $start = match (true) {
            is_string($configured) && $configured !== '' => Date::parse($configured),
            isset($user->created_at) => Date::parse($user->created_at),
            default => null,
        };

        // No cutover and no created_at: nothing to measure from, so enforce now
        // rather than granting an accidental indefinite exemption.
        if ($start === null) {
            return Date::now()->subSecond();
        }

        return $start->copy()->addDays($days);
    }

    /**
     * Request patterns that stay reachable for a non-compliant user.
     *
     * Built from `config('nova.path')` at runtime. 1.x hardcoded `admin/login`
     * and friends, so any app on a different Nova path got an infinite redirect
     * loop the moment enforcement was switched on.
     *
     * @return array<int, string>
     */
    public function exceptPatterns(): array
    {
        $novaPath = trim((string) Config::get('nova.path', '/nova'), '/');
        $prefix = $novaPath === '' ? '' : $novaPath.'/';

        $defaults = [
            // Our own enrollment and challenge surfaces, or there is no way out.
            $prefix.'two-factor',
            $prefix.'two-factor/*',
            $prefix.'user-security',
            $prefix.'user-security/*',

            // Nova's auth surfaces: never trap someone who is trying to leave.
            $prefix.'login',
            $prefix.'logout',
            $prefix.'password/*',
            $prefix.'email/*',
            $prefix.'403',
            $prefix.'404',

            // Assets and polling that the shell needs to render the block page.
            'nova-api/scripts/*',
            'nova-api/styles/*',
            'nova-vendor/nova-notifications*',
        ];

        $configured = Config::get('nova-two-factor.enforcement.except', []);

        return array_values(array_unique(array_merge(
            $defaults,
            is_array($configured) ? array_map('strval', $configured) : [],
        )));
    }
}
