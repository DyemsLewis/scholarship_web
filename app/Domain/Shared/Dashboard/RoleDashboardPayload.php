<?php

namespace App\Domain\Shared\Dashboard;

final class RoleDashboardPayload
{
    public const MAX_METRICS = 4;

    public const MAX_SECTIONS = 3;

    public const MAX_SECTION_ITEMS = 5;

    /**
     * @param  iterable<array<string, mixed>>  $metrics
     * @param  iterable<array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    public static function make(
        array $workspace,
        iterable $metrics = [],
        iterable $sections = [],
        ?array $primaryAction = null,
    ): array {
        return [
            'contract_version' => 1,
            'workspace' => self::workspace($workspace),
            'primary_action' => self::action($primaryAction),
            'metrics' => collect($metrics)
                ->filter(fn ($metric): bool => is_array($metric))
                ->take(self::MAX_METRICS)
                ->values()
                ->map(fn (array $metric, int $index): array => self::metric($metric, $index))
                ->all(),
            'sections' => collect($sections)
                ->filter(fn ($section): bool => is_array($section))
                ->take(self::MAX_SECTIONS)
                ->values()
                ->map(fn (array $section, int $index): array => self::section($section, $index))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function workspace(array $workspace): array
    {
        return [
            'key' => self::text($workspace['key'] ?? null, 'workspace'),
            'eyebrow' => self::text($workspace['eyebrow'] ?? null, 'Workspace'),
            'title' => self::text($workspace['title'] ?? null, 'Dashboard'),
            'description' => trim((string) ($workspace['description'] ?? '')),
            'icon' => self::text($workspace['icon'] ?? null, 'fa-solid fa-gauge-high'),
            'organization_name' => trim((string) ($workspace['organization_name'] ?? '')),
            'access_scope' => trim((string) ($workspace['access_scope'] ?? '')),
        ];
    }

    /** @return array<string, mixed> */
    private static function metric(array $metric, int $index): array
    {
        return [
            'key' => self::text($metric['key'] ?? null, 'metric-'.($index + 1)),
            'label' => self::text($metric['label'] ?? null, 'Metric'),
            'value' => $metric['value'] ?? 0,
            'detail' => trim((string) ($metric['detail'] ?? '')),
            'tone' => self::tone($metric['tone'] ?? null),
            'url' => self::nullableText($metric['url'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private static function section(array $section, int $index): array
    {
        return [
            'key' => self::text($section['key'] ?? null, 'section-'.($index + 1)),
            'eyebrow' => trim((string) ($section['eyebrow'] ?? '')),
            'title' => self::text($section['title'] ?? null, 'Work items'),
            'description' => trim((string) ($section['description'] ?? '')),
            'empty_message' => self::text($section['empty_message'] ?? null, 'Nothing needs attention here.'),
            'action' => self::action(is_array($section['action'] ?? null) ? $section['action'] : null),
            'items' => collect($section['items'] ?? [])
                ->filter(fn ($item): bool => is_array($item))
                ->take(self::MAX_SECTION_ITEMS)
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, string>|null */
    private static function action(?array $action): ?array
    {
        $label = trim((string) ($action['label'] ?? ''));
        $url = trim((string) ($action['url'] ?? ''));

        if ($label === '' || $url === '') {
            return null;
        }

        return [
            'label' => $label,
            'url' => $url,
            'icon' => self::text($action['icon'] ?? null, 'fa-solid fa-arrow-right'),
        ];
    }

    private static function tone(mixed $tone): string
    {
        return in_array($tone, ['neutral', 'attention', 'positive'], true)
            ? $tone
            : 'neutral';
    }

    private static function text(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : $fallback;
    }

    private static function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
