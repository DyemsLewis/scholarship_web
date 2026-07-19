<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->json('selection_stages')->nullable()->after('application_mode');
        });

        DB::table('scholarships')
            ->whereNull('selection_stages')
            ->update(['selection_stages' => json_encode(['screening', 'distribution'])]);

        Schema::create('scholarship_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('title');
            $table->dateTime('scheduled_at');
            $table->string('mode', 30)->default('onsite');
            $table->string('venue', 500)->nullable();
            $table->text('location_address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('online_url')->nullable();
            $table->text('instructions')->nullable();
            $table->string('status', 30)->default('scheduled');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scholarship_id', 'type']);
            $table->index(['scheduled_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_events');

        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn('selection_stages');
        });
    }
};
