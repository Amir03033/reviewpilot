# ReviewPilot: Production Readiness & Quality Audit

**Date:** 2026-11-07 · **Branch:** `claude/charming-darwin-ky7wda` · **Scope:** the whole application (backend, frontend, jobs, scheduler, configuration, tests)

## How to read this report

| Label                    | Meaning                                                                                                                                                                                           |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Verified (automated)** | Proven by an automated test in this repository. The test runs on SQLite **and** on a real PostgreSQL 16 server.                                                                                   |
| **Verified (browser)**   | Clicked through in a real Chromium browser against the running app.                                                                                                                               |
| **Simulated**            | Tested against faked HTTP responses (Google), a mocked gateway (Mollie) or a recording test double (Claude). This proves **our** handling. It does **not** prove the real API behaves as assumed. |
| **Not verified**         | Could not be tested here. It needs real credentials, Google approval or legal input.                                                                                                              |

> **No real external API was called during this audit.** This environment has no Claude, Google or Mollie credentials. Every statement about those integrations below is "simulated" unless stated otherwise.

---

## 1. Summary

The audit found **4 critical defects**. Each would have caused failures or customer harm with real restaurants, and all are fixed:

1. **The app crashed on PostgreSQL (the production database).** `latestOfMany()` generates `MAX(uuid)`, which PostgreSQL does not support. Every review page, the inbox and the AI pipeline returned HTTP 500. The tests only ran on SQLite, which hid this.
2. **A revoked Google token crashed the queue worker.** The `GoogleReconnectRequired` notification had a property that clashed with Laravel's `Queueable` trait, so the class could not load at all (a PHP fatal error).
3. **Monthly Mollie renewals were silently ignored.** A first-seen paid recurring payment was mistaken for a duplicate, so subscriptions never renewed or recovered from `past_due`.
4. **The offline demo AI could answer real guests.** `AI_PROVIDER` defaulted to `fake`. A production deploy that forgot the variable would have published canned demo text to real Google profiles.

The audit also fixed 14 high, 12 medium and several low-priority issues (§3), and added 72 new tests. **149 of 149 tests pass on both SQLite and PostgreSQL.**

**Pilot readiness:** the code is ready for a **closely supervised pilot**. That means Manual or Smart mode, a handful of restaurants, and someone monitoring daily. It is **not ready for paying customers yet**, because four external blockers remain (§5): Google API approval, Google OAuth verification, legal documents and VAT invoices. The real-API paths have also never run against live Claude, Google or Mollie.

---

## 2. Findings by area

### 2.1 Google Business Profile integration

