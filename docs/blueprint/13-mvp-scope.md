# 13 — MVP Scope

**MVP definition:** the smallest product that a **10-location restaurant group** would pay **€50+ per location per month** for. Target: a paid private beta around month 6 ([11](11-roadmap.md)).

## In scope ✔

| Area             | Included                                                                                                                                                       |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Auth & tenancy   | Email + Google sign-in, optional 2FA (required for owners on Group), orgs, invitations, org roles, location-scoped roles                                       |
| Google           | OAuth connect, discovery, location import, backfill, polling + Pub/Sub, reconcile, publish/update/delete reply, connection health                              |
| AI               | Analysis (language, sentiment, ~30 fixed topics, risk flags, summary), 3 draft variants, regenerate with hint, guardrails, NL/EN/DE/FR + auto language replies |
| Voice            | Org voice + location overrides, versioning, fact sheet, live preview                                                                                           |
| Workflow         | Inbox, approval queue, review detail with timeline, Manual + Smart Approval modes, hold window, caps, kill switch, circuit breaker                             |
| Insights (basic) | KPIs (rating, volume, response rate, response time), rating trend, top topics with trend, location table                                                       |
| Notifications    | In-app, email: negative review alert, approvals waiting, sync problems, weekly digest                                                                          |
| Billing          | Stripe or Mollie subscriptions per location, trial, invoices, iDEAL/SEPA/card                                                                                  |
| Compliance       | DPA, privacy policy, terms, retention defaults, data export, org deletion                                                                                      |
| Admin            | Filament: orgs, users, connections, sync runs, failed jobs, AI cost, kill switch                                                                               |
| UI               | Dark theme, NL + EN, responsive with mobile-optimised approvals                                                                                                |

## Explicitly out of scope ✖ (and why)

| Feature                                           | Reason                                        | Earliest                                    |
| ------------------------------------------------- | --------------------------------------------- | ------------------------------------------- |
| Auto-Pilot (beyond Smart)                         | Needs acceptance data to set safe defaults    | Phase 6b, behind a flag                     |
| Issues & outcome measurement                      | Needs weeks of real data and partner input    | Phase 5 (planned **soon after beta start**) |
| Monthly PDF + AI narrative                        | Same                                          | Phase 5                                     |
| Spike alerts                                      | False-positive risk without baseline data     | Phase 5                                     |
| Dish-level analysis                               | Needs menu setup UX                           | Phase 5                                     |
| Slack/Teams, WhatsApp                             | Email suffices to validate                    | Phase 6b / 7                                |
| Competitor benchmark                              | Terms constraints, low core value             | Phase 7                                     |
| Review collection campaigns                       | Compliance design needed; separate value prop | Phase 7 (first item)                        |
| Other review sources                              | API access and terms per source               | Phase 7+                                    |
| Franchise billing split, agency mode, white-label | Complexity                                    | Phase 7+                                    |
| Public API                                        | No demand yet                                 | Phase 7+                                    |
| Light theme                                       | Not needed to validate                        | Phase 6b                                    |
| Forecasting, reputation score                     | Low value at SMB volumes                      | Not planned                                 |

## MVP success criteria (end of 3-month beta)

- ≥ 5 paying design-partner groups, ≥ 40 paying locations.
- Draft acceptance (no or light edit) ≥ 75%.
- Response rate on partner locations ≥ 90%.
- Zero high-risk auto-publishes.
- At least 3 partners say they'd be "very disappointed" without ReviewPilot (Sean Ellis test ≥ 40% among active users).
- Weekly active managers ≥ 60% of seats.

If these are not met, **do not proceed to public launch**. Iterate on quality and ICP first.
