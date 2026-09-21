<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Nova\Cards;

use Gabrielesbaiz\NovaTwoFactor\Support\Routing;
use Illuminate\Support\Facades\Config;
use Laravel\Nova\Card;

/**
 * The compliance rollup and the queue of people to chase.
 *
 * A card rather than a tool page: cards are resolved by name at render time on
 * a page Nova itself owns, so this survives a cold load — a tool-registered
 * Inertia page does not.
 */
class TwoFactorComplianceOverview extends Card
{
    public $width = 'full';

    public $component = 'nova-two-factor-compliance';

    public $height = 'dynamic';

    public function __construct()
    {
        parent::__construct();

        // The whole endpoint, built server-side.
        //
        // Assembling it in JS from `Nova.config('path')` and the prefix looks
        // tidier and is wrong: an app that serves Nova from the root has an
        // empty path, and `/${''}/${prefix}/compliance` is `//two-factor/…` — a
        // protocol-relative URL aimed at a host called `two-factor`.
        $this->withMeta(['endpoint' => $this->endpoint()]);
    }

    public function uriKey(): string
    {
        return 'two-factor-compliance-overview';
    }

    protected function endpoint(): string
    {
        $nova = trim((string) Config::get('nova.path', '/nova'), '/');
        $prefix = trim(Routing::prefix(), '/');

        return '/'.trim($nova.'/'.$prefix.'/compliance', '/');
    }
}
