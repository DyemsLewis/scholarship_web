<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipient_agreement_cases', function (Blueprint $table): void {
            // Older release records can be disputed without inventing agreement acceptance.
            $table->string('agreement_version')->nullable()->change();
            $table->timestamp('agreement_accepted_at')->nullable()->change();
            $table->string('issue_kind', 32)->nullable();
            $table->string('benefit_period', 120)->nullable();
            $table->string('open_delivery_key', 64)->nullable()->unique('delivery_open_source');
            $table->foreignId('legacy_receipt_response_id')->nullable()->unique('delivery_legacy_receipt')
                ->constrained('recipient_benefit_receipt_responses', indexName: 'delivery_receipt_fk')->nullOnDelete();
            $table->date('follow_up_on')->nullable();
            $table->foreignId('proposal_response_id')->nullable()
                ->constrained('recipient_agreement_case_responses', indexName: 'delivery_proposal_fk')->nullOnDelete();
            $table->foreignId('delivery_record_id')->nullable()
                ->constrained('recipient_benefit_release_records', indexName: 'delivery_current_record_fk')->nullOnDelete();
        });
        Schema::table('recipient_agreement_case_responses', function (Blueprint $table): void {
            $table->foreignId('delivery_record_id')->nullable()
                ->constrained('recipient_benefit_release_records', indexName: 'delivery_response_record_fk')->nullOnDelete();
            $table->date('delivered_on')->nullable();
            $table->date('follow_up_on')->nullable();
        });
        Schema::table('recipient_benefit_receipt_responses', function (Blueprint $table): void {
            $table->timestamp('recipient_resolution_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('recipient_agreement_cases')->whereNull('agreement_version')->orWhereNull('agreement_accepted_at')->exists()) {
            throw new RuntimeException('Preserve legacy delivery reports before rollback; use a verified database backup rather than fabricating acceptance.');
        }
        Schema::table('recipient_benefit_receipt_responses', fn (Blueprint $table) => $table->dropColumn('recipient_resolution_confirmed_at'));
        Schema::table('recipient_agreement_case_responses', function (Blueprint $table): void {
            $table->dropForeign('delivery_response_record_fk');
            $table->dropColumn(['delivery_record_id', 'delivered_on', 'follow_up_on']);
        });
        Schema::table('recipient_agreement_cases', function (Blueprint $table): void {
            $table->dropForeign('delivery_receipt_fk');
            $table->dropForeign('delivery_proposal_fk');
            $table->dropForeign('delivery_current_record_fk');
            $table->dropUnique('delivery_open_source');
            $table->dropUnique('delivery_legacy_receipt');
            $table->dropColumn(['issue_kind', 'benefit_period', 'open_delivery_key', 'legacy_receipt_response_id', 'follow_up_on', 'proposal_response_id', 'delivery_record_id']);
            $table->string('agreement_version')->nullable(false)->change();
            $table->timestamp('agreement_accepted_at')->nullable(false)->change();
        });
    }
};
