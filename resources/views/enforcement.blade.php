@extends('nova-two-factor::layout')

@section('title', __('Two-factor authentication required'))

@section('content')
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-8">
        <h2 class="text-xl font-bold mb-2 text-gray-900 dark:text-gray-100">
            {{ ($reminder ?? false)
                ? __('Protect your account with a second factor')
                : __('Your organization requires two-factor authentication') }}
        </h2>

        @if ($reminder ?? false)
            {{-- Encouraged, not required: say why it is worth doing rather than
                 quoting a deadline that does not exist. --}}
            {{-- One sentence per line, as in the reminder mail: three reasons
                 run together read as a wall, and this screen has seconds. --}}
            <div class="mb-6 space-y-1 text-sm text-gray-500 dark:text-gray-400">
                <p>{{ __('A password alone can be guessed, reused or stolen by phishing.') }}</p>
                <p>{{ __('With a second factor, a stolen password is not enough to reach your account.') }}</p>
                <p>{{ __('Setting one up takes about a minute.') }}</p>
            </div>
        @endif

        @if (! ($reminder ?? false) && $withinGrace && $graceEndsAt)
            {{-- The countdown rewrites this line client-side, so it carries its
                 own translated templates rather than hardcoding English in JS. --}}
            <p
                class="mb-6 text-sm"
                data-n2f-countdown
                data-deadline="{{ $graceEndsAt->toIso8601String() }}"
                data-label-days="{{ __(':days days :hours hours left to set this up.') }}"
                data-label-time="{{ __(':time left to set this up.') }}"
                data-label-expired="{{ __('Set up a method to continue.') }}"
            >
                {{ __('You have until :date to set it up.', ['date' => $graceEndsAt->isoFormat('LLL')]) }}
            </p>
        @elseif (! ($reminder ?? false))
            <p class="mb-6 text-sm text-red-500">
                {{ __('Set up a method to continue.') }}
            </p>
        @endif

        {{-- Ranked strongest first, each with an honest tradeoff rather than
             marketing copy, so the choice is informed.

             The links stay real links: with no JavaScript they still reach the
             management page, which is the only way to enrol without a script.
             With the script present they are intercepted and the whole
             enrollment happens here — a user who owes mandatory enrollment must
             never be sent into Nova's SPA to satisfy it, since this page exists
             precisely because that SPA may not be reachable yet. --}}
        {{-- Above the list, because it is about the list. Inside the panel it
             rendered underneath three cards and a "choose another method"
             button that were already the answer to it. --}}
        <div data-n2f-enroll-error role="alert" class="hidden mb-4 text-sm text-red-500"></div>

        <div
            class="space-y-2"
            data-n2f-enroll
            data-store-url="{{ route('nova-two-factor.methods.store') }}"
            data-confirm-url="{{ route('nova-two-factor.methods.confirm') }}"
            data-done-url="{{ rtrim(config('nova.path'), '/') ?: '/' }}"
            {{-- One label per factor. "Working…" told a user nothing about what
                 was being worked on, and stayed on screen underneath the error
                 when the request failed. --}}
            data-label-working="{{ __('Setting this up…') }}"
            data-label-working-totp="{{ __('Building your authenticator setup…') }}"
            data-label-working-email="{{ __('Sending a code to your inbox…') }}"
            data-label-working-webauthn="{{ __('Asking your device for a passkey…') }}"
            {{-- Three parts, because the address is the one thing on this screen
                 the user has to check, and it cannot do that job wrapped inside
                 a sentence. --}}
            data-label-sent="{{ __('We sent a code to') }}"
            data-label-already-sent="{{ __('A code is already on its way to') }}"
            data-label-sent-note="{{ __('Enter it below.') }}"
            data-label-already-note="{{ __('The one already in your inbox is still the one that works.') }}"
            data-label-resend="{{ __('Send another code') }}"
            data-label-resend-wait="{{ __('You can ask for another code in :time') }}"
            data-label-resent="{{ __('New code sent. The previous one no longer works.') }}"
            data-label-scan="{{ __('Scan this with your authenticator app, then enter the code it shows.') }}"
            data-label-passkey="{{ __('Follow your browser’s prompt.') }}"
            data-label-saved="{{ __('Done. Taking you back…') }}"
            data-label-unsupported="{{ __('This browser cannot create a passkey.') }}"
        >
            @foreach ($drivers as $driver)
                <a
                    href="{{ rtrim(config('nova.path'), '/') }}/user-security#add-{{ $driver->type()->value }}"
                    class="n2f-method-row"
                    data-n2f-enroll-start="{{ $driver->type()->value }}"
                >
                    <span class="n2f-method-name">
                        {{ $driver->type()->label() }}
                        @if ($driver->type()->isPhishingResistant())
                            <span class="n2f-badge n2f-badge-ok">{{ __('Recommended') }}</span>
                        @endif
                    </span>
                    <span class="n2f-method-meta">{{ $driver->type()->description() }}</span>

                    {{-- Its own line: the trade-off is what decides the choice,
                         not a footnote to the description. --}}
                    @if (config('nova-two-factor.ui.show_method_tradeoffs', true))
                        <span class="n2f-method-tradeoff">{{ $driver->type()->tradeoff() }}</span>
                    @endif
                </a>
            @endforeach
        </div>

        {{-- One panel, reused by whichever method was picked. Hidden until a
             choice is made, so the page still opens as a list of options. --}}
        <div data-n2f-enroll-panel class="hidden mt-6">
            <div data-n2f-enroll-status role="status" class="mb-4 text-sm text-gray-500 dark:text-gray-400"></div>

            <div data-n2f-enroll-qr class="hidden mb-4 flex justify-center [&>svg]:h-44 [&>svg]:w-44"></div>

            <p data-n2f-enroll-secret class="hidden mb-4 text-center font-mono text-xs tracking-widest text-gray-500 dark:text-gray-400"></p>

            <div data-n2f-enroll-code class="hidden">
                @include('nova-two-factor::partials.code-input', ['length' => 6])

                <button type="button" class="n2f-btn n2f-btn-primary w-full mt-6" data-n2f-enroll-confirm>
                    {{ __('Confirm') }}
                </button>

                {{-- The mail that never arrives is the commonest failure of an
                     email factor, and without this the only way out is to leave
                     the screen — which used to mint a code and kill this one. --}}
                <button
                    type="button"
                    class="hidden block mt-4 w-full text-center text-xs font-bold text-gray-500 dark:text-gray-400"
                    data-n2f-enroll-resend
                ></button>
            </div>

            <button type="button" class="block mt-4 w-full text-center text-xs font-bold text-gray-500 dark:text-gray-400" data-n2f-enroll-cancel>
                {{ __('Choose a different method') }}
            </button>

            {{-- The first confirmed factor comes with recovery codes, and this
                 is the only moment they can be shown: they are stored hashed.
                 Issuing them silently — as this screen did — leaves a user one
                 lost phone away from a lockout, holding codes they have never
                 seen. --}}
            <div data-n2f-enroll-codes class="hidden">
                <h3 class="mb-2 text-base font-bold text-gray-900 dark:text-gray-100">
                    {{ __('Save your recovery codes') }}
                </h3>

                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Each code works once, if you lose access to your other methods. This is the only time we can show them to you.') }}
                </p>

                <ol
                    class="grid grid-cols-2 gap-x-6 gap-y-1 rounded-lg bg-gray-100 p-4 font-mono text-sm dark:bg-gray-900"
                    dir="ltr"
                    data-n2f-enroll-codes-list
                ></ol>

                {{-- Somewhere to put them. Codes shown once and offered no way
                     out of the browser are codes nobody keeps: the user is left
                     transcribing sixteen characters by hand, eight times. --}}
                <div
                    class="mt-4 flex flex-wrap justify-center gap-2"
                    data-n2f-enroll-codes-actions
                    data-app-name="{{ \Laravel\Nova\Nova::name() ?? config('app.name') }}"
                    data-account="{{ auth(config('nova.guard') ?: null)->user()?->email }}"
                    data-label-copy="{{ __('Copy all') }}"
                    data-label-copied="{{ __('Copied') }}"
                    data-print-title="{{ __(':app — recovery codes', ['app' => \Laravel\Nova\Nova::name() ?? config('app.name')]) }}"
                    data-print-note="{{ __('each code works once') }}"
                >
                    <button type="button" class="n2f-btn n2f-btn-outline" data-n2f-codes-copy>
                        {{ __('Copy all') }}
                    </button>
                    <button type="button" class="n2f-btn n2f-btn-outline" data-n2f-codes-download>
                        {{ __('Download') }}
                    </button>
                    <button type="button" class="n2f-btn n2f-btn-outline" data-n2f-codes-print>
                        {{ __('Print') }}
                    </button>
                </div>

                <button type="button" class="n2f-btn n2f-btn-primary w-full mt-6" data-n2f-enroll-codes-done>
                    {{ __('I have saved these codes somewhere safe') }}
                </button>
            </div>
        </div>

        {{-- Everything below the factor list is about leaving this screen
             rather than finishing it, so it is ruled off and set quieter than
             the actions above. Hidden while a setup is in progress: dismissing
             a prompt is a decision about the prompt, not about the ceremony
             running on top of it. --}}
        <div class="n2f-page-actions" data-n2f-page-actions>
            @if ($reminder ?? false)
                <form method="POST" action="{{ route('nova-two-factor.required.remind-later') }}">
                    @csrf

                    {{-- Unticked: silence is something the user asks for, not
                         something dismissing a prompt once buys them. --}}
                    <label class="flex items-start justify-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <input type="checkbox" name="snooze" value="1" class="mt-0.5">
                        <span>{{ trans_choice('{1} Don\'t remind me again for a day|[2,*] Don\'t remind me again for :count days', $remindDays, ['count' => $remindDays]) }}</span>
                    </label>

                    <div class="n2f-page-actions-row">
                        <button type="submit" class="n2f-page-action">{{ __('Not now') }}</button>
                        <span class="n2f-page-actions-sep" aria-hidden="true">&middot;</span>
                        <button type="submit" form="n2f-logout" class="n2f-page-action">{{ __('Log out') }}</button>
                    </div>
                </form>
            @else
                {{-- A POST, not a link back to Nova: leaving without recording
                     the decision means the very next page redirects here again,
                     which reads as a broken button. It buys this session only —
                     under `required` the deadline is real, so the warning
                     returns tomorrow. --}}
                <form method="POST" action="{{ route('nova-two-factor.required.remind-later') }}">
                    @csrf

                    <div class="n2f-page-actions-row">
                        @if ($canDefer)
                            <button type="submit" class="n2f-page-action">
                                {{ __('Set up later') }}
                            </button>
                            <span class="n2f-page-actions-sep" aria-hidden="true">&middot;</span>
                        @endif

                        <button type="submit" form="n2f-logout" class="n2f-page-action">{{ __('Log out') }}</button>
                    </div>

                    @if ($canDefer && ! ($reminder ?? false))
                        {{-- Red, and about the deadline rather than about the
                             prompt: what a user needs from this line is how
                             long they have, not that they will be asked
                             again. --}}
                        <p class="mt-4 text-center text-xs font-bold text-red-500">
                            {{ trans_choice(
                                '{0} Today is the last day: it becomes required tomorrow.|{1} It becomes required in a day.|[2,*] It becomes required in :count days.',
                                $daysLeft ?? 0,
                                ['count' => $daysLeft ?? 0],
                            ) }}
                        </p>
                    @endif
                </form>
            @endif
        </div>

        {{-- One logout form, referenced by whichever control needs it: forms
             cannot nest, and the way out belongs on the same line as the other
             ways out rather than stranded under them. --}}
        <form id="n2f-logout" method="POST" action="{{ route('nova.logout') }}" class="hidden">
            @csrf
        </form>
    </div>
@endsection
