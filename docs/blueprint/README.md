# ReviewPilot — Product Strategy & Technical Blueprint

> Status: **Planning phase (v1.0, 2026-11-07)**. No application code exists yet.
> This folder is the single source of truth for building ReviewPilot as a new, independent project.

## How to read this blueprint

| # | Document | Audience |
|---|----------|----------|
| 01 | [Executive product overview](01-executive-overview.md) | Founders, investors |
| 02 | [Market & competitor research](02-market-competitor-research.md) | Founders, sales |
| 03 | [Product requirements document (PRD)](03-prd.md) | Product, engineering |
| 04 | [Feature specification](04-feature-specification.md) | Engineering, design |
| 05 | [Technical architecture](05-technical-architecture.md) | Engineering |
| 06 | [Database schema & ERD](06-database-schema.md) | Engineering |
| 07 | [API integration specification](07-api-integration-spec.md) | Engineering |
| 08 | [UX/UI sitemap & screens](08-ux-ui-sitemap.md) | Design, frontend |
| 09 | [Commercial & pricing strategy](09-pricing-strategy.md) | Founders, sales |
| 10 | [Security & compliance assessment](10-security-compliance.md) | Engineering, legal |
| 11 | [Development roadmap](11-roadmap.md) | Everyone |
| 12 | [Risk register](12-risk-register.md) | Founders |
| 13 | [MVP scope](13-mvp-scope.md) | Everyone |
| 14 | [Long-term product vision](14-long-term-vision.md) | Founders |

## Evidence labels used throughout

Every non-obvious claim is tagged:

- **[V]**: verified against a cited source during research (2026-11).
- **[V-2]**: verified through secondary sources only (aggregators, review sites, press). Confirm before you rely on it commercially.
- **[T]**: known from prior technical knowledge of the API or tool, not re-verified in this research round. It is very likely correct, but **check it in the official docs before implementation**.
- **[A]**: an assumption or estimate made by the author. Treat it as a hypothesis to validate.

> **Research limitation:** this research ran in a sandbox that could not fetch `developers.google.com` directly. Google API facts marked [V] were confirmed through search snippets of the official pages. Before Phase 2 starts, a developer must read these official pages in full: Reviews reference, Notifications reference, Prerequisites, Usage limits, Places policies, and the GBP API Terms.

## The blueprint in one paragraph

ReviewPilot should **not** launch as "an AI that writes Google review replies". Google itself is testing built-in AI replies [V-2], and dozens of cheap tools already do this. The defensible product is an **operations-grade reputation system for multi-location hospitality**. It turns review streams into tracked operational issues, measures whether fixes worked, and enforces safe, on-brand responses through governed automation. Enter the Dutch market through **small restaurant groups and franchises (3–30 locations)** and hospitality-software partners. Independents are served via a low-touch self-serve tier. The stack (Laravel 13 / Vue 3 / Inertia / Postgres / Redis) is appropriate, built as a **modular monolith** with shared-schema multi-tenancy. The longest lead-time item is **Google API access approval**, and it must start on day one.
