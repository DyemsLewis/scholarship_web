<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class VerifyPlatformBackup extends Command
{
    protected $signature = 'platform:restore-check
        {archive? : Backup archive path; defaults to the newest configured backup}';

    protected $description = 'Restore a backup into an isolated temporary database and verify critical records';

    public function handle(): int
    {
        if (! class_exists(ZipArchive::class)) {
            $this->components->error('The PHP zip extension is required to verify platform backups.');

            return self::FAILURE;
        }

        $temporaryPath = storage_path('framework/cache/platform-restore-check-'.Str::lower(Str::random(8)));
        $temporaryDatabase = null;
        $temporaryConnection = 'platform_restore_check';
        File::ensureDirectoryExists($temporaryPath);

        try {
            $archivePath = $this->archivePath();
            $this->verifyChecksum($archivePath);
            [$manifest, $databasePath] = $this->extractDatabase($archivePath, $temporaryPath);
            $temporaryDatabase = $this->prepareConnection($manifest, $databasePath, $temporaryConnection);
            $counts = $this->verifyDatabase($temporaryConnection, $manifest['table_counts'] ?? []);

            $this->components->info('Backup restore check passed in an isolated database.');
            $this->line('Archive: '.$archivePath);

            foreach ($counts as $table => $count) {
                $this->line("{$table}: {$count} rows");
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->components->error('Restore check failed: '.$error->getMessage());

            return self::FAILURE;
        } finally {
            DB::purge($temporaryConnection);

            if ($temporaryDatabase !== null) {
                try {
                    DB::connection()->statement("DROP DATABASE IF EXISTS `{$temporaryDatabase}`");
                } catch (Throwable) {
                    $this->components->warn("Temporary database {$temporaryDatabase} could not be removed automatically.");
                }
            }

            File::deleteDirectory($temporaryPath);
        }
    }

    private function archivePath(): string
    {
        $requested = trim((string) $this->argument('archive'));

        if ($requested !== '') {
            $path = realpath($requested);

            if ($path === false || ! is_file($path)) {
                throw new RuntimeException("Backup archive was not found: {$requested}");
            }

            return $path;
        }

        $destination = trim((string) config('platform.backup.path'));
        $archives = File::glob(rtrim($destination, '\\/').DIRECTORY_SEPARATOR.'scholarship-backup-*.zip');

        usort($archives, fn (string $first, string $second): int => filemtime($second) <=> filemtime($first));

        if ($archives === []) {
            throw new RuntimeException('No platform backup archives were found.');
        }

        return (string) realpath($archives[0]);
    }

    private function verifyChecksum(string $archivePath): void
    {
        $checksumPath = $archivePath.'.sha256';

        if (! is_file($checksumPath)) {
            throw new RuntimeException('The backup checksum file is missing.');
        }

        preg_match('/^([a-f0-9]{64})\b/i', trim(File::get($checksumPath)), $matches);
        $expected = strtolower($matches[1] ?? '');
        $actual = strtolower((string) hash_file('sha256', $archivePath));

        if ($expected === '' || ! hash_equals($expected, $actual)) {
            throw new RuntimeException('The backup checksum does not match the archive.');
        }
    }

    private function extractDatabase(string $archivePath, string $temporaryPath): array
    {
        $archive = new ZipArchive;

        if ($archive->open($archivePath, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('The backup archive failed its integrity check.');
        }

        try {
            $manifestJson = $archive->getFromName('manifest.json');
            $manifest = is_string($manifestJson) ? json_decode($manifestJson, true) : null;

            if (! is_array($manifest) || blank($manifest['database_file'] ?? null)) {
                throw new RuntimeException('The backup manifest is missing or invalid.');
            }

            $databaseEntry = (string) $manifest['database_file'];
            $databaseStat = $archive->statName($databaseEntry);

            if ($databaseStat === false || (int) ($databaseStat['size'] ?? 0) < 1) {
                throw new RuntimeException('The backup database file is missing or empty.');
            }

            $source = $archive->getStream($databaseEntry);
            $databasePath = $temporaryPath.DIRECTORY_SEPARATOR.basename($databaseEntry);
            $destination = fopen($databasePath, 'wb');

            if (! is_resource($source) || ! is_resource($destination)) {
                throw new RuntimeException('The backup database could not be extracted.');
            }

            try {
                if (stream_copy_to_stream($source, $destination) === false) {
                    throw new RuntimeException('The backup database could not be extracted.');
                }
            } finally {
                fclose($source);
                fclose($destination);
            }

            return [$manifest, $databasePath];
        } finally {
            $archive->close();
        }
    }

    private function prepareConnection(array $manifest, string $databasePath, string $connectionName): ?string
    {
        $driver = strtolower((string) ($manifest['database_driver'] ?? ''));

        if ($driver === 'sqlite') {
            config(["database.connections.{$connectionName}" => [
                ...config('database.connections.sqlite'),
                'database' => $databasePath,
            ]]);

            return null;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Restore checks are not configured for the {$driver} database driver.");
        }

        $sourceConnectionName = DB::getDefaultConnection();
        $source = config("database.connections.{$sourceConnectionName}", []);
        $temporaryDatabase = 'scholarship_restore_check_'.Str::lower(Str::random(10));
        DB::connection()->statement("CREATE DATABASE `{$temporaryDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $input = fopen($databasePath, 'rb');

        if (! is_resource($input)) {
            throw new RuntimeException('The extracted MySQL backup could not be opened.');
        }

        try {
            $process = new Process($this->mysqlArguments($source, $temporaryDatabase), base_path(), [
                'MYSQL_PWD' => (string) ($source['password'] ?? ''),
            ]);
            $process->setInput($input);
            $process->setTimeout(900);
            $process->run();

            if (! $process->isSuccessful()) {
                $message = trim($process->getErrorOutput() ?: $process->getOutput());
                throw new RuntimeException('MySQL restore failed'.($message !== '' ? ': '.$message : '.'));
            }
        } catch (Throwable $error) {
            DB::connection()->statement("DROP DATABASE IF EXISTS `{$temporaryDatabase}`");

            throw $error;
        } finally {
            fclose($input);
        }

        config(["database.connections.{$connectionName}" => [
            ...$source,
            'database' => $temporaryDatabase,
        ]]);
        DB::purge($connectionName);

        return $temporaryDatabase;
    }

    private function mysqlArguments(array $connection, string $database): array
    {
        $arguments = [$this->mysqlBinary(), '--default-character-set=utf8mb4'];

        if (filled($connection['unix_socket'] ?? null)) {
            $arguments[] = '--socket='.$connection['unix_socket'];
        } else {
            $arguments[] = '--host='.($connection['host'] ?? '127.0.0.1');
            $arguments[] = '--port='.($connection['port'] ?? 3306);
        }

        if (filled($connection['username'] ?? null)) {
            $arguments[] = '--user='.$connection['username'];
        }

        $arguments[] = $database;

        return $arguments;
    }

    private function mysqlBinary(): string
    {
        $configured = trim((string) config('platform.backup.mysql_binary'));

        if ($configured !== '') {
            return $configured;
        }

        $xamppBinary = dirname(dirname(PHP_BINARY)).DIRECTORY_SEPARATOR.'mysql'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'mysql.exe';

        return is_file($xamppBinary) ? $xamppBinary : 'mysql';
    }

    private function verifyDatabase(string $connectionName, array $expectedCounts): array
    {
        $connection = DB::connection($connectionName);
        $schema = $connection->getSchemaBuilder();
        $tables = collect(config('platform.backup.restore_check_tables', []))
            ->filter(fn (mixed $table): bool => is_string($table))
            ->values();
        $counts = [];

        foreach ($tables as $table) {
            if (! $schema->hasTable($table)) {
                throw new RuntimeException("Restored database is missing the {$table} table.");
            }

            $count = $connection->table($table)->count();
            $counts[$table] = $count;

            if (array_key_exists($table, $expectedCounts) && (int) $expectedCounts[$table] !== $count) {
                throw new RuntimeException("Restored {$table} row count does not match the backup manifest.");
            }
        }

        return $counts;
    }
}
