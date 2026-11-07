<?php

namespace Tests\Feature\ReviewPilot;

use Anthropic\Client;
use App\Models\AiGeneration;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\GenerationRecorder;
use App\Services\Ai\LlmException;
use App\Services\Ai\LlmRequest;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaudeClientTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    private function client(array $body): ClaudeClient
    {
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode($body))]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $anthropic = new Client(apiKey: 'sk-ant-test', requestOptions: ['transporter' => new Guzzle(['handler' => $stack]), 'maxRetries' => 0]);

        return new ClaudeClient(app(GenerationRecorder::class), $anthropic);
    }

    private function message(array $content, string $stopReason = 'end_turn'): array
    {
        return [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => $content, 'stop_reason' => $stopReason, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 300],
        ];
    }

    private function request(): LlmRequest
    {
        return new LlmRequest(
            purpose: 'analysis',
            system: 'Analyse the review.',
            user: '{"review_text":"Lekker!"}',
            schemaName: 'review_analysis',
            schema: ['type' => 'object', 'additionalProperties' => false, 'required' => ['sentiment'], 'properties' => ['sentiment' => ['type' => 'string']]],
        );
    }

    public function test_structured_output_is_parsed_metered_and_requested_correctly(): void
    {
        $result = $this->client($this->message([['type' => 'text', 'text' => '{"sentiment":"positive"}']]))->structured($this->request());

        $this->assertSame(['sentiment' => 'positive'], $result->data);

        $generation = AiGeneration::withoutGlobalScopes()->sole();
        $this->assertSame('anthropic', $generation->provider);
        $this->assertSame('claude-opus-5-5', $generation->model);
        $this->assertSame(1200, $generation->input_tokens);
        // $4/M input + $20/M output: 1200*4 + 300*20 = 10800 µ¢·100 … = 1.08 cents
        $this->assertSame(1080000, (int) $generation->cost_microcents);

        $sent = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('claude-opus-5-5', $sent['model']);
        $this->assertSame('json_schema', $sent['output_config']['format']['type']);
        $this->assertSame('low', $sent['output_config']['effort']);
        $this->assertSame('default', $sent['fallbacks']);
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $this->history[0]['request']->getHeaderLine('anthropic-beta'));
    }

    public function test_refusals_raise_an_llm_exception(): void
    {
        $this->expectException(LlmException::class);

        $this->client($this->message([], 'refusal'))->structured($this->request());
    }

    public function test_non_json_output_raises_an_llm_exception(): void
    {
        $this->expectException(LlmException::class);

        $this->client($this->message([['type' => 'text', 'text' => 'not json']]))->structured($this->request());
    }
}
