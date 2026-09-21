<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Console;

use Gabrielesbaiz\NovaTwoFactor\Auth\SupersedeFortifyTwoFactorChallenge;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactorEnrollment;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\RelyingParty;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Features;
use Throwable;

/**
 * Checks the things that fail silently.
 *
 * Almost every 1.x report was a configuration problem that produced no error at
 * all: enforcement enabled but its middleware never registered, secrets stored
 * in plaintext because a flag defaulted off, a Nova path that made the redirect
 * loop. Each of those is one assertion here, and the command exits non-zero so
 * it can sit in a deployment pipeline.
 */
class DoctorCommand extends Command
{
    protected $signature = 'nova-two-factor:doctor';

    protected $description = 'Check that two-factor authentication is correctly configured';

    /** @var array<int, array{status: string, check: string, detail: string}> */
    protected array $results = [];

    protected bool $failed = false;

    public function handle(): int
    {
        $this->components->info('Checking two-factor authentication…');

        // A disabled install is a valid state, not a broken one. An application
        // serving several Nova panels from one codebase turns the package off
        // per domain, and reporting that as a misconfiguration would fail every
        // deploy pipeline it is meant to protect.
        if (! Config::get('nova-two-factor.enabled', true)) {
            $this->components->warn('Two-factor authentication is disabled (nova-two-factor.enabled). Nothing to check.');

            return self::SUCCESS;
        }

        $this->checkAppKey();
        $this->checkTables();
        $this->checkMiddleware();
        $this->checkFortifyChallenge();
        $this->checkUserSecurityPage();
        $this->checkMethods();
        $this->checkRecoveryCodes();
        $this->checkWebAuthn();
        $this->checkEnforcement();
        $this->checkExceptPatterns();
        $this->checkAdminAccess();
        $this->checkCookieSecurity();
        $this->checkLegacyConfig();
        $this->checkAssets();

        $this->newLine();
        $this->table(['', 'Check', 'Detail'], array_map(
            static fn (array $row): array => [$row['status'], $row['check'], $row['detail']],
            $this->results,
        ));

        if ($this->failed) {
            $this->newLine();
            $this->components->error('Two-factor authentication is not correctly configured.');

            return self::FAILURE;
        }

        $this->components->info('All checks passed.');

        return self::SUCCESS;
    }

    protected function ok(string $check, string $detail = ''): void
    {
        $this->results[] = ['status' => '<fg=green>PASS</>', 'check' => $check, 'detail' => $detail];
    }

    protected function caution(string $check, string $detail): void
    {
        $this->results[] = ['status' => '<fg=yellow>WARN</>', 'check' => $check, 'detail' => $detail];
    }

    protected function problem(string $check, string $detail): void
    {
        $this->failed = true;
        $this->results[] = ['status' => '<fg=red>FAIL</>', 'check' => $check, 'detail' => $detail];
    }

    protected function checkAppKey(): void
    {
        $key = (string) Config::get('app.key');

        // Every secret, credential and OTP hash in this package is bound to the
        // application key. Losing or rotating it invalidates all of them.
        $key === ''
            ? $this->problem('Application key', 'APP_KEY is not set. Encrypted secrets cannot be read or written.')
            : $this->ok('Application key', 'Set.');
    }

    protected function checkTables(): void
    {
        $missing = [];

        foreach (Config::get('nova-two-factor.database.tables', []) as $table) {
            if (! Schema::connection(Config::get('nova-two-factor.database.connection'))->hasTable((string) $table)) {
                $missing[] = (string) $table;
            }
        }

        $missing === []
            ? $this->ok('Database tables', 'All present.')
            : $this->problem('Database tables', 'Missing: '.implode(', ', $missing).'. Run `php artisan migrate`.');
    }

