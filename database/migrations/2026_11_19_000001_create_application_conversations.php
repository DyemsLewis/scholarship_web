<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_application_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('applicant_read_through_id')->default(0);
            $table->unsignedBigInteger('provider_read_through_id')->default(0);
            $table->timestamp('last_message_at')->index();
            $table->timestamps();
        });

        Schema::create('application_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_side', 12);
            $table->uuid('request_key');
            $table->text('body');
            $table->timestamp('created_at');
            $table->unique(['application_conversation_id', 'sender_id', 'request_key'], 'application_message_request_unique');
            $table->index(['application_conversation_id', 'id'], 'application_message_history');
            $table->index(['application_conversation_id', 'sender_side', 'id'], 'application_message_unread');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_messages');
        Schema::dropIfExists('application_conversations');
    }
};
