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

    {{-- Nova's stylesheet is what makes these pages look like the dashboard,
         but `mix()` throws when the manifest is absent — an application that has
         not run `nova:publish` would get a fatal error on the challenge screen
         instead of an unstyled one. Degrading is the right failure here: the
         pages are readable and usable without it. --}}
    @if (file_exists(public_path('vendor/nova/mix-manifest.json')))
        <link rel="stylesheet" href="{{ mix('app.css', 'vendor/nova') }}">
    @endif
    {{-- Cache-busted on the published file's own mtime. A plain `asset()` URL
         never changes, so a browser that cached the old bundle keeps serving it
         after every package update — which looks exactly like a fix that did
         not work. --}}
    @php($n2fStyle = public_path('vendor/nova-two-factor/css/tool.css'))
    @if (file_exists($n2fStyle))
        <link rel="stylesheet" href="{{ asset('vendor/nova-two-factor/css/tool.css').'?id='.filemtime($n2fStyle) }}">
    @endif

    {{-- Nova's theme switcher writes the user's choice to `localStorage.novaTheme`
         ('light' or 'dark'; the key is removed for "system"), and toggles the
         `dark` class on <html>. These pages read the same key, so the screen a
         user is redirected to looks like the dashboard they just left.

         Applying it before first paint is what avoids a white flash on the way
         into a dark dashboard, and the media listener keeps "system" honest if
         the OS flips while the page is open. --}}
    <script>
        (function () {
            var media = window.matchMedia('(prefers-color-scheme: dark)')

            var apply = function () {
                var stored = null

                try {
                    stored = localStorage.getItem('novaTheme')
                } catch (e) {}

                var dark = stored === 'dark' || (stored !== 'light' && media.matches)

                document.documentElement.classList.toggle('dark', dark)
            }

            apply()
            media.addEventListener('change', apply)
        })()
    </script>

    {{-- Nova's own brand-colour overrides, emitted by Nova itself.

         Hand-rolling this loop wrote `--colors-500` — Nova's variables are
         `--colors-primary-500`, so every override missed and these pages fell
         back to the stock sky palette compiled into app.css while the dashboard
         next door used the application's real brand colour. --}}
    <style>{!! \Laravel\Nova\Nova::brandColorsCSS() !!}</style>

    {{-- Strings the pre-auth bundle writes into the page after load. There is no
         Nova instance here to read `Nova.config('translations')` from, so the
         translations travel with the page or the script speaks English on an
         otherwise translated screen. --}}
    <script>
        window.__n2fLang = @json(\Gabrielesbaiz\NovaTwoFactor\Support\ClientTranslations::all())
    </script>
</head>
<body class="min-h-full bg-gray-100 dark:bg-gray-900 text-gray-500 dark:text-gray-400 text-sm font-medium">
    <div class="min-h-dvh flex flex-col items-center justify-center px-4 py-8">
        <div class="mb-6">
{{--
                Read from configuration rather than through Nova. `Nova::$brandLogo`
                and `Nova::brandLogo()` are Nova 4 APIs that do not exist in Nova 5,
                and reaching for an undeclared static property is a fatal error, not
                a null — so every page extending this layout died on render.
            --}}
            @php($brandLogo = config('nova.brand.logo'))

            @if (is_string($brandLogo) && $brandLogo !== '' && is_file($brandLogo))
                <div class="h-8 [&>svg]:h-8 [&>svg]:max-w-full text-gray-900 dark:text-gray-100">
                    {!! file_get_contents($brandLogo) !!}
                </div>
            @else
                <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ \Laravel\Nova\Nova::name() ?? config('app.name') }}</h1>
            @endif
        </div>

        <main class="w-full max-w-[25rem]">
            @yield('content')
        </main>

        <footer class="mt-6 text-xs text-gray-400 dark:text-gray-500 text-center">
            @yield('footer')
        </footer>
    </div>

    @php($n2fScript = public_path('vendor/nova-two-factor/js/challenge.js'))
    @if (file_exists($n2fScript))
        <script src="{{ asset('vendor/nova-two-factor/js/challenge.js').'?id='.filemtime($n2fScript) }}" defer></script>
    @endif
</body>
</html>
