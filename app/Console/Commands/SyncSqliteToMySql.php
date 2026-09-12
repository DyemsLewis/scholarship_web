<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncSqliteToMySql extends Command
{
    protected $signature = 'platform:sync-mysql
        {--database=scholarship_web : Target MySQL database name}
        {--force : Replace the target MySQL data without confirmation}';

    protected $description = 'Apply migrations to MySQL and copy the active SQLite data without changing the default connection';

    public function handle(): int
    {
        if (DB::getDefaultConnection() !== 'sqlite') {
            $this->components->error('The default connection must remain SQLite while this command runs.');

            return self::FAILURE;
        }

        $database = trim((string) $this->option('database'));

        if ($database === '' || preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            $this->components->error('Use a MySQL database name containing only letters, numbers, and underscores.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Replace the data in MySQL database [{$database}]?")) {
            $this->components->warn('Synchronization cancelled.');

            return self::SUCCESS;
        }

        $mysql = config('database.connections.mysql');
        $mysql['url'] = null;
        $mysql['database'] = $database;

        config(['database.connections.mysql_sync' => $mysql]);
        DB::purge('mysql_sync');

        try {
            DB::connection('mysql_sync')->getPdo();

            $this->components->info('Applying current migrations to MySQL...');
            $migrationResult = Artisan::call('migrate', [
                '--database' => 'mysql_sync',
                '--force' => true,
            ], $this->output);

            if ($migrationResult !== self::SUCCESS) {
                return self::FAILURE;
            }

            $sourceTables = collect(DB::connection('sqlite')->select(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            ))->pluck('name');

            $targetTables = collect(DB::connection('mysql_sync')->select(
                "SELECT TABLE_NAME AS name FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
            ))->pluck('name');

            $tables = $sourceTables
                ->intersect($targetTables)
                ->reject(fn (string $table): bool => $table === 'migrations')
                ->values();

            $target = DB::connection('mysql_sync');
            $source = DB::connection('sqlite');
            $copiedRows = 0;

            $target->statement('SET FOREIGN_KEY_CHECKS=0');

            try {
                foreach ($tables as $table) {
                    $quotedTable = '`'.str_replace('`', '``', $table).'`';
                    $target->statement("TRUNCATE TABLE {$quotedTable}");

                    $rows = $source->table($table)->get()->map(
                        fn (object $row): array => (array) $row
                    );

                    foreach ($rows->chunk(200) as $chunk) {
                        $target->table($table)->insert($chunk->all());
                    }

                    $count = $rows->count();
                    $copiedRows += $count;
                    $this->line("  {$table}: {$count} rows");
                }
            } finally {
                $target->statement('SET FOREIGN_KEY_CHECKS=1');
            }

            $this->newLine();
            $this->components->info("MySQL synchronized: {$tables->count()} tables and {$copiedRows} rows copied.");
            $this->line('The application is still using SQLite.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error('MySQL synchronization failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            DB::disconnect('mysql_sync');
        }
    }
}