    /**
     * The check that would have caught the single most common 1.x install bug:
     * `mandatory => true` with the middleware never registered, which failed
     * completely silently.
     */
    protected function checkMiddleware(): void
    {
        // Asserted against the router, not config('nova.middleware'). Nova
        // compiles that config into its router groups during its own provider's
        // boot and never reads it again, so a guard present only in the config
        // array is a guard that never runs — which is precisely the silent
        // failure this check exists to catch.
        $groups = $this->laravel->make('router')->getMiddlewareGroups();

        foreach (['nova' => 'page', 'nova:api' => 'API'] as $group => $label) {
            if (! isset($groups[$group])) {
                $this->problem("Middleware ({$label})", "Nova's [{$group}] middleware group does not exist.");

                continue;
            }

            $stack = $this->resolveGroup($groups, $group);

            $missing = array_values(array_filter(
                [RequireTwoFactorEnrollment::class, RequireTwoFactor::class],
                static fn (string $guard): bool => ! in_array($guard, $stack, true),
            ));

            if ($missing === []) {
                $this->ok("Middleware ({$label})", 'Challenge and enrollment guards registered.');

                continue;
            }

            $this->problem(
                "Middleware ({$label})",
                "Missing from the [{$group}] router group. Two-factor can be bypassed through the {$label} routes.",
            );
        }
    }

    /**
     * Flatten one middleware group, following the groups it nests.
     *
     * `nova:api` carries the `nova` group by name rather than repeating its
     * entries, so a check that does not follow the reference reports a guarded
     * stack as unguarded.
     *
     * @param  array<string, array<int, string>>  $groups
     * @param  array<int, string>  $seen
     * @return array<int, string>
     */
    protected function resolveGroup(array $groups, string $group, array $seen = []): array
    {
        if (in_array($group, $seen, true)) {
            return [];
        }

        $seen[] = $group;
        $stack = [];

        foreach ($groups[$group] ?? [] as $entry) {
            $stack = isset($groups[$entry])
                ? [...$stack, ...$this->resolveGroup($groups, $entry, $seen)]
                : [...$stack, $entry];
        }

        return $stack;
    }

    /**
     * Fortify's divert is the one conflict that shows up as a *working* login
     * to the wrong challenge screen, so nothing looks broken and no error is
     * logged — the operator just never sees this package's methods.
     */
    protected function checkFortifyChallenge(): void
    {
        if (! Config::get('nova-two-factor.fortify.supersede_challenge', true)) {
            $this->caution(
                'Fortify challenge',
                'Not superseded. Users holding a legacy users.two_factor_secret reach Fortify\'s challenge, not this package\'s.',
            );

            return;
        }

        try {
            $action = $this->laravel->make(RedirectIfTwoFactorAuthenticatable::class);
        } catch (Throwable $exception) {
            // Resolving the action pulls in the configured guard, so a broken
            // guard surfaces here rather than at the next login attempt.
            $this->problem('Fortify challenge', 'Could not be resolved: '.$exception->getMessage());

            return;
        }

        $action instanceof SupersedeFortifyTwoFactorChallenge
            ? $this->ok('Fortify challenge', 'Superseded — this package owns the Nova login challenge.')
            : $this->problem(
                'Fortify challenge',
                'Resolves to ['.$action::class.']. Something rebound it after this package booted; the Nova login challenge is not ours.',
            );
    }

    /**
     * The management UI has no page of its own.
     *
     * It replaces Nova's globally registered `UserSecurityTwoFactorAuthentication`
     * component on Nova's own `/user-security` page — which Nova only routes when
     * `Features::hasSecurityFeatures()` is true. With every Nova Fortify feature
     * off, that route does not exist: the security card never renders, and the
     * enforcement screen's "set this up" links lead nowhere.
     */
    protected function checkUserSecurityPage(): void
    {
        if (Route::has('nova.pages.user-security')) {
            $this->ok('Nova user-security page', 'Routed — the security card has somewhere to render.');

            return;
        }

        $this->problem(
            'Nova user-security page',
            Features::hasSecurityFeatures()
                ? 'Not routed, although Fortify reports security features. Check that Nova::fortify() runs before routes are registered.'
                : 'Not routed: no Nova Fortify security feature is enabled, so Nova never registers /user-security. '
                    .'Enable at least one in NovaServiceProvider, e.g. Nova::fortify()->features([Features::updatePasswords()]).',
        );
    }

