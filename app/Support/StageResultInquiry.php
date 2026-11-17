<?php

namespace App\Support;

use App\Models\ScholarshipApplication;

final class StageResultInquiry
{
    public static function message(ScholarshipApplication $application): string
    {
        $program = $application->scholarship;
        foreach ([$program?->contact_email, $program?->provider?->providerProfile?->provider_contact_email] as $candidate) {
            $email = is_string($candidate) ? trim($candidate) : '';
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return " For more information about this result, email the provider at {$email}.";
            }
        }

        return ' For more information about this result, contact the provider using the contact details in your application.';
    }
}
