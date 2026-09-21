<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\WebAuthn;

use Cose\Algorithm\Manager as AlgorithmManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\EdDSA\Ed25519;
use Cose\Algorithm\Signature\RSA\RS256;
use Gabrielesbaiz\NovaTwoFactor\Support\Base64Url;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * A narrow port over `web-auth/webauthn-lib`.
 *
 * Everything the rest of the package needs sits behind these few methods, so
 * swapping the underlying library — or upgrading across its fairly regular
 * breaking changes — touches one file.
 */
class WebAuthnService
{
    public function __construct(private readonly RelyingParty $rp) {}

    /**
     * Options for registering a new credential.
     *
     * @param  array<int, string>  $excludedCredentialIds  Already-enrolled ids, so the same
     *                                                     authenticator cannot be added twice.
     */
    public function creationOptions(
        string $userHandle,
        string $displayName,
        string $userName,
        array $excludedCredentialIds = [],
        bool $requireUserVerification = false,
    ): PublicKeyCredentialCreationOptions {
        $options = PublicKeyCredentialCreationOptions::create(
            rp: new PublicKeyCredentialRpEntity($this->rp->name, $this->rp->id),
            user: new PublicKeyCredentialUserEntity($userName, $userHandle, $displayName),
            challenge: random_bytes(32),
            pubKeyCredParams: $this->algorithmParameters(),
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                // Discoverable credentials are what let the challenge screen
                // offer passkey autofill through conditional mediation.
                residentKey: $this->residentKeyRequirement(),
                userVerification: $requireUserVerification
                    ? AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED
                    : $this->userVerificationRequirement(),
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: array_map(
                static fn (string $id): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    Base64Url::decode($id),
                ),
                $excludedCredentialIds,
            ),
            timeout: $this->rpTimeoutMilliseconds(),
        );

