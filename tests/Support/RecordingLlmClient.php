<?php

namespace Tests\Support;

use App\Services\Ai\FakeLlmClient;
use App\Services\Ai\GenerationRecorder;
use App\Services\Ai\LlmClient;
use App\Services\Ai\LlmRequest;
use App\Services\Ai\LlmResult;

/**
 * Test double: records every request (to assert prompts) and lets a test override
 * the model output per purpose. Falls back to the offline fake for anything else.
 * This tests OUR code paths, not Claude's real behaviour.
 */
class RecordingLlmClient implements LlmClient
{
    /** @var array<int, LlmRequest> */
    public array $requests = [];

    /** @var array<string, array<string, mixed>|callable> */
    public array $responses = [];

    public function __construct(private GenerationRecorder $recorder, private FakeLlmClient $fallback) {}

    public function structured(LlmRequest $request): LlmResult
    {
        $this->requests[] = $request;

        if (isset($this->responses[$request->purpose])) {
            $data = $this->responses[$request->purpose];
            $data = is_callable($data) ? $data($request) : $data;

            return new LlmResult($data, $this->recorder->record($request, 'test', 'test-model', 'ok', 100, 50, 1, $data));
        }

        return $this->fallback->structured($request);
    }

    public function purposes(): array
    {
        return array_map(fn (LlmRequest $r) => $r->purpose, $this->requests);
    }
}
