<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $terms = [
            'Tulay Aral Senior High Support Grant' => [
                'responsibilities' => 'Attend one recipient orientation and submit one end-of-term update explaining how the assistance was used.',
                'required_evidence' => 'Signed orientation attendance and the provider end-of-term utilization form.',
                'release_conditions' => 'The grant and supplies are released after identity, enrollment, and recipient agreement verification. No later release is scheduled for this one-time package.',
            ],
            'Tulay Aral College Starter Grant' => [
                'responsibilities' => 'Remain enrolled for the first semester and submit a brief update explaining how the college-entry assistance was used.',
                'required_evidence' => 'Official proof of enrollment before release and the provider utilization form after the first semester.',
                'release_conditions' => 'The one-time grant is released after identity, enrollment, original-document, and recipient agreement verification.',
            ],
            'Bukas Kinabukasan School Essentials Grant' => [
                'responsibilities' => 'The recipient and parent or guardian must attend the release orientation and sign the itemized receipt for the school materials.',
                'required_evidence' => 'Signed orientation attendance and an itemized acknowledgment receipt for the materials received.',
                'release_conditions' => 'School materials are released after identity, enrollment, guardian, orientation, and recipient agreement verification.',
            ],
            'Bukas Kinabukasan STEM Pathways Grant' => [
                'responsibilities' => 'Remain enrolled in the approved STEM track and complete one community learning session during the award period.',
                'required_evidence' => 'Current enrollment record, latest official grade record when requested, and provider-confirmed attendance for the community learning session.',
                'release_conditions' => 'The STEM grant is released after identity, enrollment, final selection, original-document, and recipient agreement verification.',
            ],
            'Tulay Aral Continuing Scholar Grant' => [
                'responsibilities' => 'Remain enrolled, attend the recipient orientation, and submit one academic progress update at the end of each semester.',
                'required_evidence' => 'Official report card or certified grade record for each semester and a signed attendance record for orientation.',
                'release_conditions' => 'The first allowance is prepared after identity and enrollment verification. Any later release requires the recipient agreement to remain active and all due monitoring records to be reviewed.',
            ],
        ];

        foreach ($terms as $title => $additionalTerms) {
            $row = DB::table('scholarships')->where('title', $title)->first();

            if (! $row) {
                continue;
            }

            $agreement = json_decode((string) $row->recipient_agreement, true);

            if (! is_array($agreement)) {
                continue;
            }

            DB::table('scholarships')
                ->where('id', $row->id)
                ->update(['recipient_agreement' => json_encode([...$agreement, ...$additionalTerms])]);
        }
    }

    public function down(): void
    {
        $titles = [
            'Tulay Aral Senior High Support Grant',
            'Tulay Aral College Starter Grant',
            'Bukas Kinabukasan School Essentials Grant',
            'Bukas Kinabukasan STEM Pathways Grant',
            'Tulay Aral Continuing Scholar Grant',
        ];

        DB::table('scholarships')
            ->whereIn('title', $titles)
            ->get()
            ->each(function (object $row): void {
                $agreement = json_decode((string) $row->recipient_agreement, true);

                if (! is_array($agreement)) {
                    return;
                }

                unset($agreement['responsibilities'], $agreement['required_evidence'], $agreement['release_conditions']);

                DB::table('scholarships')
                    ->where('id', $row->id)
                    ->update(['recipient_agreement' => json_encode($agreement)]);
            });
    }
};
