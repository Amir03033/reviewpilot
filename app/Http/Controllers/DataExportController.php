<?php

namespace App\Http\Controllers;

use App\Jobs\ExportOrganizationData;
use App\Models\DataExport;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataExportController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        $running = DataExport::query()->where('status', 'pending')->where('created_at', '>', now()->subHour())->exists();
        if ($running) {
            return $this->backWith('An export is already being prepared. You will receive an email when it is ready.', 'info');
        }

        $export = DataExport::query()->create([
            'organization_id' => $this->organization()->id,
            'requested_by' => $request->user()->id,
            'status' => 'pending',
        ]);
        $this->audit->log('organization.export_requested', $export);

        ExportOrganizationData::dispatch($export->id);

        return $this->backWith('Your export is being prepared. You will receive an email with a download link.');
    }

    public function download(string $export): StreamedResponse
    {
        $this->authorizeManage();

        // Organisation-scoped: an export of another organisation is a 404.
        $model = DataExport::query()->where('status', 'ready')->findOrFail($export);
        abort_if($model->expires_at?->isPast() || ! $model->path || ! Storage::disk('local')->exists($model->path), 410);

        $this->audit->log('organization.export_downloaded', $model);

        return Storage::disk('local')->download($model->path, 'reviewpilot-export-'.$model->created_at->format('Y-m-d').'.zip');
    }
}
