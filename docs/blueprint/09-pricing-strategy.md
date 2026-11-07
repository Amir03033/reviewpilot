# 09 — Commercial & Pricing Strategy

## 1. Ideal customer profiles

| ICP                          | Description                                                 | Pain                                                                          | Buying trigger                                                 | Priority                 |
| ---------------------------- | ----------------------------------------------------------- | ----------------------------------------------------------------------------- | -------------------------------------------------------------- | ------------------------ |
| **ICP-1: Hospitality group** | 3–30 locations, NL/BE, has an ops or marketing manager      | Inconsistent replies across branches, no overview, a branch quietly degrading | New marketing manager, rating drop, franchisor brand standards | **Primary**              |
| **ICP-2: Franchise brand**   | Franchisor with 10–100 franchisee locations                 | Brand voice control, franchisee accountability                                | Brand refresh, franchisee complaints                           | Secondary (longer cycle) |
| **ICP-3: Busy independent**  | 1–2 locations, ≥ 40 reviews/mo, city centre or tourist area | No time, wants decent replies in multiple languages                           | Bad review incident, competitor seen replying                  | Self-serve               |
| **ICP-4: Agency / partner**  | Hospitality marketing agencies, POS or reservation vendors  | Wants to offer reputation management to clients                               | Client demand                                                  | Channel                  |

**Anti-ICP:** low-volume independents (< 10 reviews/mo) and businesses that want fake or gated reviews.

## 2. Value-based pricing logic

- Enterprise suites: roughly $80–$450+ per location per month, quote-based, with annual contracts and onboarding fees [V-2] (see [02](02-market-competitor-research.md)).
- Commodity AI reply tools: €0–30 per month [V-2].
- ReviewPilot positioning is the **€29–€89 per location band**: monthly cancellable, no onboarding fee, transparent prices on the website. Transparency is itself a differentiator against "contact sales" competitors.

## 3. Proposed tiers (EUR, excl. VAT) [A, validate with design partners]

|                                           | **Essentials**     | **Pro**                         | **Group**                                                |
| ----------------------------------------- | ------------------ | ------------------------------- | -------------------------------------------------------- |
| Target                                    | Independents       | Busy independents, small groups | Groups and franchises (≥ 5 locations)                    |
| Price per location / month (monthly)      | **€29**            | **€69**                         | **from €49** (volume-tiered, 5-location minimum, annual) |
| Annual billing                            | 2 months free      | 2 months free                   | annual by default                                        |
| AI-processed reviews per location / month | 100                | 400                             | 600 (pooled across org)                                  |
| Users                                     | 2                  | 5 per location                  | unlimited                                                |
| Modes                                     | Manual, Smart      | + Auto-Pilot                    | + two-step approval                                      |
| Brand voice                               | 1 voice            | per-location overrides          | + org-level governance, locked fields                    |
| Insights                                  | Basic KPIs, topics | + trends, alerts, dish analysis | + location comparison, heatmaps                          |
| Issues                                    | —                  | ✔                               | ✔ + cross-location issues                                |
| Reports                                   | Weekly digest      | + monthly PDF                   | + white-label-free branded PDF, scheduled exports        |
| Integrations                              | Email              | + Slack/Teams                   | + SSO (later), API (later)                               |
| Support                                   | Help centre, email | Email, chat                     | Onboarding call, account manager (from 15 locations)     |

Group volume tiers [A]: 5–9 locations €59, 10–24 €54, 25+ €49 per location per month.

**Overage:** beyond the AI allowance, reviews are still synced and analysed with a cheap model, and drafting continues at **€5 per additional 100 reviews**. Never block responding silently.

**Trial:** 14 days, no card. Full Pro features, max 3 locations.

**Why per location?** It matches the customer's mental model ("per vestiging"), scales with value, and is the industry norm [V-2]. Pure usage-based (per review) pricing punishes success and is hard to budget. A user-based model penalises collaboration.

## 4. Unit economics

### 4.1 AI cost per review [A, based on V-2 prices]

Token assumptions per review (text review): analysis ≈ 1,200 input + 350 output tokens; generation (3 variants with few-shot context) ≈ 2,800 input + 450 output; regeneration rate 20%.

| Scenario                                                 | Model price (per 1M input/output tokens)            | Cost per review |
| -------------------------------------------------------- | --------------------------------------------------- | --------------- |
| Low (nano-class for analysis, mini-class for generation) | analysis $0.05/$0.40; generation ~$0.75/$4.50 [V-2] | ≈ $0.005        |
| Mid (mini-class for both)                                | $0.75/$4.50 [V-2]                                   | ≈ $0.007        |
| High (frontier-class generation ~$5/$30 [A])             |                                                     | ≈ $0.035        |

At 60 text reviews per location per month, plus 20% overhead for monthly insights and voice previews, **AI cost is about €0.40–€2.50 per location per month**. **AI is not the cost problem.** Use better models where quality matters.

### 4.2 Other variable costs per location / month [A]

