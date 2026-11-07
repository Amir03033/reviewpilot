<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoogleConnection extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'scopes' => 'array',
            'status' => ConnectionStatus::class,
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(GbpAccount::class);
    }

    public function isActive(): bool
    {
        return $this->status === ConnectionStatus::Active;
    }
}