    protected function checkMethods(): void
    {
        $enabled = collect(['totp', 'webauthn', 'email'])
            ->filter(static fn (string $type): bool => (bool) Config::get("nova-two-factor.methods.{$type}.enabled", false));

        if ($enabled->isEmpty()) {
            $this->problem('Methods', 'Every method is disabled. Nobody can enrol.');

            return;
        }

        $this->ok('Methods', $enabled->implode(', '));

        if (! $enabled->contains('webauthn') && ! $enabled->contains('totp')) {
            $this->caution('Method strength', 'Only email codes are enabled — the weakest option, and phishable.');
        }
    }

    /**
     * The entropy that lets recovery codes be stored unkeyed.
     *
     * Every other secret here is keyed on `APP_KEY`; these are a plain
     * SHA-256, on the argument that twenty base62 characters is beyond search
     * whatever you hash them with. That argument is only as good as the length,
     * so the length is checked rather than assumed.
     */
    protected function checkRecoveryCodes(): void
    {
        $configured = (int) Config::get('nova-two-factor.recovery_codes.length', 10);

        // What the generator will actually use: it floors at 6 per half, so a
        // smaller number in config is a misconfiguration that silently does
        // nothing rather than a weaker code.
        $effective = max(6, $configured);

        // base62, two halves.
        $bits = (int) floor($effective * 2 * log(62, 2));

        if ($configured < 6) {
            $this->caution('Recovery codes', sprintf(
                'recovery_codes.length is %d, below the floor of 6; %d is used instead. Set it to 6 or more so the config says what happens.',
                $configured,
                $effective,
            ));

            return;
        }

        if ($bits < 96) {
            $this->caution('Recovery codes', sprintf(
                'recovery_codes.length %d gives ~%d bits per code. Stored unkeyed, so keep this at the default of 10 (~119 bits) unless you have a reason.',
                $configured,
                $bits,
            ));

            return;
        }

        $this->ok('Recovery codes', sprintf('%d per code, ~%d bits.', $effective * 2, $bits));
    }

    protected function checkWebAuthn(): void
    {
        if (! Config::get('nova-two-factor.methods.webauthn.enabled', true)) {
            $this->ok('Passkeys', 'Disabled.');

            return;
        }

        try {
            $rp = RelyingParty::resolve();
        } catch (Throwable $exception) {
            $this->problem('Passkeys', $exception->getMessage());

            return;
        }

        $this->ok('Passkeys', "Relying party [{$rp->id}], origins: ".implode(', ', $rp->origins));

        $appUrl = (string) Config::get('app.url');

        if ($rp->requiresSecureContext() && ! str_starts_with($appUrl, 'https://')) {
            // Browsers refuse the ceremony outright outside a secure context,
            // and the resulting error names nothing useful.
            $this->problem('Passkeys', "app.url is [{$appUrl}]. Passkeys require https outside localhost.");
        }

        if (! extension_loaded('sodium')) {
            $this->caution('Passkeys', 'ext-sodium is missing, so Ed25519 authenticators cannot be verified.');
        }
    }

    protected function checkEnforcement(): void
    {
        $mode = (string) Config::get('nova-two-factor.enforcement.mode', 'optional');
        $gate = Config::get('nova-two-factor.enforcement.gate');

        $this->ok('Enforcement', "Mode [{$mode}], grace ".Config::get('nova-two-factor.enforcement.grace_days').' days.');

        if (is_string($gate) && $gate !== '' && ! app('Illuminate\Contracts\Auth\Access\Gate')->has($gate)) {
            $this->problem('Enforcement gate', "Gate [{$gate}] is configured but not defined, so nobody is in scope.");
        }
    }

