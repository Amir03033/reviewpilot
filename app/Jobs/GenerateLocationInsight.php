<?php

namespace App\Jobs;

use App\Models\Location;
use App\Services\Insights\LocationAdvisor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateLocationInsight implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 300;

    public function __construct(public string $locationId)
    {
        $this->onQueue('ai');
    }

    public function handle(LocationAdvisor $advisor): void
    {
        $location = Location::withoutGlobalScopes()->find($this->locationId);
        if ($location && $location->status === 'active') {
            $advisor->generate($location);
        }
    }
}
