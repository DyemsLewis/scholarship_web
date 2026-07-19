<?php

namespace App\Support;

use App\Models\ApplicationSchedule;

class ApplicationSchedulePayload
{
    public static function make(ApplicationSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'type' => $schedule->type,
            'title' => $schedule->title,
            'scheduled_at' => $schedule->scheduled_at?->format('Y-m-d\TH:i'),
            'scheduled_label' => $schedule->scheduled_at?->format('M d, Y h:i A'),
            'mode' => $schedule->mode,
            'venue' => $schedule->venue,
            'location_address' => $schedule->location_address,
            'latitude' => $schedule->latitude,
            'longitude' => $schedule->longitude,
            'map_url' => self::mapUrl($schedule),
            'online_url' => $schedule->online_url,
            'instructions' => $schedule->instructions,
            'status' => $schedule->status,
            'attendance_status' => $schedule->attendance_status,
            'attendance_notes' => $schedule->attendance_notes,
            'applicant_acknowledged' => $schedule->applicant_acknowledged_at !== null,
            'applicant_acknowledged_at' => $schedule->applicant_acknowledged_at?->format('M d, Y h:i A'),
            'completed_at' => $schedule->completed_at?->format('M d, Y h:i A'),
            'cancelled_at' => $schedule->cancelled_at?->format('M d, Y h:i A'),
            'updated_at' => $schedule->updated_at?->format('M d, Y h:i A'),
        ];
    }

    private static function mapUrl(ApplicationSchedule $schedule): ?string
    {
        if ($schedule->latitude !== null && $schedule->longitude !== null) {
            return "https://www.openstreetmap.org/?mlat={$schedule->latitude}&mlon={$schedule->longitude}#map=16/{$schedule->latitude}/{$schedule->longitude}";
        }

        $query = $schedule->location_address ?: $schedule->venue;

        return filled($query)
            ? 'https://www.openstreetmap.org/search?query='.rawurlencode($query)
            : null;
    }
}
