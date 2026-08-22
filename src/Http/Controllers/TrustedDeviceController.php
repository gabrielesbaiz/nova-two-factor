<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Controllers;

use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorTrustedDevice;
use Gabrielesbaiz\NovaTwoFactor\TrustedDevices\TrustedDeviceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrustedDeviceController extends Controller
{
    public function __construct(private readonly TrustedDeviceManager $devices) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->novaUserOrFail();

        $current = $request->cookie($this->devices->cookieName());
        $currentHash = is_string($current) && $current !== '' ? hash('sha256', $current) : null;

        return response()->json([
            'devices' => $user->twoFactorTrustedDevices()
                ->active()
                ->orderByDesc('last_used_at')
                ->get()
                ->map(static fn (TwoFactorTrustedDevice $device): array => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'ip' => $device->ip,
                    'last_used_at' => $device->last_used_at?->toIso8601String(),
                    'expires_at' => $device->expires_at->toIso8601String(),
                    // Pinned first in the UI, so nobody revokes the browser they
                    // are sitting at by accident.
                    'is_current' => $currentHash !== null && hash_equals($device->token_hash, $currentHash),
                ])
                ->all(),
            'enabled' => $this->devices->enabled(),
        ]);
    }

    public function destroy(Request $request, TwoFactorTrustedDevice $device): JsonResponse
    {
        $user = $this->novaUserOrFail();

        $this->devices->revoke($user, $device);

        return response()->json(['message' => __('Device revoked.')]);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $user = $this->novaUserOrFail();

        $count = $this->devices->revokeAll($user);

        return response()
            ->json(['message' => __(':count devices revoked.', ['count' => $count])])
            ->withCookie($this->devices->forgetCookie());
    }
}
