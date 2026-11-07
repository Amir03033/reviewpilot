# ReviewPilot

AI-powered review management for restaurants and hospitality groups. ReviewPilot syncs Google Business Profile reviews, analyses them with Claude, drafts on-brand replies, and publishes them through an approval workflow (Manual, Smart Approval or Auto-Pilot) with hard safety gates.

Product strategy, architecture and roadmap: [`docs/blueprint/`](docs/blueprint/README.md).

**Stack:** Laravel 13 · PHP 8.4 · Vue 3 + Inertia + TypeScript · Tailwind CSS 4 · PostgreSQL (SQLite locally) · Redis queues · Claude (Anthropic API) · Mollie · Google Business Profile APIs.

---

## Quick start (no credentials needed)

The app runs fully offline by default. AI uses a built-in offline stand-in (`AI_PROVIDER=fake`), and Google and Mollie screens explain what is missing.

```bash
composer install
```

```bash
cp .env.example .env && php artisan key:generate
```

```bash
touch database/database.sqlite && php artisan migrate --seed
```

```bash
npm install && npm run build
```

```bash
composer run dev
```

Log in at http://localhost:8000 as **demo@reviewpilot.test** with password **password**. The demo organisation has three locations and 45 analysed reviews. A second demo user, **manager@reviewpilot.test**, can only see one location.

## Configuration (add the keys to `.env`)

| What                             | Keys                                                                             | Notes                                                                                                                                                                                                                                                                                                                                                               |
| -------------------------------- | -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Claude**                       | `AI_PROVIDER=anthropic`, `ANTHROPIC_API_KEY`                                     | Defaults to `claude-opus-5-5` (effort `low` for analysis, `medium` for drafting). Override with `AI_MODEL_*` / `AI_EFFORT_*`. Server-side refusal fallbacks are enabled.                                                                                                                                                                                            |
| **Google OAuth**                 | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`                                       | OAuth client type "Web application". Redirect URI: `{APP_URL}/integrations/google/callback`. Enable the _My Business Account Management_, _Business Information_, _Google My Business_ (v4) and _Notifications_ APIs. **Business Profile API access must be approved by Google first** (quota is 0 until then); see `docs/blueprint/07-api-integration-spec.md` §2. |
| **Real-time reviews** (optional) | `GOOGLE_PUBSUB_TOPIC`, `GOOGLE_PUBSUB_AUDIENCE`, `GOOGLE_PUBSUB_SERVICE_ACCOUNT` | Create a topic and grant `mybusiness-api-pubsub@system.gserviceaccount.com` the Publisher role. Create a push subscription to `{APP_URL}/webhooks/google/pubsub` with authentication enabled (the audience and service account must match these values). Without it, reviews are polled every 15 minutes.                                                           |
| **Mollie**                       | `MOLLIE_KEY`                                                                     | A `test_` key for testing, `live_` in production. Mollie calls `{APP_URL}/webhooks/mollie`, so it must be publicly reachable.                                                                                                                                                                                                                                       |
| **Mail**                         | `MAIL_*`                                                                         | Used for negative-review alerts, invitations and the weekly digest.                                                                                                                                                                                                                                                                                                 |
| **Production infra**             | `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`             | See below.                                                                                                                                                                                                                                                                                                                                                          |

## Running in production (e.g. Laravel Cloud, EU region)

- **Web:** standard Laravel app. Run `php artisan migrate --force` on deploy, plus `php artisan db:seed --force` once (it only seeds the topic taxonomy outside local).
- **Queue worker:** `php artisan queue:work --queue=realtime,ai,sync,notifications,backfill,default`
- **Scheduler:** `php artisan schedule:run` every minute. It publishes auto-replies once their hold window ends, polls Google, runs the nightly reconcile, applies data retention and sends the weekly digest.

## How a review flows

```
Google ──(Pub/Sub push or poll)──► ReviewIngestor ──► ProcessReview job
   ReviewAnalyzer (Claude): language, sentiment, topics, risk flags (+ keyword safety backstop)
   DraftGenerator (Claude): 3 variants in the brand voice, only using location facts
   Guardrails: forbidden phrases, invented contact details, compensation, liability, repetition, language
   AutomationEvaluator: Manual / Smart / Auto-Pilot + hard gates (≤2★, food safety, legal, discrimination…)
      ├─ eligible → scheduled after hold window → reviewpilot:publish-due → PublishReply → Google
      └─ otherwise → Approvals queue → person approves/edits → PublishReply → Google
```

Every step is recorded in `audit_logs` (append-only). Every Claude call is metered in `ai_generations` and `usage_records`.

## Code map

| Path                      | What                                                                                                          |
| ------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `app/Services/Google`     | OAuth (PKCE), token refresh, Business Profile client, Pub/Sub JWT verification, location import               |
| `app/Services/Ai`         | `LlmClient` interface, `ClaudeClient` (Anthropic PHP SDK), offline `FakeLlmClient`, cost metering             |
| `app/Services/Reviews`    | Analyzer, draft generator, guardrails, brand voice, publisher, ingestor, pipeline                             |
| `app/Services/Automation` | Auto-publish policy evaluation                                                                                |
| `app/Services/Billing`    | Mollie gateway, per-location subscriptions, plan entitlements                                                 |
| `app/Support/Tenancy`     | Tenant context + global scope (shared-schema multi-tenancy)                                                   |
| `app/Jobs`                | Sync, notifications, processing, publishing                                                                   |
| `routes/console.php`      | Scheduled commands                                                                                            |
| `resources/js/pages`      | Inertia pages (Overview, Inbox, Approvals, Locations, Brand voice, Integrations, Team, Billing, Organisation) |

## Tests

```bash
php artisan test
```

```bash
npm run check && npm run types:check
```

The test suite covers tenant isolation, the review pipeline and automation gates, Google OAuth/sync/publishing (HTTP faked), Pub/Sub JWT verification, Mollie billing (gateway mocked), the Claude client (mock transport) and the guardrails.
