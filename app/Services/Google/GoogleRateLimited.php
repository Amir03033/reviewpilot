<?php

namespace App\Services\Google;

use RuntimeException;

/** Our own project-wide GBP quota guard tripped; retry after the given delay. */
class GoogleRateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("GBP rate limit reached, retry in {$retryAfterSeconds}s");
    }
}
