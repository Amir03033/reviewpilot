<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GoogleConnectionResource\Pages;
use App\Models\GoogleConnection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Read-only platform view across all organisations. */
class GoogleConnectionResource extends Resource
{
    protected static ?string $model = GoogleConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?int $navigationSort = 3;

    protected static string|UnitEnum|null $navigationGroup = 'Health';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->with('organization');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('organization.name')->label('Organisation')->searchable()->placeholder('—'),
                TextColumn::make('google_email')->searchable(),
                TextColumn::make('status')->badge()->color(fn ($state) => ($state?->value ?? $state) === 'active' ? 'success' : 'danger'),
                TextColumn::make('access_token_expires_at')->label('Token expires')->since(),
                TextColumn::make('last_refreshed_at')->since()->placeholder('—'),
                TextColumn::make('last_error')->limit(60)->tooltip(fn ($state) => $state)->placeholder('—'),
                TextColumn::make('created_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'needs_reauth' => 'Needs reconnect', 'revoked' => 'Revoked']),
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
        return ['index' => Pages\ListGoogleConnections::route('/')];
    }
}
