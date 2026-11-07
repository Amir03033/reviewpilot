<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Message Batches API batch (platform-wide, may contain reviews of several organisations). */
class AiBatch extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(AiBatchItem::class);
    }
}
