<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GbpLocationLinkResource\Pages;
use App\Models\GbpLocationLink;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Read-only platform view across all organisations. */
class GbpLocationLinkResource extends Resource
{
    protected static ?string $model = GbpLocationLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?int $navigationSort = 4;

    protected static string|UnitEnum|null $navigationGroup = 'Health';

    protected static ?string $navigationLabel = 'Location sync';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->with('organization');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('organization.name')->label('Organisation')->searchable()->placeholder('—'),
                TextColumn::make('title')->label('Google location')->searchable(),
                TextColumn::make('sync_state')->badge(),
                TextColumn::make('last_synced_at')->since()->sortable()->placeholder('never')
                    ->color(fn ($state) => $state && $state->lt(now()->subHours(2)) ? 'danger' : null),
                TextColumn::make('last_error')->limit(60)->tooltip(fn ($state) => $state)->placeholder('—'),
                IconColumn::make('location_id')->label('Imported')->boolean(),
            ])
            ->filters([
                SelectFilter::make('sync_state')->options(['pending' => 'Pending', 'backfilling' => 'Backfilling', 'active' => 'Active', 'error' => 'Error']),
            ])
            ->defaultSort('last_synced_at');
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
        return ['index' => Pages\ListGbpLocationLinks::route('/')];
    }
}
