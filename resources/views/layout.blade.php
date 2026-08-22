{{--
    Layout for the pre-authentication screens.

    These pages cannot be Inertia pages registered by a tool: Nova resolves the
    initial Inertia component before it runs any tool script, so a tool-registered
    page renders a spinner forever on a cold load — and a challenge is always a
    cold load.

    Loading Nova's own compiled stylesheet is what keeps them pixel-native: the
    same tokens, the same form primitives, the same dark mode, with no second
    Tailwind build to drift out of step.
--}}
<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="h-full font-sans antialiased"
    dir="{{ __('nova::ui.dir') === 'rtl' ? 'rtl' : 'ltr' }}"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('Two-factor authentication')) &middot; {{ config('app.name') }}</title>

    <link rel="stylesheet" href="{{ mix('app.css', 'vendor/nova') }}">
    @if (file_exists(public_path('vendor/nova-two-factor/css/tool.css')))
        <link rel="stylesheet" href="{{ asset('vendor/nova-two-factor/css/tool.css') }}">
    @endif

    {{-- Nova stores the theme choice in localStorage. Applying it before first
         paint is what avoids a white flash on the way into a dark dashboard. --}}
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('nova.appearance')
                var dark = stored === 'dark' || (stored !== 'light' &&
                    window.matchMedia('(prefers-color-scheme: dark)').matches)
                if (dark) document.documentElement.classList.add('dark')
            } catch (e) {}
        })()
    </script>

    <style>
        :root { @foreach (\Laravel\Nova\Nova::brandColors() as $key => $value) --colors-{{ $key }}: {{ $value }}; @endforeach }
    </style>
</head>
<body class="min-h-full bg-gray-100 dark:bg-gray-900 text-gray-500 dark:text-gray-400 text-sm font-medium">
    <div class="min-h-dvh flex flex-col items-center justify-center px-4 py-8">
        <div class="mb-6">
            @if (\Laravel\Nova\Nova::$brandLogo)
                <div class="h-8 [&>svg]:h-8 [&>svg]:max-w-full text-gray-900 dark:text-gray-100">
                    {!! \Laravel\Nova\Nova::brandLogo() !!}
                </div>
            @else
                <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ config('app.name') }}</h1>
            @endif
        </div>

        <main class="w-full max-w-[25rem]">
            @yield('content')
        </main>

        <footer class="mt-6 text-xs text-gray-400 dark:text-gray-500 text-center">
            @yield('footer')
        </footer>
    </div>

    @if (file_exists(public_path('vendor/nova-two-factor/js/challenge.js')))
        <script src="{{ asset('vendor/nova-two-factor/js/challenge.js') }}" defer></script>
    @endif
</body>
</html>
