<?php

namespace App\Services\Ai;

use App\Models\AiGeneration;

final readonly class LlmResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public array $data, public AiGeneration $generation) {}
}
