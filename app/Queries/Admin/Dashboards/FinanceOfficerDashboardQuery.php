<?php

namespace App\Queries\Admin\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Queries\Admin\FinanceRecordQuery;

final class FinanceOfficerDashboardQuery
{
    public function __construct(private readonly FinanceRecordQuery $finance) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        $summary = $this->finance->summary();
        $exceptions = collect($this->finance->exceptions(['status' => 'all', 'per_page' => 5])['items'])
            ->map(fn (array $payment): array => $this->exceptionItem($payment));
        $payments = collect($this->finance->payments(['period' => 'month', 'per_page' => 5])['items'])
            ->map(fn (array $payment): array => $this->paymentItem($payment));

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'finance-officer',
                'eyebrow' => 'Financial oversight',
                'title' => 'Finance dashboard',
                'description' => 'Review payment exceptions and confirmed provider payments.',
                'icon' => 'fa-solid fa-chart-column',
                'access_scope' => 'Provider service payments and receipts',
            ],
            primaryAction: [
                'label' => $summary['exceptions'] > 0 ? 'Review payment exceptions' : 'Open confirmed payments',
                'url' => $summary['exceptions'] > 0 ? '/admin/workspaces/finance/exceptions' : '/admin/workspaces/finance/payments',
                'icon' => 'fa-solid fa-arrow-right',
            ],
            metrics: [
                ['key' => 'exceptions', 'label' => 'Payment exceptions', 'value' => $summary['exceptions'], 'detail' => $summary['pending_payments'].' pending · '.$summary['failed_payments'].' failed', 'tone' => 'attention', 'url' => '/admin/workspaces/finance/exceptions', 'icon' => 'fa-solid fa-triangle-exclamation'],
                ['key' => 'month', 'label' => 'This month', 'value' => $this->money($summary['month'], $summary['currency']), 'detail' => 'Confirmed revenue', 'tone' => 'positive', 'url' => '/admin/workspaces/finance/payments?period=month', 'icon' => 'fa-solid fa-calendar-days'],
                ['key' => 'today', 'label' => 'Today', 'value' => $this->money($summary['today'], $summary['currency']), 'detail' => 'Confirmed revenue', 'url' => '/admin/workspaces/finance/payments?period=today', 'icon' => 'fa-solid fa-coins'],
                ['key' => 'confirmed', 'label' => 'Confirmed payments', 'value' => $summary['successful_payments'], 'detail' => 'All recorded payments', 'tone' => 'positive', 'url' => '/admin/workspaces/finance/payments', 'icon' => 'fa-solid fa-receipt'],
            ],
            sections: [
                [
                    'key' => 'payment-exceptions',
                    'eyebrow' => 'Current focus',
                    'title' => 'Payments needing review',
                    'description' => 'Pending and failed payment records appear here.',
                    'empty_message' => 'No payment exception needs review.',
                    'icon' => 'fa-solid fa-triangle-exclamation',
                    'action' => ['label' => 'Open exceptions', 'url' => '/admin/workspaces/finance/exceptions', 'icon' => 'fa-solid fa-arrow-right'],
                    'items' => $exceptions,
                ],
                [
                    'key' => 'recent-payments',
                    'eyebrow' => 'Confirmed payments',
                    'title' => 'Recent receipts this month',
                    'description' => 'A concise view of the latest confirmed provider payments.',
                    'empty_message' => 'No confirmed payment was recorded this month.',
                    'icon' => 'fa-solid fa-receipt',
                    'action' => ['label' => 'Open payment records', 'url' => '/admin/workspaces/finance/payments', 'icon' => 'fa-solid fa-arrow-right'],
                    'items' => $payments,
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function exceptionItem(array $payment): array
    {
        return [
            'id' => (int) $payment['id'],
            'title' => $payment['service'],
            'subtitle' => $payment['provider'],
            'image_url' => $payment['provider_logo_url'],
            'status' => ['label' => ucfirst($payment['status']), 'tone' => 'attention'],
            'meta' => [
                $payment['reference_number'],
                $this->money($payment['amount'], $payment['currency']),
            ],
            'action' => ['label' => 'Open exceptions', 'url' => '/admin/workspaces/finance/exceptions?search='.urlencode($payment['reference_number'])],
        ];
    }

    /** @return array<string, mixed> */
    private function paymentItem(array $payment): array
    {
        return [
            'id' => (int) $payment['id'],
            'title' => $payment['service'],
            'subtitle' => $payment['provider'],
            'image_url' => $payment['provider_logo_url'],
            'status' => ['label' => 'Confirmed', 'tone' => 'positive'],
            'meta' => [
                $this->money($payment['amount'], $payment['currency']),
                $payment['paid_at'] ?? 'Payment date unavailable',
            ],
            'action' => ['label' => 'Open receipt', 'url' => $payment['receipt_url']],
        ];
    }

    private function money(int $amount, string $currency): string
    {
        return $currency.' '.number_format($amount / 100, 2);
    }
}
