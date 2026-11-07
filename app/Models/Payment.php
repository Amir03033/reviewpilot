<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'paid_at' => 'datetime', 'amount_cents' => 'integer'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
