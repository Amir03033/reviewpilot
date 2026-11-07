<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GbpLocationLink extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_review_update_time' => 'datetime',
            'raw' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GbpAccount::class, 'gbp_account_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function connection(): GoogleConnection
    {
        return $this->account->connection;
    }
}
