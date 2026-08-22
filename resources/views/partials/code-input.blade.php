{{--
    One real input beneath N decorative boxes.

    A single field is what makes iOS, Android and password-manager one-time-code
    autofill work; six separate inputs break all three, and give a screen reader
    six unlabelled fields to announce. The boxes are aria-hidden decoration and
    the input is the accessible object.

    The group is forced to LTR regardless of page direction: a numeric code is
    entered left to right in every locale, and letting it mirror is a real bug.
--}}
@props([
    'length' => 6,
    'name' => 'code',
    'label' => null,
    'autofocus' => true,
    'mode' => 'numeric',
])
<div class="n2f-otp" data-n2f-otp data-length="{{ $length }}" dir="ltr">
    <label for="n2f-{{ $name }}" class="sr-only">
        {{ $label ?? __('Verification code') }}
    </label>

    <input
        id="n2f-{{ $name }}"
        name="{{ $name }}"
        type="text"
        class="n2f-otp-input"
        @if ($mode === 'numeric') inputmode="numeric" pattern="[0-9]*" @endif
        autocomplete="one-time-code"
        autocapitalize="off"
        autocorrect="off"
        spellcheck="false"
        maxlength="{{ $length }}"
        aria-describedby="n2f-{{ $name }}-hint"
        @if ($autofocus) autofocus @endif
        required
    >

    <div class="n2f-otp-boxes" aria-hidden="true">
        @for ($i = 0; $i < $length; $i++)
            <span class="n2f-otp-box" data-index="{{ $i }}"></span>
        @endfor
    </div>

    <p id="n2f-{{ $name }}-hint" class="sr-only">
        {{ trans_choice('{1} :count digit|[2,*] :count digits', $length, ['count' => $length]) }}
    </p>
</div>
