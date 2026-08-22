<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Drivers;

use Gabrielesbaiz\NovaTwoFactor\Contracts\TwoFactorMethodDriver;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Exceptions\InvalidCodeException;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Results\ChallengeContext;
use Gabrielesbaiz\NovaTwoFactor\Results\EnrollmentIntent;
use Gabrielesbaiz\NovaTwoFactor\Results\VerificationResult;
use Gabrielesbaiz\NovaTwoFactor\Support\MorphOwner;
use Gabrielesbaiz\NovaTwoFactor\Totp\QrCodeGenerator;
use Gabrielesbaiz\NovaTwoFactor\Totp\TotpProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Config;

class TotpDriver implements TwoFactorMethodDriver
{
    public function __construct(
        private readonly TotpProvider $totp,
        private readonly QrCodeGenerator $qr,
        private readonly Cache $cache,
    ) {}

    public function type(): MethodType
    {
        return MethodType::Totp;
    }

    public function isAvailable(): bool
    {
        return (bool) Config::get('nova-two-factor.methods.totp.enabled', true);
    }

    /**
     * Begin enrollment, holding the pending secret in the cache.
     *
     * Two properties matter here, and 1.x had neither. The secret is not written
     * to the database until it is confirmed, so an abandoned setup leaves no row
     * that could later be mistaken for a usable factor. And the pending secret
     * is reused for its whole TTL, so refreshing the setup page does not rotate
     * the QR the user has already scanned — 1.x regenerated on every render and
     * silently invalidated the recovery code each time.
     */
    public function beginEnrollment(Authenticatable $user, array $input = []): EnrollmentIntent
    {
        $ttl = max(60, (int) Config::get('nova-two-factor.methods.totp.enrollment_ttl', 900));
        $key = $this->pendingKey($user);

        $secret = $this->cache->get($key);

        if (! is_string($secret) || $secret === '') {
            $secret = $this->totp->generateSecret();
            $this->cache->put($key, $secret, $ttl);
        }

        $uri = $this->totp->provisioningUri($this->issuer(), $this->accountLabel($user), $secret);

        return new EnrollmentIntent(
            type: $this->type()->value,
            secret: $secret,
            qrCodeSvg: $this->qr->svg($uri),
            otpauthUri: $uri,
            expiresIn: $ttl,
            extra: [
                'digits' => (int) Config::get('nova-two-factor.methods.totp.digits', 6),
                'period' => $this->totp->period(),
            ],
        );
    }

    public function completeEnrollment(Authenticatable $user, array $input): TwoFactorMethod
    {
        $key = $this->pendingKey($user);
        $secret = $this->cache->get($key);

        if (! is_string($secret) || $secret === '') {
            throw InvalidCodeException::because(VerificationResult::CEREMONY_EXPIRED);
        }

        $code = (string) ($input['code'] ?? '');

        if ($this->totp->verifyForEnrollment($secret, $code) === null) {
            throw InvalidCodeException::because(VerificationResult::INVALID_CODE);
        }

        $method = new TwoFactorMethod([
            'type' => MethodType::Totp,
            'name' => $this->resolveName($input),
            'secret' => $secret,
            'confirmed_at' => now(),
            'last_used_at' => now(),
            // Seed the replay high-water mark with the step just consumed, so
            // the very code used to enrol cannot immediately be replayed at the
            // login challenge.
            'last_timestep' => $this->totp->currentTimestep(),
        ]);

        $method->authenticatable()->associate(MorphOwner::model($user));
        $method->save();

        $this->cache->forget($key);

        return $method;
    }

    public function beginChallenge(TwoFactorMethod $method, ChallengeContext $context): ?array
    {
        // Nothing to prepare: the proof is computed on the user's device.
        return null;
    }

    public function verify(TwoFactorMethod $method, array $input, ChallengeContext $context): VerificationResult
    {
        if (! $method->isConfirmed()) {
            return VerificationResult::failed(VerificationResult::UNCONFIRMED_METHOD, $method);
        }

        return $this->totp->verify($method, (string) ($input['code'] ?? ''));
    }

    public function suggestName(array $input = []): string
    {
        return 'Authenticator app';
    }

    /**
     * Discard a pending enrollment. Used when the user cancels, so the next
     * attempt starts from a genuinely new secret.
     */
    public function forgetPendingEnrollment(Authenticatable $user): void
    {
        $this->cache->forget($this->pendingKey($user));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function resolveName(array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));

        return $name !== '' ? mb_substr($name, 0, 100) : $this->suggestName($input);
    }

    /**
     * Scoped to the morph class as well as the key, so two models sharing a
     * primary key cannot collide on a pending secret.
     */
    protected function pendingKey(Authenticatable $user): string
    {
        return sprintf(
            'nova-two-factor:pending:totp:%s:%s',
            $user->getMorphClass(),
            (string) $user->getAuthIdentifier(),
        );
    }

    protected function issuer(): string
    {
        return (string) (Config::get('app.name') ?: 'Laravel Nova');
    }

    /**
     * What the authenticator app shows under the issuer. The user's email when
     * there is one, since that is what makes two accounts distinguishable in a
     * list of a dozen entries.
     */
    protected function accountLabel(Authenticatable $user): string
    {
        foreach (['email', 'username', 'name'] as $attribute) {
            $value = $user->{$attribute} ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return (string) $user->getAuthIdentifier();
    }
}
