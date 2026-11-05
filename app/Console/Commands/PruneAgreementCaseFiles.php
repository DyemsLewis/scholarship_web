<?php

namespace App\Console\Commands;

use App\Models\RecipientAgreementCaseEvidence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PruneAgreementCaseFiles extends Command
{
    protected $signature = 'agreement-cases:prune-files {--dry-run : Report eligible orphan files without deleting them}';

    protected $description = 'Remove unreferenced private agreement-case files after a 24-hour safety window';

    public function handle(): int
    {
        if (! Schema::hasTable('recipient_agreement_case_evidence')) {
            $this->info('Agreement case storage is not installed. No files were removed.');

            return self::SUCCESS;
        }
        $disk = Storage::disk('local');
        $eligible = $removed = 0;
        foreach ($disk->allFiles('recipient-agreement-cases') as $path) {
            if (! preg_match('#^recipient-agreement-cases/[1-9][0-9]*/evidence/[a-f0-9-]{36}\.(pdf|png|jpg|jpeg)$#D', $path)
                || $disk->lastModified($path) >= now()->subDay()->getTimestamp()
                || RecipientAgreementCaseEvidence::query()->where('path', $path)->exists()) {
                continue;
            }
            $eligible++;
            if (! $this->option('dry-run') && $disk->delete($path)) {
                $removed++;
            }
        }
        $this->info("Eligible orphan files: {$eligible}; removed: {$removed}. Referenced case evidence was retained.");

        return self::SUCCESS;
    }
}
