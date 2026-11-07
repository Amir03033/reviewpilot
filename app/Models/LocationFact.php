<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A fact the AI may reference in replies. The AI may cite nothing else. */
class LocationFact extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['id'];
}
