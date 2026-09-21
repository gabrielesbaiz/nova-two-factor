<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Support\ClientTranslations;
use Workbench\App\Models\User;

/**
 * The pre-auth bundle renders messages after load, on pages with no Nova
 * instance to read translations from. A passkey failure answered an Italian
 * screen with "Your device could not complete the request." because those
 * strings lived as literals in the JavaScript.
 */
function clientKeysUsedInJavaScript(): array
{
    $sources = [
        __DIR__.'/../../resources/js/challenge.js',
        __DIR__.'/../../resources/js/support/webauthn.js',
    ];

    $keys = [];

    foreach ($sources as $source) {
        preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($source), $matches);

        foreach ($matches[1] as $key) {
            $keys[] = stripslashes($key);
        }
    }

    return array_values(array_unique($keys));
}

it('ships every string the pre-auth bundle asks for', function (): void {
    expect(array_diff(clientKeysUsedInJavaScript(), ClientTranslations::keys()))->toBe([]);
});

it('carries no string the bundle no longer uses', function (): void {
    expect(array_diff(ClientTranslations::keys(), clientKeysUsedInJavaScript()))->toBe([]);
});

it('translates every one of them in both catalogues', function (): void {
    $en = json_decode((string) file_get_contents(__DIR__.'/../../resources/lang/en.json'), true);
    $it = json_decode((string) file_get_contents(__DIR__.'/../../resources/lang/it.json'), true);

    foreach (ClientTranslations::keys() as $key) {
        expect($en)->toHaveKey($key);
        expect($it)->toHaveKey($key);
        expect($it[$key])->not->toBe($en[$key], "[{$key}] is still English in it.json");
    }
});

it('renders the bag into the pre-auth layout', function (): void {
    $user = User::factory()->create();
    $prefix = trim((string) config('nova.path'), '/');
    $segment = trim((string) config('nova-two-factor.routes.prefix', 'two-factor'), '/');

    $this->actingAs($user)
        ->get('/'.trim($prefix.'/'.$segment.'/challenge', '/'))
        ->assertOk()
        ->assertSee('window.__n2fLang', false)
        ->assertSee('Your device could not complete the request.', false);
});
