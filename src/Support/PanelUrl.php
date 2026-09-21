<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;

/**
 * Absolute links to the Nova panel, for mail.
 *
 * `url()` builds from `APP_URL`, which is the wrong host wherever Nova is
 * served from a domain of its own — an admin panel on `hub.example.com`
 * alongside a customer app on `app.example.com` is the ordinary arrangement,
 * and `APP_URL` names the second. A reminder that links a user to the customer
 * site to set up their *admin* second factor is worse than one with no link.
 *
 * Resolution order, most specific first:
 *
 *   1. a URL captured where the mail was sent from — the administrator was on
 *      the panel, so their host is the right one, and this survives queueing;
 *   2. `nova.domain`, when the host application has told Nova its own domain;
 *   3. `APP_URL`, which is right for a single-domain install and the only
 *      thing left for a job with no request behind it.
 */
final class PanelUrl
{
    public static function userSecurity(): string
    {
        return self::to('user-security');
    }

    public static function to(string $path = ''): string
    {
        $novaPath = trim((string) Config::get('nova.path', '/nova'), '/');
        $suffix = trim($novaPath.'/'.ltrim($path, '/'), '/');

        return rtrim(self::base(), '/').'/'.$suffix;
    }

    /**
     * The scheme and host the panel answers on.
     */
    protected static function base(): string
    {
        $domain = Config::get('nova.domain');

        if (is_string($domain) && $domain !== '') {
            // A configured domain is a host, not a URL: it carries no scheme,
            // and guessing http for it would downgrade every link in the mail.
            return str_contains($domain, '://')
                ? $domain
                : (Request::secure() ? 'https://' : 'http://').$domain;
        }

        // Inside a request — which is where an administrator sends a reminder
        // from — the host they are looking at is the panel's own.
        $current = rescue(static fn (): string => Request::getSchemeAndHttpHost(), '', report: false);

        return $current ?: rtrim((string) Config::get('app.url', ''), '/');
    }
}
