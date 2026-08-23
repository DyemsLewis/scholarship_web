<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_service_purchases', function (Blueprint $table): void {
            $table->text('request_summary')->nullable()->after('service_terms_accepted_at');
            $table->text('requested_outcome')->nullable()->after('request_summary');
            $table->string('priority', 20)->default('normal')->after('requested_outcome');
            $table->foreignId('assigned_to')->nullable()->after('priority')->constrained('users')->nullOnDelete();
            $table->timestamp('target_due_at')->nullable()->after('assigned_to');
            $table->json('milestones')->nullable()->after('target_due_at');
            $table->timestamp('provider_confirmed_at')->nullable()->after('fulfilled_at');
            $table->text('provider_feedback')->nullable()->after('provider_confirmed_at');
            $table->unsignedTinyInteger('provider_rating')->nullable()->after('provider_feedback');
            $table->timestamp('reopened_at')->nullable()->after('provider_rating');

            $table->index(['assigned_to', 'fulfillment_status']);
            $table->index(['priority', 'target_due_at']);
        });

        Schema::create('provider_service_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_service_purchase_id')
                ->constrained('provider_service_purchases')
                ->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 40)->default('progress_update');
            $table->text('message');
            $table->boolean('visible_to_provider')->default(true);
            $table->timestamps();

            $table->index(['provider_service_purchase_id', 'created_at'], 'provider_service_updates_purchase_created_index');
        });

        Schema::create('provider_service_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_service_purchase_id')
                ->constrained('provider_service_purchases')
                ->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 30);
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('visible_to_provider')->default(true);
            $table->timestamps();

            $table->index(['provider_service_purchase_id', 'category'], 'provider_service_files_purchase_category_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_service_files');
        Schema::dropIfExists('provider_service_updates');

        Schema::table('provider_service_purchases', function (Blueprint $table): void {
            $table->dropForeign(['assigned_to']);
            $table->dropIndex(['assigned_to', 'fulfillment_status']);
            $table->dropIndex(['priority', 'target_due_at']);
            $table->dropColumn([
                'request_summary',
                'requested_outcome',
                'priority',
                'assigned_to',
                'target_due_at',
                'milestones',
                'provider_confirmed_at',
                'provider_feedback',
                'provider_rating',
                'reopened_at',
            ]);
        });
    }
};