| Check                                                                                                | Result                                                                                                                                                                                                                                                                                              |
| ---------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| OAuth with PKCE, `state` and organisation binding, offline access                                    | Simulated ✔ (existing tests)                                                                                                                                                                                                                                                                        |
| Access-token refresh before expiry and on HTTP 401 (one retry)                                       | Simulated ✔ `test_expired_access_token_is_refreshed_once_on_401`                                                                                                                                                                                                                                    |
| Revoked refresh token → connection set to _needs reconnect_, owners and admins emailed, no API calls | Simulated ✔. **Was crashing (critical #2)**                                                                                                                                                                                                                                                         |
| Location discovery and import (verified locations only)                                              | Simulated ✔                                                                                                                                                                                                                                                                                         |
| One Google location managed by one organisation only (prevents double replies)                       | **Fixed:** added a database partial unique index alongside the existing app check. Verified ✔                                                                                                                                                                                                       |
| Duplicate review imports (push + poll race)                                                          | **Fixed:** retry on unique violation; DB unique key. Verified ✔                                                                                                                                                                                                                                     |
| Reply publishing (PUT upsert)                                                                        | Simulated ✔. Google allows one owner reply per review and `updateReply` replaces it [T]. A repeated PUT therefore cannot create a second reply on Google. We also prevent repeat PUTs (§2.5).                                                                                                       |
| 429 / own quota guard / 5xx / 403 / 404                                                              | Simulated ✔. 429 and 5xx are retried with the cursor not advanced; 403 marks the location with a clear error; deleted reviews are hidden after reconcile.                                                                                                                                           |
| Pub/Sub push: OIDC JWT verification, duplicate deliveries, key rotation                              | **Fixed:** refetch certificates on unknown `kid`; never cache a failed fetch; insert-or-ignore for duplicates (it was aborting the transaction on PostgreSQL). Verified ✔                                                                                                                           |
| Polling fallback (every 15 min) and nightly reconcile                                                | Scheduler verified ✔. Poll and reconcile logic simulated ✔.                                                                                                                                                                                                                                         |
| Reconnecting Google after disconnect                                                                 | **Fixed:** locations stayed _disconnected_ forever. Now reactivated. Verified ✔                                                                                                                                                                                                                     |
| First import of a large history                                                                      | **Fixed:** previously _every_ historical review went to Claude and counted against the monthly allowance. A 2,000-review location could exhaust the allowance and cost tens of euros on day one. Now only the last 90 days are analysed, outside the allowance, and never auto-answered. Verified ✔ |
| Real Google API behaviour                                                                            | **Not verified.** Field names (`hasVoiceOfMerchant`, the notification payload keys, the format of translated text), quotas and policy-rejection bodies are based on documentation and prior knowledge. They must be checked with a real profile (§5).                                               |

### 2.2 AI reliability & safety (Claude)

| Check                                         | Result                                                                                                                                                                                                                                                                                                                                                               |
| --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sensitive reviews can never be auto-published | Verified ✔ (11 scenarios in NL/EN/DE/FR, with the model _wrongly_ saying "harmless"). There are four independent layers: a keyword backstop, hard-gate flags, rating ≤ 2, and a re-check at publish time.                                                                                                                                                            |
| Hard-gate list                                | **Extended:** `hygiene`, `refund_request` and `personal_data` added to food safety, discrimination, legal threats, health authority and staff misconduct.                                                                                                                                                                                                            |
| Keyword backstop languages                    | **Extended** from NL/EN to NL/EN/DE/FR/ES, plus allergy, foreign objects, vermin and harassment.                                                                                                                                                                                                                                                                     |
| AI never invents restaurant information       | **Partially guaranteed.** The prompt allows only listed facts. New guardrail: times, prices, percentages and dates not present in the facts block the draft. Contact details not in the facts were already blocked. **Free-text claims** (e.g. "our new chef") cannot be detected mechanically. That is why sensitive and non-positive reviews always need a person. |
| Brand voice consistently respected            | The prompt contents are verified ✔ (facts, formality, sign-off, forbidden phrases, emoji rule, compensation policy). **New:** the emoji rule is now enforced by a guardrail. Forbidden phrases, language and length were already enforced. _Tone_ cannot be verified mechanically.                                                                                   |
| Languages                                     | Plumbing verified ✔: the target language is passed to Claude and wrong-language drafts are rejected. Real Claude output quality in NL/EN/DE was **not verified**.                                                                                                                                                                                                    |
| The three variants                            | Schema and storage verified ✔. Real Claude quality **not verified**.                                                                                                                                                                                                                                                                                                 |
| Cost and unnecessary calls                    | Verified ✔: 2 calls per text review, 1 for rating-only reviews, at most 1 automatic retry, regeneration limited to 10/min per user, history import capped (§2.1).                                                                                                                                                                                                    |
| Demo AI in production                         | **Fixed (critical #4).** Production refuses `fake`, so reviews fall back to human replies. `reviewpilot:doctor` flags it.                                                                                                                                                                                                                                            |
| Claude refusal / API failure                  | Verified ✔: the review goes to "needs a human reply" and is never blocked.                                                                                                                                                                                                                                                                                           |
| `fallbacks: "default"` (Anthropic beta)       | **Not verified** against the real API. Now switchable off with `AI_REFUSAL_FALLBACKS=false` in case the account rejects it.                                                                                                                                                                                                                                          |

### 2.3 Multi-tenant security

| Check                                                                                                   | Result                                                                                                                  |
| ------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Cross-organisation access through **every** route that takes an ID (21 routes)                          | Verified ✔. All return 403/404 and nothing changes.                                                                     |
| Importing another organisation's Google locations by ID                                                 | Verified ✔ (ignored).                                                                                                   |
| Organisation switch / forged `current_organization_id`                                                  | Verified ✔                                                                                                              |
| Every application route requires authentication (except webhooks, the invitation landing page and home) | Verified ✔ (automated route scan)                                                                                       |
| Role enforcement (member / contributor / analyst / billing)                                             | Verified ✔                                                                                                              |
| Admin could demote or remove an owner                                                                   | **Fixed** (high). Verified ✔                                                                                            |
| Invitations could be accepted with an unverified email                                                  | **Fixed:** `verified` middleware. Wrong email, expired and revoked invitations verified ✔                               |
| Payment history visible to every member                                                                 | **Fixed:** owners and billing role only.                                                                                |
| Mass assignment (plan, trial, Mollie IDs, kill switch through the settings form)                        | Verified ✔ (ignored). Models use explicit validated input.                                                              |
| Background jobs                                                                                         | Jobs only receive IDs created by our own code and re-load records. No user input reaches them unvalidated.              |
| Webhooks                                                                                                | Pub/Sub: OIDC-verified. Mollie: payment re-fetched from the API (forgery-proof, verified ✔). Both are now rate-limited. |

### 2.4 Mollie billing

| Check                                                                                       | Result                                                                                                                                                                                                      |
| ------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Subscription creation (first payment → mandate → subscription)                              | Simulated ✔                                                                                                                                                                                                 |
| Recurring payments                                                                          | **Fixed (critical #3).** Simulated ✔                                                                                                                                                                        |
| Webhook "signatures"                                                                        | Classic Mollie webhooks are **not signed**. Mollie's security model is: receive an ID, fetch the payment from the API. ReviewPilot does exactly that, so a forged webhook cannot change state (verified ✔). |
| Failed payments                                                                             | **Fixed:** previously `past_due` kept full access forever. Now there is a 14-day grace period (`REVIEWPILOT_PAST_DUE_GRACE_DAYS`), then features lock. Verified ✔                                           |
| Cancellation                                                                                | **Fixed:** access now runs to the end of the paid period (it was cut off immediately). Verified ✔                                                                                                           |
| Subscription canceled or suspended at Mollie (mandate revoked)                              | **Fixed:** Mollie sends no webhook for this. New daily `reviewpilot:billing-reconcile`. Verified ✔                                                                                                          |
| Expired trial                                                                               | **Fixed:** previously only AI was blocked and manual publishing kept working. Now publishing, drafting and importing are blocked with a clear banner, while data stays readable. Verified ✔                 |
| Plan feature limits (Auto-Pilot only on Pro and Group)                                      | Verified ✔                                                                                                                                                                                                  |
| Pricing per location incl. 21% VAT, Group volume tiers, quantity sync when locations change | Verified ✔                                                                                                                                                                                                  |
| VAT invoices, EU reverse charge                                                             | **Not implemented** (see §5).                                                                                                                                                                               |
| Real Mollie API                                                                             | **Not verified** (no key).                                                                                                                                                                                  |

### 2.5 Auto-Pilot reliability

| Check                                                       | Result                                                                                                                                                                                                                           |
| ----------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2-hour hold window (minimum 15 min, enforced by validation) | Verified ✔                                                                                                                                                                                                                       |
| Business hours (no replies at 03:00)                        | Verified ✔ (23:30 → 08:00 the next morning, location time zone)                                                                                                                                                                  |
| Daily limit                                                 | Verified ✔. Known limitation: not atomic under heavy concurrency, so it may overshoot by 1–2; it counts per UTC day.                                                                                                             |
| Organisation kill switch, including jobs already queued     | Verified ✔                                                                                                                                                                                                                       |
| **Platform-wide emergency stop**                            | **New:** `php artisan reviewpilot:automation pause` (stored in the DB so a cache flush cannot resume it) or `REVIEWPILOT_AUTOMATION_DISABLED=true`. Banner shown to customers. Verified ✔                                        |
| Re-check at publish time                                    | **New:** right before publishing, pauses, rating, current risk flags, deletion and external replies are re-checked. Verified ✔                                                                                                   |
| Customer edits the review during the hold window            | Scheduled reply cancelled. Verified ✔                                                                                                                                                                                            |
| One review cannot get multiple replies                      | Verified ✔: one reply row per review (DB unique), approval locks the draft row (**new**, prevents double-click double-publish), the job only acts in state `publishing` and runs without overlap, and Google's PUT is an upsert. |
| Imported history                                            | **Fixed:** never auto-answered (previously up to 30 days of backlog could be auto-published right after import).                                                                                                                 |
| 3★ reviews in Smart mode                                    | Verified ✔ never auto (Smart is 4–5★ only).                                                                                                                                                                                      |

### 2.6 Production infrastructure

| Item                 | Status                                                                                                                                                              |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Queues               | Named queues (`realtime, ai, sync, notifications, backfill`), retries and backoff, failed-jobs table. **Recommended:** Redis + Laravel Horizon (not installed).     |
| Scheduler            | 7 tasks, verified present in `schedule:list` ✔                                                                                                                      |
| Locks / rate limits  | Need a shared cache (Redis). `doctor` checks this.                                                                                                                  |
| Database             | Migrations and the full suite verified on **PostgreSQL 16** ✔. New indexes: partial unique on GBP links, `ai_generations.created_at`. CI now runs a PostgreSQL job. |
| Demo safety          | Demo seeder refuses production ✔. `doctor` fails if `@reviewpilot.test` accounts exist in production ✔.                                                             |
| Config check         | **New:** `php artisan reviewpilot:doctor` (exit code 1 on problems; run it in the deploy pipeline).                                                                 |
| Secrets              | All in `.env`. Google tokens encrypted with `APP_KEY` (**never rotate without `APP_PREVIOUS_KEYS`**). Tokens are redacted in audit logs.                            |
| Logging / monitoring | Laravel default logging. **Not configured:** Sentry or Flare error tracking, uptime monitoring, queue-latency alerts (§5).                                          |
| Backups              | Platform responsibility (Laravel Cloud / managed Postgres PITR). Not verifiable from code; restore drills needed (§5).                                              |

### 2.7 User experience (real browser, desktop 1280px + mobile 375px)

Flow tested: register → verify email → create organisation → integrations → empty states → demo data → approve on mobile.

| Finding                                                                              | Status                                                                                                  |
| ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------- |
| No console errors, no HTTP 500s, no horizontal overflow on any main screen on mobile | Verified ✔                                                                                              |
| **The toast said "sent to Google" even when publishing failed**                      | **Fixed** (trust issue). It now says "published", "publishing…" or "Not published: reason". Test added. |
| Auth pages showed the Laravel logo                                                   | **Fixed** (ReviewPilot mark)                                                                            |
| No reply box when AI analysis failed or the queue had not run                        | **Fixed** (manual reply always possible)                                                                |
| No visible notice when the trial ended                                               | **Fixed:** banner with "Choose a plan"                                                                  |
| Sidebar badge and inbox "Needs attention" counts disagreed                           | **Fixed** (same definition)                                                                             |
| Inbox search case-sensitive on PostgreSQL                                            | **Fixed** + test                                                                                        |
| Email verification depends on a working mailer                                       | Configuration (§5)                                                                                      |
| UI is English-only                                                                   | Open (medium): Dutch restaurants expect a Dutch UI.                                                     |

---

## 3. Issues by priority

### Critical (all fixed)

| #   | Issue                                                                                             | Fix                                                                                           |
| --- | ------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| C1  | App crashes on PostgreSQL (`MAX(uuid)` from `latestOfMany`)                                       | Removed `latestOfMany`; full suite now runs on PostgreSQL; CI job added                       |
| C2  | Fatal error loading `GoogleReconnectRequired` (property clash) → worker crash on token revocation | Renamed property; test that loads every class and renders every email                         |
| C3  | Mollie renewals ignored (first-seen paid payment treated as duplicate)                            | Payment created as `open`, status applied as a transition                                     |
| C4  | Offline demo AI could publish to real Google profiles in production                               | `DisabledLlmClient` in production; provider default `anthropic` in production; `doctor` check |

### High (fixed)

| #   | Issue                                                                                 |
| --- | ------------------------------------------------------------------------------------- |
| H1  | Same Google location importable by two organisations (double replies) → DB constraint |
| H2  | Admins could demote or remove owners                                                  |
| H3  | Invitations accepted without email verification                                       |
| H4  | `past_due` granted access forever                                                     |
| H5  | Expired trial still allowed publishing                                                |
| H6  | Subscriptions canceled at Mollie never detected                                       |
| H7  | First import sent the entire history to Claude and consumed the allowance             |
| H8  | First import could auto-publish replies to old reviews                                |
| H9  | Hygiene, refund and personal-data reviews were not hard-gated                         |
| H10 | No platform-wide emergency stop                                                       |
| H11 | No re-check at publish time (analysis or pauses could change after scheduling)        |
| H12 | Double approval (double click / two managers) could publish twice                     |
| H13 | Reconnecting Google left locations disconnected                                       |
| H14 | "Sent to Google" toast shown when publishing failed                                   |

### Medium (fixed)

Pub/Sub key rotation; failed certificate fetch cached for 1 hour; Pub/Sub duplicate deliveries aborting the PostgreSQL transaction; push/poll ingest race; reconnect emails only to the original connector; payment history visible to all members; cancellation cutting access immediately; invented times/prices/dates not detected; emoji rule not enforced; keyword backstop NL/EN only; no reply box after an AI failure; webhooks not rate-limited; scheduled replies kept after removing a location.

### Medium (open)

| #   | Issue                                                    | Recommendation                                                         |
| --- | -------------------------------------------------------- | ---------------------------------------------------------------------- |
| M1  | No error tracking or monitoring                          | Add Sentry/Flare + uptime check + queue-latency alert before the pilot |
| M2  | No Horizon                                               | `composer require laravel/horizon` with Redis                          |
| M3  | UI English-only                                          | Add Dutch translations (Laravel lang + vue-i18n)                       |
| M4  | No GDPR data export                                      | Add JSON/CSV export of reviews, replies and audit log                  |
| M5  | 2FA not enforced for owners                              | Enforce on the Group plan                                              |
| M6  | Daily cap not atomic, UTC day                            | Use a Redis counter per location per local day                         |
| M7  | Dashboard runs several queries per location              | Pre-aggregate daily stats before groups reach 30+ locations            |
| M8  | Larastan/PHPStan removed (sandbox could not download it) | Re-add in CI                                                           |

### Low (open)

Timeline loads users one by one; a 429 release counts as a job attempt (8 tries); no in-app notification centre (emails only); the AI allowance being used up has no visible warning beyond the billing page; no Filament admin panel.

---

## 4. Test results

| Run               | Tests | Passed  | Failed | Assertions |
| ----------------- | ----- | ------- | ------ | ---------- |
| SQLite (as in CI) | 149   | **149** | 0      | 871        |
| **PostgreSQL 16** | 149   | **149** | 0      | 871        |

- The original 77 tests are **unchanged** and all pass.
- 72 new tests in `tests/Feature/Production/`:
    - `AiSafetyTest`: 21 (including 11 sensitive-review scenarios)
    - `AutomationSafetyTest`: 11
    - `GoogleFailureTest`: 13
    - `TenantSecurityTest`: 8 (21 cross-tenant routes)
    - `BillingLifecycleTest`: 10
    - `InfrastructureTest`: 9
- Before the fixes, running on PostgreSQL gave **51 of 147 tests failing or erroring** (C1), and the new tests exposed C2 and C3.
- Frontend: `vue-tsc` passes, lint/format passes (0 warnings), production build succeeds.

---

## 5. Requires manual configuration, credentials or decisions

**Blockers for paying customers**

1. **Google Business Profile API access approval.** It requires a Business Profile verified for 60+ days and an application. Quota stays at 0 until approved.
2. **Google OAuth app verification** for the `business.manage` scope (privacy policy URL, homepage, domain verification, demo video). Without it you're limited to 100 test users and an "unverified app" warning.
3. **Legal documents:** privacy policy, terms of service and a data processing agreement (_verwerkersovereenkomst_). The app has no such pages yet, and Google verification requires a privacy policy. Get them drafted by a lawyer, then add the pages.
4. **VAT invoices.** Mollie does not issue invoices for you. Dutch B2B customers need proper invoices, and EU customers outside NL may need reverse charge. Connect accounting software (e.g. Moneybird) or generate invoices before charging real customers.

**Credentials and configuration** (`php artisan reviewpilot:doctor` checks all of these)

- `AI_PROVIDER=anthropic` and `ANTHROPIC_API_KEY`. If the account rejects the refusal-fallback beta, set `AI_REFUSAL_FALLBACKS=false`.
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`, redirect URI `https://<domain>/integrations/google/callback`.
- Optionally Pub/Sub: topic + publisher grant for `mybusiness-api-pubsub@system.gserviceaccount.com` + push subscription with OIDC auth → `GOOGLE_PUBSUB_*`.
- `MOLLIE_KEY` (`live_` in production), with a publicly reachable `https://<domain>/webhooks/mollie`.
- A real mailer (`MAIL_*`). Email verification, alerts and invitations depend on it.
- PostgreSQL, Redis (queue + cache), a queue worker for the named queues, and the scheduler every minute.
- Error tracking, uptime monitoring, Postgres backups/PITR and one restore drill.

**Verification still to do with real accounts** (in this order)

1. One real test restaurant profile: connect → import → confirm field mapping (verification flag, addresses, translated reviews).
2. Publish one reply manually and confirm it appears on Google. Then edit it and confirm it is _replaced_, not duplicated.
3. Run Claude on 30–50 real reviews (NL/EN/DE, including negative and sensitive ones) and have a native speaker rate the drafts. Check `ai_generations` for the real cost per review.
4. Mollie test mode: checkout with iDEAL, webhook, then simulate a failed recurring payment and a cancellation in the Mollie dashboard.
5. Pub/Sub: post a review on the test profile and confirm it arrives within minutes.

---

## 6. Honest readiness assessment

| Question                                                  | Answer                                                                                                                                                                                                                                                                                                          |
| --------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Is the code safe and robust enough to run with real data? | **Yes, for a supervised pilot.** The critical defects are fixed, safety-critical paths are covered by tests on the production database engine, and automatic publishing has five independent stops.                                                                                                             |
| Has any integration been proven against the real API?     | **No.** Claude, Google and Mollie are only simulated here. The first real-account checks in §5 are mandatory before onboarding a customer.                                                                                                                                                                      |
| Can it launch with paying restaurants today?              | **No.** Google API approval, OAuth verification, legal documents and invoicing are hard blockers, and none of them can be solved in code.                                                                                                                                                                       |
| Recommended pilot setup                                   | 2–5 friendly restaurants, **free or discounted**, Manual or Smart mode only (no Auto-Pilot for the first 2–3 weeks). Check the approval queue and the failed-jobs list daily, and review `ai_generations` costs weekly. Move to paid plans once the §5 blockers are closed and two weeks run without incidents. |