| Item                                                                                 | Est.                                                           |
| ------------------------------------------------------------------------------------ | -------------------------------------------------------------- |
| Infrastructure (app, workers, DB, Redis, storage, logs) at ~1,000 locations          | €0.80–1.50                                                     |
| Google Business Profile API                                                          | **€0.** No usage fees are published for GBP APIs [T, verify].  |
| Places API (competitor benchmark, Phase 7, capped at 5 competitors × weekly refresh) | ~€0.50–1.00 (≈ 20–40 Enterprise calls at ~$20–25 per 1k [V-2]) |
| Email, notifications                                                                 | €0.05                                                          |
| Payment fees (SEPA/iDEAL/card blend ~1.5% + fixed)                                   | ~€1.00–1.50 on €69                                             |
| Support (allocated)                                                                  | €3–6                                                           |
| **Total variable**                                                                   | **≈ €6–12**                                                    |

### 4.3 Gross margin

- Pro at €69: ~€60 contribution, **≈ 85% gross margin** [A].
- Essentials at €29: ~€21, ≈ 72%. Acceptable only if it is truly self-serve.
- Group at €54 effective: ~€46, ≈ 85%.

### 4.4 Customer acquisition assumptions [A]

| Channel                                      | CAC per location                          | Notes                                          |
| -------------------------------------------- | ----------------------------------------- | ---------------------------------------------- |
| Self-serve (SEO, content, free tool)         | €80–200                                   | Essentials/Pro                                 |
| Founder-led outbound to groups               | €300–600                                  | Group (10 locations per deal ≈ €3–6k per deal) |
| Partner referral (20% revenue share, year 1) | ~€170 effective + partner management cost | Most scalable [A]                              |

**Payback:** Pro self-serve about 2–4 months. Group outbound about 6–10 months.
**LTV** (Pro, 3% monthly churn → ~33-month lifetime × €60) ≈ €2,000. LTV:CAC > 3 is achievable if CAC stays below €650 [A].

### 4.5 Break-even scenarios [A]

| Scenario                                         | Monthly fixed cost | Blended contribution / location | Break-even locations                                                                     |
| ------------------------------------------------ | ------------------ | ------------------------------- | ---------------------------------------------------------------------------------------- |
| Lean (2 founders-engineers, low salaries, tools) | €12k               | €50                             | **~240**                                                                                 |
| Seed team (4 FTE + marketing)                    | €35k               | €50                             | **~700**                                                                                 |
| Scale-up (10 FTE)                                | €90k               | €52                             | **~1,750** (requires expansion beyond NL; see [02 §1](02-market-competitor-research.md)) |

**Critical takeaway:** NL can support the lean scenario. The seed scenario needs BE/DACH traction, or vertical expansion, within about 18 months.

## 5. Customer acquisition strategy

1. **Design-partner program** (10 groups, 50% discount for 6 months, in exchange for weekly feedback, logo and case study).
2. **Founder-led outbound** to ops and marketing managers at NL chains (LinkedIn, KHN network, Horecava).
3. **Content and SEO in Dutch:** "reageren op negatieve reviews", templates, a "Google review health check" free tool (single location, using permitted public data).
4. **Partnerships:** POS and reservation vendors (embedded "connect ReviewPilot", rev share), hospitality marketing agencies (20–30% reseller margin), franchise consultants and associations.
5. **Product-led loops:** monthly report PDFs forwarded to owners or franchisors carry a subtle "Powered by ReviewPilot". Team invitations.

## 6. Retention strategy

- **Habit:** a daily approval flow on mobile plus a weekly digest.
- **Proof of value:** a monthly "your impact" panel (response rate before/after, time saved estimate, issues resolved with measured outcomes).
- **Switching costs, earned honestly:** brand voice history, issue history, benchmarks over time. Data export is always available, which builds trust.
- **Churn signals:** no logins for 14 days, approvals piling up, connection broken. Respond with automated nudges plus a CSM touch for Group accounts.
- **Seasonality:** restaurants may pause or close. Offer a "pause location" option at €5/mo, which keeps data, instead of cancellation.

## 7. Upsell & expansion

- Location expansion (natural NRR driver).
- Essentials → Pro, triggered by Auto-Pilot, Issues or the monthly PDF.
- Add-ons (later): review-collection kits (QR table stands, NFC cards, as physical products), competitor benchmark, additional review sources, API access.

## 8. Partnerships to explore

| Partner type          | Examples (NL-relevant) [A]                          | Value                                        |
| --------------------- | --------------------------------------------------- | -------------------------------------------- |
| POS                   | Lightspeed (strong NL hospitality presence), others | Visit-triggered review invites, distribution |
| Reservations          | Formitable, Zenchef, TheFork                        | Post-visit invite emails, visit context      |
| Delivery and ordering | Ordering platforms                                  | Delivery-specific feedback context           |
| Associations          | KHN (Koninklijke Horeca Nederland)                  | Member discount, credibility                 |
| Agencies              | Hospitality marketing agencies                      | Reseller channel                             |
