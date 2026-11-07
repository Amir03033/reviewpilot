<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pre-launch / runtime configuration check. Exits non-zero when something would make
 * ReviewPilot unsafe or broken for real customers.
 *
 *   php artisan reviewpilot:doctor
 */
class DoctorCommand extends Command
{
    protected $signature = 'reviewpilot:doctor';

    protected $description = 'Check configuration for production readiness';

    private int $failures = 0;

    public function handle(): int
    {
        $production = app()->isProduction();
        $env = (string) app()->environment();
        $this->line('Environment: '.($env !== '' ? $env : '(empty: set APP_ENV=production in .env)').($production ? '' : ' (production-only checks are shown as warnings)'));

        $this->section('Application');
        $this->check(filled(config('app.key')), 'APP_KEY is set');
        $this->check(! config('app.debug') || ! $production, 'APP_DEBUG is false in production', $production);
        $this->check(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL uses https (needed for OAuth, Mollie and Pub/Sub callbacks)', $production);
        $this->check((bool) config('session.secure') || ! $production, 'SESSION_SECURE_COOKIE=true', $production);

        $this->section('Data & queues');
        $this->check(config('database.default') === 'pgsql', 'Database is PostgreSQL', $production);
        $this->check(! in_array(config('queue.default'), ['sync', 'null'], true), 'Queue is asynchronous (QUEUE_CONNECTION=redis recommended)', $production);
        $this->check(! in_array(config('cache.default'), ['array', 'file', 'null'], true), 'Shared cache store for locks and rate limits (CACHE_STORE=redis)', $production);
        try {
            \DB::connection()->getPdo();
            $this->check(true, 'Database connection works');
        } catch (Throwable $e) {
            $this->check(false, 'Database connection works: '.$e->getMessage());
        }

        $this->section('Claude');
        $provider = config('reviewpilot.ai.provider');
        $this->check(in_array($provider, ['anthropic', 'claude'], true), "AI_PROVIDER=anthropic (currently \"{$provider}\")", $production);
        $this->check(filled(config('services.anthropic.key')) || ! in_array($provider, ['anthropic', 'claude'], true), 'ANTHROPIC_API_KEY is set', $production);

        $this->section('Google Business Profile');
        $this->check(filled(config('services.google.client_id')) && filled(config('services.google.client_secret')), 'GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET are set', $production);
        $this->check(str_starts_with((string) config('services.google.redirect'), 'https://'), 'Google redirect URI is https', $production);
        $pubsub = filled(config('services.google.pubsub_topic'));
        $this->check(! $pubsub || (filled(config('services.google.pubsub_audience')) && filled(config('services.google.pubsub_service_account'))), 'Pub/Sub audience and service account are set when a topic is configured');
        if (! $pubsub) {
            $this->warn('  ! Pub/Sub not configured: reviews are polled every '.config('reviewpilot.sync.poll_minutes').' minutes.');
        }

        $this->section('Mollie');
        $key = (string) config('mollie.key');
        $this->check($key !== '' && ! str_contains($key, 'xxxx'), 'MOLLIE_KEY is set', $production);
        $this->check(! $production || str_starts_with($key, 'live_'), 'MOLLIE_KEY is a live key in production', $production);

        $this->section('Mail');
        $this->check(! in_array(config('mail.default'), ['log', 'array'], true), 'A real mailer is configured (alerts, invitations, email verification)', $production);

        $this->section('Monitoring');
        $this->check(filled(config('sentry.dsn')), 'Error monitoring: SENTRY_LARAVEL_DSN is set', false);
        $this->check(! config('sentry.send_default_pii'), 'Sentry does not send personal data (SENTRY_SEND_DEFAULT_PII=false)');

        $this->section('Company details (invoices and legal pages)');
        foreach (['legal_name' => 'COMPANY_LEGAL_NAME', 'address' => 'COMPANY_ADDRESS', 'kvk' => 'COMPANY_KVK', 'vat_number' => 'COMPANY_VAT_NUMBER'] as $key => $env) {
            $this->check(filled(config("reviewpilot.company.{$key}")), "{$env} is set", $production);
        }

        $this->section('Safety');
        if (! \Schema::hasTable('users')) {
            $this->check(false, 'Database tables exist (run: php artisan migrate --force)');
        } else {
            $this->check(! User::query()->where('email', 'like', '%@reviewpilot.test')->exists() || ! $production, 'No demo accounts exist in production', $production);
        }
        if (PlatformSettings::automationPaused()) {
            $this->warn('  ! Automatic publishing is paused platform-wide (reviewpilot:automation resume).');
        }

        $this->newLine();
        if ($this->failures > 0) {
            $this->error("{$this->failures} problem(s) found.");

            return self::FAILURE;
        }

        $this->info('All checks passed.');

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    private function check(bool $ok, string $label, bool $required = true): void
    {
        if ($ok) {
            $this->line("  <fg=green>✓</> {$label}");

            return;
        }

        if ($required) {
            $this->failures++;
            $this->line("  <fg=red>✗</> {$label}");
        } else {
            $this->line("  <fg=yellow>!</> {$label}");
        }
    }
}
