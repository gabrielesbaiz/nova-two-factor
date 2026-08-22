@extends('nova-two-factor::layout')

@section('title', __('Confirm your identity'))

@section('content')
    <form
        method="POST"
        action="{{ route('nova-two-factor.step-up.store') }}"
        class="bg-white dark:bg-gray-800 shadow rounded-lg p-8"
        data-n2f-challenge
        data-prepare-url="{{ route('nova-two-factor.step-up.prepare') }}"
        data-step-up="1"
    >
        @csrf
        <input type="hidden" name="scope" value="{{ $scope }}">
        <input type="hidden" name="intended" value="{{ $intended }}">

        <h2 class="text-lg font-bold mb-2 text-gray-900 dark:text-gray-100">
            {{ __('Confirm your identity') }}
        </h2>

        {{-- Naming the action is the difference between a prompt people read and
             one they learn to approve reflexively. --}}
        <p class="mb-6 text-sm">
            {{ __('This action needs a fresh check: :scope', ['scope' => $scope]) }}
        </p>

        <div data-n2f-error role="alert" class="hidden mb-4 text-sm text-red-500"></div>
        <div data-n2f-status role="status" class="sr-only"></div>

        @if ($default)
            <input type="hidden" name="method_id" value="{{ $default->id }}" data-n2f-method-id>
        @endif

        <div data-n2f-code-field class="{{ $default && $default->type->isPhishingResistant() ? 'hidden' : '' }} mb-6">
            @include('nova-two-factor::partials.code-input', ['length' => 6, 'name' => 'code'])
        </div>

        <div data-n2f-passkey class="hidden mb-6">
            <button type="button" class="n2f-btn n2f-btn-primary w-full" data-n2f-passkey-trigger>
                {{ __('Confirm with your passkey') }}
            </button>
        </div>

        <div class="flex gap-2">
            <a href="{{ $intended }}" class="n2f-btn n2f-btn-ghost flex-1 text-center">{{ __('Cancel') }}</a>
            <button type="submit" class="n2f-btn n2f-btn-primary flex-1" data-n2f-submit>{{ __('Confirm') }}</button>
        </div>

        {{-- Never offers to remember the device: freshness is the entire point. --}}
        <p class="mt-4 text-xs text-gray-400 dark:text-gray-500 text-center">
            {{ __('Valid for this action only.') }}
        </p>
    </form>
@endsection
