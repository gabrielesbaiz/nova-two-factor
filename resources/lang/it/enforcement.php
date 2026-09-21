<?php

declare(strict_types=1);

return [
    'modes' => [
        'optional' => 'Facoltativa',
        'encouraged' => 'Incoraggiata',
        'required' => 'Obbligatoria',
    ],

    'summaries' => [
        'optional' => 'A nessuno viene chiesto di attivarla.',
        'encouraged' => 'Agli utenti viene chiesto di attivarla, ma nulla viene bloccato.',
        'required' => 'Passato il periodo di tolleranza, Nova non è raggiungibile finché non viene attivata.',
    ],
];
