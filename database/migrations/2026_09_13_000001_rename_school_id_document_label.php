<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_LABEL = 'School ID';

    private const NEW_LABEL = 'Recent school ID';

    public function up(): void
    {
        $this->renameDocumentLabel(self::OLD_LABEL, self::NEW_LABEL);
    }

    public function down(): void
    {
        $this->renameDocumentLabel(self::NEW_LABEL, self::OLD_LABEL);
    }

    private function renameDocumentLabel(string $from, string $to): void
    {
        $this->renameUploadedDocuments('student_documents', 'user_id', $from, $to);
        $this->renameUploadedDocuments('application_documents', 'scholarship_application_id', $from, $to);
        $this->renameProgramRequirements($from, $to);
        $this->renameApplicationChecklists($from, $to);
    }

    private function renameUploadedDocuments(string $table, string $ownerColumn, string $from, string $to): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)
            ->where('document_name', $from)
            ->orderBy('id')
            ->get(['id', $ownerColumn])
            ->each(function (object $document) use ($table, $ownerColumn, $to): void {
                $duplicateExists = DB::table($table)
                    ->where($ownerColumn, $document->{$ownerColumn})
                    ->where('document_name', $to)
                    ->exists();

                if (! $duplicateExists) {
                    DB::table($table)->where('id', $document->id)->update(['document_name' => $to]);
                }
            });
    }

    private function renameProgramRequirements(string $from, string $to): void
    {
        if (! Schema::hasTable('scholarships')) {
            return;
        }

        $columns = collect(['requirements', 'optional_requirements', 'post_qualification_requirements'])
            ->filter(fn (string $column): bool => Schema::hasColumn('scholarships', $column))
            ->values();

        DB::table('scholarships')
            ->orderBy('id')
            ->get(['id', ...$columns])
            ->each(function (object $scholarship) use ($columns, $from, $to): void {
                $updates = [];

                foreach ($columns as $column) {
                    if (is_string($scholarship->{$column})) {
                        $updates[$column] = $this->renameRequirementText($scholarship->{$column}, $from, $to);
                    }
                }

                if ($updates !== []) {
                    DB::table('scholarships')->where('id', $scholarship->id)->update($updates);
                }
            });
    }

    private function renameRequirementText(string $value, string $from, string $to): string
    {
        if ($from === self::OLD_LABEL) {
            return str_ireplace(
                ['Learner school ID', 'Valid school ID', self::OLD_LABEL],
                ['Recent learner school ID', self::NEW_LABEL, self::NEW_LABEL],
                $value,
            );
        }

        return str_ireplace(
            ['Recent learner school ID', self::NEW_LABEL],
            ['Learner school ID', self::OLD_LABEL],
            $value,
        );
    }

    private function renameApplicationChecklists(string $from, string $to): void
    {
        if (! Schema::hasTable('scholarship_applications')) {
            return;
        }

        $columns = collect(['document_checklist', 'optional_document_checklist'])
            ->filter(fn (string $column): bool => Schema::hasColumn('scholarship_applications', $column))
            ->values();

        DB::table('scholarship_applications')
            ->orderBy('id')
            ->get(['id', ...$columns])
            ->each(function (object $application) use ($columns, $from, $to): void {
                $updates = [];

                foreach ($columns as $column) {
                    $documents = json_decode($application->{$column} ?? 'null', true);

                    if (! is_array($documents)) {
                        continue;
                    }

                    $updates[$column] = json_encode(array_map(
                        fn (mixed $document): mixed => $document === $from ? $to : $document,
                        $documents,
                    ));
                }

                if ($updates !== []) {
                    DB::table('scholarship_applications')->where('id', $application->id)->update($updates);
                }
            });
    }
};
