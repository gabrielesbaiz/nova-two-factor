<?php

declare(strict_types=1);

/**
 * Documentation that drifts is worse than none: it tells operators a setting
 * applies when it does not. These assertions are cheap and they run in CI.
 */
it('documents no configuration key that does not exist', function (): void {
    $readme = (string) file_get_contents(__DIR__.'/../../README.md');
    $config = require __DIR__.'/../../config/nova-two-factor.php';

    /** Every key name anywhere in the config tree, not a hand-kept allowlist. */
    $names = static function (array $tree) use (&$names): array {
        $found = [];

        foreach ($tree as $key => $value) {
            if (is_string($key)) {
                $found[] = $key;
            }

            if (is_array($value)) {
                $found = [...$found, ...$names($value)];
            }
        }

        return $found;
    };

    $known = array_unique($names($config));

    // Keys the README shows as the head of a config array.
    preg_match_all("/'([a-z_]+)' => \[/", $readme, $matches);

    $unknown = array_values(array_diff(array_unique($matches[1] ?? []), $known));

    expect($unknown)->toBe([]);
});

it('documents every removed 1.x key in the upgrade guide', function (): void {
    $upgrade = (string) file_get_contents(__DIR__.'/../../UPGRADE.md');

    // Anyone upgrading needs to find their old key and be told what replaced it.
    foreach ([
        'mandatory',
        'reauthorize_urls',
        'reauthorize_timeout',
        'except_routes',
        'encrypt_google2fa_secrets',
        'use_google_qr_code_api',
        'user_model',
        'showin_sidebar',
        'ProtectWith2FA',
    ] as $key) {
        expect($upgrade)->toContain($key);
    }
});

it('names every shipped artisan command in the readme', function (): void {
    $readme = (string) file_get_contents(__DIR__.'/../../README.md');

    foreach (['doctor', 'reset', 'prune', 'upgrade'] as $command) {
        expect($readme)->toContain("nova-two-factor:{$command}");
    }
});

it('keeps the translation file in step with the strings in use', function (): void {
    $translations = json_decode(
        (string) file_get_contents(__DIR__.'/../../resources/lang/en.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($translations)->toBeArray()->not->toBeEmpty();

    // Every value must be a usable string, and placeholders must survive
    // translation — a dropped `:count` renders as literal text to the user.
    foreach ($translations as $key => $value) {
        expect($value)->toBeString()->not->toBe('');

        preg_match_all('/:([a-z_]+)/', (string) $key, $expected);
        preg_match_all('/:([a-z_]+)/', (string) $value, $actual);

        expect($actual[1])->toBe($expected[1], "placeholders differ for [{$key}]");
    }
});
