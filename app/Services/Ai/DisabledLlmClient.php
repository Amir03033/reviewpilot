<?php

namespace App\Services\Ai;

/**
 * Used when no real AI provider may run (e.g. AI_PROVIDER=fake in production).
 * Every call fails, so reviews fall back to human-written replies instead of
 * publishing canned demo text to real Google profiles.
 */
class DisabledLlmClient implements LlmClient
{
    public function __construct(private string $reason) {}

    public function structured(LlmRequest $request): LlmResult
    {
        throw new LlmException($this->reason);
    }
}
