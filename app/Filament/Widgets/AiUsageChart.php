<?php

namespace App\Filament\Widgets;

use App\Models\AiGeneration;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/** Daily AI cost and number of AI calls across all organisations. */
class AiUsageChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'AI usage per day';

    protected ?string $maxHeight = '260px';

    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?? 30);
        $from = now()->subDays($days - 1)->startOfDay();

        $rows = AiGeneration::withoutGlobalScopes()
            ->where('created_at', '>=', $from)
            ->get(['created_at', 'cost_microcents'])
            ->groupBy(fn (AiGeneration $g) => $g->created_at->toDateString());

        $labels = $cost = $calls = [];
        // Index-based loop: dates are immutable in this app (CarbonImmutable).
        for ($i = 0; $i < $days; $i++) {
            $key = $from->addDays($i)->toDateString();
            $labels[] = Carbon::parse($key)->format('d-m');
            $cost[] = round(($rows->get($key)?->sum('cost_microcents') ?? 0) / 100_000_000, 2);
            $calls[] = $rows->get($key)?->count() ?? 0;
        }

        return [
            'datasets' => [
                ['label' => 'Cost ($)', 'data' => $cost, 'backgroundColor' => '#D9A441', 'borderColor' => '#D9A441', 'yAxisID' => 'y'],
                ['label' => 'AI calls', 'data' => $calls, 'type' => 'line', 'borderColor' => '#8a8478', 'backgroundColor' => '#8a8478', 'yAxisID' => 'y1', 'tension' => 0.3, 'pointRadius' => 0],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['position' => 'left', 'beginAtZero' => true, 'title' => ['display' => true, 'text' => '$']],
                'y1' => ['position' => 'right', 'beginAtZero' => true, 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'calls']],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
