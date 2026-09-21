<?php

declare(strict_types=1);

/*
 * Namespaced on purpose.
 *
 * These read as ordinary words — "Required", "Optional" — and an application's
 * own `lang/{locale}.json` wins over a package's for a plain key. A host that
 * translates "Required" as "Richiesto" for its form fields would silently
 * rename this package's enforcement mode, which is the one string on the
 * compliance page that must mean exactly one thing.
 */

return [
    'modes' => [
        'optional' => 'Optional',
        'encouraged' => 'Encouraged',
        'required' => 'Mandatory',
    ],

    'summaries' => [
        'optional' => 'Nobody is asked to enrol.',
        'encouraged' => 'Users are asked to enrol, but nothing is blocked.',
        'required' => 'Nova is unreachable until enrolled, once the grace window has passed.',
    ],
];
