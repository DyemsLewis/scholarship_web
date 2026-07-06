<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_status')->default('active')->index();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('suspension_reason')->nullable();
            $table->boolean('must_reset_password')->default(false)->index();
            $table->timestamp('password_reset_required_at')->nullable();

            $table->index(['role', 'account_status']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role', 'account_status']);
            $table->dropIndex(['account_status']);
            $table->dropIndex(['must_reset_password']);
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropColumn([
                'account_status',
                'suspended_at',
                'suspension_reason',
                'must_reset_password',
                'password_reset_required_at',
            ]);
        });
    }
};
