<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\RateLimiting;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

trait RegistersRateLimiters
{
    /**
     * Limiter names, in the form the `throttle:` middleware expects.
     */
    public const CHALLENGE = 'nova-two-factor:challenge';

    public const STEP_UP = 'nova-two-factor:step-up';

    public const RECOVERY = 'nova-two-factor:recovery';

    public const ENROLL = 'nova-two-factor:enroll';

    public const OTP_SEND = 'nova-two-factor:otp-send';

    /**
     * @return array<string, string>
     */
    public static function limiterConfigKeys(): array
    {
        return [
            self::CHALLENGE => 'challenge',
            self::STEP_UP => 'step_up',
            self::RECOVERY => 'recovery',
            self::ENROLL => 'enroll',
            self::OTP_SEND => 'otp_send',
        ];
    }

    protected function registerRateLimiters(): void
    {
        foreach (static::limiterConfigKeys() as $limiter => $configKey) {
            RateLimiter::for($limiter, fn (Request $request): array => $this->limitsFor($configKey, $request));
        }
    }

    /**
     * Every limiter is keyed on both the subject and the IP.
     *
     * Keying on the IP alone lets a botnet spread an attack across hosts while
     * staying under the per-IP ceiling for one account; keying on the subject
     * alone lets a single host walk the whole user table. Both limits are
     * returned, and Laravel applies whichever trips first.
     *
     * @return array<int, Limit>
     */
    protected function limitsFor(string $configKey, Request $request): array
    {
        /** @var array{per_user?: int, per_ip?: int, decay?: int} $settings */
        $settings = Config::get("nova-two-factor.rate_limits.{$configKey}", []);

        $decayMinutes = max(1, (int) round(($settings['decay'] ?? 60) / 60));

        return [
            Limit::perMinutes($decayMinutes, (int) ($settings['per_user'] ?? 5))
                ->by($configKey.'|subject|'.$this->subjectKey($request)),

            Limit::perMinutes($decayMinutes, (int) ($settings['per_ip'] ?? 20))
                ->by($configKey.'|ip|'.$request->ip()),
        ];
    }

    /**
     * Identify the subject being throttled.
     *
     * At the login challenge the user is not authenticated yet, so the only
     * identifier available is Fortify's `login.id` in the session — which is
     * exactly the value we must throttle on, since that is what the attacker is
     * iterating. Falling back to the session id keeps a guest from getting an
     * unthrottled bucket shared with every other guest.
     */
    protected function subjectKey(Request $request): string
    {
        if ($user = $request->user()) {
            return $user->getAuthIdentifier().'@'.$user->getMorphClass();
        }

        if ($request->hasSession() && $request->session()->has('login.id')) {
            return 'login:'.$request->session()->get('login.id');
        }

        return $request->hasSession()
            ? 'session:'.$request->session()->getId()
            : 'anon:'.(string) $request->ip();
    }
}
