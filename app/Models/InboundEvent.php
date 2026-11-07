<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Idempotency inbox for webhooks (Pub/Sub, Mollie). Not tenant-scoped. */
class InboundEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
