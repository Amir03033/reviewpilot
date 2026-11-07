<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FailedJobResource\Pages;
use App\Models\FailedJob;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use UnitEnum;

class FailedJobResource extends Resource
{
    protected static ?string $model = FailedJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Health';

    protected static ?string $navigationLabel = 'Failed jobs';

    public static function getNavigationBadge(): ?string
    {
        $count = FailedJob::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('failed_at', 'desc')
            ->columns([
                TextColumn::make('failed_at')->dateTime()->sortable(),
                TextColumn::make('job')->getStateUsing(fn (FailedJob $r) => $r->jobName()),
                TextColumn::make('queue')->badge(),
                TextColumn::make('exception')->limit(90)->wrap()
                    ->formatStateUsing(fn ($state) => strtok((string) $state, "\n")),
            ])
            ->recordActions([
                Action::make('retry')->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->action(function (FailedJob $record) {
                        Artisan::call('queue:retry', ['id' => [$record->uuid]]);
                        Notification::make()->title('Job pushed back onto the queue')->success()->send();
                    }),
                Action::make('forget')->label('Delete')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (FailedJob $record) => Artisan::call('queue:forget', ['id' => $record->uuid])),
            ])
            ->toolbarActions([
                BulkAction::make('retrySelected')->label('Retry selected')
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => Artisan::call('queue:retry', ['id' => $records->pluck('uuid')->all()])),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFailedJobs::route('/')];
    }
}
