<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Console;

use Gabrielesbaiz\NovaTwoFactor\Actions\ResetTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorUser;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * The break-glass path.
 *
 * Somebody locked out of an admin panel by their own second factor cannot fix it
 * from inside that panel, so there has to be a way in from the shell — and it
 * has to be audited like any other reset.
 */
class ResetCommand extends Command
{
    protected $signature = 'nova-two-factor:reset
        {user : Email address or primary key}
        {--guard= : Auth guard whose provider model to search}
        {--reason=Reset from the command line : Recorded in the audit log}';

    protected $description = 'Remove every two-factor method for a user so they can enrol again';

    public function handle(ResetTwoFactor $reset): int
    {
        $model = $this->resolveModel();

        if ($model === null) {
            $this->components->error('No user model is configured for that guard.');

            return self::FAILURE;
        }

        $identifier = (string) $this->argument('user');

        $user = $model::query()
            ->where('email', $identifier)
            ->orWhere($model->getKeyName(), $identifier)
            ->first();

        if ($user === null) {
            $this->components->error("No user found for [{$identifier}].");

            return self::FAILURE;
        }

        $this->components->warn(sprintf(
            'This removes every method, recovery code and trusted device for %s.',
            $user->email ?? $user->getKey(),
        ));

        if (! $this->confirm('Continue?', false)) {
            return self::SUCCESS;
        }

        $reset(TwoFactorUser::assert($user), (string) $this->option('reason'));

        $this->components->info('Two-factor authentication reset. The user can enrol again at their next sign-in.');

        return self::SUCCESS;
    }

    protected function resolveModel(): ?Model
    {
        $guard = (string) ($this->option('guard') ?: Config::get('nova.guard') ?: Config::get('auth.defaults.guard'));
        $provider = Config::get("auth.guards.{$guard}.provider");
        $class = Config::get("auth.providers.{$provider}.model");

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        $instance = new $class;

        return $instance instanceof Model ? $instance : null;
    }
}
