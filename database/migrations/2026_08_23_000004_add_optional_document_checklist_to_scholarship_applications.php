<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->json('optional_document_checklist')->nullable()->after('document_checklist');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->dropColumn('optional_document_checklist');
        });
    }
};
