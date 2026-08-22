<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Results;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Everything the client needs to complete an enrollment, and nothing more.
 *
 * The secret appears here exactly once — during setup, when the user must be
 * able to copy it — and is deliberately absent from every other payload.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class EnrollmentIntent implements Arrayable
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $type,
        public ?string $secret = null,
        public ?string $qrCodeSvg = null,
        public ?string $otpauthUri = null,
        public ?string $destinationHint = null,
        public ?int $expiresIn = null,
        public array $extra = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'secret' => $this->secret,
            'secret_groups' => $this->secretGroups(),
            'qr_code' => $this->qrCodeSvg,
            'otpauth_uri' => $this->otpauthUri,
            'destination_hint' => $this->destinationHint,
            'expires_in' => $this->expiresIn,
            ...$this->extra,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The secret in four-character groups.
     *
     * Transcribing a 32-character base32 string by hand is where enrollment
     * actually fails, and grouping is the cheapest fix there is.
     */
    public function secretGroups(): ?string
    {
        if ($this->secret === null) {
            return null;
        }

        return trim(chunk_split($this->secret, 4, ' '));
    }
}
