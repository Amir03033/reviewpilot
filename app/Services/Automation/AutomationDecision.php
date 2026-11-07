<?php

namespace App\Services\Automation;

use Carbon\CarbonInterface;

final readonly class AutomationDecision
{
    /**
     * @param  array<int, string>  $reasons  Why the reply needs a human (empty when eligible).
     */
    public function __construct(
        public bool $eligible,
        public array $reasons = [],
        public ?CarbonInterface $publishAt = null,
    ) {}
}
