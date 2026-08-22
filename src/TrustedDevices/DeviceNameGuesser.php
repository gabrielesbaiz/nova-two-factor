<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\TrustedDevices;

use Illuminate\Support\Str;

/**
 * Turns a user agent into something a person recognises in a list.
 *
 * Deliberately crude. "Chrome on macOS" is all this needs to be — a full
 * user-agent parsing dependency would be a lot of surface for a label the user
 * can rename anyway.
 */
class DeviceNameGuesser
{
    public function guess(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return 'Unknown device';
        }

        $browser = $this->browser($userAgent);
        $platform = $this->platform($userAgent);

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} on {$platform}",
            $browser !== null => $browser,
            $platform !== null => $platform,
            default => 'Unknown device',
        };
    }

    protected function browser(string $agent): ?string
    {
        // Order matters: Edge and Opera both advertise Chrome, and Chrome
        // advertises Safari.
        return match (true) {
            Str::contains($agent, 'Edg/') => 'Edge',
            Str::contains($agent, ['OPR/', 'Opera']) => 'Opera',
            Str::contains($agent, 'Firefox/') => 'Firefox',
            Str::contains($agent, 'Chrome/') => 'Chrome',
            Str::contains($agent, 'Safari/') => 'Safari',
            default => null,
        };
    }

    protected function platform(string $agent): ?string
    {
        return match (true) {
            Str::contains($agent, 'iPhone') => 'iPhone',
            Str::contains($agent, 'iPad') => 'iPad',
            Str::contains($agent, 'Android') => 'Android',
            Str::contains($agent, ['Macintosh', 'Mac OS X']) => 'macOS',
            Str::contains($agent, 'Windows') => 'Windows',
            Str::contains($agent, ['Linux', 'X11']) => 'Linux',
            default => null,
        };
    }
}
