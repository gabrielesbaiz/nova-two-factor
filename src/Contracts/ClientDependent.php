<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Contracts;

/**
 * A factor whose usability depends on the client making the request.
 *
 * Distinct from `isAvailable()`, and deliberately so. `isAvailable()` answers
 * "may this method be offered", which is policy: a method switched off in
 * config is hidden from enrollment but still answers a challenge, because a
 * config change must never lock out somebody who already enrolled.
 *
 * This answers something stronger — "can this client complete the ceremony at
 * all" — and the honest answer there can be no. A passkey inside an embedded
 * web view is the case: the browser engine refuses the API outright, so the
 * offer is a button that cannot work. Only a factor that can be impossible
 * rather than merely unwanted implements this.
 */
interface ClientDependent
{
    public function supportsCurrentClient(): bool;
}
