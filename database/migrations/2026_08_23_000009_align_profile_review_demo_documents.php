<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Bukas Kinabukasan School Essentials Grant';

    public function up(): void
    {
        DB::table('scholarships')
            ->where('title', self::TITLE)
            ->update([
                'requirements' => null,
                'optional_requirements' => null,
                'post_qualification_requirements' => implode("\n", [
                    'Original learner enrollment record',
                    'Latest report card or grades',
                    'Learner school ID when available',
                    'Parent or guardian valid ID',
                    'Provider formal application form signed by the guardian',
                ]),
            ]);
    }

    public function down(): void
    {
        DB::table('scholarships')
            ->where('title', self::TITLE)
            ->update([
                'requirements' => "Certificate of enrollment\nLatest report card or grades",
                'post_qualification_requirements' => implode("\n", [
                    'Original learner enrollment record',
                    'Learner school ID when available',
                    'Parent or guardian valid ID',
                    'Provider formal application form signed by the guardian',
                ]),
            ]);
    }
};
