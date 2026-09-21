<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\TrustedDevices;

use Gabrielesbaiz\NovaTwoFactor\Events\TrustedDeviceRegistered;
use Gabrielesbaiz\NovaTwoFactor\Events\TrustedDeviceRevoked;
use Gabrielesbaiz\NovaTwoFactor\Events\TrustedDeviceUsed;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorTrustedDevice;
use Gabrielesbaiz\NovaTwoFactor\Support\CookieSecurity;
use Gabrielesbaiz\NovaTwoFactor\Support\MorphOwner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * "Don't ask again on this device."
 *
 * The token is a 32-byte secret held in an encrypted cookie; only its hash is
 * stored, so a database leak does not hand anybody a set of working bypasses.
 */
class TrustedDeviceManager
{
    public function __construct(private readonly DeviceNameGuesser $names) {}

    public function enabled(): bool
    {
        return (bool) Config::get('nova-two-factor.trusted_devices.enabled', true);
    }

    /**
     * Remember this browser, returning the cookie to queue on the response.
     */
    public function trust(Authenticatable $user, Request $request): ?SymfonyCookie
    {
        if (! $this->enabled()) {
            return null;
        }

        $token = Str::random(64);
        $days = max(1, (int) Config::get('nova-two-factor.trusted_devices.days', 30));

        $device = new TwoFactorTrustedDevice([
            'token_hash' => $this->hash($token),
            'name' => $this->names->guess($request->userAgent()),
            'client_hash' => $this->clientHash($request),
            'ip' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays($days),
        ]);

        $device->authenticatable()->associate(MorphOwner::model($user));
        $device->save();

        event(new TrustedDeviceRegistered($user, null, ['name' => $device->name, 'days' => $days]));

        return Cookie::make(
            name: $this->cookieName(),
            value: $token,
            minutes: $days * 24 * 60,
            secure: CookieSecurity::secure($request),
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    /**
     * Whether this request arrives from a device that may skip the challenge.
     */
    public function isTrusted(Authenticatable $user, Request $request): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $token = $request->cookie($this->cookieName());

        if (! is_string($token) || $token === '') {
            return false;
        }

        /** @var TwoFactorTrustedDevice|null $device */
        $device = $user->twoFactorTrustedDevices()
            ->where('token_hash', $this->hash($token))
            ->first();

        if (! $device instanceof TwoFactorTrustedDevice || $device->hasExpired()) {
            return false;
        }

        // A soft check on the user agent. Not a security boundary — a user agent
        // is trivially forged — but it does catch a cookie lifted into a
        // completely different client, which is the common accidental case.
        if (! hash_equals($device->client_hash, $this->clientHash($request))) {
            return false;
        }

        $device->forceFill(['last_used_at' => now(), 'ip' => $request->ip()])->save();

        event(new TrustedDeviceUsed($user, null, ['name' => $device->name]));

        return true;
    }

    public function revoke(Authenticatable $user, TwoFactorTrustedDevice $device): void
    {
        abort_unless($device->isOwnedBy($user), 403);

        $name = $device->name;
        $device->delete();

        event(new TrustedDeviceRevoked($user, null, ['name' => $name]));
    }

    public function revokeAll(Authenticatable $user): int
    {
        $count = $user->twoFactorTrustedDevices()->delete();

        event(new TrustedDeviceRevoked($user, null, ['revoked' => $count, 'all' => true]));

        return (int) $count;
    }

    public function forgetCookie(): SymfonyCookie
    {
        return Cookie::forget($this->cookieName());
    }

    public function pruneExpired(): int
    {
        return (int) TwoFactorTrustedDevice::query()->expired()->delete();
    }

    public function cookieName(): string
    {
        return (string) Config::get('nova-two-factor.trusted_devices.cookie', 'nova_two_factor_device');
    }

    protected function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    protected function clientHash(Request $request): string
    {
        return hash('sha256', (string) $request->userAgent());
    }
}
