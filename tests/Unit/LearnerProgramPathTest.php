<?php

namespace Tests\Unit;

use App\Support\LearnerProgramPath;
use PHPUnit\Framework\TestCase;

class LearnerProgramPathTest extends TestCase
{
    public function test_common_information_technology_names_match(): void
    {
        $this->assertTrue(LearnerProgramPath::matches('BS Information Technology', 'BSIT'));
        $this->assertTrue(LearnerProgramPath::matches('BS IT', 'BS Information Technology'));
        $this->assertTrue(LearnerProgramPath::matches('Bachelor of Science in Information Technology', 'BSIT'));
    }

    public function test_program_path_list_is_saved_with_canonical_labels(): void
    {
        $this->assertSame(
            "BS Information Technology\nSTEM",
            LearnerProgramPath::canonicalizeList("BSIT\nSTEM\nBS Information Technology"),
        );
    }

    public function test_different_courses_do_not_match(): void
    {
        $this->assertFalse(LearnerProgramPath::matches('BS Nursing', 'BSIT'));
    }
}
