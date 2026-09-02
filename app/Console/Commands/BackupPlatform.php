<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class BackupPlatform extends Command
{
    protected $signature = 'platform:backup
        {--database-only : Exclude uploaded documents and public program files}
        {--keep= : Override the configured backup retention in days}';

    protected $description = 'Create and verify a database and uploaded-file backup archive';

    public function handle(): int
    {
        if (! class_exists(ZipArchive::class)) {
            $this->components->error('The PHP zip extension is required to create platform backups.');

            return self::FAILURE;
        }

        try {
            $destination = $this->backupDestination();
            $retentionDays = $this->retentionDays();
        } catch (RuntimeException $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        $identifier = now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $archivePath = $destination.DIRECTORY_SEPARATOR."scholarship-backup-{$identifier}.zip";
        $temporaryPath = storage_path("framework/cache/platform-backup-{$identifier}");

        File::ensureDirectoryExists($temporaryPath);

        try {
            $database = $this->backupDatabase($temporaryPath);
            $archive = new ZipArchive;

            if ($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Unable to create backup archive at {$archivePath}.");
            }

            $archive->addFile($database['path'], 'database/'.$database['filename']);
            $includedFiles = 0;
            $includedLocations = [];

            if (! $this->option('database-only')) {
                foreach ($this->storageLocations() as $archiveDirectory => $sourceDirectory) {
                    if (! is_dir($sourceDirectory)) {
                        continue;
                    }

                    $includedFiles += $this->addDirectory($archive, $sourceDirectory, $archiveDirectory, $destination);
                    $includedLocations[] = $archiveDirectory;
                }
            }

            $archive->addFromString('manifest.json', json_encode([
                'application' => config('app.name'),
                'environment' => app()->environment(),
                'created_at' => now()->toIso8601String(),
                'database_driver' => DB::connection()->getDriverName(),
                'database_file' => 'database/'.$database['filename'],
                'table_counts' => $this->criticalTableCounts(),
                'included_storage' => $includedLocations,
                'uploaded_file_count' => $includedFiles,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if (! $archive->close()) {
                throw new RuntimeException('The backup archive could not be finalized.');
            }

            $this->verifyArchive($archivePath, 'database/'.$database['filename']);

            $checksum = hash_file('sha256', $archivePath);
            File::put($archivePath.'.sha256', "{$checksum}  ".basename($archivePath).PHP_EOL);
            @chmod($archivePath, 0600);
            @chmod($archivePath.'.sha256', 0600);

            $removed = $this->removeExpiredBackups($destination, $retentionDays, $archivePath);

            $this->components->info('Platform backup created and verified.');
            $this->line('Archive: '.$archivePath);
            $this->line('SHA-256: '.$checksum);
            $this->line('Uploaded files included: '.$includedFiles);

            if ($removed > 0) {
                $this->line("Expired backups removed: {$removed}");
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            File::delete([$archivePath, $archivePath.'.sha256']);
            $this->components->error('Backup failed: '.$error->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($temporaryPath);
        }
    }

    private function backupDestination(): string
    {
        $path = trim((string) config('platform.backup.path'));

        if ($path === '') {
            throw new RuntimeException('Configure PLATFORM_BACKUP_PATH before running a backup.');
        }

        File::ensureDirectoryExists($path);
        $resolved = realpath($path);

        if ($resolved === false || ! is_writable($resolved)) {
            throw new RuntimeException("Backup path is not writable: {$path}");
        }

        if (str_starts_with($this->normalizedPath($resolved), $this->normalizedPath(public_path()))) {
            throw new RuntimeException('PLATFORM_BACKUP_PATH must be outside the public web directory.');
        }

        return $resolved;
    }

    private function retentionDays(): int
    {
        $value = $this->option('keep') ?? config('platform.backup.retention_days', 14);

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new RuntimeException('Backup retention must be at least one day.');
        }

        return (int) $value;
    }

    private function backupDatabase(string $temporaryPath): array
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => $this->backupSqlite($temporaryPath),
            'mysql', 'mariadb' => $this->backupMySql($temporaryPath),
            default => throw new RuntimeException("Database backups are not configured for the {$driver} driver."),
        };
    }

    private function backupSqlite(string $temporaryPath): array
    {
        $path = $temporaryPath.DIRECTORY_SEPARATOR.'database.sqlite';
        $quotedPath = DB::connection()->getPdo()->quote($path);

        DB::connection()->unprepared("VACUUM INTO {$quotedPath}");

        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('SQLite did not produce a usable backup file.');
        }

        return ['path' => $path, 'filename' => 'database.sqlite'];
    }

    private function backupMySql(string $temporaryPath): array
    {
        $connectionName = DB::getDefaultConnection();
        $connection = config("database.connections.{$connectionName}", []);
        $path = $temporaryPath.DIRECTORY_SEPARATOR.'database.sql';
        $binary = $this->mysqldumpBinary();
        $arguments = [
            $binary,
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--default-character-set=utf8mb4',
            '--result-file='.$path,
        ];

        if (filled($connection['unix_socket'] ?? null)) {
            $arguments[] = '--socket='.$connection['unix_socket'];
        } else {
            $arguments[] = '--host='.($connection['host'] ?? '127.0.0.1');
            $arguments[] = '--port='.($connection['port'] ?? 3306);
        }

        if (filled($connection['username'] ?? null)) {
            $arguments[] = '--user='.$connection['username'];
        }

        $arguments[] = (string) ($connection['database'] ?? '');

        $process = new Process($arguments, base_path(), [
            'MYSQL_PWD' => (string) ($connection['password'] ?? ''),
        ]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($path) || filesize($path) === 0) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException('MySQL backup failed'.($message !== '' ? ': '.$message : '.'));
        }

        return ['path' => $path, 'filename' => 'database.sql'];
    }

    private function mysqldumpBinary(): string
    {
        $configured = trim((string) config('platform.backup.mysqldump_binary'));

        if ($configured !== '') {
            return $configured;
        }

        $xamppBinary = dirname(dirname(PHP_BINARY)).DIRECTORY_SEPARATOR.'mysql'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'mysqldump.exe';

        return is_file($xamppBinary) ? $xamppBinary : 'mysqldump';
    }

    private function storageLocations(): array
    {
        $locations = [];

        if (config('platform.backup.include_private_files', true)) {
            $locations['storage/private'] = storage_path('app/private');
        }

        if (config('platform.backup.include_public_files', true)) {
            $locations['storage/public'] = storage_path('app/public');
        }

        return $locations;
    }

    private function criticalTableCounts(): array
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();

        return collect(config('platform.backup.restore_check_tables', []))
            ->filter(fn (mixed $table): bool => is_string($table) && $schema->hasTable($table))
            ->mapWithKeys(fn (string $table): array => [$table => $connection->table($table)->count()])
            ->all();
    }

    private function addDirectory(ZipArchive $archive, string $source, string $target, string $backupDestination): int
    {
        $sourcePath = rtrim((string) realpath($source), DIRECTORY_SEPARATOR);
        $backupPath = $this->normalizedPath($backupDestination);
        $count = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourcePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }

            $realPath = $file->getRealPath();

            if ($realPath === false || str_starts_with($this->normalizedPath($realPath), $backupPath)) {
                continue;
            }

            $relativePath = ltrim(substr($realPath, strlen($sourcePath)), DIRECTORY_SEPARATOR);
            $archivePath = trim($target, '/').'/'.str_replace('\\', '/', $relativePath);

            if (! $archive->addFile($realPath, $archivePath)) {
                throw new RuntimeException("Unable to add {$realPath} to the backup archive.");
            }

            $count++;
        }

        return $count;
    }

    private function verifyArchive(string $archivePath, string $databasePath): void
    {
        $archive = new ZipArchive;

        if ($archive->open($archivePath, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('The completed backup archive failed its integrity check.');
        }

        $valid = $archive->locateName('manifest.json') !== false
            && $archive->locateName($databasePath) !== false
            && $archive->statName($databasePath)['size'] > 0;
        $archive->close();

        if (! $valid) {
            throw new RuntimeException('The completed backup is missing its manifest or database file.');
        }
    }

    private function removeExpiredBackups(string $destination, int $retentionDays, string $currentArchive): int
    {
        $cutoff = now()->subDays($retentionDays)->getTimestamp();
        $removed = 0;

        foreach (File::glob($destination.DIRECTORY_SEPARATOR.'scholarship-backup-*.zip') as $archive) {
            if ($archive === $currentArchive || filemtime($archive) >= $cutoff) {
                continue;
            }

            File::delete([$archive, $archive.'.sha256']);
            $removed++;
        }

        return $removed;
    }

    private function normalizedPath(string $path): string
    {
        return strtolower(str_replace('\\', '/', rtrim($path, '\\/')).'/');
    }
}
