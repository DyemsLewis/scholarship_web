<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_service_purchases', function (Blueprint $table): void {
            $table->string('current_action_owner', 20)->nullable()->after('fulfillment_status');
            $table->string('current_action', 180)->nullable()->after('current_action_owner');
            $table->timestamp('action_due_at')->nullable()->after('current_action');
            $table->timestamp('started_at')->nullable()->after('paid_at');
            $table->timestamp('closed_at')->nullable()->after('fulfilled_at');
            $table->unsignedSmallInteger('revision_count')->default(0)->after('reopened_at');

            $table->index(
                ['current_action_owner', 'action_due_at'],
                'provider_services_action_owner_due_index',
            );
        });

        Schema::create('provider_service_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_service_purchase_id')
                ->constrained('provider_service_purchases')
                ->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 60);
            $table->string('payment_from', 40)->nullable();
            $table->string('payment_to', 40)->nullable();
            $table->string('delivery_from', 40)->nullable();
            $table->string('delivery_to', 40)->nullable();
            $table->string('action_owner', 20)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['provider_service_purchase_id', 'created_at'],
                'provider_service_lifecycle_purchase_created_index',
            );
            $table->index(['event_type', 'created_at'], 'provider_service_lifecycle_type_created_index');
        });

        DB::table('provider_service_purchases')
            ->orderBy('id')
            ->chunkById(100, function ($purchases): void {
                foreach ($purchases as $purchase) {
                    $deliveryStatus = $purchase->status === 'paid'
                        ? (string) $purchase->fulfillment_status
                        : ((string) $purchase->fulfillment_status === 'ready' ? 'queued' : (string) $purchase->fulfillment_status);
                    [$owner, $action] = $this->nextAction(
                        (string) $purchase->status,
                        $deliveryStatus,
                        $purchase->meeting_status,
                    );

                    $startedAt = in_array($deliveryStatus, ['in_progress', 'provider_review', 'completed'], true)
                        ? ($purchase->paid_at ?: $purchase->updated_at)
                        : null;
                    $closedAt = $deliveryStatus === 'completed'
                        ? ($purchase->fulfilled_at ?: $purchase->updated_at)
                        : null;

                    DB::table('provider_service_purchases')
                        ->where('id', $purchase->id)
                        ->update([
                            'fulfillment_status' => $deliveryStatus,
                            'current_action_owner' => $owner,
                            'current_action' => $action,
                            'action_due_at' => $owner ? $purchase->target_due_at : null,
                            'started_at' => $startedAt,
                            'closed_at' => $closedAt,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_service_lifecycle_events');

        Schema::table('provider_service_purchases', function (Blueprint $table): void {
            $table->dropIndex('provider_services_action_owner_due_index');
            $table->dropColumn([
                'current_action_owner',
                'current_action',
                'action_due_at',
                'started_at',
                'closed_at',
                'revision_count',
            ]);
        });
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function nextAction(string $paymentStatus, string $deliveryStatus, ?string $meetingStatus): array
    {
        if ($paymentStatus === 'pending') {
            return ['provider', 'Complete service payment'];
        }

        if ($paymentStatus === 'failed') {
            return ['provider', 'Restart service checkout'];
        }

        if ($paymentStatus !== 'paid') {
            return ['platform', 'Review payment state'];
        }

        return match ($deliveryStatus) {
            'needs_information' => ['provider', 'Provide requested information'],
            'provider_review' => ['provider', 'Review the delivered work'],
            'completed' => [null, null],
            default => $meetingStatus === 'requested'
                ? ['platform', 'Review the meeting request']
                : ['platform', $deliveryStatus === 'in_progress'
                    ? 'Continue service delivery'
                    : 'Assign and start the service'],
        };
    }
};
