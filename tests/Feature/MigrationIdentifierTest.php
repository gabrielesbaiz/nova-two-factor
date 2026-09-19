<?php

declare(strict_types=1);

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;

/**
 * The suite runs on sqlite, which has no identifier length limit, so nothing
 * here would otherwise notice that `morphs()` derives a name from the table
 * plus both columns — 71 characters on this package's longer table names, and
 * a hard `1059 Identifier name … is too long` on the first MySQL `migrate`.
 *
 * Compiling the real migration against the MySQL grammar in pretend mode gives
 * the exact statements a consumer's database would receive, with no server
 * involved.
 */
it('generates no identifier MySQL would reject', function (): void {
    // A MySQL connection with no server behind it: the grammar is the real one,
    // the version is stubbed (the only thing that would reach for a PDO), and
    // pretend mode collects the statements instead of running them.
    DB::extend('mysql_identifier_probe', static fn (): MySqlConnection => new class(static fn () => null, 'probe', '', ['driver' => 'mysql']) extends MySqlConnection
    {
        public function getServerVersion(): string
        {
            return '8.0.36';
        }
    });

    config()->set('database.connections.mysql_identifier_probe', ['driver' => 'mysql', 'database' => 'probe', 'prefix' => '']);
    config()->set('nova-two-factor.database.connection', 'mysql_identifier_probe');

    $migration = require __DIR__.'/../../database/migrations/create_two_factor_tables.php.stub';

    $statements = DB::connection('mysql_identifier_probe')->pretend(
        static fn () => $migration->up(),
    );

    // Restored before the assertions: teardown resolves this connection too,
    // and a probe with no PDO behind it cannot answer.
    config()->set('nova-two-factor.database.connection', null);

    $sql = implode("\n", array_column($statements, 'query'));

    expect($sql)->not->toBe('');

    // Every backtick-quoted identifier the migration would create.
    preg_match_all('/`([^`]+)`/', $sql, $matches);

    $tooLong = array_values(array_unique(array_filter(
        $matches[1],
        static fn (string $identifier): bool => strlen($identifier) > 64,
    )));

    expect($tooLong)->toBe([]);
});

it('names every morph index explicitly', function (): void {
    $stub = (string) file_get_contents(__DIR__.'/../../database/migrations/create_two_factor_tables.php.stub');

    // A bare `morphs('authenticatable')` is the exact shape that produced the
    // over-long name, so it is refused outright rather than measured.
    expect($stub)->not->toMatch("/(nullable)?[Mm]orphs\('authenticatable'\)/");
});
