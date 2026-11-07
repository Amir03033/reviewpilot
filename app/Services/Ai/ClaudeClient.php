<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;

/**
 * Claude (Anthropic Messages API) with JSON-schema structured outputs.
 *
 * - Model per purpose from config/reviewpilot.php (default: Claude Opus 5.5).
 * - Server-side refusal fallbacks are enabled ("default" routing), so a false-positive
 *   safety decline is retried on Anthropic's recommended fallback model instead of failing.
 * - Thinking stays at the model default (adaptive); depth is controlled with effort.
 */
class ClaudeClient implements LlmClient
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private ?Client $client;

    public function __construct(private GenerationRecorder $recorder, ?Client $client = null)
    {
        $this->client = $client;
    }

    public function structured(LlmRequest $request): LlmResult
    {
        $model = $request->model
            ?? config("reviewpilot.ai.models.{$request->purpose}")
            ?? config('reviewpilot.ai.models.draft');
        $effort = config("reviewpilot.ai.effort.{$request->purpose}") ?? 'medium';
        $started = microtime(true);

        try {
            $message = $this->client()->beta->messages->create(
                model: $model,
                maxTokens: 16000,
                system: $request->system,
                messages: [['role' => 'user', 'content' => $request->user]],
                outputConfig: [
                    'effort' => $effort,
                    'format' => ['type' => 'json_schema', 'schema' => $request->schema],
                ],
                fallbacks: 'default',
                betas: [self::FALLBACK_BETA],
            );
        } catch (APIStatusException $e) {
            $this->recorder->record($request, 'anthropic', $model, 'error', latencyMs: $this->ms($started), error: $e->getMessage());
            throw new LlmException('Claude API error: '.$e->getMessage(), (int) $e->getCode(), $e);
        } catch (APIConnectionException $e) {
            $this->recorder->record($request, 'anthropic', $model, 'error', latencyMs: $this->ms($started), error: $e->getMessage());
            throw new LlmException('Could not reach the Claude API: '.$e->getMessage(), 0, $e);
        }

        $servedModel = $message->model ?: $model;
        $inputTokens = $message->usage->inputTokens;
        $outputTokens = $message->usage->outputTokens;

        if ($message->stopReason === 'refusal') {
            $this->recorder->record($request, 'anthropic', $servedModel, 'refused', $inputTokens, $outputTokens, $this->ms($started), error: 'refusal');
            throw new LlmException('Claude declined this request.');
        }

        $text = null;
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text = $block->text;
                break;
            }
        }

        $data = is_string($text) ? json_decode($text, true) : null;

        if (! is_array($data) || $message->stopReason === 'max_tokens') {
            $this->recorder->record($request, 'anthropic', $servedModel, 'invalid_output', $inputTokens, $outputTokens, $this->ms($started), ['text' => $text, 'stop_reason' => $message->stopReason]);
            throw new LlmException('Claude returned output that is not valid JSON.');
        }

        $generation = $this->recorder->record($request, 'anthropic', $servedModel, 'ok', $inputTokens, $outputTokens, $this->ms($started), $data);

        return new LlmResult($data, $generation);
    }

    private function client(): Client
    {
        if ($this->client) {
            return $this->client;
        }

        $key = config('services.anthropic.key');
        if (blank($key)) {
            throw new LlmException('ANTHROPIC_API_KEY is not configured.');
        }

        return $this->client = new Client(
            apiKey: $key,
            baseUrl: config('services.anthropic.base_url') ?: null,
            requestOptions: ['timeout' => (float) config('services.anthropic.timeout', 120)],
        );
    }

    private function ms(float $started): int
    {
        return (int) ((microtime(true) - $started) * 1000);
    }
}
