<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Nova\Cards;

use Gabrielesbaiz\NovaTwoFactor\Support\Routing;
use Illuminate\Support\Facades\Config;
use Laravel\Nova\Card;

class TwoFactorSettingsPanel extends Card
{
    public $width = 'full';

    public $component = 'nova-two-factor-settings';

    public $height = 'dynamic';

    public function __construct()
    {
        parent::__construct();

        // Built here rather than assembled in JS: an application serving Nova
        // from the root has an empty path, and `/${''}/${prefix}/settings` is a
        // protocol-relative URL pointing at a host called `two-factor`.
        $this->withMeta(['endpoint' => $this->endpoint()]);
    }

    public function uriKey(): string
    {
        return 'two-factor-settings-panel';
    }

    protected function endpoint(): string
    {
        $nova = trim((string) Config::get('nova.path', '/nova'), '/');
        $prefix = trim(Routing::prefix(), '/');

        return '/'.trim($nova.'/'.$prefix.'/settings', '/');
    }
}