    /**
     * Who can read the compliance figures, and who can change the policy.
     *
     * Both surfaces fall back to "any authenticated Nova user" when no gate is
     * named. That is a deliberate default for a single-administrator panel, and
     * the wrong one everywhere else — in most applications everyone on staff
     * can reach Nova, and the settings page can then be used to stand
     * two-factor authentication down.
     */
    /**
     * What enforcement is told to leave alone.
     *
     * The one setting here with no symptom when it is wrong. A pattern that is
     * too broad does not error, does not log and does not break the login
     * flow — it simply means requests that should have been stopped are not,
     * and the check above, which reports the middleware as registered, makes
     * that read as healthy.
     *
     * So this does two things: prints what the middleware actually matches on,
     * because there is otherwise no way to see it, and refuses the patterns
     * that void the control rather than widen it.
     */
    protected function checkExceptPatterns(): void
    {
        $effective = app(Enforcement::class)->exceptPatterns();

        $configured = Config::get('nova-two-factor.enforcement.except', []);
        $configured = is_array($configured) ? array_map('strval', $configured) : [];

        // Judged by consequence, not by spelling: `*`, `nova*` and `nova-api/*`
        // are three different mistakes with one outcome, and listing syntax to
        // ban would only ever be a list of the forms somebody thought of.
        $mustStayGated = $this->pathsThatMustStayGated();

        $voided = [];

        foreach ($effective as $pattern) {
            foreach ($mustStayGated as $label => $path) {
                if (Str::is($pattern, $path)) {
                    $voided[$pattern][] = $label;
                }
            }
        }

        if ($voided !== []) {
            foreach ($voided as $pattern => $labels) {
                $this->problem(
                    'Enforcement exceptions',
                    sprintf(
                        'Pattern [%s] leaves %s unguarded, so enforcement does not apply there.',
                        $pattern,
                        implode(' and ', array_unique($labels)),
                    ),
                );
            }

            return;
        }

        $this->ok('Enforcement exceptions', sprintf(
            '%d patterns, %d of them yours.',
            count($effective),
            count($configured),
        ));

        // Printed rather than summarised: the point of the check is that nobody
        // can otherwise see this list, and a count is not seeing it.
        if ($configured !== []) {
            $this->newLine();
            $this->components->info('Enforcement leaves these open, by your configuration:');

            foreach ($configured as $pattern) {
                $this->line('  - '.$pattern);
            }
        }
    }

    /**
     * Paths where enforcement must always apply, whatever the list says.
     *
     * The dashboard itself, because reaching it is the thing being gated, and
     * the API behind it, because guarding pages while leaving `nova-api/*` open
     * is exactly how 1.x could be sidestepped.
     *
     * @return array<string, string>
     */
    protected function pathsThatMustStayGated(): array
    {
        $novaPath = trim((string) Config::get('nova.path', '/nova'), '/');
        $prefix = $novaPath === '' ? '' : $novaPath.'/';

        return [
            'the dashboard' => $novaPath === '' ? '/' : $novaPath,
            'resource pages' => $prefix.'resources/users',
            'the Nova API' => 'nova-api/users',
        ];
    }

    protected function checkAdminAccess(): void
    {
        $gate = Config::get('nova-two-factor.nova.admin_gate');
        $named = is_string($gate) && $gate !== '';
        $editable = (bool) Config::get('nova-two-factor.settings.editable', false);

        if ($named && ! app('Illuminate\Contracts\Auth\Access\Gate')->has($gate)) {
            // The shipped default, and the state a fresh install is in: the
            // ability does not exist, `Gate::allows()` denies, and the admin
            // pages stay closed. Not a failure — closed is the safe end, and
            // enrollment and the challenge work regardless — but it does need
            // saying, because the menu entry simply will not appear.
            $this->caution('Admin gate', sprintf(
                'Gate [%s] is not defined, so the admin pages are closed to everyone. Define it with Gate::define, or set nova.admin_gate to null to open them to every Nova user.',
                $gate,
            ));

            return;
        }

        if ($named) {
            $this->ok('Admin gate', "Compliance and settings are gated on [{$gate}].");

            return;
        }

        if ($editable) {
            $this->problem(
                'Admin gate',
                'nova-two-factor.settings.editable is on with no nova.admin_gate, so any user who can reach Nova can weaken or pause two-factor policy.',
            );

            return;
        }

        $this->caution(
            'Admin gate',
            'No nova.admin_gate: every authenticated Nova user can read the compliance dashboard and the activity log.',
        );
    }

