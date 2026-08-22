@extends('nova-two-factor::layout')

@section('title', __('Two-factor authentication required'))

@section('content')
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-8">
        <h2 class="text-xl font-bold mb-2 text-gray-900 dark:text-gray-100">
            {{ __('Your organization requires two-factor authentication') }}
        </h2>

        @if ($withinGrace && $graceEndsAt)
            <p class="mb-6 text-sm" data-n2f-countdown data-deadline="{{ $graceEndsAt->toIso8601String() }}">
                {{ __('You have until :date to set it up.', ['date' => $graceEndsAt->isoFormat('LLL')]) }}
            </p>
        @else
            <p class="mb-6 text-sm text-red-500">
                {{ __('Set up a method to continue.') }}
            </p>
        @endif

        {{-- Ranked strongest first, each with an honest tradeoff rather than
             marketing copy, so the choice is informed. --}}
        <div class="space-y-2">
            @foreach ($drivers as $driver)
                <a href="{{ rtrim(config('nova.path'), '/') }}/user-security#add-{{ $driver->type()->value }}" class="n2f-method-row">
                    <span class="n2f-method-name">
                        {{ $driver->type()->label() }}
                        @if ($driver->type()->isPhishingResistant())
                            <span class="n2f-badge n2f-badge-ok">{{ __('Recommended') }}</span>
                        @endif
                    </span>
                    <span class="n2f-method-meta">
                        @switch($driver->type()->value)
                            @case('webauthn')
                                {{ __('Fingerprint, face, or a security key. Cannot be phished.') }}
                                @break
                            @case('totp')
                                {{ __('A code from an app on your phone. Works offline.') }}
                                @break
                            @case('email')
                                {{ __('A code by email. Weakest option — anyone with your inbox has your second factor.') }}
                                @break
                        @endswitch
                    </span>
                </a>
            @endforeach
        </div>

        @if ($canDefer)
            <a href="{{ rtrim(config('nova.path'), '/') }}" class="block mt-6 text-center text-xs font-bold text-gray-500 dark:text-gray-400">
                {{ __('Set up later') }}
            </a>
        @endif
    </div>
@endsection

@section('footer')
    {{-- Even with no way forward, there is always a way out and someone to ask.
         A dead end with no explanation is what makes enforcement screens hated. --}}
    <form method="POST" action="{{ route('nova.logout') }}" class="inline">
        @csrf
        <button type="submit" class="underline">{{ __('Log out') }}</button>
    </form>
@endsection
