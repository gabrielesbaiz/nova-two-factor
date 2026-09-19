<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Support;

use Illuminate\Support\Facades\Config;

/**
 * One place that knows where this package's pages live.
 *
 * The paths are built rather than resolved through `route()` because the
 * middleware has to produce them for a request that is being halted, and
 * because Nova serves its own shell from a configurable path that the
 * enforcement except-list has to match as a pattern.
 *
 * The segment is configurable for a case that is common in multi-host
 * applications: Nova served at the domain root (`nova.path` empty), where a
 * fixed `two-factor` segment sits at `/two-factor/*` and can collide with the
 * host's own routes of that name — silently, since the first registration wins
 * and the loser simply disappears from the route table.
 */
final class Routing
{
    /**
     * The package's own path segment, below Nova's path.
     */
    public static function prefix(): string
    {
        $prefix = trim((string) Config::get('nova-two-factor.routes.prefix', 'two-factor'), '/');

        return $prefix === '' ? 'two-factor' : $prefix;
    }

    /**
     * Nova's path segment, empty when Nova is served at the domain root.
     */
    public static function novaPrefix(): string
    {
        return trim((string) Config::get('nova.path', '/nova'), '/');
    }

    /**
     * An absolute path to one of the package's pages.
     */
    public static function path(string $suffix = ''): string
    {
        return '/'.trim(self::novaPrefix().'/'.self::prefix().'/'.ltrim($suffix, '/'), '/');
    }

    /**
     * The `Request::is()` patterns covering every page the package owns.
     *
     * @return array<int, string>
     */
    public static function patterns(): array
    {
        $base = trim(self::novaPrefix().'/'.self::prefix(), '/');

        return [$base, $base.'/*'];
    }
}
