<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrganizationResource\Pages;
use App\Models\AuditLog;
use App\Models\Organization;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        $month = now()->startOfMonth()->toDateString();

        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->withCount(['locations', 'memberships'])
            ->addSelect(['ai_cost_microcents' => DB::table('usage_records')->select('quantity')
                ->whereColumn('usage_records.organization_id', 'organizations.id')
                ->where('metric', 'ai_cost_microcents')->where('period', $month)->limit(1)]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('plan')->badge()->placeholder('none'),
                TextColumn::make('trial_ends_at')->label('Trial ends')->date()->sortable()->placeholder('—'),
                TextColumn::make('locations_count')->label('Locations')->sortable(),
                TextColumn::make('memberships_count')->label('Users'),
                TextColumn::make('ai_cost_microcents')->label('AI cost (month)')
                    ->formatStateUsing(fn ($state) => '$ '.number_format(((int) $state) / 100_000_000, 2)),
                IconColumn::make('automation_kill_switch')->label('Paused')->boolean(),
                IconColumn::make('terms_accepted_at')->label('Terms')->boolean()->getStateUsing(fn (Organization $r) => $r->hasAcceptedCurrentTerms()),
                TextColumn::make('created_at')->since()->sortable(),
                TextColumn::make('deleted_at')->label('Deleted')->since()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('plan')->options(collect(config('reviewpilot.plans'))->mapWithKeys(fn ($p, $k) => [$k => $p['name']])),
                TrashedFilter::make(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Organisation')->columns(3)->schema([
                TextEntry::make('name'),
                TextEntry::make('slug'),
                TextEntry::make('country'),
                TextEntry::make('plan')->badge()->placeholder('none'),
                TextEntry::make('trial_ends_at')->dateTime()->placeholder('—'),
                TextEntry::make('created_at')->dateTime(),
                IconEntry::make('automation_kill_switch')->label('Automation paused')->boolean(),
                TextEntry::make('terms_version')->placeholder('not accepted'),
                TextEntry::make('terms_accepted_at')->dateTime()->placeholder('—'),
            ]),
            Section::make('Billing')->columns(3)->schema([
                TextEntry::make('billing_name')->placeholder('—'),
                TextEntry::make('billing_email')->placeholder('—'),
                TextEntry::make('vat_number')->placeholder('—'),
                TextEntry::make('mollie_customer_id')->placeholder('—')->copyable(),
                TextEntry::make('activeSubscription.status')->label('Subscription')->placeholder('none'),
                TextEntry::make('activeSubscription.current_period_ends_at')->label('Paid until')->dateTime()->placeholder('—'),
            ]),
        ]);
    }

    /** Support actions. Every action is written to the organisation's audit log. */
    public static function supportActions(): array
    {
        return [
            Action::make('extendTrial')
                ->label('Extend trial 14 days')->icon(Heroicon::OutlinedClock)
                ->requiresConfirmation()
                ->action(function (Organization $record) {
                    $base = $record->trial_ends_at?->isFuture() ? $record->trial_ends_at : now();
                    $record->forceFill(['trial_ends_at' => $base->copy()->addDays(14)])->save();
                    self::audit($record, 'admin.trial_extended', ['trial_ends_at' => $record->trial_ends_at->toIso8601String()]);
                    Notification::make()->title('Trial extended')->success()->send();
                }),
            Action::make('toggleKillSwitch')
                ->label(fn (Organization $record) => $record->automation_kill_switch ? 'Resume automation' : 'Pause automation')
                ->icon(Heroicon::OutlinedPause)->color('danger')
                ->requiresConfirmation()
                ->action(function (Organization $record) {
                    $record->forceFill(['automation_kill_switch' => ! $record->automation_kill_switch])->save();
                    self::audit($record, $record->automation_kill_switch ? 'admin.automation_paused' : 'admin.automation_resumed');
                    Notification::make()->title('Saved')->success()->send();
                }),
        ];
    }

    private static function audit(Organization $org, string $action, array $changes = []): void
    {
        AuditLog::withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'actor_type' => 'platform_admin',
            'actor_id' => (string) auth()->id(),
            'action' => $action,
            'subject_type' => $org->getMorphClass(),
            'subject_id' => $org->id,
            'changes' => $changes,
            'ip' => request()->ip(),
        ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizations::route('/'),
            'view' => Pages\ViewOrganization::route('/{record}'),
        ];
    }
}
