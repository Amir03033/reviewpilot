<?php

namespace App\Jobs;

use App\Models\GoogleConnection;
use App\Services\Google\GoogleLocationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DiscoverGbpLocations implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public string $connectionId)
    {
        $this->onQueue('sync');
    }

    public function handle(GoogleLocationService $service): void
    {
        $connection = GoogleConnection::withoutGlobalScopes()->find($this->connectionId);

        if ($connection && $connection->isActive()) {
            $service->discover($connection);
        }
    }
}
