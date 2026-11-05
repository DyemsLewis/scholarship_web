<?php

namespace App\Console\Commands;

use App\Services\RecipientCasePrivacyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneRecipientCaseAccessLogs extends Command
{
    protected $signature = 'case-privacy:prune-access {--dry-run : Count eligible access metadata without deleting it}';

    protected $description = 'Prune old case access metadata, respecting the latest explicit audit-retention hold';

    public function handle(): int
    {
        if (! Schema::hasTable('recipient_case_access_logs') || ! Schema::hasTable('recipient_case_privacy_reviews')) {
            $this->info('Access audit storage is not installed. No logs were removed.');

            return self::SUCCESS;
        }
        $old = DB::table('recipient_case_access_logs')->where('created_at', '<', now()->subDays(RecipientCasePrivacyService::AUDIT_RETENTION_DAYS));
        $eligible = (clone $old)->whereNotExists(function ($q): void {
            $q->selectRaw('1')->from('recipient_case_privacy_reviews as hold')
                ->whereColumn('hold.recipient_agreement_case_id', 'recipient_case_access_logs.recipient_agreement_case_id')
                ->where('hold.audit_retention_hold', true)->whereNotExists(function ($newer): void {
                    $newer->selectRaw('1')->from('recipient_case_privacy_reviews as newer')
                        ->whereColumn('newer.recipient_agreement_case_id', 'hold.recipient_agreement_case_id')->whereColumn('newer.id', '>', 'hold.id');
                });
        });
        $count = $eligible->count();
        $held = $old->count() - $count;
        $removed = 0;
        if (! $this->option('dry-run')) {
            do {
                $ids = (clone $eligible)->orderBy('id')->limit(500)->pluck('id');
                if ($ids->isEmpty()) {
                    break;
                }
                $batch = (clone $eligible)->whereIn('id', $ids)->delete();
                $removed += $batch;
                if (! $batch) {
                    break;
                }
            } while (true);
        }
        $this->info("Eligible access logs: {$count}; held: {$held}; removed: {$removed}. Case records, findings, and evidence were not deleted.");

        return self::SUCCESS;
    }
}
