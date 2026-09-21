<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Support;

use Carbon\CarbonInterface;
use Closure;
use Gabrielesbaiz\NovaTwoFactor\Enums\EnforcementMode;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
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
    /** Session key for a "not now" that lasts until the user signs out. */
    private const DISMISSED = 'nova_two_factor.reminder_dismissed';

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
     * Whether to put the enrollment page in front of this user as a reminder.
     *
     * `encouraged` is the mode that says "we would like you to, and we will not
     * stand in your way". A banner nobody reads is not that, and blocking is
     * the other mode — so the page appears, explains itself, and can be waved
     * away for a configured number of days.
     */
    public function shouldRemind(Authenticatable $user): bool
    {
        if ($this->mode() !== EnforcementMode::Encouraged || ! $this->appliesTo($user)) {
            return false;
        }

        if (method_exists($user, 'hasTwoFactorEnabled') && $user->hasTwoFactorEnabled()) {
            return false;
        }

        return ! $this->isSnoozed($user);
    }

    /**
     * Put the reminder away for the configured number of days.
     *
     * Server-side rather than a cookie: "not now" is a decision about the
     * account, and a user who clears their browser should not be nagged again
     * the same afternoon.
     */
    public function snooze(Authenticatable $user): void
    {
        $days = max(1, (int) Config::get('nova-two-factor.enforcement.remind_every_days', 7));

        Cache::put($this->snoozeKey($user), now()->addDays($days)->getTimestamp(), now()->addDays($days));
    }

    public function isSnoozed(Authenticatable $user): bool
    {
        $until = Cache::get($this->snoozeKey($user));

        return is_numeric($until) && (int) $until > now()->getTimestamp();
    }

    /**
     * "Not now", without asking for silence.
     *
     * Recorded for this session only. Without it the reminder had no memory at
     * all when the box was left unticked: dismissing it redirected to Nova, the
     * middleware saw an unenrolled user again, and sent them straight back to
     * the page they had just dismissed.
     */
    public function dismissForSession(Session $session): void
    {
        $session->put(self::DISMISSED, true);
    }

    public function isDismissedForSession(?Session $session): bool
    {
        return $session?->get(self::DISMISSED) === true;
    }

    /**
     * Start asking again.
     *
     * Part of what a reset restores: an administrator helping a locked-out or
     * unenrolled user should not leave them silently un-prompted for the rest
     * of the week they snoozed.
     */
    public function clearSnooze(Authenticatable $user): void
    {
        Cache::forget($this->snoozeKey($user));
    }

    public function remindEveryDays(): int
    {
        return max(1, (int) Config::get('nova-two-factor.enforcement.remind_every_days', 7));
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

        // No grace at all: enforcement bites at the next request. Expressed as
        // a moment just past rather than null, so every caller keeps comparing
        // dates instead of special-casing "no deadline" — which is how a user
        // with no grace ends up reading as "not yet due".
        if (! $this->graceEnabled()) {
            return Date::now()->subSecond();
        }

        // A fixed date is a deadline everybody shares — "everyone must comply
        // by 1 October" — and is read as such rather than as a start to count
        // days from. The old behaviour treated it as a start, which quietly
        // gave every account the cutover date *plus* the grace window.
        if ($this->graceMode() === 'date') {
            $configured = Config::get('nova-two-factor.enforcement.enforced_from');

            return is_string($configured) && $configured !== ''
                ? rescue(fn (): CarbonInterface => Date::parse($configured)->endOfDay(), Date::now()->subSecond(), report: false)
                : Date::now()->subSecond();
        }

        $days = max(0, (int) Config::get('nova-two-factor.enforcement.grace_days', 0));

        // Counted from the account, so every new joiner gets the same runway.
        // Nothing to measure from means enforce now, rather than granting an
        // accidental indefinite exemption.
        return isset($user->created_at)
            ? Date::parse($user->created_at)->addDays($days)
            : Date::now()->subSecond();
    }

    /**
     * Whether `required` grants any runway at all before it blocks.
     */
    public function graceEnabled(): bool
    {
        return (bool) Config::get('nova-two-factor.enforcement.grace_enabled', true);
    }

    /**
     * `days` — a runway per account, from its creation.
     * `date` — one deadline everybody shares.
     */
    public function graceMode(): string
    {
        return Config::get('nova-two-factor.enforcement.grace_mode') === 'date' ? 'date' : 'days';
    }

    /**
     * Whole days left before this user is blocked, or null when nothing will
     * block them.
     */
    public function graceDaysLeft(Authenticatable $user): ?int
    {
        if (! $this->mode()->blocks() || ! $this->appliesTo($user)) {
            return null;
        }

        $endsAt = $this->graceEndsAt($user);

        if ($endsAt === null || $endsAt->isPast()) {
            return 0;
        }

        // Rounded up: "0 days left" on the morning of the deadline is true to
        // the hour and useless to the reader, who still has today.
        return (int) ceil(Date::now()->diffInHours($endsAt) / 24);
    }

    /**
     * Whether to put the enrollment page in front of a user who still has time.
     *
     * The gap this closes: under `required` with a grace window, nothing was
     * shown at all until the day the wall appeared. The first a user heard of a
     * policy was being locked out by it — so the page now appears during grace
     * too, counting down, and can be skipped for the session.
     */
    public function shouldWarn(Authenticatable $user): bool
    {
        if (! $this->mode()->blocks() || ! $this->appliesTo($user)) {
            return false;
        }

        if (method_exists($user, 'hasTwoFactorEnabled') && $user->hasTwoFactorEnabled()) {
            return false;
        }

        $endsAt = $this->graceEndsAt($user);

        return $endsAt !== null && $endsAt->isFuture();
    }

    /**
     * How many audited users are past their grace window with no confirmed factor.
     *
     * The one number the compliance entry carries in the menu. Counted across
     * every audited population rather than one model, and filtered in SQL
     * first: the group that matters is the small one, and on a large user table
     * loading every row to count a handful is the difference between a menu
     * that renders and a menu that times out.
     *
     * Per-user hooks (`requireFor`, `hasTwoFactorEnabled`) cannot be expressed
     * in SQL, so they are still applied in PHP — but only to the rows that have
     * already failed the cheap test.
     */
    public function overdueCount(): int
    {
        if (! $this->enabled() || ! $this->mode()->blocks()) {
            return 0;
        }

        $count = 0;

        AuditedModels::each(
            function (Model $user) use (&$count): void {
                if ($user instanceof Authenticatable && $this->blocks($user)) {
                    $count++;
                }
            },
            static fn (Builder $query): Builder => $query->whereDoesntHave(
                'twoFactorMethods',
                static fn ($methods) => $methods->whereNotNull('confirmed_at'),
            ),
        );

        return $count;
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
            ...Routing::patterns(),
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

    protected function snoozeKey(Authenticatable $user): string
    {
        return 'nova-two-factor:remind-after|'.$user->getMorphClass().'|'.$user->getAuthIdentifier();
    }
}
