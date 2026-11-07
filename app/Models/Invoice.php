<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An issued invoice. Seller, buyer and lines are snapshots taken at issue time,
 * so later changes to company or organisation details never alter an invoice.
 */
class Invoice extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'seller' => 'array',
            'buyer' => 'array',
            'lines' => 'array',
            'vat_rate' => 'float',
            'net_cents' => 'integer',
            'vat_cents' => 'integer',
            'gross_cents' => 'integer',
            'emailed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function filename(): string
    {
        return 'factuur-'.$this->number.'.pdf';
    }
}
