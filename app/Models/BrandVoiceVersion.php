<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable snapshot of a brand voice's settings. */
class BrandVoiceVersion extends Model
{
    use BelongsToOrganization, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'preferred_phrases' => 'array',
            'forbidden_phrases' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function voice(): BelongsTo
    {
        return $this->belongsTo(BrandVoice::class, 'brand_voice_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
