@extends('nova-two-factor::layout')

@section('title', __('Two-factor authentication'))

@section('content')
    <form
        method="POST"
        action="{{ route('nova-two-factor.challenge.store') }}"
        class="bg-white dark:bg-gray-800 shadow rounded-lg p-8"
        data-n2f-challenge
        data-prepare-url="{{ route('nova-two-factor.challenge.prepare') }}"
    >
        @csrf

        <h2 class="text-2xl text-center font-normal mb-2 text-gray-900 dark:text-gray-100" data-n2f-heading>
            @if ($default && $default->type->isPhishingResistant())
                {{ __('Confirm with your passkey') }}
            @elseif ($default && $default->type->value === 'email')
                {{ __('Enter the code we emailed you') }}
            @else
                {{ __('Enter your authenticator code') }}
            @endif
        </h2>

        <p class="text-center text-xs text-gray-400 dark:text-gray-500 mb-6">
            {{ auth(config('nova.guard') ?: null)->user()?->email }}
        </p>

        <div class="n2f-divider mb-6"></div>

        {{-- Announced separately from the status region so a failure interrupts. --}}
        <div data-n2f-error role="alert" class="hidden mb-4 text-sm text-red-500"></div>
        <div data-n2f-status role="status" class="sr-only"></div>

        @if ($default)
            <input type="hidden" name="method_id" value="{{ $default->id }}" data-n2f-method-id>
        @endif

        <div data-n2f-code-field class="{{ $default && $default->type->isPhishingResistant() ? 'hidden' : '' }}">
            <div class="mb-6">
                @include('nova-two-factor::partials.code-input', ['length' => 6])
                <p class="mt-2 text-xs text-gray-400 dark:text-gray-500" data-n2f-code-hint>
                    {{ __('Codes change every 30 seconds.') }}
                </p>
            </div>
        </div>

        {{-- Passkey users get the platform prompt as the primary action. On
             capable browsers the script also arms conditional mediation on load,
             so this often resolves with one touch and no typing at all. --}}
        <div data-n2f-passkey class="hidden mb-6">
            <button type="button" class="n2f-btn n2f-btn-primary w-full" data-n2f-passkey-trigger>
                {{ __('Use your passkey') }}
            </button>
            <p class="mt-2 text-center text-xs text-gray-400 dark:text-gray-500">
                {{ __('Your browser will ask for your fingerprint, face, or PIN.') }}
            </p>
        </div>

        @if ($trustedDevicesEnabled)
            <label class="flex items-start gap-2 mb-6 text-xs text-gray-500 dark:text-gray-400">
                <input type="checkbox" name="trust_device" value="1" class="mt-0.5">
                <span>{{ __("Don't ask again on this device for :count days", ['count' => $trustedDeviceDays]) }}</span>
            </label>
        @endif

        <button type="submit" class="n2f-btn n2f-btn-primary w-full" data-n2f-submit>
            {{ __('Log in') }}
        </button>

        @if ($methods->count() > 1 || $recoveryCodesRemaining > 0)
            <div class="n2f-divider my-6"></div>

            {{-- Inline, never a separate page and never shown first: one extra
                 click on every single login is not an acceptable price. --}}
            <button
                type="button"
                class="w-full text-center text-xs font-bold text-gray-500 dark:text-gray-400"
                data-n2f-chooser-toggle
                aria-expanded="false"
                aria-controls="n2f-chooser"
            >
                {{ __('Use another method') }}
            </button>

            <div id="n2f-chooser" class="hidden mt-4 space-y-1" data-n2f-chooser>
                @foreach ($methods as $method)
                    <button
                        type="button"
                        class="n2f-method-row"
                        data-n2f-choose
                        data-method-id="{{ $method->id }}"
                        data-method-type="{{ $method->type->value }}"
                        @if ($default && $method->is($default)) aria-current="true" @endif
                    >
                        <span class="n2f-method-name">{{ $method->name }}</span>
                        <span class="n2f-method-meta">
                            {{ $method->type->label() }}
                            @if ($method->destination_hint) &middot; {{ $method->destination_hint }} @endif
                            @if ($method->last_used_at) &middot; {{ $method->last_used_at->diffForHumans() }} @endif
                        </span>
                    </button>
                @endforeach

                @if ($recoveryCodesRemaining > 0)
                    <button type="button" class="n2f-method-row" data-n2f-choose data-method-type="recovery_code">
                        <span class="n2f-method-name">{{ __('Recovery code') }}</span>
                        <span class="n2f-method-meta">
                            {{ trans_choice('{1} :count code remaining|[2,*] :count codes remaining', $recoveryCodesRemaining, ['count' => $recoveryCodesRemaining]) }}
                        </span>
                    </button>
                @endif
            </div>

            <div data-n2f-recovery-field class="hidden mt-6">
                <label for="n2f-recovery_code" class="block mb-2 text-xs font-bold text-gray-500 dark:text-gray-400">
                    {{ __('Recovery code') }}
                </label>
                <input
                    id="n2f-recovery_code"
                    name="recovery_code"
                    type="text"
                    dir="ltr"
                    autocomplete="one-time-code"
                    autocapitalize="off"
                    spellcheck="false"
                    class="form-control form-input form-control-bordered w-full font-mono"
                    placeholder="xxxxxxxxxx-xxxxxxxxxx"
                >
                <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                    {{ __('Each code works once. Using one does not turn off two-factor authentication.') }}
                </p>
            </div>
        @endif
    </form>
@endsection

@section('footer')
    <form method="POST" action="{{ route('nova.logout') }}" class="inline">
        @csrf
        <button type="submit" class="underline">{{ __('Log out') }}</button>
    </form>
@endsection
