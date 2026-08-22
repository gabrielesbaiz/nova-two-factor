<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Config;

/**
 * 1.x used a plain class here, which meant no `authorize()`, no validation
 * helpers and no middleware registration — and it showed.
 */
abstract class Controller extends BaseController
{
    protected function novaUser(): mixed
    {
        return request()->user(Config::get('nova.guard') ?: null);
    }

    protected function novaUserOrFail(): mixed
    {
        $user = $this->novaUser();

        abort_if($user === null, 401);

        return $user;
    }

    protected function novaPath(string $path = ''): string
    {
        $prefix = trim((string) Config::get('nova.path', '/nova'), '/');

        return '/'.trim($prefix.'/'.ltrim($path, '/'), '/');
    }
}
