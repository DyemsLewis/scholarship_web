<?php

namespace App\Support;

use App\Models\ScholarshipEvent;

class ScholarshipEventPayload
{
    public static function make(ScholarshipEvent $event): array
    {
        return [
            'id' => $event->id,
            'scholarship_id' => $event->scholarship_id,
            'type' => $event->type,
            'title' => $event->title,
            'scheduled_at' => $event->scheduled_at?->format('Y-m-d\TH:i'),
            'scheduled_label' => $event->scheduled_at?->format('M d, Y h:i A'),
            'mode' => $event->mode,
            'venue' => $event->venue,
            'location_address' => $event->location_address,
            'latitude' => $event->latitude,
            'longitude' => $event->longitude,
            'online_url' => $event->online_url,
            'instructions' => $event->instructions,
            'status' => $event->status,
            'updated_at' => $event->updated_at?->format('M d, Y h:i A'),
        ];
    }
}
