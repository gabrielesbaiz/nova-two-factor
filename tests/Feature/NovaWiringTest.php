<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireFreshTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactorEnrollment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * Both assertions here cover failures that are invisible from the inside: the
 * package looks installed, `doctor` can be made to agree, and Nova is simply
 * unguarded.
 */
it('registers its own routes under Nova path', function (): void {
    // Nothing else loads routes/nova.php — a host application never sees the
    // file — so without the provider doing it the challenge middleware
    // redirects to a URL that 404s.
    foreach ([
        'nova-two-factor.challenge',
        'nova-two-factor.required',
        'nova-two-factor.step-up',
        'nova-two-factor.methods.index',
        'nova-two-factor.recovery-codes.index',
    ] as $name) {
        expect(Route::has($name))->toBeTrue("route [{$name}] is not registered");
    }

    $prefix = trim((string) config('nova.path', '/nova'), '/');

    expect(Route::getRoutes()->getByName('nova-two-factor.challenge')->uri())
        ->toBe(trim($prefix.'/two-factor/challenge', '/'));
});

it('guards the router middleware groups Nova actually uses', function (): void {
    // Appending to config('nova.middleware') is not enough: Nova compiles both
    // config arrays into router groups in its own provider's boot, before this
    // package boots, and never reads the config again.
    $groups = app('router')->getMiddlewareGroups();

    $guards = [RequireTwoFactorEnrollment::class, RequireTwoFactor::class, RequireFreshTwoFactor::class];

    foreach ($guards as $guard) {
        expect($groups['nova'] ?? [])->toContain($guard);
    }

    // `nova:api` nests `nova`, so the guards must reach API routes through it
    // rather than being listed twice and running twice per request.
    expect($groups['nova:api'] ?? [])->toContain('nova');

    foreach ($guards as $guard) {
        expect($groups['nova:api'] ?? [])->not->toContain($guard);
    }
});

it('moves every path it owns when the route prefix is changed', function (): void {
    // A host serving Nova at the domain root needs this: the default segment
    // lands on `/two-factor/*`, where an application route of the same name
    // silently wins or loses the registration.
    config()->set('nova-two-factor.routes.prefix', 'admin-2fa');

    $enforcement = app(Gabrielesbaiz\NovaTwoFactor\Support\Enforcement::class);

    expect(Gabrielesbaiz\NovaTwoFactor\Support\Routing::path('challenge'))
        ->toContain('/admin-2fa/challenge')
        ->and($enforcement->exceptPatterns())
        ->toContain(trim(trim((string) config('nova.path'), '/').'/admin-2fa', '/').'/*');
});

/**
 * Nova's SPA reads `Nova.config('translations')`, which is Nova's own bag — a
 * package's `lang/*.json` never reaches it. Without registering ours, the Blade
 * screens were Italian while the security card, from the same package, stayed
 * English.
 */
it('hands Nova the package catalogue for the active locale', function (): void {
    app()->setLocale('it');

    (new Gabrielesbaiz\NovaTwoFactor\ToolServiceProvider(app()))->boot();

    $translations = Laravel\Nova\Nova::allTranslations();

    expect($translations)->toHaveKey('Not set up')
        ->and($translations['Not set up'])->toBe('Non configurato')
        ->and($translations)->toHaveKey('Another passkey');

    app()->setLocale('en');
});

/**
 * A host that ever ran `vendor:publish` froze the catalogue at that moment:
 * every string added since read back as its English key, in a UI whose other
 * half was translated. The package supplies the floor; the application's file
 * overrides it key by key.
 */
it('merges the published catalogue over the package one', function (): void {
    $override = lang_path('vendor/nova-two-factor/it.json');

    File::ensureDirectoryExists(dirname($override));
    File::put($override, json_encode(['Rename' => 'Ribattezza'], JSON_UNESCAPED_UNICODE));

    try {
        app()->setLocale('it');

        (new Gabrielesbaiz\NovaTwoFactor\ToolServiceProvider(app()))->boot();

        $translations = Laravel\Nova\Nova::allTranslations();

        // The host's wording wins…
        expect($translations['Rename'])->toBe('Ribattezza')
            // …and everything it never mentioned is still there.
            ->and($translations['Not set up'])->toBe('Non configurato');
    } finally {
        File::delete($override);
        app()->setLocale('en');
    }
});
