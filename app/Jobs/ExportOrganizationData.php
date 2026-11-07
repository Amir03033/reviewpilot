<?php

namespace App\Jobs;

use App\Models\DataExport;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\DataExportReady;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * GDPR data portability: all of an organisation's business data as CSV files in a ZIP.
 * Stored privately and downloadable for 7 days through a signed link.
 */
class ExportOrganizationData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public const DAYS_AVAILABLE = 7;

    public function __construct(public string $exportId)
    {
        $this->onQueue('backfill');
    }

    public function handle(): void
    {
        $export = DataExport::withoutGlobalScopes()->findOrFail($this->exportId);
        $org = Organization::withTrashed()->findOrFail($export->organization_id);

        try {
            $path = "exports/{$org->id}/{$export->id}.zip";
            $disk = Storage::disk('local');
            $disk->makeDirectory(dirname($path));

            $zip = new ZipArchive;
            if ($zip->open($disk->path($path), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create export archive.');
            }

            foreach ($this->tables($org->id) as $name => [$query, $columns]) {
                $zip->addFromString("{$name}.csv", $this->csv($query, $columns));
            }
            $zip->addFromString('README.txt', "ReviewPilot export for {$org->name}\nCreated: ".now()->toIso8601String()."\nFiles are UTF-8 CSV, comma separated.\n");
            $zip->close();

            $export->forceFill([
                'status' => 'ready',
                'path' => $path,
                'size_bytes' => $disk->size($path),
                'completed_at' => now(),
                'expires_at' => now()->addDays(self::DAYS_AVAILABLE),
            ])->save();

            if ($user = User::find($export->requested_by)) {
                $user->notify(new DataExportReady($export));
            }
        } catch (Throwable $e) {
            $export->forceFill(['status' => 'failed'])->save();

            throw $e;
        }
    }

    /** @return array<string, array{0: Builder, 1: array<int, string>}> */
    private function tables(string $org): array
    {
        return [
            'organization' => [DB::table('organizations')->where('id', $org),
                ['id', 'name', 'country', 'vat_number', 'billing_email', 'billing_name', 'billing_address', 'billing_postal_code', 'billing_city', 'plan', 'trial_ends_at', 'terms_accepted_at', 'terms_version', 'created_at']],
            'team_members' => [DB::table('organization_memberships')->join('users', 'users.id', '=', 'organization_memberships.user_id')
                ->where('organization_memberships.organization_id', $org)->select('users.name', 'users.email', 'organization_memberships.role', 'organization_memberships.created_at'),
                ['name', 'email', 'role', 'created_at']],
            'locations' => [DB::table('locations')->where('organization_id', $org),
                ['id', 'name', 'address_line', 'postal_code', 'city', 'country', 'status', 'automation_mode', 'created_at', 'deleted_at']],
            'location_facts' => [DB::table('location_facts')->where('organization_id', $org),
                ['location_id', 'key', 'value', 'updated_at']],
            'reviews' => [DB::table('reviews')->where('organization_id', $org)->orderBy('created_at_source'),
                ['id', 'location_id', 'source', 'reviewer_display_name', 'star_rating', 'comment', 'comment_language', 'created_at_source', 'owner_reply_text', 'owner_reply_updated_at', 'workflow_state', 'removed_at']],
            'review_analyses' => [DB::table('review_analyses')->where('organization_id', $org)->where('is_current', true),
                ['review_id', 'sentiment_label', 'summary', 'risk_score', 'created_at']],
            'replies' => [DB::table('review_replies')->where('organization_id', $org),
                ['review_id', 'text', 'state', 'published_via', 'published_at', 'created_at']],
            'audit_log' => [DB::table('audit_logs')->where('organization_id', $org)->orderBy('id'),
                ['created_at', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'changes']],
            'invoices' => [DB::table('invoices')->where('organization_id', $org)->orderBy('number'),
                ['number', 'issued_on', 'net_cents', 'vat_cents', 'gross_cents', 'currency']],
        ];
    }

    private function csv($query, array $columns): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 correctly
        fputcsv($out, $columns, escape: '');

        if (! $query->columns) {
            $query->select($columns);
        }
        foreach ($query->cursor() as $row) {
            fputcsv($out, array_map(fn ($c) => $this->cell(((array) $row)[$c] ?? null), $columns), escape: '');
        }

        rewind($out);

        return stream_get_contents($out);
    }

    /** Neutralise spreadsheet formula injection from user-generated text (reviews). */
    private function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
