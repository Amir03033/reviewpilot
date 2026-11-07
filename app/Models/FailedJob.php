<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Read model for Laravel's failed_jobs table (used by the admin panel). */
class FailedJob extends Model
{
    public $timestamps = false;

    protected $table = 'failed_jobs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'failed_at' => 'datetime'];
    }

    public function jobName(): string
    {
        return class_basename($this->payload['displayName'] ?? 'unknown');
    }
}