        return $options;
    }

    /**
     * Options for asserting an existing credential.
     *
     * @param  array<int, string>  $allowedCredentialIds  Empty for a usernameless flow.
     */
    public function requestOptions(
        array $allowedCredentialIds = [],
        bool $requireUserVerification = false,
    ): PublicKeyCredentialRequestOptions {
        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $this->rp->id,
            allowCredentials: array_map(
                static fn (string $id): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    Base64Url::decode($id),
                ),
                $allowedCredentialIds,
            ),
            userVerification: $requireUserVerification
                ? PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED
                : $this->userVerificationRequirement(),
            timeout: $this->rpTimeoutMilliseconds(),
        );
    }

    /**
     * Validate a registration response, returning the credential to persist.
     */
    public function verifyAttestation(
        PublicKeyCredentialCreationOptions $options,
        string $rawResponseJson,
        string $host,
    ): CredentialRecord {
        $credential = $this->serializer()->deserialize(
            $rawResponseJson,
            PublicKeyCredential::class,
            'json',
        );

        $response = $credential->response;

        if (! $response instanceof AuthenticatorAttestationResponse) {
            throw new RuntimeException('Expected an attestation response.');
        }

        return AuthenticatorAttestationResponseValidator::create(
            $this->ceremonyFactory()->creationCeremony(),
        )->check($response, $options, $host);
    }

    /**
     * Validate an assertion response against a stored credential.
     */
    public function verifyAssertion(
        CredentialRecord $stored,
        PublicKeyCredentialRequestOptions $options,
        string $rawResponseJson,
        string $host,
        ?string $userHandle,
    ): CredentialRecord {
        $credential = $this->serializer()->deserialize(
            $rawResponseJson,
            PublicKeyCredential::class,
            'json',
        );

        $response = $credential->response;

        if (! $response instanceof AuthenticatorAssertionResponse) {
            throw new RuntimeException('Expected an assertion response.');
        }

        return AuthenticatorAssertionResponseValidator::create(
            $this->ceremonyFactory()->requestCeremony(),
        )->check($stored, $response, $options, $host, $userHandle);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeCredential(CredentialRecord $record): array
    {
        $source = PublicKeyCredentialSource::fromCredentialRecord($record);

        /** @var array<string, mixed> $data */
        $data = json_decode(
            $this->serializer()->serialize($source, 'json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    /**
     * Rebuild a stored credential.
     *
     * Typed to `CredentialRecord`, not `PublicKeyCredentialSource`: the
     * library's denormaliser hands back the parent, and the narrower return
     * type turned every passkey login into a `TypeError` — caught one frame
     * later and reported to the user as "that code is not correct", for a
     * ceremony where no code was typed. `check()` wants the parent anyway.
     *
     * @param  array<string, mixed>  $data
     */
    public function deserializeCredential(array $data): CredentialRecord
    {
        return $this->serializer()->deserialize(
            json_encode($data, JSON_THROW_ON_ERROR),
            CredentialRecord::class,
            'json',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeOptions(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($this->serializer()->serialize($options, 'json'), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function deserializeCreationOptions(array $data): PublicKeyCredentialCreationOptions
    {
        return $this->serializer()->deserialize(
            json_encode($data, JSON_THROW_ON_ERROR),
            PublicKeyCredentialCreationOptions::class,
            'json',
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function deserializeRequestOptions(array $data): PublicKeyCredentialRequestOptions
    {
        return $this->serializer()->deserialize(
            json_encode($data, JSON_THROW_ON_ERROR),
            PublicKeyCredentialRequestOptions::class,
            'json',
        );
    }

    public function relyingParty(): RelyingParty
    {
        return $this->rp;
    }

    protected function ceremonyFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;

        $factory->setAlgorithmManager($this->algorithmManager());
        $factory->setAttestationStatementSupportManager($this->attestationSupport());

        // Exact-origin matching, no subdomain wildcard: a wildcard here is how
        // an origin check quietly stops being one.
        $factory->setAllowedOrigins($this->rp->origins, allowSubdomains: false);

        if ($this->rp->requiresSecureContext()) {
            $factory->setSecuredRelyingPartyId([$this->rp->id]);
        }

        $factory->setCounterChecker(new DeferredCounterChecker);

        return $factory;
    }

    protected function serializer(): SerializerInterface
    {
        return (new WebauthnSerializerFactory($this->attestationSupport()))->create();
    }

    protected function attestationSupport(): AttestationStatementSupportManager
    {
        $manager = new AttestationStatementSupportManager;

        // `none` only. Verifying anything stronger needs the FIDO metadata
        // service, and attestation also carries a privacy cost — it identifies
        // the exact make and model of a user's authenticator.
        $manager->add(new NoneAttestationStatementSupport);

        return $manager;
    }

    protected function algorithmManager(): AlgorithmManager
    {
        $algorithms = [ES256::create(), RS256::create()];

        // Ed25519 needs libsodium. Offered when it is there, silently skipped
        // when it is not, rather than failing every ceremony.
        if (extension_loaded('sodium')) {
            $algorithms[] = Ed25519::create();
        }

        $manager = AlgorithmManager::create();

        foreach ($algorithms as $algorithm) {
            $manager->add($algorithm);
        }

        return $manager;
    }

    /**
     * @return array<int, PublicKeyCredentialParameters>
     */
    protected function algorithmParameters(): array
    {
        $identifiers = [ES256::ID, RS256::ID];

        if (extension_loaded('sodium')) {
            array_splice($identifiers, 1, 0, [Ed25519::ID]);
        }

        return array_map(
            static fn (int $alg): PublicKeyCredentialParameters => PublicKeyCredentialParameters::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                $alg,
            ),
            $identifiers,
        );
    }

    protected function userVerificationRequirement(): string
    {
        $configured = (string) Config::get('nova-two-factor.methods.webauthn.user_verification', 'preferred');

        return in_array($configured, ['discouraged', 'preferred', 'required'], true)
            ? $configured
            : 'preferred';
    }

    protected function residentKeyRequirement(): string
    {
        $configured = (string) Config::get('nova-two-factor.methods.webauthn.resident_key', 'preferred');

        return in_array($configured, ['discouraged', 'preferred', 'required'], true)
            ? $configured
            : 'preferred';
    }

    protected function rpTimeoutMilliseconds(): int
    {
        return max(15, (int) Config::get('nova-two-factor.methods.webauthn.timeout', 60)) * 1000;
    }
}
