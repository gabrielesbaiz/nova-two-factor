<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Drivers;

use Gabrielesbaiz\NovaTwoFactor\Contracts\TwoFactorMethodDriver;
use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Exceptions\InvalidCodeException;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Results\ChallengeContext;
use Gabrielesbaiz\NovaTwoFactor\Results\EnrollmentIntent;
use Gabrielesbaiz\NovaTwoFactor\Results\VerificationResult;
use Gabrielesbaiz\NovaTwoFactor\Support\Base64Url;
use Gabrielesbaiz\NovaTwoFactor\Support\MorphOwner;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\AaguidRegistry;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\CeremonyStore;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\WebAuthnService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Throwable;

/**
 * WebAuthn / passkeys.
 *
 * The only phishing-resistant factor here: the assertion is bound to the origin
 * by the browser, so a proxied login page cannot replay it.
 */
class WebAuthnDriver implements TwoFactorMethodDriver
{
    private const OPTIONS_KEY = 'nova_two_factor.webauthn_options';

    public function __construct(
        private readonly WebAuthnService $webauthn,
        private readonly CeremonyStore $ceremonies,
        private readonly AaguidRegistry $aaguids,
    ) {}

    public function type(): MethodType
    {
        return MethodType::WebAuthn;
    }

    public function isAvailable(): bool
    {
        return (bool) Config::get('nova-two-factor.methods.webauthn.enabled', true);
    }

    public function beginEnrollment(Authenticatable $user, array $input = []): EnrollmentIntent
    {
        $options = $this->webauthn->creationOptions(
            userHandle: $this->userHandle($user),
            displayName: $this->displayName($user),
            userName: $this->userName($user),
            excludedCredentialIds: $this->existingCredentialIds($user),
            requireUserVerification: false,
        );

        $this->ceremonies->put(
            Base64Url::encode($options->challenge),
            ChallengePurpose::Enrollment,
            $this->userHandle($user),
        );

        Session::put(self::OPTIONS_KEY, $this->webauthn->serializeOptions($options));

        return new EnrollmentIntent(
            type: $this->type()->value,
            expiresIn: $this->ceremonies->timeout(),
            extra: ['public_key' => $this->webauthn->serializeOptions($options)],
        );
    }

    public function completeEnrollment(Authenticatable $user, array $input): TwoFactorMethod
    {
        $ceremony = $this->ceremonies->pull(ChallengePurpose::Enrollment);

        if ($ceremony === null) {
            throw InvalidCodeException::because(VerificationResult::CEREMONY_EXPIRED);
        }

        /** @var array<string, mixed>|null $stored */
        $stored = Session::pull(self::OPTIONS_KEY);

        if (! is_array($stored)) {
            throw InvalidCodeException::because(VerificationResult::CEREMONY_EXPIRED);
        }

        try {
            $record = $this->webauthn->verifyAttestation(
                $this->webauthn->deserializeCreationOptions($stored),
                $this->rawResponse($input),
                $this->webauthn->relyingParty()->id,
            );
        } catch (Throwable $exception) {
            // A failed ceremony is a failed verification, never a 500 — and the
            // reason is deliberately not echoed back, since the library's
            // messages describe our own configuration. It is logged instead:
            // "that code is not correct" for a ceremony nobody typed a code
            // into is otherwise impossible to debug from the outside.
            $this->logCeremonyFailure('enrollment', $exception);

            throw InvalidCodeException::because(VerificationResult::INVALID_CODE);
        }

        $credentialId = Base64Url::encode($record->publicKeyCredentialId);
        $credential = $this->webauthn->serializeCredential($record);

        $method = new TwoFactorMethod([
            'type' => MethodType::WebAuthn,
            'name' => $this->resolveName($input, $record->aaguid->__toString()),
            'credential' => $credential,
            'credential_id' => $credentialId,
            'credential_id_hash' => hash('sha256', $credentialId),
            'sign_count' => $record->counter,
            'confirmed_at' => now(),
            'last_used_at' => now(),
        ]);

        $method->authenticatable()->associate(MorphOwner::model($user));
        $method->save();

        return $method;
    }

    public function beginChallenge(TwoFactorMethod $method, ChallengeContext $context): ?array
    {
        $requireUv = $context->requiresUserVerification();

        // Every passkey this account holds, not only the one the page happened
        // to default to. Offering one meant a user with a laptop key and a
        // security key had to guess which was selected before touching
        // anything — and presenting the other simply failed.
        $options = $this->webauthn->requestOptions(
            allowedCredentialIds: $this->existingCredentialIds($context->user),
            requireUserVerification: $requireUv,
        );

        $this->ceremonies->put(
            Base64Url::encode($options->challenge),
            $context->purpose,
            $this->userHandle($context->user),
        );

        Session::put(self::OPTIONS_KEY, $this->webauthn->serializeOptions($options));

        return [
            'public_key' => $this->webauthn->serializeOptions($options),
            'user_verification_required' => $requireUv,
        ];
    }

