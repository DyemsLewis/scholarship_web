<?php

namespace App\Support;

use App\Models\ProviderProfile;
use App\Models\Scholarship;
use Illuminate\Support\Str;

final class CoverImageCatalog
{
    private const IMAGES = [
        'campus-community' => [
            'label' => 'Campus community',
            'description' => 'Students walking together on a Philippine campus.',
            'path' => 'images/filipino-campus.jpg',
        ],
        'learning-together' => [
            'label' => 'Learning together',
            'description' => 'A collaborative classroom scene for general programs.',
            'path' => 'images/filipino-students-group-study.jpg',
        ],
        'student-workshop' => [
            'label' => 'Student workshop',
            'description' => 'A practical learning session for skills-focused support.',
            'path' => 'images/filipino-students-workshop.jpg',
        ],
        'computer-learning' => [
            'label' => 'Computer learning',
            'description' => 'Students learning in a computer laboratory.',
            'path' => 'images/filipino-students-computer-lab.jpg',
        ],
        'science-class' => [
            'label' => 'Science class',
            'description' => 'A classroom image for STEM and science programs.',
            'path' => 'images/filipino-students-science-class.jpg',
        ],
        'science-project' => [
            'label' => 'Science project',
            'description' => 'Students working through a hands-on science activity.',
            'path' => 'images/filipino-students-science-experiment.jpg',
        ],
        'graduation-milestone' => [
            'label' => 'Graduation milestone',
            'description' => 'Graduates celebrating an education milestone.',
            'path' => 'images/filipino-graduates.jpg',
        ],
        'young-learners' => [
            'label' => 'Young learners',
            'description' => 'A welcoming image for basic education programs.',
            'path' => 'images/filipino-young-learner.jpg',
        ],
    ];

    public static function options(): array
    {
        return collect(self::IMAGES)
            ->map(fn (array $image, string $key): array => [
                'key' => $key,
                'label' => $image['label'],
                'description' => $image['description'],
                'url' => asset($image['path']),
            ])
            ->values()
            ->all();
    }

    public static function keys(): array
    {
        return array_keys(self::IMAGES);
    }

    public static function has(?string $key): bool
    {
        return filled($key) && array_key_exists($key, self::IMAGES);
    }

    public static function urlForKey(?string $key): ?string
    {
        return self::has($key)
            ? asset(self::IMAGES[$key]['path'])
            : null;
    }

    public static function defaultProviderKey(): string
    {
        return 'campus-community';
    }

    public static function defaultScholarshipKey(?string $category = null): string
    {
        $category = Str::lower((string) $category);

        return match (true) {
            Str::contains($category, ['stem', 'science', 'technology', 'engineering']) => 'science-class',
            Str::contains($category, ['college', 'university', 'graduate', 'academic merit']) => 'graduation-milestone',
            Str::contains($category, ['elementary', 'basic education', 'community']) => 'young-learners',
            Str::contains($category, ['training', 'athletic', 'leadership']) => 'student-workshop',
            default => 'learning-together',
        };
    }

    public static function providerUrl(?ProviderProfile $profile): string
    {
        if (filled($profile?->cover_image_path)) {
            return asset(ltrim($profile->cover_image_path, '/'));
        }

        return self::urlForKey($profile?->cover_image_key)
            ?? self::urlForKey(self::defaultProviderKey());
    }

    public static function scholarshipUrl(Scholarship $scholarship): string
    {
        if (filled($scholarship->cover_image_path)) {
            return asset(ltrim($scholarship->cover_image_path, '/'));
        }

        $systemUrl = self::urlForKey($scholarship->cover_image_key);
        if ($systemUrl !== null) {
            return $systemUrl;
        }

        if (filled($scholarship->image_path)) {
            return asset(ltrim($scholarship->image_path, '/'));
        }

        return self::urlForKey(self::defaultScholarshipKey($scholarship->category));
    }
}
