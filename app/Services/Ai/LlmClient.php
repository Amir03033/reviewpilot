<?php

namespace App\Services\Ai;

/**
 * Provider-agnostic structured-output LLM interface.
 * Implementations: ClaudeClient (production, Anthropic), FakeLlmClient (local/demo/tests).
 */
interface LlmClient
{
    public function structured(LlmRequest $request): LlmResult;
}