    /**
     * Whether the trusted-device cookie can leave without `Secure`.
     *
     * The one that fails silently: behind a proxy terminating TLS, PHP is
     * spoken to over plain HTTP, and `$request->isSecure()` is false unless
     * `TrustProxies` was configured. The cookie then travels on any `http://`
     * request to the host — and it is a thirty-day skip past the challenge, so
     * anyone who intercepts it replays it. Encryption is no help: possession is
     * the whole credential.
     */
    protected function checkCookieSecurity(): void
    {
        $url = (string) Config::get('app.url');
        $https = str_starts_with(mb_strtolower($url), 'https://');

        $configured = Config::get('nova-two-factor.cookies.secure');
        $session = Config::get('session.secure');

        if ($configured !== null) {
            $configured
                ? $this->ok('Cookie security', 'nova-two-factor.cookies.secure is on.')
                : $this->caution('Cookie security', 'nova-two-factor.cookies.secure is off: two-factor cookies will travel over plain HTTP.');

            return;
        }

        if ($session !== null && (bool) $session) {
            $this->ok('Cookie security', 'Following session.secure, which is on.');

            return;
        }

        if (! $https) {
            // Local development over http is the ordinary case, not a finding.
            $this->ok('Cookie security', 'app.url is not https; nothing to enforce.');

            return;
        }

        // A warning, not a failure: with TrustProxies configured the request is
        // seen as secure and the cookie is fine. What cannot be checked from
        // here is whether that configuration exists, and the failure is silent
        // — hence saying so rather than guessing either way.
        $this->caution(
            'Cookie security',
            'app.url is https but neither session.secure nor nova-two-factor.cookies.secure is set: behind a proxy without TrustProxies the trusted-device cookie ships without Secure. Set SESSION_SECURE_COOKIE=true to settle it.',
        );
    }

    protected function checkLegacyConfig(): void
    {
        $removed = array_filter([
            'use_google_qr_code_api',
            'encrypt_google2fa_secrets',
            'mandatory',
            'reauthorize_urls',
            'reauthorize_timeout',
            'except_routes',
            'showin_sidebar',
            'user_model',
        ], static fn (string $key): bool => Config::has("nova-two-factor.{$key}"));

        if ($removed === []) {
            $this->ok('Configuration', 'No 1.x keys present.');

            return;
        }

        // A stale published config is silently ignored otherwise, so an operator
        // thinks a setting applies when it does not.
        $this->problem(
            'Configuration',
            'Removed 1.x keys still present: '.implode(', ', $removed).'. See UPGRADE.md.',
        );
    }

    protected function checkAssets(): void
    {
        file_exists(__DIR__.'/../../dist/js/tool.js')
            ? $this->ok('Compiled assets', 'Present.')
            : $this->problem('Compiled assets', 'dist/js/tool.js is missing. Run `npm ci && npm run build`.');

        $this->checkPublishedAssets();
    }

    /**
     * The pre-auth bundle is a *copy* under `public/`.
     *
     * Nova serves the in-SPA bundle straight from the package, but the
     * challenge, step-up and enforcement pages load a published file. Nothing
     * re-copies it on `composer update`, so a fixed bundle can sit in the
     * package while the browser is still served last month's — a fix that
     * appears not to have worked, with no error anywhere to say why.
     */
    protected function checkPublishedAssets(): void
    {
        $copies = [
            'js/challenge.js' => __DIR__.'/../../dist/js/challenge.js',
            'css/tool.css' => __DIR__.'/../../dist/css/tool.css',
        ];

        foreach ($copies as $path => $source) {
            $published = public_path('vendor/nova-two-factor/'.$path);

            if (! file_exists($published)) {
                // Absent is a warning: the pre-auth pages degrade to a plain
                // form post and still work. Stale is a failure, because the
                // page looks fine and behaves like an older version.
                $this->caution(
                    'Published asset '.$path,
                    'Missing — the pre-auth pages lose the code input, passkeys and the countdown. '
                        .'Run `php artisan vendor:publish --tag=nova-two-factor-assets --force`.',
                );

                continue;
            }

            if (! file_exists($source) || hash_file('xxh128', $published) === hash_file('xxh128', $source)) {
                $this->ok('Published asset '.$path, 'Up to date.');

                continue;
            }

            $this->problem(
                'Published asset '.$path,
                'Stale — the copy in public/ differs from the one shipped with this version. '
                    .'Run `php artisan vendor:publish --tag=nova-two-factor-assets --force`.',
            );
        }
    }
}
