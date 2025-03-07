<?php

namespace Gabrielesbaiz\NovaTwoFactor\Http\Middleware;

use Closure;
use Laravel\Nova\Nova;
use Gabrielesbaiz\NovaTwoFactor\NovaTwoFactor;

class Authorize
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  Closure                   $next
     * @return \Illuminate\Http\Response
     */
    public function handle($request, $next)
    {
        $tool = collect(Nova::registeredTools())->first([$this, 'matchesTool']);

        return optional($tool)->authorize($request) ? $next($request) : abort(403);
    }

    /**
     * Determine whether this tool belongs to the package.
     *
     * @param  \Laravel\Nova\Tool $tool
     * @return bool
     */
    public function matchesTool($tool)
    {
        return $tool instanceof NovaTwoFactor;
    }
}
