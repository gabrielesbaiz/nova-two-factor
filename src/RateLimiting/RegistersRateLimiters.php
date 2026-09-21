<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\RateLimiting;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

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

    public const REMIND = 'nova-two-factor:remind';

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
            self::REMIND => 'remind',
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
        // A passkey assertion is a signature, not a guess: there is no budget
        // of attempts to exhaust, and the authenticator itself rate-limits the
        // human. Counting cancelled ceremonies and flaky readers against a
        // five-try budget locked people out of the strongest factor they had
        // — for a minute at a time, with nothing gained.
        if ($this->isSignatureAttempt($request)) {
            return [
                Limit::perMinute((int) Config::get('nova-two-factor.rate_limits.webauthn_per_minute', 30))
                    ->by($configKey.'|webauthn|'.$this->subjectKey($request))
                    ->response(fn (Request $throttled, array $headers = []): Response => $this->throttledResponse($throttled, $headers, $configKey)),
            ];
        }

        // A recovery code is not a code guess. It carries ~119 bits, so the
        // budget here is not what stops it being guessed — the entropy is — and
        // the person spending it has already lost their usual factor. Sharing
        // the challenge's five-a-minute meant the attempt that matters most was
        // usually the one already spent, so it gets its own bucket, with the
        // controller charging the tighter half of it for input that is not even
        // shaped like a code.
        if ($configKey === 'challenge' && $this->isRecoveryAttempt($request)) {
            $configKey = 'recovery';
        }

        /** @var array{per_user?: int, per_ip?: int, decay?: int} $settings */
        $settings = Config::get("nova-two-factor.rate_limits.{$configKey}", []);

        $decayMinutes = max(1, (int) round(($settings['decay'] ?? 60) / 60));

        return [
            Limit::perMinutes($decayMinutes, (int) ($settings['per_user'] ?? 5))
                ->by($configKey.'|subject|'.$this->subjectKey($request))
                ->response(fn (Request $throttled, array $headers = []): Response => $this->throttledResponse($throttled, $headers, $configKey)),

            Limit::perMinutes($decayMinutes, (int) ($settings['per_ip'] ?? 20))
                ->by($configKey.'|ip|'.$request->ip())
                ->response(fn (Request $throttled, array $headers = []): Response => $this->throttledResponse($throttled, $headers, $configKey)),
        ];
    }

    /**
     * Whether this request is a WebAuthn ceremony rather than a typed secret.
     *
     * Kept generous rather than unlimited: the ceremony still costs a signature
     * verification, so a loop hammering the endpoint is worth stopping — just
     * not at the same count as a guessable code.
     */
    protected function isSignatureAttempt(Request $request): bool
    {
        return $request->filled('credential')
            || $request->input('type') === 'webauthn';
    }

    /**
     * Whether this request is spending a recovery code rather than a factor.
     */
    protected function isRecoveryAttempt(Request $request): bool
    {
        return $request->filled('recovery_code');
    }

    /**
     * Answer a throttled request in the application's own words.
     *
     * Laravel's `throttle` middleware aborts with the literal string
     * "Too Many Attempts." — untranslated, and silent about the two things a
     * locked-out user needs: how long the wait is, and that a second factor is
     * not the only way in.
     *
     * @param  array<string, mixed>  $headers
     */
    protected function throttledResponse(Request $request, array $headers = [], string $configKey = 'challenge'): Response
    {
        $seconds = (int) ($headers['Retry-After'] ?? 60);
        $minutes = (int) ceil($seconds / 60);

        // "Use another method" is true at a challenge, where a second factor or
        // a recovery code is standing by. While enrolling it is a lie: the
        // budget is shared across factors, so picking a different one hits the
        // same wall. Telling a locked-out user to do something that cannot work
        // is worse than saying nothing.
        $suggestsAlternative = in_array($configKey, ['challenge', 'step_up'], true);

        $message = match (true) {
            $suggestsAlternative && $seconds >= 60 => __('Too many attempts. Try again in :minutes minutes, or use another method.', ['minutes' => $minutes]),
            $suggestsAlternative => __('Too many attempts. Try again in :seconds seconds, or use another method.', ['seconds' => $seconds]),
            $seconds >= 60 => __('Too many attempts. Try again in :minutes minutes.', ['minutes' => $minutes]),
            default => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => $seconds]),
        };

        // The field name matters: the challenge page renders errors under the
        // input the user was typing into, so a bag keyed anything else shows
        // nothing at all.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => ['code' => [$message]],
                'retry_after' => $seconds,
            ], 429, $headers);
        }

        return back()
            ->withErrors(['code' => $message])
            ->withHeaders($headers);
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
