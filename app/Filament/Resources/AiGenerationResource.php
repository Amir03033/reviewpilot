<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiGenerationResource\Pages;
use App\Models\AiGeneration;
use App\Models\Organization;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Read-only platform view across all organisations. */
class AiGenerationResource extends Resource
{
    protected static ?string $model = AiGeneration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 5;

    protected static string|UnitEnum|null $navigationGroup = 'Health';

    protected static ?string $navigationLabel = 'AI usage';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('organization_id')->label('Organisation')
                    ->formatStateUsing(fn ($state) => Organization::withTrashed()->whereKey($state)->value('name') ?? '—'),
                TextColumn::make('purpose')->badge(),
                TextColumn::make('model'),
                TextColumn::make('status')->badge()->color(fn ($state) => $state === 'ok' ? 'success' : 'danger'),
                TextColumn::make('input_tokens')->numeric(),
                TextColumn::make('output_tokens')->numeric(),
                TextColumn::make('cost_microcents')->label('Cost')->formatStateUsing(fn ($state) => '$ '.number_format($state / 100_000_000, 4)),
                TextColumn::make('latency_ms')->label('ms')->numeric(),
                TextColumn::make('error')->limit(50)->tooltip(fn ($state) => $state)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(['ok' => 'OK', 'invalid_output' => 'Invalid output', 'error' => 'Error']),
                SelectFilter::make('purpose')->options(['analysis' => 'Analysis', 'draft' => 'Draft', 'voice_preview' => 'Voice preview']),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAiGenerations::route('/')];
    }
}
