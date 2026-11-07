<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReviewRevision extends Model
{
    use BelongsToOrganization, HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['updated_at_source' => 'datetime', 'captured_at' => 'datetime'];
    }
}
