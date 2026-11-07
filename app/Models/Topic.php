<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Global (non-tenant) topic taxonomy. */
class Topic extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'nl' ? $this->label_nl : $this->label_en;
    }
}
