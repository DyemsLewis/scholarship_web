<?php

namespace Tests\Unit;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use PHPUnit\Framework\TestCase;

class RoleDashboardPayloadTest extends TestCase
{
    public function test_dashboard_payload_enforces_information_density_limits(): void
    {
        $payload = RoleDashboardPayload::make(
            workspace: ['key' => 'reviews', 'title' => 'Review dashboard'],
            metrics: collect(range(1, 6))->map(fn (int $number): array => [
                'key' => "metric-{$number}",
                'label' => "Metric {$number}",
                'value' => $number,
            ]),
            sections: collect(range(1, 4))->map(fn (int $section): array => [
                'key' => "section-{$section}",
                'title' => "Section {$section}",
                'items' => collect(range(1, 7))->map(fn (int $item): array => ['id' => $item]),
            ]),
        );

        $this->assertCount(RoleDashboardPayload::MAX_METRICS, $payload['metrics']);
        $this->assertCount(RoleDashboardPayload::MAX_SECTIONS, $payload['sections']);
        $this->assertCount(RoleDashboardPayload::MAX_SECTION_ITEMS, $payload['sections'][0]['items']);
    }

    public function test_dashboard_payload_normalizes_actions_tones_and_defaults(): void
    {
        $payload = RoleDashboardPayload::make(
            workspace: [],
            metrics: [['label' => '', 'tone' => 'rainbow']],
            sections: [['items' => []]],
            primaryAction: ['label' => 'Open queue'],
        );

        $this->assertSame(1, $payload['contract_version']);
        $this->assertSame('Dashboard', $payload['workspace']['title']);
        $this->assertNull($payload['primary_action']);
        $this->assertSame('neutral', $payload['metrics'][0]['tone']);
        $this->assertSame('Nothing needs attention here.', $payload['sections'][0]['empty_message']);
    }
}
