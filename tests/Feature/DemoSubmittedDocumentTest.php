<?php

namespace Tests\Feature;

use Database\Seeders\Support\DemoSubmittedDocument;
use Tests\TestCase;

class DemoSubmittedDocumentTest extends TestCase
{
    public function test_demo_applicant_submissions_use_real_sample_images_instead_of_svg_placeholders(): void
    {
        $types = [
            'academic_record',
            'recent_school_id',
            'achievement_evidence',
            'Certificate of enrollment',
            'Latest report card or grades',
            'Recent school ID',
            'Proof of household income',
        ];

        foreach ($types as $type) {
            $document = DemoSubmittedDocument::get($type, 1);

            $this->assertNotSame('image/svg+xml', $document['mime_type']);
            $this->assertContains($document['extension'], ['jpg', 'png']);
            $this->assertNotSame('', $document['contents']);
            $this->assertGreaterThan(0, $document['size']);
        }
    }

    public function test_academic_income_and_achievement_examples_rotate_between_applicants(): void
    {
        $this->assertNotSame(
            DemoSubmittedDocument::get('academic_record', 1)['original_name'],
            DemoSubmittedDocument::get('academic_record', 2)['original_name'],
        );
        $this->assertNotSame(
            DemoSubmittedDocument::get('proof_of_household_income', 1)['original_name'],
            DemoSubmittedDocument::get('proof_of_household_income', 2)['original_name'],
        );
        $this->assertNotSame(
            DemoSubmittedDocument::get('achievement_evidence', 1)['original_name'],
            DemoSubmittedDocument::get('achievement_evidence', 2)['original_name'],
        );
    }
}
