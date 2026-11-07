<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Billing\InvoiceService;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function download(string $invoice, InvoiceService $invoices): Response
    {
        Gate::authorize('manage-billing');

        // Organisation-scoped query: another tenant's invoice id is a 404.
        $model = Invoice::query()->findOrFail($invoice);

        return response($invoices->pdf($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$model->filename().'"',
        ]);
    }
}
