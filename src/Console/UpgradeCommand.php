<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Console;

use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ports 1.x `nova_twofa` rows into the 2.0 schema.
 *
 * Two things are deliberately *not* migrated:
 *
 *  - The single legacy recovery code. It was bcrypt-hashed, and the new scheme
 *    needs a SHA-256 of the plaintext, which nobody has. Users must regenerate,
 *    which is the right outcome anyway.
 *  - Rows with `google2fa_enable = 0` are, by default, migrated as enrolled.
 *    Those users *bypassed* 2FA in 1.x because the middleware waved them
 *    through, so this is a behaviour change and the command says so out loud.
 */
class UpgradeCommand extends Command
{
    protected $signature = 'nova-two-factor:upgrade
        {--dry-run : Report what would change without writing anything}
        {--chunk=500 : Rows per batch}
        {--disable-unconfirmed : Skip rows that had two-factor switched off, instead of enrolling them}
        {--drop-legacy-table : Drop nova_twofa once the port has been verified}';

    protected $description = 'Migrate 1.x two-factor data into the 2.0 schema';

    public function handle(): int
    {
        if ($this->option('drop-legacy-table')) {
            return $this->dropLegacyTable();
        }

        if (! Schema::hasTable('nova_twofa')) {
            $this->components->info('No nova_twofa table found — nothing to migrate.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $skipDisabled = (bool) $this->option('disable-unconfirmed');

        $this->warnAboutSecrets();

        $stats = ['migrated' => 0, 'skipped' => 0, 'failed' => 0, 'already' => 0, 'was_disabled' => 0];

        DB::table('nova_twofa')->orderBy('id')->chunk((int) $this->option('chunk'), function ($rows) use (&$stats, $dryRun, $skipDisabled): void {
            foreach ($rows as $row) {
                $result = $this->migrateRow($row, $dryRun, $skipDisabled);
                $stats[$result]++;
            }
        });

        $this->newLine();
        $this->table(['Outcome', 'Rows'], [
            ['Migrated', $stats['migrated']],
            ['Already migrated', $stats['already']],
            ['Skipped', $stats['skipped']],
            ['Failed to decrypt', $stats['failed']],
        ]);

        if ($stats['was_disabled'] > 0) {
            $this->newLine();
            $this->components->warn(sprintf(
                '%d user(s) had two-factor switched off in 1.x and were let past the gate entirely. '
                .'They are now enrolled and %s.',
                $stats['was_disabled'],
                $skipDisabled ? 'were skipped as requested' : 'WILL be challenged at their next sign-in',
            ));
        }

        $this->newLine();
        $this->components->warn('Nobody has recovery codes yet: the 1.x code cannot be re-hashed. Ask users to generate a new set.');
        $this->components->info('nova_twofa has been left in place. Re-run with --drop-legacy-table once you have verified sign-in works.');

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function migrateRow(object $row, bool $dryRun, bool $skipDisabled): string
    {
        $userType = (string) Config::get('nova-two-factor.upgrade.morph_alias', $this->guessMorphAlias());

        $exists = TwoFactorMethod::query()
            ->where('authenticatable_type', $userType)
            ->where('authenticatable_id', $row->user_id)
            ->where('type', MethodType::Totp->value)
            ->exists();

        if ($exists) {
            return 'already';
        }

        $secret = $this->decryptSecret($row->google2fa_secret ?? null);

        if ($secret === null || $secret === '') {
            return 'failed';
        }

        $wasDisabled = ! (bool) ($row->google2fa_enable ?? false);

        if ($wasDisabled && $skipDisabled) {
            return 'skipped';
        }

        if ($dryRun) {
            return $wasDisabled ? 'was_disabled' : 'migrated';
        }

        TwoFactorMethod::query()->create([
            'authenticatable_type' => $userType,
            'authenticatable_id' => $row->user_id,
            'type' => MethodType::Totp,
            'name' => MethodType::Totp->label(),
            'secret' => $secret,
            'is_default' => true,
            'confirmed_at' => (bool) ($row->confirmed ?? false) ? ($row->updated_at ?? now()) : null,
            'created_at' => $row->created_at ?? now(),
            'updated_at' => now(),
        ]);

        return $wasDisabled ? 'was_disabled' : 'migrated';
    }

    /**
     * 1.x stored the secret either in plaintext or `Crypt::encrypt`-ed,
     * depending on a config flag that could be toggled at any point in the
     * table's life — so both shapes can coexist in one table.
     */
    protected function decryptSecret(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decrypt($stored);

            return is_string($decrypted) ? $decrypted : null;
        } catch (Throwable) {
            // Not encrypted: 1.x defaulted `encrypt_google2fa_secrets` to false.
            return $stored;
        }
    }

    protected function guessMorphAlias(): string
    {
        $guard = (string) (Config::get('nova.guard') ?: Config::get('auth.defaults.guard'));
        $provider = Config::get("auth.guards.{$guard}.provider");
        $class = Config::get("auth.providers.{$provider}.model");

        if (! is_string($class) || ! class_exists($class)) {
            return 'user';
        }

        return (new $class)->getMorphClass();
    }

    protected function warnAboutSecrets(): void
    {
        $this->newLine();
        $this->components->warn(
            'Treat every migrated secret as compromised. 1.x sent the otpauth:// URI — shared secret '
            .'included — to api.qrserver.com by default, so those secrets may be in a third party\'s logs. '
            .'Consider requiring everyone to re-enrol instead of migrating.',
        );
        $this->newLine();
    }

    protected function dropLegacyTable(): int
    {
        if (! Schema::hasTable('nova_twofa')) {
            $this->components->info('nova_twofa is already gone.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Drop nova_twofa? This cannot be undone.', false)) {
            return self::SUCCESS;
        }

        Schema::drop('nova_twofa');
        $this->components->info('nova_twofa dropped.');

        return self::SUCCESS;
    }
}
