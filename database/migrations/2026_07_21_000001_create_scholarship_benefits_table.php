<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_benefits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('title', 150)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('coverage', 30)->nullable();
            $table->string('frequency', 30)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['scholarship_id', 'sort_order']);
            $table->index('type');
        });

        $now = now();

        DB::table('scholarships')
            ->whereNotNull('award_amount')
            ->orderBy('id')
            ->chunkById(100, function ($scholarships) use ($now): void {
                $rows = $scholarships->map(fn ($scholarship): array => [
                    'scholarship_id' => $scholarship->id,
                    'type' => 'cash_grant',
                    'title' => 'Cash grant',
                    'amount' => $scholarship->award_amount,
                    'coverage' => null,
                    'frequency' => 'one_time',
                    'description' => null,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('scholarship_benefits')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_benefits');
    }
};
