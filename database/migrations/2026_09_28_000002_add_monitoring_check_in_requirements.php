<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('recipient_monitoring_cycles', 'recipient_monitoring_plan_id')) {
            Schema::table('recipient_monitoring_cycles', function (Blueprint $table): void {
                $table->foreignId('recipient_monitoring_plan_id')
                    ->nullable()
                    ->after('scholarship_id')
                    ->constrained('recipient_monitoring_plans')
                    ->nullOnDelete();
                $table->unsignedInteger('monitoring_plan_version')->nullable()->after('recipient_monitoring_plan_id');
                $table->unsignedSmallInteger('grace_period_days')->default(0)->after('monitoring_plan_version');
                $table->boolean('allow_exception_requests')->default(false)->after('grace_period_days');
            });
        }

        if (! Schema::hasTable('recipient_monitoring_cycle_requirements')) {
            Schema::create('recipient_monitoring_cycle_requirements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('recipient_monitoring_cycle_id');
                $table->foreign('recipient_monitoring_cycle_id', 'monitoring_cycle_req_cycle_fk')
                    ->references('id')
                    ->on('recipient_monitoring_cycles')
                    ->cascadeOnDelete();
                $table->foreignId('source_requirement_id')->nullable();
                $table->foreign('source_requirement_id', 'monitoring_cycle_req_source_fk')
                    ->references('id')
                    ->on('recipient_monitoring_requirements')
                    ->nullOnDelete();
                $table->string('type')->index();
                $table->string('title');
                $table->text('description')->nullable();
                $table->text('evidence_description')->nullable();
                $table->boolean('required')->default(true);
                $table->boolean('requires_file')->default(true);
                $table->boolean('requires_original_verification')->default(false);
                $table->decimal('minimum_grade', 5, 2)->nullable();
                $table->string('grading_scale')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(
                    ['recipient_monitoring_cycle_id', 'sort_order'],
                    'monitoring_cycle_req_sort_idx'
                );
            });
        }

        // MySQL uses the old composite unique index to support rm_sub_cycle_fk.
        // Add its permanent replacement before removing that unique constraint.
        if (! $this->hasIndex('recipient_monitoring_submissions', 'monitoring_sub_cycle_application_idx')) {
            Schema::table('recipient_monitoring_submissions', function (Blueprint $table): void {
                $table->index(
                    ['recipient_monitoring_cycle_id', 'scholarship_application_id'],
                    'monitoring_sub_cycle_application_idx'
                );
            });
        }

        if ($this->hasIndex('recipient_monitoring_submissions', 'recipient_monitoring_submission_unique')) {
            Schema::table('recipient_monitoring_submissions', function (Blueprint $table): void {
                $table->dropUnique('recipient_monitoring_submission_unique');
            });
        }

        if (! Schema::hasColumn('recipient_monitoring_submissions', 'recipient_monitoring_cycle_requirement_id')) {
            Schema::table('recipient_monitoring_submissions', function (Blueprint $table): void {
                $table->foreignId('recipient_monitoring_cycle_requirement_id')->nullable()->after('recipient_monitoring_cycle_id');
                $table->foreign('recipient_monitoring_cycle_requirement_id', 'monitoring_sub_requirement_fk')
                    ->references('id')
                    ->on('recipient_monitoring_cycle_requirements')
                    ->cascadeOnDelete();
                $table->text('applicant_note')->nullable()->after('applicant_id');
                $table->unique(
                    ['recipient_monitoring_cycle_requirement_id', 'scholarship_application_id'],
                    'monitoring_sub_requirement_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::table('recipient_monitoring_submissions', function (Blueprint $table): void {
            $table->dropUnique('monitoring_sub_requirement_unique');
            $table->dropForeign('monitoring_sub_requirement_fk');
            $table->dropColumn(['recipient_monitoring_cycle_requirement_id', 'applicant_note']);
            $table->unique(
                ['recipient_monitoring_cycle_id', 'scholarship_application_id'],
                'recipient_monitoring_submission_unique'
            );
            $table->dropIndex('monitoring_sub_cycle_application_idx');
        });

        Schema::dropIfExists('recipient_monitoring_cycle_requirements');

        Schema::table('recipient_monitoring_cycles', function (Blueprint $table): void {
            $table->dropForeign(['recipient_monitoring_plan_id']);
            $table->dropColumn([
                'recipient_monitoring_plan_id',
                'monitoring_plan_version',
                'grace_period_days',
                'allow_exception_requests',
            ]);
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => $index['name'] === $indexName);
    }
};
