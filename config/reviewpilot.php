<?php

/*
|--------------------------------------------------------------------------
| ReviewPilot product configuration
|--------------------------------------------------------------------------
|
| Product-level settings (plans, automation defaults, AI behaviour).
| Credentials for external services live in config/services.php and are
| read from the environment (.env). Nothing secret belongs in this file.
|
*/

return [

    'trial_days' => (int) env('REVIEWPILOT_TRIAL_DAYS', 14),

    /*
    | Plans are priced per billable (active) location per month, in EUR cents.
    | See docs/blueprint/09-pricing-strategy.md.
    */
    'plans' => [
        'essentials' => [
            'name' => 'Essentials',
            'price_per_location' => 2900,
            'ai_reviews_per_location' => 100,
            'max_users' => 2,
            'modes' => ['manual', 'smart'],
            'features' => ['insights_basic'],
        ],
        'pro' => [
            'name' => 'Pro',
            'price_per_location' => 6900,
            'ai_reviews_per_location' => 400,
            'max_users' => null,
            'modes' => ['manual', 'smart', 'autopilot'],
            'features' => ['insights_basic', 'insights_advanced', 'voice_overrides'],
        ],
        'group' => [
            'name' => 'Group',
            'price_per_location' => 5900,
            'min_locations' => 5,
            'ai_reviews_per_location' => 600,
            'max_users' => null,
            'modes' => ['manual', 'smart', 'autopilot'],
            'features' => ['insights_basic', 'insights_advanced', 'voice_overrides', 'two_step_approval'],
        ],
    ],

    // Plan used while an organisation is on trial.
    'trial_plan' => 'pro',

    'billing' => [
        // Days a failed recurring payment keeps the plan active before features are locked.
        'past_due_grace_days' => (int) env('REVIEWPILOT_PAST_DUE_GRACE_DAYS', 14),
        'currency' => 'EUR',
        'vat_rate' => (float) env('REVIEWPILOT_VAT_RATE', 0.21),
    ],

    'automation' => [
        'default_mode' => 'smart',
        'min_rating' => 4,
        'max_risk_score' => 20,
        'min_confidence' => 0.8,
        // Platform-wide emergency stop (also switchable with `php artisan reviewpilot:automation pause`).
        'globally_disabled' => (bool) env('REVIEWPILOT_AUTOMATION_DISABLED', false),
        'hold_minutes' => 120,
        'min_hold_minutes' => 15,
        'daily_cap' => 20,
        'business_hours' => ['start' => 8, 'end' => 22],

        // Risk flags that ALWAYS require a human, regardless of mode or policy.
        'hard_gate_flags' => [
            'food_safety',
            'hygiene',
            'refund_request',
            'personal_data',
            'discrimination',
            'legal_threat',
            'health_authority',
            'staff_misconduct',
        ],
        // Ratings at or below this always require a human.
        'hard_gate_max_rating' => 2,
    ],

    'ai' => [
        // "anthropic" (Claude) in production; "fake" runs fully offline for local dev/demos/tests.
        // Defaults to Claude in production. "fake" is refused in production (see AppServiceProvider).
        'provider' => env('AI_PROVIDER', env('APP_ENV') === 'production' ? 'anthropic' : 'fake'),
        // Server-side refusal fallbacks (Anthropic beta). Set to false if your account rejects it.
        'refusal_fallbacks' => (bool) env('AI_REFUSAL_FALLBACKS', true),
        'models' => [
            'analysis' => env('AI_MODEL_ANALYSIS', 'claude-opus-5-5'),
            'draft' => env('AI_MODEL_DRAFT', 'claude-opus-5-5'),
        ],
        // Claude effort per task: analysis is classification-like, drafting needs more care.
        'effort' => [
            'analysis' => env('AI_EFFORT_ANALYSIS', 'low'),
            'draft' => env('AI_EFFORT_DRAFT', 'medium'),
        ],
        'prompt_version' => 'v1',
        'max_reply_chars' => 1500,
        'similarity_threshold' => 0.85,
        'recent_replies_window' => 30,

        // USD per 1M tokens (Anthropic list prices), used for cost metering only. Review monthly.
        'prices' => [
            'default' => ['input' => 4.00, 'output' => 20.00],
            'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
            'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
            'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
            'fake-model' => ['input' => 0.0, 'output' => 0.0],
        ],
    ],

    'sync' => [
        'poll_minutes' => (int) env('REVIEWPILOT_POLL_MINUTES', 15),
        // Reviews older than this found during the first import are analysed but not drafted.
        'backfill_draft_days' => (int) env('REVIEWPILOT_BACKFILL_DRAFT_DAYS', 30),
        // First import: only reviews younger than this are analysed by AI (cost control).
        // Older history is stored without AI processing.
        'backfill_analysis_days' => (int) env('REVIEWPILOT_BACKFILL_ANALYSIS_DAYS', 90),
        'gbp_requests_per_minute' => (int) env('GBP_REQUESTS_PER_MINUTE', 240),
    ],

    'retention' => [
        'ai_payload_days' => 30,
        'inbound_event_days' => 30,
        'disconnect_grace_days' => 30,
    ],
];
