<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class PlatformOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_command_creates_and_verifies_a_database_archive(): void
    {
        $backupPath = storage_path('framework/testing/platform-backups');
        $databasePath = storage_path('framework/testing/platform-backup.sqlite');
        $defaultConnection = config('database.default');
        File::deleteDirectory($backupPath);
        File::ensureDirectoryExists(dirname($databasePath));
        File::put($databasePath, '');
        config([
            'platform.backup.path' => $backupPath,
            'platform.backup.retention_days' => 7,
            'database.default' => 'backup_test',
            'database.connections.backup_test' => [
                ...config('database.connections.sqlite'),
                'database' => $databasePath,
            ],
        ]);
        DB::purge('backup_test');

        try {
            $this->artisan('migrate:fresh', ['--database' => 'backup_test'])->assertSuccessful();
            $this->artisan('platform:backup', ['--database-only' => true])
                ->expectsOutputToContain('Platform backup created and verified')
                ->assertSuccessful();

            $archives = File::glob($backupPath.DIRECTORY_SEPARATOR.'scholarship-backup-*.zip');
            $this->assertCount(1, $archives);
            $this->assertFileExists($archives[0].'.sha256');

            $archive = new ZipArchive;
            $this->assertTrue($archive->open($archives[0], ZipArchive::CHECKCONS) === true);
            $this->assertNotFalse($archive->locateName('manifest.json'));
            $this->assertNotFalse($archive->locateName('database/database.sqlite'));
            $this->assertGreaterThan(0, $archive->statName('database/database.sqlite')['size']);
            $archive->close();

            $this->artisan('platform:restore-check', ['archive' => $archives[0]])
                ->expectsOutputToContain('Backup restore check passed in an isolated database')
                ->assertSuccessful();
        } finally {
            DB::purge('backup_test');
            config(['database.default' => $defaultConnection]);
            File::deleteDirectory($backupPath);
            File::delete($databasePath);
        }
    }

    public function test_retention_command_removes_only_expired_operational_data(): void
    {
        config([
            'privacy.retention.read_notifications_days' => 30,
            'privacy.retention.activity_logs_days' => 90,
        ]);
        $user = User::factory()->create();
        $old = now()->subDays(120);
        $recent = now()->subDay();

        DB::table('pending_registrations')->insert([
            'token' => str_repeat('a', 64),
            'email' => 'expired@example.test',
            'username' => 'expired_registration',
            'role' => 'applicant',
            'payload' => '{}',
            'password_hash' => 'hash',
            'code_hash' => 'hash',
            'attempts' => 0,
            'expires_at' => now()->subMinute(),
            'last_sent_at' => now()->subMinutes(5),
            'created_at' => $old,
            'updated_at' => $old,
        ]);
        DB::table('mobile_api_tokens')->insert([
            'user_id' => $user->id,
            'name' => 'mobile_app',
            'token_hash' => hash('sha256', 'expired-token'),
            'expires_at' => now()->subMinute(),
            'created_at' => $old,
            'updated_at' => $old,
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => 'expired-reset-token',
            'created_at' => now()->subHours(2),
        ]);
        DB::table('sessions')->insert([
            'id' => 'expired-session',
            'user_id' => $user->id,
            'payload' => 'expired',
            'last_activity' => now()->subHours(3)->getTimestamp(),
        ]);
        DB::table('portal_notifications')->insert([
            [
                'user_id' => $user->id,
                'type' => 'info',
                'title' => 'Old read notification',
                'message' => 'Eligible for retention cleanup.',
                'read_at' => now()->subDays(31),
                'created_at' => $old,
                'updated_at' => $old,
            ],
            [
                'user_id' => $user->id,
                'type' => 'info',
                'title' => 'Old unread notification',
                'message' => 'Unread records are retained.',
                'read_at' => null,
                'created_at' => $old,
                'updated_at' => $old,
            ],
            [
                'user_id' => $user->id,
                'type' => 'info',
                'title' => 'Recent read notification',
                'message' => 'Still inside the retention period.',
                'read_at' => $recent,
                'created_at' => $recent,
                'updated_at' => $recent,
            ],
        ]);
        DB::table('activity_logs')->insert([
            [
                'user_id' => $user->id,
                'actor_name' => 'Old actor',
                'actor_role' => 'applicant',
                'action' => 'old_action',
                'description' => 'Eligible for retention cleanup.',
                'created_at' => $old,
                'updated_at' => $old,
            ],
            [
                'user_id' => $user->id,
                'actor_name' => 'Recent actor',
                'actor_role' => 'applicant',
                'action' => 'recent_action',
                'description' => 'Still inside the retention period.',
                'created_at' => $recent,
                'updated_at' => $recent,
            ],
        ]);

        $this->artisan('platform:prune-data', ['--dry-run' => true])
            ->expectsOutputToContain('No data was deleted')
            ->assertSuccessful();
        $this->assertDatabaseHas('pending_registrations', ['email' => 'expired@example.test']);

        $this->artisan('platform:prune-data')->assertSuccessful();

        $this->assertDatabaseMissing('pending_registrations', ['email' => 'expired@example.test']);
        $this->assertDatabaseMissing('mobile_api_tokens', ['token_hash' => hash('sha256', 'expired-token')]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('sessions', ['id' => 'expired-session']);
        $this->assertDatabaseMissing('portal_notifications', ['title' => 'Old read notification']);
        $this->assertDatabaseHas('portal_notifications', ['title' => 'Old unread notification']);
        $this->assertDatabaseHas('portal_notifications', ['title' => 'Recent read notification']);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'old_action']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'recent_action']);
    }
}
