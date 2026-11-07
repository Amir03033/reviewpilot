# 03 — Product Requirements Document (PRD)

## 1. Goals and non-goals

### Goals (first 12 months)
- G1: Every connected location's Google reviews are synced within **15 minutes**, or within **2 minutes** once Pub/Sub notifications are live.
- G2: ≥ **80%** of AI drafts are approved with no edits or light edits. Measured as edit distance below 20% [A, target].
- G3: Median time-to-response falls from baseline (often days, or never) to **< 24 h** for customers.
- G4: Groups identify and track at least one recurring operational issue per month via Issues.
- G5: Zero incidents of an auto-published reply on a review classified as high-risk.

### Non-goals (v1)
- Not a full CX suite: no SMS inbox, payments, phone or chat widgets.
- No scraping of any platform.
- No fake or incentivised reviews, and no review gating.
- No non-Google review sources in the MVP. Facebook and Tripadvisor are evaluated later under their API terms.
- No consumer-facing review site.

## 2. Personas

| Persona | Context | Jobs-to-be-done |
|---------|---------|-----------------|
| **Ops Director "Sanne"** (group HQ, 12 locations) | Owns brand standards and reports to owners/franchisor | See which locations slip; enforce tone; get a monthly report without Excel work |
| **Location Manager "Youssef"** | Runs one branch and is busy on the floor | Approve replies on mobile in 2 minutes; know what guests complain about this week |
| **Owner "Peter"** (independent café) | Does everything himself | "Just make sure every review gets a decent reply"; get alerts for bad ones |
| **Marketing Manager "Lotte"** (franchise brand) | Brand voice and campaigns | Define voice and forbidden phrases; run review-collection campaigns |
| **Agency Account Manager** (later) | Manages 20 clients | Multi-org switching, white-label reports |
| **ReviewPilot Support / Admin** (internal) | Uses Filament | Debug syncs, impersonate (with consent), manage plans |

## 3. User stories (MVP-critical marked ★)

### Onboarding & tenancy
- ★ As a new user, I sign up with email or Google, create an organisation, and invite teammates.
- ★ As an owner, I connect a Google account and select which GBP locations to import.
- ★ As an owner, I assign managers to specific locations only.

### Reviews
- ★ As a manager, I see a unified inbox of reviews across my locations, filtered by status, rating, location, topic and language.
- ★ As a manager, I open a review and see the AI analysis (sentiment, topics, risk flags), the draft variants and the history.
- ★ As a manager, I edit, approve, regenerate (with optional guidance), or reject a draft.
- ★ As a manager, I can reply manually without AI.
- As a manager, I can edit or delete an already-published reply. Edits are audited.

### Automation
- ★ As an owner, I choose Manual or Smart Approval per location.
- As an owner, I enable Auto-Pilot for eligible reviews with a configurable policy (Pro+).
- ★ As an owner, I pause all automation with one switch (kill switch).

### Voice
- ★ As a marketing manager, I configure the organisation's brand voice and override it per location.
- ★ As a manager, I add location facts (opening hours, parking, reservations policy) that the AI may reference. The AI may never invent facts.

### Intelligence
- ★ As an ops director, I see rating trend, volume, response rate and response time per location.
- ★ As an ops director, I see top complaint topics with trend arrows and example quotes.
- As an ops director, I receive an alert when a topic spikes (e.g. "hygiene" mentions ×3 vs baseline).
- As an ops director, I convert an insight into an Issue, assign it, and later see whether the complaint rate dropped.
- ★ As an owner, I receive a weekly email digest and a monthly PDF report.

### Commercial
- ★ As an owner, I start a 14-day trial, choose a plan, and pay by iDEAL, SEPA or card.
- ★ As an owner, I download invoices with VAT details.

## 4. Functional requirements summary

Each requirement is detailed in [04](04-feature-specification.md).

| ID | Area | Requirement | MVP |
|----|------|-------------|-----|
| FR-01 | Auth | Email/password with verification, Google sign-in, 2FA (TOTP), session management | ✔ (2FA optional) |
| FR-02 | Tenancy | Organisations, memberships, roles, location-scoped permissions | ✔ |
| FR-03 | GBP | OAuth connect, account and location discovery, import, disconnect | ✔ |
| FR-04 | Sync | Initial backfill, incremental polling, Pub/Sub ingestion, reconciliation | ✔ (Pub/Sub can follow) |
| FR-05 | Publish | Publish, update and delete replies with retry and status tracking | ✔ |
| FR-06 | AI analysis | Language, sentiment, topics, risk flags, entities (dishes, staff mention) | ✔ |
| FR-07 | AI drafting | 2–3 variants, voice-aware, fact-grounded, regenerate with guidance | ✔ |
| FR-08 | Workflow | State machine, approval queue, modes, policies, kill switch | ✔ (Auto-Pilot beta) |
| FR-09 | Voice | Org + location personalities, versioned | ✔ |
| FR-10 | Analytics | KPIs, trends, topic analysis, comparisons | ✔ basic |
| FR-11 | Issues | Issue tracker linked to topics and reviews, outcome measurement | Phase 5 |
| FR-12 | Alerts & notifications | In-app, email, digest; Slack/WhatsApp later | ✔ email/in-app |
| FR-13 | Reports | Weekly digest email, monthly PDF | ✔ digest |
| FR-14 | Billing | Plans, trials, per-location quantity, invoices, dunning | ✔ |
| FR-15 | Audit | Immutable audit log for all state-changing actions | ✔ |
| FR-16 | Admin | Filament back-office | ✔ minimal |
| FR-17 | Collection | QR/link campaigns (no gating) | Phase 6–7 |
| FR-18 | Competitors | Public Places comparisons within the Places terms | Phase 7 |

## 5. Non-functional requirements

| Category | Requirement |
|----------|-------------|
| Performance | Inbox p95 < 400 ms server time for 10k reviews per org. Dashboard p95 < 800 ms, using pre-aggregated tables. |
| Availability | 99.5% monthly for the web app in year 1, 99.9% later. Sync is eventually consistent. |
| Scalability | Design for 10,000 locations and about 1M reviews per year without re-architecture [A]. |
| Security | OWASP ASVS L2 target. Tokens encrypted at rest. Tenant isolation is tested automatically. |
| Privacy | EU hosting, GDPR processor role, DPA, configurable retention. |
| i18n | UI in NL and EN at launch, DE next. AI replies in the review's language. |
| Accessibility | WCAG 2.2 AA for core flows. A dark theme must still meet contrast requirements. |
| Mobile | The approval flow is fully usable on a 375 px viewport. A PWA push is optional later. |
| Observability | Every sync, AI call and publish is traceable by a correlation ID. |

## 6. Success metrics

| Metric | Definition | Target (6 mo post-launch) [A] |
|--------|------------|-------------------------------|
| Activation | % of trials that connect GBP and approve ≥ 3 replies within 7 days | ≥ 50% |
| Trial → paid | | ≥ 25% (self-serve), ≥ 60% (sales-assisted groups) |
| Draft acceptance | approved with no or light edits / total drafts | ≥ 80% |
| Response rate | replied reviews / total reviews, for customers | ≥ 95% |
| Logo churn | monthly | ≤ 3% (groups ≤ 1.5%) |
| NRR | 12-month | ≥ 100% (location expansion) |
