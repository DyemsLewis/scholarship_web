<?php

use App\Domain\Recipients\RecipientSupportCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_benefit_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scholarship_application_id');
            $table->foreign('scholarship_application_id', 'rb_entitlement_application_fk')
                ->references('id')
                ->on('scholarship_applications')
                ->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('scholarship_benefit_id')->nullable();
            $table->foreign('scholarship_benefit_id', 'rb_entitlement_benefit_fk')
                ->references('id')
                ->on('scholarship_benefits')
                ->nullOnDelete();
            $table->string('benefit_type');
            $table->string('title');
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('coverage')->nullable();
            $table->string('frequency')->default('one_time');
            $table->string('duration')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default(RecipientSupportCatalog::ENTITLEMENT_PENDING_AGREEMENT)->index();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('withheld_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['scholarship_application_id', 'scholarship_benefit_id'],
                'rb_entitlement_application_benefit_unique'
            );
            $table->index(['scholarship_id', 'status'], 'rb_entitlement_program_status_idx');
            $table->index(['applicant_id', 'status'], 'rb_entitlement_applicant_status_idx');
        });

        Schema::create('recipient_benefit_release_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_benefit_release_record_id');
            $table->foreign('recipient_benefit_release_record_id', 'rb_allocation_record_fk')
                ->references('id')
                ->on('recipient_benefit_release_records')
                ->cascadeOnDelete();
            $table->foreignId('recipient_benefit_entitlement_id');
            $table->foreign('recipient_benefit_entitlement_id', 'rb_allocation_entitlement_fk')
                ->references('id')
                ->on('recipient_benefit_entitlements')
                ->cascadeOnDelete();
            $table->string('status')->default('scheduled')->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['recipient_benefit_release_record_id', 'recipient_benefit_entitlement_id'],
                'rb_allocation_record_entitlement_unique'
            );
            $table->index(
                ['recipient_benefit_entitlement_id', 'status'],
                'rb_allocation_entitlement_status_idx'
            );
        });

        $this->backfillEntitlements();
        $this->backfillReleaseAllocations();
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_benefit_release_allocations');
        Schema::dropIfExists('recipient_benefit_entitlements');
    }

    private function backfillEntitlements(): void
    {
        $now = now();

        DB::table('scholarship_benefits')
            ->orderBy('scholarship_id')
            ->orderBy('id')
            ->each(function (object $benefit) use ($now): void {
                $applications = DB::table('scholarship_applications')
                    ->where('scholarship_id', $benefit->scholarship_id)
                    ->where(function ($query): void {
                        $query->where('final_outcome', 'selected')
                            ->orWhereIn('status', [
                                'awarded',
                                'distribution_scheduled',
                                'disbursed',
                                'renewed',
                                'benefits_terminated',
                            ]);
                    })
                    ->orderBy('id')
                    ->get([
                        'id',
                        'applicant_id',
                        'status',
                        'student_response_status',
                        'outcome_at',
                        'created_at',
                    ]);

                foreach ($applications as $application) {
                    $cancelled = $application->status === 'benefits_terminated'
                        || $application->student_response_status === 'declined';
                    $accepted = $application->student_response_status === 'accepted';
                    $status = match (true) {
                        $cancelled => RecipientSupportCatalog::ENTITLEMENT_CANCELLED,
                        ! $accepted => RecipientSupportCatalog::ENTITLEMENT_PENDING_AGREEMENT,
                        ($benefit->frequency ?: 'one_time') === 'one_time' => RecipientSupportCatalog::ENTITLEMENT_READY,
                        default => RecipientSupportCatalog::ENTITLEMENT_ACTIVE,
                    };

                    DB::table('recipient_benefit_entitlements')->updateOrInsert(
                        [
                            'scholarship_application_id' => $application->id,
                            'scholarship_benefit_id' => $benefit->id,
                        ],
                        [
                            'scholarship_id' => $benefit->scholarship_id,
                            'applicant_id' => $application->applicant_id,
                            'benefit_type' => $benefit->type,
                            'title' => $benefit->title,
                            'amount' => $benefit->amount,
                            'coverage' => $benefit->coverage,
                            'frequency' => $benefit->frequency ?: 'one_time',
                            'duration' => $benefit->duration,
                            'description' => $benefit->description,
                            'status' => $status,
                            'assigned_at' => $application->outcome_at ?? $application->created_at,
                            'ready_at' => $accepted ? $now : null,
                            'cancelled_at' => $cancelled ? $now : null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    );
                }
            });
    }

    private function backfillReleaseAllocations(): void
    {
        $now = now();

        DB::table('recipient_benefit_releases')
            ->orderBy('id')
            ->each(function (object $release) use ($now): void {
                $benefits = DB::table('scholarship_benefits')
                    ->where('scholarship_id', $release->scholarship_id)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();
                $benefitIds = $this->matchingBenefitIds($release, $benefits);

                if ($benefitIds === []) {
                    return;
                }

                $records = DB::table('recipient_benefit_release_records')
                    ->where('recipient_benefit_release_id', $release->id)
                    ->get();

                foreach ($records as $record) {
                    foreach ($benefitIds as $benefitId) {
                        $entitlement = DB::table('recipient_benefit_entitlements')
                            ->where('scholarship_application_id', $record->scholarship_application_id)
                            ->where('scholarship_benefit_id', $benefitId)
                            ->first();

                        if (! $entitlement) {
                            continue;
                        }

                        DB::table('recipient_benefit_release_allocations')->updateOrInsert(
                            [
                                'recipient_benefit_release_record_id' => $record->id,
                                'recipient_benefit_entitlement_id' => $entitlement->id,
                            ],
                            [
                                'status' => $record->status,
                                'scheduled_at' => $release->published_at ?? $release->created_at,
                                'released_at' => $record->status === 'released' ? $record->released_at : null,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ],
                        );

                        $this->syncBackfilledEntitlement($entitlement, $record, $now);
                    }
                }
            });
    }

    /** @return array<int, int> */
    private function matchingBenefitIds(object $release, Collection $benefits): array
    {
        if ($benefits->count() === 1) {
            return [(int) $benefits->first()->id];
        }

        if ($release->amount !== null) {
            $amountMatches = $benefits->filter(
                fn (object $benefit): bool => $benefit->amount !== null
                    && abs((float) $benefit->amount - (float) $release->amount) < 0.005,
            );
            if ($amountMatches->count() === 1) {
                return [(int) $amountMatches->first()->id];
            }
        }

        $releaseText = Str::lower(trim($release->title.' '.$release->benefit_description));
        $textMatches = $benefits->filter(function (object $benefit) use ($releaseText): bool {
            $title = Str::lower(trim((string) $benefit->title));

            return $title !== '' && Str::contains($releaseText, $title);
        });

        return $textMatches->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    private function syncBackfilledEntitlement(object $entitlement, object $record, mixed $now): void
    {
        if ($entitlement->status === RecipientSupportCatalog::ENTITLEMENT_CANCELLED) {
            return;
        }

        $attributes = match (true) {
            $record->status === 'withheld' => [
                'status' => RecipientSupportCatalog::ENTITLEMENT_WITHHELD,
                'withheld_at' => $record->recorded_at ?? $now,
            ],
            $entitlement->frequency === 'one_time' && $record->status === 'released' => [
                'status' => RecipientSupportCatalog::ENTITLEMENT_FULFILLED,
                'fulfilled_at' => $record->released_at ?? $record->recorded_at ?? $now,
            ],
            $entitlement->frequency === 'one_time' && in_array($record->status, ['scheduled', 'prepared'], true) => [
                'status' => RecipientSupportCatalog::ENTITLEMENT_SCHEDULED,
                'scheduled_at' => $record->created_at ?? $now,
            ],
            default => ['status' => $entitlement->frequency === 'one_time'
                ? RecipientSupportCatalog::ENTITLEMENT_READY
                : RecipientSupportCatalog::ENTITLEMENT_ACTIVE],
        };

        DB::table('recipient_benefit_entitlements')
            ->where('id', $entitlement->id)
            ->update([...$attributes, 'updated_at' => $now]);
    }
};
