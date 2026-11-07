<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BrandVoice extends Model
{
    use BelongsToOrganization, HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    public function versions(): HasMany
    {
        return $this->hasMany(BrandVoiceVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(BrandVoiceVersion::class, 'current_version_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
