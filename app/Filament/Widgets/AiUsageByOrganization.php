<?php

namespace App\Filament\Widgets;

use App\Models\Organization;
use App\Services\Billing\Entitlements;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\DB;

/** This month's AI usage per organisation against the plan allowance. */
class AiUsageByOrganization extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'AI usage this month per organisation';

    public function table(Table $table): Table
    {
        $month = now()->startOfMonth()->toDateString();
        $usage = fn (string $metric) => DB::table('usage_records')->select('quantity')
            ->whereColumn('usage_records.organization_id', 'organizations.id')
            ->where('metric', $metric)->where('period', $month)->limit(1);

        return $table
            ->query(Organization::query()
                ->addSelect(['organizations.*', 'ai_reviews' => $usage('reviews_processed'), 'ai_cost' => $usage('ai_cost_microcents')]))
            ->defaultSort('ai_cost', 'desc')
            ->paginated([10, 25])
            ->columns([
                TextColumn::make('name')->label('Organisation')->searchable(),
                TextColumn::make('plan')->badge()->placeholder('none'),
                ViewColumn::make('usage_bar')->label('AI reviews used')
                    ->view('filament.usage-bar')
                    ->getStateUsing(function (Organization $record) {
                        $allowance = app(Entitlements::class)->aiReviewAllowance($record);

                        return ['used' => (int) $record->ai_reviews, 'allowance' => $allowance];
                    }),
                TextColumn::make('ai_cost')->label('Cost')->sortable()
                    ->formatStateUsing(fn ($state) => '$ '.number_format(((int) $state) / 100_000_000, 2))
                    ->default(0),
            ]);
    }
}
