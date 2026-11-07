# 01 — Executive Product Overview

## 1. The problem

Restaurants live and die by their Google rating. Owners and managers know they should answer every review, but:

1. **No time.** Replying is a chore done at 23:00 after service. It gets skipped, and replies become copy-paste ("Thanks for your review!").
2. **Negative reviews are emotionally hard.** Defensive or sarcastic replies go viral for the wrong reasons.
3. **Signals are lost.** "Cold fries" appears in twelve reviews across three branches over six weeks. Nobody connects them, so the operational problem persists and the rating erodes.
4. **Groups lack control.** In a 12-location group, HQ cannot see which branches reply, how they reply, or which branch is quietly degrading.

## 2. The product

**ReviewPilot** is a reputation operations platform for hospitality, starting with Google Business Profile.

| Layer | What it does | Why it matters |
|------|--------------|----------------|
| **Respond** | AI drafts personalised, on-brand replies. People approve them, or governed automation publishes them. | Saves time and keeps every review answered. |
| **Govern** | Approval workflows, risk classification, policies, audit trail, per-location voice. | Brand safety for groups and franchises. |
| **Understand** | Sentiment, topic and dish-level analysis, trends, branch comparison. | Makes reviews a source of operational data. |
| **Act** | Recurring complaints become *issues*, with an owner, a deadline and a measured outcome. | The differentiator: it closes the loop from review to fix to proof. |
| **Grow** (later) | Compliant review-request campaigns via QR, receipts, and reservation or POS integrations. | More reviews and more recent reviews. Recency matters to consumers [V-2]. |

Tagline (kept): **"Every review deserves a response."**
Positioning line (added): **"…and every complaint deserves a fix."**

## 3. Critical assessment of the original concept

| Original idea | Verdict | Reasoning |
|---------------|---------|-----------|
| AI response generator as the core | **Necessary, but not sufficient** | Commoditising fast. Google is testing native AI replies in GBP [V-2]. Many $10–$75/month tools exist [V-2]. On its own, it cannot sustain a premium price. |
| Three operating modes (Manual / Smart / Auto-Pilot) | **Keep, with guardrails** | Smart approval is the sweet spot. Full Auto-Pilot is a liability on negative reviews and must stay limited to positive, low-risk reviews with a hold window. |
| Negative review intelligence | **Core differentiator, make it the centre** | Few SMB tools close the loop to operational action. Enterprise tools (Reputation, Birdeye) do this at roughly $150–$450 per location per month [V-2]. |
| Multi-location management | **Core, and drives ICP choice** | Groups pay more, churn less, and feel governance pain the most. |
| Competitor intelligence | **Downgrade to a later, light feature** | Only public Places data is available: rating, count and at most 5 reviews per place [V-2]. Caching rules and per-call cost apply. No scraping. Its value is a nice-to-have. |
| Reputation score, forecasting | **Defer or cut** | Low review volume per location makes forecasts noise. Vanity scores do not change behaviour. |
| WhatsApp / Slack | **Slack later, WhatsApp only for alerts** | WhatsApp is popular in the NL, but templates add cost and approval overhead. Email and in-app come first. |
| White-label | **Defer until agency channel proven** | It adds a lot of multi-tenant complexity (domains, branding, billing hierarchy). |
| Review widgets | **Low priority** | Cheap to build, low willingness to pay, and display may conflict with Google terms. Verify before building. |
| Review collection campaigns | **High priority after MVP** | Strong ROI story. Must be built **without review gating**, which Google prohibits [V-2]. |

## 4. Who we sell to first

**Primary ICP:** Dutch restaurant groups, franchisees and hospitality chains with **3–30 locations**. Examples are burger or pizza chains, café formulas, and hotel-restaurant groups. They have an operations or marketing manager who owns reputation.

**Secondary ICP:** Busy independents with **≥ 40 Google reviews per month** [A], self-serve.

**Channel ICP:** Hospitality software vendors and agencies. Candidates include POS (e.g. Lightspeed), reservation systems (e.g. Formitable, Zenchef, TheFork) and local-marketing agencies, reached through partnerships or referral.

Full reasoning is in [02](02-market-competitor-research.md) and [09](09-pricing-strategy.md).

## 5. Business model in brief

- Per-location monthly subscription in three tiers (proposed €29 / €69 / Group from €49 per location with a 5-location minimum). See [09](09-pricing-strategy.md).
- AI cost is estimated at **€0.01–0.03 per review processed** [A, based on V-2 token prices]. That is immaterial to gross margin. The real costs are customer acquisition and support.
- Target gross margin is 80–88% [A]. Break-even at roughly 260–400 paying locations, depending on team size [A].

## 6. What must be true for this to work

1. Google grants Business Profile API access and keeps the Reviews endpoints available. Today they are still on the legacy **v4** API [V].
2. AI replies are good enough that managers approve ≥ 80% of drafts with no or minimal edits [A, to measure in beta].
3. Groups pay for the issue-tracking and governance layer, not only for reply drafting.
4. CAC stays at or below about €600 per location, via partners and outbound to groups [A].

## 7. Headline timeline (realistic)

| Milestone | Earliest | Notes |
|-----------|----------|-------|
| Google API access application submitted | Week 1 | Requires a 60+-day-old verified Business Profile and a website [V]. Create ReviewPilot's own profile **now**. |
| Private alpha (own test profiles) | ~Month 3 | |
| Paid design-partner beta (5–10 groups) | ~Month 5–6 | |
| Public launch NL | ~Month 8–10 | |
| Intelligence and Issues layer complete | ~Month 10–12 | |

Assumptions: two senior full-stack engineers, a part-time designer and a founder doing sales. See [11](11-roadmap.md).
