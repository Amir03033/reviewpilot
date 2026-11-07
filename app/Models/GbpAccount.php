<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GbpAccount extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['notifications_registered_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleConnection::class, 'google_connection_id');
    }

    public function locationLinks(): HasMany
    {
        return $this->hasMany(GbpLocationLink::class);
    }
}
