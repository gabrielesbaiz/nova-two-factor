@extends('nova-two-factor::layout')

@section('title', __('Two-factor authentication'))

@section('content')
    {{-- The card is the wrapper, not the form: log out is a second form, and
         forms cannot nest — so without this the only way out of the screen ends
         up floating underneath the card it belongs to. --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-8">
    <form
        method="POST"
        action="{{ route('nova-two-factor.challenge.store') }}"
        data-n2f-challenge
        data-prepare-url="{{ route('nova-two-factor.challenge.prepare') }}"
        {{-- The factor in play. Without it the bundle could not tell what the
             page had opened on, so the default method was never prepared and an
             emailed code was only ever sent by re-picking it in the chooser. --}}
        data-method-type="{{ $default?->type->value }}"
        data-label-resend="{{ __('Send another code') }}"
        data-label-resend-wait="{{ __('You can ask for another code in :time') }}"
        data-label-resent="{{ __('New code sent. The previous one no longer works.') }}"
    >
        @csrf

        <h2
            class="text-2xl text-center font-normal mb-2 text-gray-900 dark:text-gray-100"
            data-n2f-heading
            data-heading-totp="{{ __('Enter your authenticator code') }}"
            data-heading-email="{{ __('Enter the code we emailed you') }}"
            data-heading-webauthn="{{ __('Confirm with your passkey') }}"
            data-heading-recovery_code="{{ __('Enter a recovery code') }}"
        >
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


        @if (session('nova-two-factor.status'))
            <p class="mb-6 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ session('nova-two-factor.status') }}
            </p>
        @endif

        {{-- With JavaScript off nothing can POST the prepare endpoint for the
             user, and the GET that renders this page must not send a code: a
             link prefetch would spend it before they read the mail. So the
             send becomes a form they submit themselves. --}}
        @if ($default && $default->type->value === 'email')
            <noscript>
                <form method="POST" action="{{ route('nova-two-factor.challenge.prepare') }}" class="mb-6">
                    @csrf
                    <input type="hidden" name="method_id" value="{{ $default->id }}">
                    <button type="submit" class="n2f-btn n2f-btn-primary w-full">
                        {{ __('Send the code') }}
                    </button>
                </form>
            </noscript>
        @endif
        {{-- Sits with the code field, not under the chooser: the input for the
         factor you picked belongs where the factor's input always is. --}}
        {{-- With the code field, not under the chooser that opened it: the
             input for the factor you picked belongs where a factor's input
             always is on this screen. --}}
        <div data-n2f-recovery-field class="hidden mb-6">
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
        <div data-n2f-code-field class="{{ $default && $default->type->isPhishingResistant() ? 'hidden' : '' }}">
            <div class="mb-6">
                @include('nova-two-factor::partials.code-input', ['length' => 6])
                {{-- The hint has to match the factor in front of the user: an
                     emailed code does not rotate every 30 seconds, and telling
                     someone it does invites them to wait for a new one that is
                     never coming. Swapped client-side when the method changes. --}}
                <p
                    class="mt-2 text-xs text-gray-400 dark:text-gray-500"
                    data-n2f-code-hint
                    data-hint-totp="{{ __('Codes change every 30 seconds.') }}"
                    data-hint-email="{{ __('The code we emailed you expires in a few minutes.') }}"
                    data-hint-recovery_code="{{ __('Each code works once. Using one does not turn off two-factor authentication.') }}"
                >
                    {{ $default && $default->type->value === 'email'
                        ? __('The code we emailed you expires in a few minutes.')
                        : __('Codes change every 30 seconds.') }}
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
                {{-- Pluralised: `trusted_devices.days` is configurable, and at 1
                     the old string read "per 1 giorni". --}}
                <span>{{ trans_choice("{1} Don't ask again on this device for a day|[2,*] Don't ask again on this device for :count days", $trustedDeviceDays, ['count' => $trustedDeviceDays]) }}</span>
            </label>
        @endif

        {{-- Hidden for a passkey: the ceremony is the submission, and a second
             button that posts an empty form under "Usa la tua passkey" asks the
             user to choose between one real action and one that does nothing. --}}
        <button
            type="submit"
            class="n2f-btn n2f-btn-primary w-full{{ $default && $default->type->isPhishingResistant() ? ' hidden' : '' }}"
            data-n2f-submit
        >
            {{ __('Log in') }}
        </button>
        {{-- The mail that never arrives is the commonest failure of an email
             factor. Hidden until there is something it can do: an always-visible
             control that answers "not yet" is worse than none. --}}
        <button
            type="button"
            class="hidden block mt-4 w-full text-center text-xs font-bold text-gray-500 dark:text-gray-400"
            data-n2f-resend
        ></button>


        {{-- Every factor is rendered; the bundle hides whichever one is in use.
             Filtering here instead left a user who had switched to a recovery
             code with a list that could not lead back to their email. --}}
        @php($alternatives = $methods->reject(fn ($method) => $default && $method->is($default)))

        @if ($alternatives->isNotEmpty() || $recoveryCodesRemaining > 0)
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

            <div id="n2f-chooser" class="hidden mt-4 space-y-2" data-n2f-chooser>
                @foreach ($methods as $method)
                    <button
                        type="button"
                        class="n2f-method-row"
                        data-n2f-choose
                        data-method-id="{{ $method->id }}"
                        data-method-type="{{ $method->type->value }}"
                    >
                        <span class="n2f-method-name">{{ $method->name }}</span>
                        <span class="n2f-method-meta">
                            {{ $method->type->label() }}
                            @if ($method->destination_hint) &middot; {{ $method->destination_hint }} @endif
                            @if ($method->last_used_at) &middot; {{ $method->last_used_at->diffForHumans() }} @endif
                        </span>

                        {{-- Same trade-off, same line of its own, same switch as
                             the enrollment list: picking which factor to prove
                             with is the same kind of decision as picking which
                             one to set up. --}}
                        @if (config('nova-two-factor.ui.show_method_tradeoffs', true))
                            <span class="n2f-method-tradeoff">{{ $method->type->tradeoff() }}</span>
                        @endif
                    </button>
                @endforeach

                @if ($recoveryCodesRemaining > 0)
                    <button type="button" class="n2f-method-row" data-n2f-choose data-method-type="recovery_code">
                        <span class="n2f-method-name">{{ __('Recovery code') }}</span>
                        <span class="n2f-method-meta">
                            {{ trans_choice('{1} :count code remaining|[2,*] :count codes remaining', $recoveryCodesRemaining, ['count' => $recoveryCodesRemaining]) }}
                        </span>

                        @if (config('nova-two-factor.ui.show_method_tradeoffs', true))
                            <span class="n2f-method-tradeoff">
                                {{ __('Each code works once. Using one does not turn off two-factor authentication.') }}
                            </span>
                        @endif
                    </button>
                @endif
            </div>

        @endif
    </form>

    {{-- Inside the card with the other actions, not stranded under it. --}}
    <form method="POST" action="{{ route('nova.logout') }}" class="mt-6">
        @csrf
        <button type="submit" class="block w-full text-center text-xs font-bold text-gray-500 dark:text-gray-400">
            {{ __('Log out') }}
        </button>
    </form>
    </div>
@endsection
