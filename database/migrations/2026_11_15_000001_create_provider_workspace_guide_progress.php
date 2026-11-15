<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_workspace_guide_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('workspace_key', 50);
            $table->unsignedSmallInteger('guide_version');
            $table->string('status', 20);
            $table->timestamps();
            $table->unique(['user_id', 'workspace_key', 'guide_version'], 'provider_guide_account_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_workspace_guide_progress');
    }
};
