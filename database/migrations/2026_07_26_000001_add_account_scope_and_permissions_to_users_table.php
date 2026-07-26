<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('parent_account_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('account_title')->nullable()->after('role');
            $table->json('permissions')->nullable()->after('account_title');
            $table->index(['parent_account_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['parent_account_id', 'role']);
            $table->dropConstrainedForeignId('parent_account_id');
            $table->dropColumn(['account_title', 'permissions']);
        });
    }
};
