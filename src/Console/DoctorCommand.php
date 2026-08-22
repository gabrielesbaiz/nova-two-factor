<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Console;

use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactorEnrollment;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\RelyingParty;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
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

        $this->checkAppKey();
        $this->checkTables();
        $this->checkMiddleware();
        $this->checkMethods();
        $this->checkWebAuthn();
        $this->checkEnforcement();
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
        foreach (['nova.middleware' => 'page', 'nova.api_middleware' => 'API'] as $group => $label) {
            $stack = Config::get($group, []);
            $stack = is_array($stack) ? $stack : [];

            $hasChallenge = in_array(RequireTwoFactor::class, $stack, true);
            $hasEnrollment = in_array(RequireTwoFactorEnrollment::class, $stack, true);

            if ($hasChallenge && $hasEnrollment) {
                $this->ok("Middleware ({$label})", 'Challenge and enrollment guards registered.');

                continue;
            }

            $this->problem(
                "Middleware ({$label})",
                "Not registered on {$group}. Two-factor can be bypassed through the {$label} routes.",
            );
        }
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
    }
}
