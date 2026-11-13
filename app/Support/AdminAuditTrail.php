<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

final class AdminAuditTrail
{
    public const RECORDS = [
        'account' => ['target_user_id', 'applicant_id', 'provider_id', 'user_id'],
        'application' => ['application_id', 'scholarship_application_id'],
        'program' => ['scholarship_id'],
        'service' => ['purchase_id', 'provider_service_purchase_id'],
        'support' => ['support_report_id'],
    ];

    public static function apply(Builder $query, string $type, int $id): void
    {
        $query->where(function (Builder $related) use ($type, $id): void {
            foreach (self::RECORDS[$type] as $key) {
                // Preserve exact JSON types; older entries sometimes stored numeric IDs as strings.
                $related->orWhereJsonContains('metadata->'.$key, $id)
                    ->orWhereJsonContains('metadata->'.$key, (string) $id);
            }
        });
    }

    public static function links(array $metadata): array
    {
        $links = [];
        foreach (self::RECORDS as $type => $keys) {
            foreach ($keys as $key) {
                $id = filter_var($metadata[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (! $id) {
                    continue;
                }
                $links[] = [
                    'label' => 'Related '.$type.' history',
                    'url' => route('admin.workspaces.records.activity', ['record_type' => $type, 'record_id' => $id, 'period' => 'all'], false),
                ];
                break;
            }
        }

        return $links;
    }
}
