<?php

namespace App\Services\Ai;

final readonly class LlmRequest
{
    /**
     * @param  array<string, mixed>  $schema  JSON schema for the structured output
     * @param  array<string, mixed>  $context  Extra structured context (used by the fake provider and for logging)
     */
    public function __construct(
        public string $purpose,
        public string $system,
        public string $user,
        public string $schemaName,
        public array $schema,
        public ?string $organizationId = null,
        public ?string $locationId = null,
        public ?string $model = null,
        public array $context = [],
    ) {}
}
