<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Totp;

use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Results\VerificationResult;
use Illuminate\Support\Facades\Config;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * TOTP verification with durable replay protection.
 *
 * Fortify's own provider caches `md5($code)` and wraps the cache in
 * `optional()`, so an application with no cache binding silently gets no replay
 * protection at all. Here the accepted timestep is persisted on the method row
 * and claimed with a conditional UPDATE, which survives a cache flush, a driver
 * swap and a multi-node deployment.
 */
class TotpProvider
{
    public function __construct(private readonly Google2FA $engine) {}

    /**
     * A fresh base32 secret.
     *
     * Length is in base32 characters; 32 of them carry 160 bits, which is what
     * RFC 4226 recommends. Floored at 16 so a misconfiguration cannot weaken it
     * below 80 bits.
     */
    public function generateSecret(): string
    {
        $length = max(16, (int) Config::get('nova-two-factor.methods.totp.secret_bytes', 32));

        return $this->engine->generateSecretKey($length);
    }

    /**
     * The `otpauth://` provisioning URI.
     *
     * Built locally and rendered only into a QR image or a copy-to-clipboard
     * field. It is never placed in a URL, a redirect or a log line — 1.x put it
     * in a query string to a third-party QR service by default, which leaked
     * every secret it ever generated.
     */
    public function provisioningUri(string $issuer, string $account, string $secret): string
    {
        return $this->engine->getQRCodeUrl($issuer, $account, $secret);
    }

    /**
     * Verify a code against a method, claiming its timestep on success.
     *
     * The claim is what makes this single-use: a code that verifies but whose
     * timestep has already been claimed is a replay, and is reported as such
     * rather than being quietly accepted.
     */
    public function verify(TwoFactorMethod $method, string $code): VerificationResult
    {
        $secret = $method->secret;

        if ($secret === null || $secret === '') {
            return VerificationResult::failed(VerificationResult::NO_METHOD, $method);
        }

        $code = $this->normalize($code);

        if ($code === '') {
            return VerificationResult::failed(VerificationResult::INVALID_CODE, $method);
        }

        // Verified without the stored high-water mark on purpose.
        //
        // `verifyKeyNewer()` compares with `>=`, so it happily re-accepts the
        // very same timestep — it cannot be relied on for replay protection.
        // Worse, letting it filter would collapse a replay into an
        // indistinguishable "invalid code", losing the one signal worth
        // alerting on. So we learn the timestep here and let the strictly
        // monotonic claim below decide.
        $timestep = $this->verifyAgainstSecret($secret, $code);

        if ($timestep === null) {
            return VerificationResult::failed(VerificationResult::INVALID_CODE, $method);
        }

        if (! $method->claimTimestep($timestep)) {
            return VerificationResult::failed(VerificationResult::REPLAYED, $method);
        }

        return VerificationResult::passed($method, timestep: $timestep);
    }

    /**
     * Verify against a raw secret, for the enrollment step where no method row
     * exists yet. Returns the accepted timestep, or null.
     */
    public function verifyForEnrollment(string $secret, string $code): ?int
    {
        return $this->verifyAgainstSecret($secret, $this->normalize($code));
    }

    public function window(): int
    {
        return max(0, (int) Config::get('nova-two-factor.methods.totp.window', 1));
    }

    public function period(): int
    {
        return max(1, (int) Config::get('nova-two-factor.methods.totp.period', 30));
    }

    public function currentTimestep(): int
    {
        return (int) floor(time() / $this->period());
    }

    /**
     * @return int|null The accepted timestep, or null when nothing matched.
     */
    protected function verifyAgainstSecret(string $secret, string $code): ?int
    {
        if ($code === '') {
            return null;
        }

        $this->engine->setWindow($this->window());
        $this->engine->setKeyRegeneration($this->period());

        try {
            $result = $this->engine->verifyKeyNewer($secret, $code, null);
        } catch (Throwable) {
            // A malformed secret or code is a failed verification, never a 500.
            return null;
        }

        if ($result === false) {
            return null;
        }

        // With no previous timestamp to compare against the library answers a
        // bare `true`, so resolve that to the step it must have matched.
        return $result === true ? $this->currentTimestep() : (int) $result;
    }

    /**
     * Strip everything a user might paste around the digits — spaces from an
     * authenticator's own grouping, or a "Your code is 123456" prefix.
     */
    protected function normalize(string $code): string
    {
        return preg_replace('/\D/', '', $code) ?? '';
    }
}
