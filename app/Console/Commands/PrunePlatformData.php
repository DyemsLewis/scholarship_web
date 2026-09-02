<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PrunePlatformData extends Command
{
    protected $signature = 'platform:prune-data
        {--dry-run : Count eligible records without deleting them}';

    protected $description = 'Remove expired temporary data and old read-only operational records';

    public function handle(): int
    {
        $operations = array_filter([
            $this->operation('pending_registrations', 'Expired registration attempts', fn (Builder $query): Builder => $query
                ->where('expires_at', '<', now())),
            $this->operation('mobile_api_tokens', 'Expired mobile sessions', fn (Builder $query): Builder => $query
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())),
            $this->operation('password_reset_tokens', 'Expired password reset tokens', fn (Builder $query): Builder => $query
                ->where('created_at', '<', now()->subMinutes((int) config('auth.passwords.users.expire', 60)))),
            $this->operation('sessions', 'Expired database sessions', fn (Builder $query): Builder => $query
                ->where('last_activity', '<', now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp())),
            $this->operation('portal_notifications', 'Old read notifications', fn (Builder $query): Builder => $query
                ->whereNotNull('read_at')
                ->where('read_at', '<', now()->subDays($this->retentionDays('read_notifications_days')))),
            $this->operation('activity_logs', 'Expired activity logs', fn (Builder $query): Builder => $query
                ->where('created_at', '<', now()->subDays($this->retentionDays('activity_logs_days')))),
        ]);

        $rows = [];

        foreach ($operations as $operation) {
            $query = $operation['query'];
            $eligible = $query->count();
            $removed = $this->option('dry-run') || $eligible === 0 ? 0 : $query->delete();

            $rows[] = [$operation['label'], $eligible, $removed];
        }

        $this->table(['Data category', 'Eligible', 'Removed'], $rows);
        $this->components->info($this->option('dry-run')
            ? 'Dry run complete. No data was deleted.'
            : 'Platform retention cleanup completed.');

        return self::SUCCESS;
    }

    private function operation(string $table, string $label, callable $scope): ?array
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        return [
            'label' => $label,
            'query' => $scope(DB::table($table)),
        ];
    }

    private function retentionDays(string $key): int
    {
        return max(1, (int) config("privacy.retention.{$key}"));
    }
}
