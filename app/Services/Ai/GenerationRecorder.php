<?php

namespace App\Services\Ai;

use App\Models\AiGeneration;
use App\Models\UsageRecord;
use Illuminate\Support\Facades\DB;

/** Persists every LLM call for cost metering, debugging and reproducibility. */
class GenerationRecorder
{
    /**
     * @param  array<string, mixed>|null  $response
     */
    public function record(
        LlmRequest $request,
        string $provider,
        string $model,
        string $status,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $latencyMs = 0,
        ?array $response = null,
        ?string $error = null,
        int $cacheReadTokens = 0,
        int $cacheWriteTokens = 0,
        bool $batched = false,
    ): AiGeneration {
        $costMicrocents = self::cost($model, $inputTokens, $outputTokens, $cacheReadTokens, $cacheWriteTokens, $batched);

        $generation = AiGeneration::withoutGlobalScopes()->create([
            'organization_id' => $request->organizationId,
            'location_id' => $request->locationId,
            'purpose' => $request->purpose,
            'provider' => $provider,
            'model' => $model,
            'prompt_version' => config('reviewpilot.ai.prompt_version'),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'cache_write_tokens' => $cacheWriteTokens,
            'batched' => $batched,
            'cost_microcents' => $costMicrocents,
            'latency_ms' => $latencyMs,
            'status' => $status,
            'request_hash' => hash('sha256', $request->system.$request->user),
            'request_payload' => ['system' => $request->system, 'user' => $request->user],
            'response_payload' => $response,
            'error' => $error,
        ]);

        if ($request->organizationId) {
            $this->meter($request->organizationId, 'ai_generations', 1);
            $this->meter($request->organizationId, 'ai_cost_microcents', $costMicrocents);
        }

        return $generation;
    }

    /**
     * Cost in micro-cents (1 cent = 1,000,000 micro-cents) from USD-per-1M-token prices.
     * input_tokens from the API excludes cached tokens, which are priced separately.
     */
    public static function cost(string $model, int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0, bool $batched = false): int
    {
        $p = config("reviewpilot.ai.prices.{$model}") ?? config('reviewpilot.ai.prices.default');
        $usd = $input * $p['input']
            + $output * $p['output']
            + $cacheRead * ($p['cache_read'] ?? $p['input'] * 0.1)
            + $cacheWrite * ($p['cache_write'] ?? $p['input'] * 1.25);

        if ($batched) {
            $usd *= (float) config('reviewpilot.ai.batch_discount', 0.5);
        }

        return (int) round($usd * 100);
    }

    public function meter(string $organizationId, string $metric, int $quantity): void
    {
        $period = now()->startOfMonth()->toDateString();

        DB::transaction(function () use ($organizationId, $metric, $quantity, $period) {
            $record = UsageRecord::withoutGlobalScopes()->firstOrCreate(
                ['organization_id' => $organizationId, 'metric' => $metric, 'period' => $period],
                ['quantity' => 0],
            );
            $record->increment('quantity', $quantity);
        });
    }
}
