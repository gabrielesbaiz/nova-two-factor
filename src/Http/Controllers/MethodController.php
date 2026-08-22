<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Controllers;

use Gabrielesbaiz\NovaTwoFactor\Actions\RemoveMethod;
use Gabrielesbaiz\NovaTwoFactor\Actions\SetDefaultMethod;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Events\MethodConfirmed;
use Gabrielesbaiz\NovaTwoFactor\Events\MethodEnrolled;
use Gabrielesbaiz\NovaTwoFactor\Events\MethodRenamed;
use Gabrielesbaiz\NovaTwoFactor\Exceptions\LastFactorException;
use Gabrielesbaiz\NovaTwoFactor\Exceptions\TwoFactorException;
use Gabrielesbaiz\NovaTwoFactor\Http\Resources\MethodResource;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Recovery\RecoveryCodeManager;
use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MethodController extends Controller
{
    public function __construct(
        private readonly TwoFactorManager $twoFactor,
        private readonly RecoveryCodeManager $recoveryCodes,
    ) {}

    /**
     * Everything the security page needs to render, in one request.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->novaUserOrFail();

        return response()->json([
            'methods' => MethodResource::collection($user->confirmedTwoFactorMethods())->resolve(),
            'available' => $this->twoFactor->availableDrivers()
                ->map(static fn ($driver): array => [
                    'type' => $driver->type()->value,
                    'label' => $driver->type()->label(),
                    'icon' => $driver->type()->icon(),
                    'phishing_resistant' => $driver->type()->isPhishingResistant(),
                ])->all(),
            'recovery_codes' => [
                'remaining' => $this->recoveryCodes->unusedCount($user),
                'total' => (int) config('nova-two-factor.recovery_codes.count', 8),
                'running_low' => $this->recoveryCodes->isRunningLow($user),
            ],
            'enforcement' => [
                'mode' => $this->twoFactor->enforcement()->mode()->value,
                'applies' => $this->twoFactor->enforcement()->appliesTo($user),
                'grace_ends_at' => $this->twoFactor->enforcement()->graceEndsAt($user)?->toIso8601String(),
            ],
            'enabled' => $user->hasTwoFactorEnabled(),
        ]);
    }

    /**
     * Begin enrolling a method. Returns whatever that factor needs to proceed.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string'],
            'destination' => ['nullable', 'email', 'max:255'],
        ]);

        $user = $this->novaUserOrFail();
        $type = MethodType::tryFrom($validated['type']);

        abort_if($type === null, 422);

        $driver = $this->twoFactor->driver($type);

        abort_unless($driver->isAvailable(), 422);

        try {
            $intent = $driver->beginEnrollment($user, $validated);
        } catch (TwoFactorException $exception) {
            throw ValidationException::withMessages([
                'type' => [__('This method cannot be set up right now.')],
            ])->status(422);
        }

        event(new MethodEnrolled($user, null, ['method_type' => $type->value]));

        // `no-store`, because the response body carries a setup secret exactly
        // once and it must not sit in a shared cache.
        return response()->json($intent->toArray())
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    /**
     * Prove possession and confirm the method.
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:64'],
            'credential' => ['nullable'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $this->novaUserOrFail();
        $type = MethodType::tryFrom($validated['type']);

        abort_if($type === null, 422);

        try {
            $method = $this->twoFactor->driver($type)->completeEnrollment($user, $validated);
        } catch (TwoFactorException $exception) {
            throw ValidationException::withMessages([
                'code' => [$this->messageFor($exception->reason)],
            ])->status(422);
        }

        event(new MethodConfirmed($user, $method));

        // First factor on the account: issue recovery codes in the same breath.
        // Someone with one factor and no backup is a lockout waiting to happen.
        $freshCodes = null;

        if ($this->recoveryCodes->unusedCount($user) === 0) {
            $freshCodes = $this->recoveryCodes->regenerate($user)->all();
        }

        return response()->json(array_filter([
            'method' => (new MethodResource($method))->resolve(),
            'recovery_codes' => $freshCodes,
        ], static fn (mixed $value): bool => $value !== null))
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    public function rename(Request $request, TwoFactorMethod $method): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $user = $this->novaUserOrFail();

        abort_unless($method->isOwnedBy($user), 403);

        $method->forceFill(['name' => $validated['name']])->save();

        event(new MethodRenamed($user, $method));

        return response()->json((new MethodResource($method))->resolve());
    }

    public function setDefault(Request $request, TwoFactorMethod $method): JsonResponse
    {
        $user = $this->novaUserOrFail();

        abort_unless($method->isOwnedBy($user), 403);

        app(SetDefaultMethod::class)($user, $method);

        return response()->json(['message' => __('Default method updated.')]);
    }

    public function destroy(Request $request, TwoFactorMethod $method): JsonResponse
    {
        $user = $this->novaUserOrFail();

        try {
            app(RemoveMethod::class)($user, $method);
        } catch (LastFactorException) {
            throw ValidationException::withMessages([
                'method' => [__('Add another method before removing this one — your organization requires two-factor authentication.')],
            ])->status(422);
        }

        return response()->json(['message' => __('Method removed.')]);
    }

    protected function messageFor(string $reason): string
    {
        return match ($reason) {
            'ceremony_expired' => __('That took too long. Start again.'),
            'no_destination' => __('No email address is available for this account.'),
            'attempts_exhausted' => __('Too many incorrect attempts. Request a new code.'),
            'expired' => __('That code has expired. Request a new one.'),
            default => __('That code is not correct.'),
        };
    }
}