    public function verify(TwoFactorMethod $method, array $input, ChallengeContext $context): VerificationResult
    {
        // The authenticator chooses which credential to sign with, so the one
        // that answered may not be the row the page started from. Resolve it
        // from the assertion before anything else, or a user with two passkeys
        // is told the wrong one is invalid.
        $method = $this->methodForAssertion($context, $input) ?? $method;

        if (! $method->isConfirmed()) {
            return VerificationResult::failed(VerificationResult::UNCONFIRMED_METHOD, $method);
        }

        $ceremony = $this->ceremonies->pull($context->purpose);

        if ($ceremony === null) {
            return VerificationResult::failed(VerificationResult::CEREMONY_EXPIRED, $method);
        }

        /** @var array<string, mixed>|null $stored */
        $stored = Session::pull(self::OPTIONS_KEY);
        $credential = $method->credential;

        if (! is_array($stored) || ! is_array($credential)) {
            return VerificationResult::failed(VerificationResult::CEREMONY_EXPIRED, $method);
        }

        try {
            $record = $this->webauthn->verifyAssertion(
                $this->webauthn->deserializeCredential($credential),
                $this->webauthn->deserializeRequestOptions($stored),
                $this->rawResponse($input),
                $this->webauthn->relyingParty()->id,
                $ceremony['user_handle'],
            );
        } catch (Throwable $exception) {
            $this->logCeremonyFailure('assertion', $exception, $method);

            return VerificationResult::failed(VerificationResult::INVALID_CODE, $method);
        }

        // A step-up must be a fresh, deliberate act. If the authenticator did
        // not actually verify the user, refuse it whatever the browser claimed
        // it would do.
        if ($context->requiresUserVerification() && ! $record->uvInitialized) {
            return VerificationResult::failed(VerificationResult::USER_VERIFICATION_REQUIRED, $method);
        }

        if (! $method->claimSignCount($record->counter)) {
            if (Config::get('nova-two-factor.methods.webauthn.on_counter_regression') === 'log') {
                $method->touchLastUsed();

                return VerificationResult::passed($method, signCount: $record->counter);
            }

            return VerificationResult::failed(VerificationResult::COUNTER_REGRESSION, $method);
        }

        // Persist any refreshed backup/UV flags the authenticator reported.
        $method->forceFill(['credential' => $this->webauthn->serializeCredential($record)])->save();

        return VerificationResult::passed($method, signCount: $record->counter);
    }

    public function suggestName(array $input = []): string
    {
        return $this->type()->label();
    }

    /**
     * The raw JSON credential the browser produced.
     *
     * Passed through to the library verbatim rather than being reassembled from
     * parsed parts: the signature covers exact bytes, and re-encoding is a
     * reliable way to invalidate a perfectly good assertion.
     *
     * @param  array<string, mixed>  $input
     */
    protected function rawResponse(array $input): string
    {
        $raw = $input['credential'] ?? null;

        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        if (is_array($raw)) {
            return json_encode($raw, JSON_THROW_ON_ERROR);
        }

        throw InvalidCodeException::because(VerificationResult::INVALID_CODE);
    }

    /**
     * The enrolled passkey that produced this assertion.
     *
     * Matched on the credential id the authenticator returned, hashed the same
     * way it is indexed. Scoped to the user's own confirmed credentials, so an
     * id belonging to somebody else resolves to nothing rather than to them.
     *
     * @param  array<string, mixed>  $input
     */
    protected function methodForAssertion(ChallengeContext $context, array $input): ?TwoFactorMethod
    {
        $credential = $input['credential'] ?? null;
        $credential = is_string($credential) ? json_decode($credential, true) : $credential;

        $id = is_array($credential) ? ($credential['rawId'] ?? $credential['id'] ?? null) : null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        /** @var TwoFactorMethod|null $match */
        $match = $context->user->twoFactorMethods()
            ->ofType(MethodType::WebAuthn)
            ->confirmed()
            ->where('credential_id_hash', hash('sha256', $id))
            ->first();

        return $match;
    }

    /**
     * @return array<int, string>
     */
    protected function existingCredentialIds(Authenticatable $user): array
    {
        return $user->twoFactorMethods()
            ->ofType(MethodType::WebAuthn)
            ->whereNotNull('credential_id')
            ->pluck('credential_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function resolveName(array $input, ?string $aaguid): string
    {
        $supplied = trim((string) ($input['name'] ?? ''));

        if ($supplied !== '') {
            return mb_substr($supplied, 0, 100);
        }

        // A name derived from the authenticator's own model is what makes a list
        // of four passkeys legible six months later.
        return $this->aaguids->name($aaguid) ?? $this->suggestName($input);
    }

    protected function userHandle(Authenticatable $user): string
    {
        return Base64Url::encode(
            method_exists($user, 'twoFactorUserHandle')
                ? $user->twoFactorUserHandle()
                : hash('sha256', $user->getMorphClass().'|'.$user->getAuthIdentifier(), true),
        );
    }

    protected function displayName(Authenticatable $user): string
    {
        $name = $user->name ?? null;

        return is_string($name) && $name !== '' ? $name : $this->userName($user);
    }

    protected function userName(Authenticatable $user): string
    {
        $email = $user->email ?? null;

        return is_string($email) && $email !== '' ? $email : (string) $user->getAuthIdentifier();
    }

    /**
     * Record why a ceremony was refused.
     *
     * The user is told only "that is not correct", because the library's
     * messages describe *our* relying party, origins and challenge state — but
     * an administrator staring at a passkey that enrolls and then refuses to
     * log in has nothing to go on without this. Warning level: it is an
     * authentication failure, not a crash, and it carries no credential data.
     */
    protected function logCeremonyFailure(string $stage, Throwable $exception, ?TwoFactorMethod $method = null): void
    {
        Log::warning('nova-two-factor: WebAuthn '.$stage.' refused.', [
            'reason' => $exception->getMessage(),
            'exception' => $exception::class,
            'relying_party' => Config::get('nova-two-factor.methods.webauthn.relying_party.id'),
            'origins' => Config::get('nova-two-factor.methods.webauthn.origins'),
            'app_url' => Config::get('app.url'),
            'method_id' => $method?->getKey(),
        ]);
    }
}
