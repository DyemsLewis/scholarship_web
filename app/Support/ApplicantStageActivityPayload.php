<?php

namespace App\Support;

use App\Models\ApplicationStageAssignment;

class ApplicantStageActivityPayload
{
    public static function make(ApplicationStageAssignment $assignment): ?array
    {
        $assignment->loadMissing(['session', 'attendance']);
        $session = $assignment->session;

        if (! $session || $session->status === 'draft') {
            return null;
        }

        $isActive = in_array($assignment->status, ['assigned', 'acknowledged'], true)
            && in_array($session->status, ['scheduled', 'ongoing'], true);
        $status = in_array($assignment->status, ['rescheduled', 'cancelled'], true)
            ? $assignment->status
            : $session->status;

        return [
            'id' => (int) $assignment->id,
            'source' => 'stage_session',
            'type' => $session->stage_key,
            'title' => $session->title,
            'scheduled_at' => $session->starts_at?->toIso8601String(),
            'scheduled_label' => $session->starts_at?->format('M d, Y h:i A'),
            'ends_at' => $session->ends_at?->toIso8601String(),
            'ends_label' => $session->ends_at?->format('M d, Y h:i A'),
            'timezone' => $session->timezone,
            'mode' => $session->mode,
            'venue' => $session->venue,
            'location_address' => $session->location_address,
            'latitude' => $session->latitude,
            'longitude' => $session->longitude,
            'map_url' => self::mapUrl($session->latitude, $session->longitude, $session->location_address, $session->venue),
            'online_url' => $isActive && in_array($session->mode, ['online', 'hybrid'], true)
                ? $session->meeting_url
                : null,
            'instructions' => $session->instructions,
            'status' => $status,
            'attendance_status' => $assignment->attendance?->status,
            'requires_applicant_acknowledgment' => $isActive && $assignment->status === 'assigned',
            'applicant_acknowledged' => $assignment->acknowledged_at !== null,
            'applicant_acknowledged_at' => $assignment->acknowledged_at?->format('M d, Y h:i A'),
            'completed_at' => $session->completed_at?->format('M d, Y h:i A'),
            'cancelled_at' => $session->cancelled_at?->format('M d, Y h:i A'),
        ];
    }

    private static function mapUrl(mixed $latitude, mixed $longitude, ?string $address, ?string $venue): ?string
    {
        if ($latitude !== null && $longitude !== null) {
            return "https://www.openstreetmap.org/?mlat={$latitude}&mlon={$longitude}#map=16/{$latitude}/{$longitude}";
        }

        $query = $address ?: $venue;

        return filled($query)
            ? 'https://www.openstreetmap.org/search?query='.rawurlencode($query)
            : null;
    }
}
