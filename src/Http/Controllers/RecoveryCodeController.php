<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Controllers;

use Gabrielesbaiz\NovaTwoFactor\Events\RecoveryCodesRegenerated;
use Gabrielesbaiz\NovaTwoFactor\Recovery\RecoveryCodeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Both routes here sit behind a fresh password confirmation, applied as route
 * middleware so it cannot be forgotten at a call site.
 */
class RecoveryCodeController extends Controller
{
    public function __construct(private readonly RecoveryCodeManager $codes) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->novaUserOrFail();

        // Existing codes genuinely cannot be shown again: only their hashes are
        // stored. The UI says so rather than implying they are retrievable.
        return response()->json([
            'remaining' => $this->codes->unusedCount($user),
            'total' => (int) config('nova-two-factor.recovery_codes.count', 8),
            'running_low' => $this->codes->isRunningLow($user),
            'retrievable' => false,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->novaUserOrFail();

        $codes = $this->codes->regenerate($user);

        event(new RecoveryCodesRegenerated($user, null, ['count' => $codes->count()]));

        return response()->json([
            'codes' => $codes->all(),
            'generated_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, max-age=0');
    }
}
